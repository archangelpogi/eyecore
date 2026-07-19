<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}


// ✅ Development mode toggle
$DEV_MODE = true;

// Show errors only in development
if ($DEV_MODE) {
    ini_set('display_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(0);
}

// ✅ Set header JSON
header('Content-Type: application/json; charset=UTF-8');

// ✅ Catch fatal errors before output
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Fatal Error: ' . $err['message'],
            'file' => $err['file'],
            'line' => $err['line']
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
});

// ✅ Global try/catch wrapper
try {
    // Include DB connection
    require_once __DIR__ . '/../config/db.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('Database connection failed');
    }

    // Include RBACHelper
    require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ IDAGDAG ITO!

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - HR module (ENTERPRISE plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('hr')) {
    header('Location: ../views/subscription.php');
    exit;
}
    // Load permissions to session if not already loaded
    if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
        RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
    }

    // Include PayrollCalculator
    require_once __DIR__ . '/payroll_calculator.php';

    $payrollBackend = new PayrollBackend($pdo);
    $payrollBackend->handleRequest();

} catch (Exception $e) {
    // Always return JSON on exception
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage(),
        'trace' => $DEV_MODE ? $e->getTraceAsString() : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

class PayrollBackend {
    private $pdo;
    private $clinic_id;
    private $user_id;
    private $user_role;
    
    // ✅ IISA LANG NA CONSTRUCTOR
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // ✅ Initialize RBACHelper with PDO
        RBACHelper::init($this->pdo);
        
        $this->checkAuth();
    }
    
    private function checkAuth() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
            $this->sendResponse(401, ['error' => 'Unauthorized']);
        }
        
        $this->clinic_id = $_SESSION['clinic_id'];
        $this->user_id = $_SESSION['user_id'];
        $this->user_role = $_SESSION['role'] ?? null;
        
        // ✅ Load permissions to session if not already loaded
        if (!isset($_SESSION['permissions'])) {
            RBACHelper::loadPermissionsToSession($this->user_id, $this->clinic_id);
        }
        
        // Debug for Mean Mendoza
        if ($this->user_id == 161) {
            error_log("=== Payroll Backend Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Permissions loaded: " . count($_SESSION['permissions'] ?? []));
            error_log("Has payroll_view: " . ($this->canView() ? 'YES' : 'NO'));
            error_log("Has payroll_create: " . ($this->canCreate() ? 'YES' : 'NO'));
        }
    }
    
    // ============= AUDIT LOGGING METHOD =============
    private function logAudit($action, $table_name, $record_id = null, $old_values = null, $new_values = null) {
        try {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            
            $old_values_json = is_array($old_values) ? json_encode($old_values) : $old_values;
            $new_values_json = is_array($new_values) ? json_encode($new_values) : $new_values;
            
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_logs 
                (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->user_id,
                $this->clinic_id,
                $action,
                $table_name,
                $record_id,
                $old_values_json,
                $new_values_json,
                $ip_address,
                $user_agent
            ]);
            
            error_log("Audit log created: $action on $table_name");
            
        } catch (Exception $e) {
            error_log("Failed to create audit log: " . $e->getMessage());
        }
    }
    
    // ============= PERMISSION METHODS USING RBACHelper =============
    private function checkHRExists() {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as hr_count 
            FROM users 
            WHERE clinic_id = ? AND role = 'HR' AND status = 'Active'
        ");
        $stmt->execute([$this->clinic_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['hr_count'] > 0;
    }
    
    private function getCurrentUserRole() {
        return $this->user_role;
    }
    
    // ✅ GAMITIN ANG RBACHelper PARA SA PERMISSION CHECKS
    private function hasPermission($permission_name) {
        return RBACHelper::hasPermission($permission_name);
    }
    
    private function canView() {
        return $this->hasPermission('payroll_view');
    }
    
    private function canCreate() {
        return $this->hasPermission('payroll_create');
    }
    
    private function canEdit() {
        return $this->hasPermission('payroll_edit');
    }
    
    private function canDelete() {
        return $this->hasPermission('payroll_delete');
    }
    
    private function canApprove() {
        return $this->hasPermission('payroll_approve');
    }
    
    private function canReject() {
        return $this->hasPermission('payroll_reject');
    }
    
    private function canRelease() {
        return $this->hasPermission('payroll_release');
    }
    
    private function canCancel() {
        return $this->hasPermission('payroll_cancel');
    }
    
    private function canExport() {
        return $this->hasPermission('payroll_export');
    }
    
    public function getCurrentUserPermissions() {
        $role = $this->getCurrentUserRole();
        $hasHR = $this->checkHRExists();
        
        // ✅ GAMIT ANG MGA PERMISSION NAMES
        $permissions = [
            'view' => $this->canView(),
            'create' => $this->canCreate(),
            'edit' => $this->canEdit(),
            'delete' => $this->canDelete(),
            'approve' => $this->canApprove(),
            'reject' => $this->canReject(),
            'release' => $this->canRelease(),
            'cancel' => $this->canCancel(),
            'export' => $this->canExport()
        ];
        
        // Override for ClinicAdmin in oversight mode
        if ($role === 'ClinicAdmin' && $hasHR && !$this->canEdit()) {
            $permissions = [
                'view' => true,
                'create' => false,
                'edit' => false,
                'delete' => false,
                'approve' => true,
                'reject' => true,
                'release' => true,
                'cancel' => false,
                'export' => true
            ];
        }
        
        return [
            'role' => $role,
            'permissions' => $permissions,
            'hasHR' => $hasHR,
            'isOwner' => ($role === 'ClinicAdmin' && !$hasHR),
            'user_id' => $this->user_id
        ];
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet();
                    break;
                case 'POST':
                    $this->handlePost();
                    break;
                default:
                    $this->sendResponse(405, ['error' => 'Method not allowed']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    // ============= GET REQUEST HANDLERS =============
    private function handleGet() {
        // ✅ Check view permission
        if (!$this->canView()) {
            $this->sendResponse(403, ['error' => 'You do not have permission to view payroll']);
        }
        
        if (isset($_GET['get_permissions'])) {
            $this->sendResponse(200, [
                'success' => true,
                'data' => $this->getCurrentUserPermissions()
            ]);
        } elseif (isset($_GET['id'])) {
            $this->getPayrollDetails($_GET['id']);
        } elseif (isset($_GET['summary'])) {
            $this->getPayrollSummary();
        } elseif (isset($_GET['pending_approvals'])) {
            $this->getPendingApprovals();
        } elseif (isset($_GET['active_only'])) {
            $this->getActiveEmployees();
        } elseif (isset($_GET['export'])) {
            $this->exportPayroll();
        } else {
            $this->getPayrollList();
        }
    }
    
    private function getPayrollDetails($payroll_id) {
        $stmt = $this->pdo->prepare("
            SELECT p.*, 
                   e.employee_no,
                   e.basic_salary,
                   e.bank_name,
                   e.bank_account_number,
                   e.bank_account_holder,
                   e.salary_frequency,  
                   e.salary_type,       
                   CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                   pos.position_name,
                   pos.department,
                   CONCAT(ug.first_name, ' ', ug.last_name) as generated_by_name,
                   CONCAT(ur.first_name, ' ', ur.last_name) as released_by_name,
                   (SELECT COUNT(*) FROM payroll_approvals pa WHERE pa.payroll_id = p.id AND pa.status = 'approved') as approval_count,
                   (SELECT COUNT(*) FROM payroll_approvals pa WHERE pa.payroll_id = p.id) as total_approvers
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            LEFT JOIN positions pos ON e.position_id = pos.id
            LEFT JOIN users ug ON p.generated_by = ug.id
            LEFT JOIN users ur ON p.released_by = ur.id
            WHERE p.id = ? AND p.clinic_id = ?
        ");
        $stmt->execute([$payroll_id, $this->clinic_id]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payroll) {
            $this->sendResponse(404, ['error' => 'Payroll not found']);
        }
        
        // Get payroll adjustments
        $stmt = $this->pdo->prepare("
            SELECT pa.*, pi.item_name, pi.item_type
            FROM payroll_adjustments pa
            LEFT JOIN payroll_items pi ON pa.payroll_item_id = pi.id
            WHERE pa.employee_id = ? 
            AND pa.status = 'active'
            AND (
                (pa.is_recurring = 1 AND pa.effective_date <= ? AND (pa.end_date IS NULL OR pa.end_date >= ?))
                OR (pa.is_recurring = 0 AND DATE_FORMAT(pa.effective_date, '%Y-%m') = ?)
            )
        ");
        $stmt->execute([
            $payroll['employee_id'], 
            $payroll['period_end'],
            $payroll['period_start'],
            date('Y-m', strtotime($payroll['period_start']))
        ]);
        $adjustments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get overtime for the period (WITH APPROVAL STATUS)
        $stmt = $this->pdo->prepare("
            SELECT ot.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as approved_by_name
            FROM overtime_requests ot
            LEFT JOIN users u ON ot.approved_by = u.id
            WHERE ot.employee_id = ? 
            AND ot.overtime_date BETWEEN ? AND ?
            ORDER BY ot.overtime_date ASC
        ");
        $stmt->execute([$payroll['employee_id'], $payroll['period_start'], $payroll['period_end']]);
        $overtime = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get attendance records (WITH APPROVAL STATUS)
        $stmt = $this->pdo->prepare("
            SELECT a.date, a.time_in, a.time_out, a.total_hours, a.status, a.approval_status, a.remarks
            FROM attendance a
            WHERE a.employee_id = ? 
            AND a.date BETWEEN ? AND ?
            ORDER BY a.date ASC
        ");
        $stmt->execute([$payroll['employee_id'], $payroll['period_start'], $payroll['period_end']]);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get leave records for the period (WITH APPROVAL STATUS)
        $stmt = $this->pdo->prepare("
            SELECT l.start_date, l.end_date, l.leave_type, l.number_of_days, l.status, l.reason,
                   CONCAT(u.first_name, ' ', u.last_name) as approved_by_name
            FROM leaves l
            LEFT JOIN users u ON l.approved_by = u.id
            WHERE l.employee_id = ? 
            AND (
                (l.start_date BETWEEN ? AND ?)
                OR (l.end_date BETWEEN ? AND ?)
                OR (l.start_date <= ? AND l.end_date >= ?)
            )
            ORDER BY l.start_date ASC
        ");
        $stmt->execute([$payroll['employee_id'], $payroll['period_start'], $payroll['period_end'], 
                       $payroll['period_start'], $payroll['period_end'], 
                       $payroll['period_start'], $payroll['period_end']]);
        $leave_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get approval history
        $stmt = $this->pdo->prepare("
            SELECT pa.*, CONCAT(u.first_name, ' ', u.last_name) as approver_name, u.role
            FROM payroll_approvals pa
            JOIN users u ON pa.approver_id = u.id
            WHERE pa.payroll_id = ?
            ORDER BY pa.approval_level
        ");
        $stmt->execute([$payroll_id]);
        $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Log view action
        $this->logAudit('VIEW', 'payroll', $payroll_id, null, null);
        
        $this->sendResponse(200, [
            'success' => true, 
            'data' => $payroll,
            'adjustments' => $adjustments,
            'overtime' => $overtime,
            'approvals' => $approvals,
            'attendance_records' => $attendance_records,
            'leave_records' => $leave_records  // ✅ NEW: Add leave records
        ]);
    }
    
    private function getPayrollSummary() {
        $month = $_GET['month'] ?? date('Y-m');
        
        $stmt = $this->pdo->prepare("
            SELECT 
                COUNT(*) as total_payrolls,
                COUNT(CASE WHEN status = 'Released' THEN 1 END) as released_payrolls,
                COUNT(CASE WHEN status = 'Draft' THEN 1 END) as draft_payrolls,
                COUNT(CASE WHEN status = 'For Approval' THEN 1 END) as pending_approval,
                COALESCE(SUM(CASE WHEN status IN ('Released', 'For Approval', 'Approved') THEN net_pay ELSE 0 END), 0) as total_net_pay
            FROM payroll
            WHERE clinic_id = ?
            AND payroll_period = ?
        ");
        $stmt->execute([$this->clinic_id, $month]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->sendResponse(200, ['success' => true, 'data' => $summary]);
    }
    
    private function getPendingApprovals() {
        $stmt = $this->pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as pending_approvals
            FROM payroll p
            JOIN payroll_approvals pa ON p.id = pa.payroll_id
            WHERE p.status = 'For Approval'  
            AND pa.status = 'pending'
            AND pa.approver_id = ?
            AND p.clinic_id = ?
        ");
        $stmt->execute([$this->user_id, $this->clinic_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->sendResponse(200, ['success' => true, 'data' => $result]);
    }
    
    private function getActiveEmployees() {
        $stmt = $this->pdo->prepare("
            SELECT e.id, e.employee_no, u.first_name, u.last_name
            FROM employees e
            JOIN users u ON e.user_id = u.id
            WHERE u.clinic_id = ?
            AND e.status = 'Active'
            AND u.status = 'Active'
            ORDER BY u.first_name
        ");
        $stmt->execute([$this->clinic_id]);
        $this->sendResponse(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    private function getPayrollList() {
        $employee_id = $_GET['employee_id'] ?? null;
        $payroll_period = $_GET['payroll_period'] ?? null;
        $status = $_GET['status'] ?? null;
        
        $where = ["p.clinic_id = ?"];
        $params = [$this->clinic_id];
        
        if ($employee_id) {
            $where[] = "p.employee_id = ?";
            $params[] = $employee_id;
        }
        
        if ($payroll_period) {
            $where[] = "p.payroll_period = ?";
            $params[] = $payroll_period;
        }
        
        if ($status) {
            $where[] = "p.status = ?";
            $params[] = $status;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where);
        
        $stmt = $this->pdo->prepare("
            SELECT p.*, 
                   e.employee_no,
                   e.salary_frequency,  
                   e.salary_type,       
                   CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                   pos.position_name,
                   (SELECT COUNT(*) FROM payroll_approvals pa WHERE pa.payroll_id = p.id AND pa.status = 'approved') as approval_count,
                   (SELECT COUNT(*) FROM payroll_approvals pa WHERE pa.payroll_id = p.id) as total_approvers
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            LEFT JOIN positions pos ON e.position_id = pos.id
            $where_clause
            ORDER BY p.period_start DESC, p.id DESC
        ");
        $stmt->execute($params);
        
        $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logAudit('VIEW_LIST', 'payroll', null, null, ['count' => count($payrolls)]);
        
        $this->sendResponse(200, $payrolls);
    }
    
    private function exportPayroll() {
        if (!$this->canExport()) {
            $this->sendResponse(403, ['error' => 'You do not have permission to export payroll']);
        }
        
        $employee_id = $_GET['employee_id'] ?? null;
        $payroll_period = $_GET['payroll_period'] ?? null;
        $status = $_GET['status'] ?? null;
        
        $where = ["p.clinic_id = ?"];
        $params = [$this->clinic_id];
        
        if ($employee_id) {
            $where[] = "p.employee_id = ?";
            $params[] = $employee_id;
        }
        
        if ($payroll_period) {
            $where[] = "p.payroll_period = ?";
            $params[] = $payroll_period;
        }
        
        if ($status) {
            $where[] = "p.status = ?";
            $params[] = $status;
        }
        
        $where_clause = "WHERE " . implode(" AND ", $where);
        
        $stmt = $this->pdo->prepare("
            SELECT 
                p.payroll_period,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                e.employee_no,
                p.basic_salary,
                p.overtime,
                p.holiday_pay,
                p.allowances,
                p.bonuses,
                p.gross_pay,
                p.sss_contribution,
                p.philhealth_contribution,
                p.pagibig_contribution,
                p.withholding_tax,
                p.other_deductions,
                p.total_deductions,
                p.net_pay,
                p.status,
                p.generated_at
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            $where_clause
            ORDER BY p.period_start DESC, p.id DESC
        ");
        $stmt->execute($params);
        $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Set headers for CSV download
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="payroll_export_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Add UTF-8 BOM for Excel
        fputs($output, "\xEF\xBB\xBF");
        
        // Headers
        fputcsv($output, [
            'Period', 'Employee', 'Employee No', 'Basic Salary', 'Overtime', 
            'Holiday Pay', 'Allowances', 'Bonuses', 'Gross Pay',
            'SSS', 'PhilHealth', 'Pag-IBIG', 'Withholding Tax', 'Other Deductions',
            'Total Deductions', 'Net Pay', 'Status', 'Generated Date'
        ]);
        
        // Data rows
        foreach ($payrolls as $p) {
            fputcsv($output, [
                $p['payroll_period'],
                $p['employee_name'],
                $p['employee_no'],
                number_format($p['basic_salary'], 2),
                number_format($p['overtime'], 2),
                number_format($p['holiday_pay'], 2),
                number_format($p['allowances'], 2),
                number_format($p['bonuses'], 2),
                number_format($p['gross_pay'], 2),
                number_format($p['sss_contribution'], 2),
                number_format($p['philhealth_contribution'], 2),
                number_format($p['pagibig_contribution'], 2),
                number_format($p['withholding_tax'], 2),
                number_format($p['other_deductions'], 2),
                number_format($p['total_deductions'], 2),
                number_format($p['net_pay'], 2),
                $p['status'],
                date('Y-m-d H:i', strtotime($p['generated_at']))
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    // ============= POST REQUEST HANDLERS =============
    private function handlePost() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            $this->sendResponse(400, ['error' => 'Invalid input']);
        }
        
        // ============================================
        // PERMISSION CHECKS - Using RBACHelper
        // ============================================
        
        if (isset($data['generate_bulk_payroll']) || isset($data['generate_payroll'])) {
            if (!$this->canCreate()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to generate payroll']);
            }
            if (isset($data['generate_bulk_payroll'])) {
                $this->generateBulkPayroll($data);
            } else {
                $this->generatePayroll($data);
            }
        } elseif (isset($data['update_payroll'])) {
            if (!$this->canEdit()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to edit payroll']);
            }
            $this->updatePayroll($data);
        } elseif (isset($data['submit_for_approval'])) {
            if (!$this->canEdit()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to submit payroll']);
            }
            $this->submitForApproval($data);
        } elseif (isset($data['cancel_payroll'])) {
            if (!$this->canCancel()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to cancel payroll']);
            }
            $this->cancelPayroll($data);
        } elseif (isset($data['preview_payroll'])) {
            if (!$this->canView()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to preview payroll']);
            }
            $this->previewPayroll($data);
        } elseif (isset($data['approve_payroll'])) {
            if (!$this->canApprove()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to approve payroll']);
            }
            $this->approvePayroll($data);
        } elseif (isset($data['reject_payroll'])) {
            if (!$this->canReject()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to reject payroll']);
            }
            $this->rejectPayroll($data);
        } elseif (isset($data['release_payroll'])) {
            if (!$this->canRelease()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to release payroll']);
            }
            $this->releasePayroll($data);
        } else {
            $this->sendResponse(400, ['error' => 'Invalid request action']);
        }
    }
    
    private function generateBulkPayroll($data) {
        $period = $data['payroll_period'] ?? '';
        $start_date = $data['period_start'] ?? '';
        $end_date = $data['period_end'] ?? '';
        
        $validate_attendance = $data['validate_attendance'] ?? true;
        $prorate_salary = $data['prorate_salary'] ?? false;
        $manual_bonus = floatval($data['manual_bonus'] ?? 0);
        $manual_deduction = floatval($data['manual_deduction'] ?? 0);
        $manual_holiday_pay = floatval($data['manual_holiday_pay'] ?? 0);
        $manual_allowances = floatval($data['manual_allowances'] ?? 0);
        $adjustment_remarks = $data['adjustment_remarks'] ?? '';
        $include_sss = $data['include_sss'] ?? true;
        $include_philhealth = $data['include_philhealth'] ?? true;
        $include_pagibig = $data['include_pagibig'] ?? true;
        $include_tax = $data['include_tax'] ?? true;
        
        $use_manual_contributions = $data['use_manual_contributions'] ?? false;
        $manual_sss = floatval($data['manual_sss'] ?? 0);
        $manual_philhealth = floatval($data['manual_philhealth'] ?? 0);
        $manual_pagibig = floatval($data['manual_pagibig'] ?? 0);
        $manual_tax = floatval($data['manual_tax'] ?? 0);
        
        if (!$period || !$start_date || !$end_date) {
            $this->sendResponse(400, ['error' => 'Missing required fields']);
        }
        
        // Get active employees
        $stmt = $this->pdo->prepare("
            SELECT e.*, u.first_name, u.last_name
            FROM employees e
            JOIN users u ON e.user_id = u.id
            WHERE u.clinic_id = ?
            AND e.status = 'Active'
            AND u.status = 'Active'
        ");
        $stmt->execute([$this->clinic_id]);
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($employees)) {
            $this->sendResponse(400, ['error' => 'No active employees found']);
        }
        
        $this->pdo->beginTransaction();
        $generated_count = 0;
        $skipped_count = 0;
        $no_attendance_count = 0;
        $validation_errors = [];
        $generated_payrolls = [];
        
        try {
            $calculator = new PayrollCalculator($this->pdo);
            
            foreach ($employees as $employee) {
                $employee_name = $employee['first_name'] . ' ' . $employee['last_name'];
                
                // Check if payroll already exists
                $stmt = $this->pdo->prepare("
                    SELECT id FROM payroll 
                    WHERE employee_id = ? 
                    AND payroll_period = ?
                    AND clinic_id = ?
                ");
                $stmt->execute([$employee['id'], $period, $this->clinic_id]);
                
                if ($stmt->rowCount() > 0) {
                    $skipped_count++;
                    continue;
                }
                
                // ✅ VALIDATE EMPLOYEE FIRST (Attendance, OT, Leave must be approved)
                $validation = $this->validateEmployeeForPayroll($employee['id'], $start_date, $end_date);
                
                if (!$validation['valid']) {
                    $skipped_count++;
                    $validation_errors[] = [
                        'employee_id' => $employee['id'],
                        'employee_name' => $employee_name,
                        'issues' => $validation['issues']
                    ];
                    continue;
                }
                
                // ATTENDANCE VALIDATION (for days worked calculation)
                $attendance_data = null;
                if ($validate_attendance) {
                    $stmt = $this->pdo->prepare("
                        SELECT date, time_in, time_out, total_hours, status
                        FROM attendance 
                        WHERE employee_id = ? 
                        AND date BETWEEN ? AND ?
                        AND approval_status = 'approved'
                        AND status IN ('Present', 'Late', 'Half-day')
                        ORDER BY date
                    ");
                    $stmt->execute([$employee['id'], $start_date, $end_date]);
                    $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($attendance_records) == 0) {
                        $no_attendance_count++;
                        $skipped_count++;
                        continue;
                    }
                    
                    $total_days_equivalent = 0;
                    $total_hours_worked = 0;
                    $late_days = 0;
                    
                    foreach ($attendance_records as $record) {
                        if (!empty($record['time_in']) && !empty($record['time_out'])) {
                            $hours = floatval($record['total_hours'] ?? 0);
                            $total_hours_worked += $hours;
                            
                            if ($hours >= 8) {
                                $total_days_equivalent += 1;
                            } else if ($hours >= 4) {
                                $total_days_equivalent += 0.5;
                            } else if ($hours > 0) {
                                $total_days_equivalent += $hours / 8;
                            }
                        } else {
                            $total_days_equivalent += 1;
                            $total_hours_worked += 8;
                            
                            if ($record['status'] === 'Late') {
                                $late_days++;
                            }
                        }
                    }
                    
                    $attendance_data = [
                        'present_days' => $total_days_equivalent,
                        'absent_days' => 0,
                        'late_days' => $late_days,
                        'half_days' => 0,
                        'total_hours' => $total_hours_worked,
                        'total_days_worked' => $total_days_equivalent,
                        'absences_deduction' => 0,
                        'tardiness_deduction' => 0,
                        'records' => $attendance_records
                    ];
                }
                
                $manual_values = $use_manual_contributions ? [
                    'use_manual' => true,
                    'sss' => $manual_sss,
                    'philhealth' => $manual_philhealth,
                    'pagibig' => $manual_pagibig,
                    'tax' => $manual_tax
                ] : [];
                
                $result = $calculator->calculatePayroll(
                    $employee['id'],
                    $start_date,
                    $end_date,
                    $period,
                    true,
                    $attendance_data,
                    $prorate_salary,
                    [
                        'include_sss' => $include_sss,
                        'include_philhealth' => $include_philhealth,
                        'include_pagibig' => $include_pagibig,
                        'include_tax' => $include_tax
                    ],
                    $manual_values
                );
                
                if ($result['error']) {
                    error_log("Payroll calculation failed for employee {$employee['id']}: " . $result['message']);
                    $skipped_count++;
                    continue;
                }
                
                // Apply manual adjustments (bonus, deduction, holiday pay, allowances)
                $total_manual_adjustment = 0;
                if ($manual_bonus > 0 || $manual_deduction > 0 || $manual_holiday_pay > 0 || $manual_allowances > 0) {
                    $result['bonuses'] += $manual_bonus;
                    $result['holiday_pay'] += $manual_holiday_pay;
                    $result['allowances'] += $manual_allowances;
                    $result['other_deductions'] += $manual_deduction;
                    $total_manual_adjustment = $manual_bonus + $manual_holiday_pay + $manual_allowances - $manual_deduction;
                    
                    $result['gross_pay'] = $result['basic_salary'] + $result['overtime'] + 
                                           $result['holiday_pay'] + $result['allowances'] + 
                                           $result['bonuses'] + ($result['night_differential'] ?? 0);
                    $result['total_deductions'] = $result['sss_contribution'] + 
                                                  $result['philhealth_contribution'] + 
                                                  $result['pagibig_contribution'] + 
                                                  $result['withholding_tax'] + 
                                                  $result['other_deductions'];
                    $result['net_pay'] = $result['gross_pay'] - $result['total_deductions'];
                }
                
                $status = 'Draft';
                $submitted_at = null;
                
                // Insert payroll record
                $stmt = $this->pdo->prepare("
                    INSERT INTO payroll (
                        clinic_id, employee_id, payroll_period, period_start, period_end,
                        basic_salary, overtime, holiday_pay, allowances, bonuses,
                        tardiness, absences, leave_without_pay,
                        night_differential, thirteenth_month,
                        sss_contribution, philhealth_contribution, pagibig_contribution,
                        withholding_tax, other_deductions,
                        gross_pay, total_deductions, net_pay, status,
                        submitted_at, generated_by, generated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $this->clinic_id,
                    $employee['id'],
                    $period,
                    $start_date,
                    $end_date,
                    $result['basic_salary'],
                    $result['overtime'],
                    $result['holiday_pay'],
                    $result['allowances'],
                    $result['bonuses'],
                    $result['tardiness'],
                    $result['absences'],
                    $result['leave_without_pay'],
                    $result['night_differential'] ?? 0.00,
                    $result['thirteenth_month'] ?? 0.00,
                    $result['sss_contribution'],
                    $result['philhealth_contribution'],
                    $result['pagibig_contribution'],
                    $result['withholding_tax'],
                    $result['other_deductions'],
                    $result['gross_pay'],
                    $result['total_deductions'],
                    $result['net_pay'],
                    $status,
                    $submitted_at,
                    $this->user_id
                ]);
                
                $payroll_id = $this->pdo->lastInsertId();
                
                // ✅ SAVE MANUAL ADJUSTMENT RECORD
                if ($total_manual_adjustment != 0) {
                    try {
                        $adjustment_details = [];
                        if ($manual_bonus > 0) $adjustment_details[] = "Bonus: ₱" . number_format($manual_bonus, 2);
                        if ($manual_holiday_pay > 0) $adjustment_details[] = "Holiday Pay: ₱" . number_format($manual_holiday_pay, 2);
                        if ($manual_allowances > 0) $adjustment_details[] = "Allowances: ₱" . number_format($manual_allowances, 2);
                        if ($manual_deduction > 0) $adjustment_details[] = "Deduction: ₱" . number_format($manual_deduction, 2);
                        
                        $adjustment_text = implode(", ", $adjustment_details);
                        $adjustment_reason = $adjustment_text . ($adjustment_remarks ? " - " . $adjustment_remarks : "");
                        
                        $stmt = $this->pdo->prepare("
                            INSERT INTO payroll_adjustments 
                            (payroll_id, employee_id, adjustment_type, amount, reason, created_by, created_at)
                            VALUES (?, ?, 'manual', ?, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $payroll_id, 
                            $employee['id'], 
                            $total_manual_adjustment,
                            $adjustment_reason,
                            $this->user_id
                        ]);
                        
                        error_log("Manual adjustment saved for payroll ID: $payroll_id, amount: $total_manual_adjustment");
                    } catch (Exception $e) {
                        error_log("Failed to save adjustment: " . $e->getMessage());
                    }
                }
                
                // ✅ SAVE CONTRIBUTION MANUAL ADJUSTMENT if used
                if ($use_manual_contributions && ($manual_sss > 0 || $manual_philhealth > 0 || $manual_pagibig > 0 || $manual_tax > 0)) {
                    try {
                        $contribution_details = [];
                        if ($manual_sss > 0) $contribution_details[] = "SSS: ₱" . number_format($manual_sss, 2);
                        if ($manual_philhealth > 0) $contribution_details[] = "PhilHealth: ₱" . number_format($manual_philhealth, 2);
                        if ($manual_pagibig > 0) $contribution_details[] = "Pag-IBIG: ₱" . number_format($manual_pagibig, 2);
                        if ($manual_tax > 0) $contribution_details[] = "Tax: ₱" . number_format($manual_tax, 2);
                        
                        $contribution_text = "Manual contributions: " . implode(", ", $contribution_details);
                        
                        $stmt = $this->pdo->prepare("
                            INSERT INTO payroll_adjustments 
                            (payroll_id, employee_id, adjustment_type, amount, reason, created_by, created_at)
                            VALUES (?, ?, 'contribution_override', 0, ?, ?, NOW())
                        ");
                        $stmt->execute([
                            $payroll_id, 
                            $employee['id'], 
                            $contribution_text,
                            $this->user_id
                        ]);
                    } catch (Exception $e) {
                        error_log("Failed to save contribution override: " . $e->getMessage());
                    }
                }
                
                // ✅ AUDIT TRAIL
                $stmt = $this->pdo->prepare("
                    INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
                    VALUES (?, 'created', ?, ?, ?)
                ");
                $stmt->execute([
                    $payroll_id, 
                    $this->user_id, 
                    'Bulk payroll generated' . ($adjustment_remarks ? ' - ' . $adjustment_remarks : ''),
                    $_SERVER['REMOTE_ADDR']
                ]);
                
                // ✅ LOG TO AUDIT_LOGS
                $new_values = [
                    'employee_id' => $employee['id'],
                    'employee_name' => $employee_name,
                    'period' => $period,
                    'net_pay' => $result['net_pay'],
                    'status' => $status,
                    'manual_bonus' => $manual_bonus,
                    'manual_deduction' => $manual_deduction,
                    'manual_holiday_pay' => $manual_holiday_pay,
                    'manual_allowances' => $manual_allowances,
                    'adjustment_remarks' => $adjustment_remarks
                ];
                $this->logAudit('CREATE', 'payroll', $payroll_id, null, $new_values);
                
                // ✅ ADD APPROVERS
                $approvers = $this->getPayrollApprovers();
                foreach ($approvers as $approver) {
                    if ($approver['level'] == 1) {
                        $stmt = $this->pdo->prepare("
                            INSERT INTO payroll_approvals
                            (payroll_id, approver_id, approval_level, status)
                            VALUES (?, ?, 1, 'pending')
                        ");
                        $stmt->execute([$payroll_id, $approver['user_id']]);
                        break;
                    }
                }
                
                $generated_count++;
                $generated_payrolls[] = [
                    'employee_id' => $employee['id'],
                    'employee_name' => $employee_name,
                    'payroll_id' => $payroll_id,
                    'net_pay' => $result['net_pay'],
                    'status' => $status,
                    'days_present' => $attendance_data['present_days'] ?? 0,
                    'total_hours' => $attendance_data['total_hours'] ?? 0,
                    'manual_adjustment' => $total_manual_adjustment
                ];
            }
            
            $this->pdo->commit();
            
            // Build response with validation errors
            $response = [
                'success' => true,
                'message' => "Generated payroll for $generated_count employees.",
                'generated_count' => $generated_count,
                'skipped_count' => $skipped_count,
                'no_attendance_count' => $no_attendance_count,
                'payrolls' => $generated_payrolls
            ];
            
            // Add validation errors if any
            if (!empty($validation_errors)) {
                $response['validation_errors'] = $validation_errors;
                $response['message'] .= " " . count($validation_errors) . " employee(s) skipped due to pending/rejected records.";
            }
            
            $this->sendResponse(200, $response);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Bulk payroll generation error: " . $e->getMessage());
            $this->sendResponse(500, ['error' => 'Bulk generation failed: ' . $e->getMessage()]);
        }
    }
    
    private function generatePayroll($data) {
        $employee_id = $data['employee_id'] ?? null;
        $period = $data['payroll_period'] ?? '';
        $start_date = $data['period_start'] ?? '';
        $end_date = $data['period_end'] ?? '';
        
        if (!$employee_id || !$period || !$start_date || !$end_date) {
            $this->sendResponse(400, ['error' => 'Missing required fields']);
        }
        
        // Check if payroll already exists
        $stmt = $this->pdo->prepare("
            SELECT id FROM payroll 
            WHERE employee_id = ? 
            AND payroll_period = ?
            AND clinic_id = ?
        ");
        $stmt->execute([$employee_id, $period, $this->clinic_id]);
        
        if ($stmt->rowCount() > 0) {
            $this->sendResponse(400, ['error' => 'Payroll already exists for this period']);
        }
        
        $calculator = new PayrollCalculator($this->pdo);
        
        $result = $calculator->calculatePayroll(
            $employee_id,
            $start_date,
            $end_date,
            $period
        );
        
        if ($result['error']) {
            $this->sendResponse(400, ['error' => $result['message']]);
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll (
                    clinic_id, employee_id, payroll_period, period_start, period_end,
                    basic_salary, overtime, holiday_pay, allowances, bonuses,
                    tardiness, absences, leave_without_pay,
                    night_differential, thirteenth_month,
                    sss_contribution, philhealth_contribution, pagibig_contribution,
                    withholding_tax, other_deductions,
                    gross_pay, total_deductions, net_pay, status,
                    generated_by, generated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->clinic_id,
                $employee_id,
                $period,
                $start_date,
                $end_date,
                $result['basic_salary'],
                $result['overtime'],
                $result['holiday_pay'],
                $result['allowances'],
                $result['bonuses'],
                $result['tardiness'],
                $result['absences'],
                $result['leave_without_pay'],
                $result['night_differential'],
                $result['thirteenth_month'],
                $result['sss_contribution'],
                $result['philhealth_contribution'],
                $result['pagibig_contribution'],
                $result['withholding_tax'],
                $result['other_deductions'],
                $result['gross_pay'],
                $result['total_deductions'],
                $result['net_pay'],
                'Draft',
                $this->user_id
            ]);
            
            $payroll_id = $this->pdo->lastInsertId();
            
            // Audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll_audit_trail
                (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'created', ?, 'Single payroll generated', ?)
            ");
            $stmt->execute([
                $payroll_id,
                $this->user_id,
                $_SERVER['REMOTE_ADDR']
            ]);
            
            // Log to audit_logs
            $new_values = [
                'employee_id' => $employee_id,
                'period' => $period,
                'net_pay' => $result['net_pay'],
                'status' => 'Draft'
            ];
            $this->logAudit('CREATE', 'payroll', $payroll_id, null, $new_values);
            
            $approvers = $this->getPayrollApprovers();
            foreach ($approvers as $approver) {
                if ($approver['level'] == 1) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO payroll_approvals
                        (payroll_id, approver_id, approval_level, status)
                        VALUES (?, ?, 1, 'pending')
                    ");
                    $stmt->execute([$payroll_id, $approver['user_id']]);
                    break;
                }
            }
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payroll generated successfully (Draft)',
                'payroll_id' => $payroll_id,
                'status' => 'Draft',
                'data' => $result
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => 'Failed to generate payroll: ' . $e->getMessage()]);
        }
    }
    
    private function updatePayroll($data) {
        $payroll_id = $data['payroll_id'] ?? null;
        
        if (!$payroll_id) {
            $this->sendResponse(400, ['error' => 'Payroll ID is required']);
        }
        
        // Get current payroll
        $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$payroll_id, $this->clinic_id]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current) {
            $this->sendResponse(404, ['error' => 'Payroll not found']);
        }
        
        if (!in_array($current['status'], ['Draft', 'For Approval'])) {
            $this->sendResponse(400, ['error' => 'Payroll cannot be edited in current status']);
        }
        
        $old_values = $current;
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE payroll 
                SET overtime = ?, 
                    holiday_pay = ?,
                    allowances = ?,
                    bonuses = ?,
                    tardiness = ?,
                    absences = ?,
                    other_deductions = ?,
                    gross_pay = basic_salary + COALESCE(overtime, 0) + COALESCE(holiday_pay, 0) + COALESCE(allowances, 0) + COALESCE(bonuses, 0) - COALESCE(tardiness, 0) - COALESCE(absences, 0),
                    total_deductions = COALESCE(sss_contribution, 0) + COALESCE(philhealth_contribution, 0) + COALESCE(pagibig_contribution, 0) + COALESCE(withholding_tax, 0) + COALESCE(other_deductions, 0),
                    net_pay = (basic_salary + COALESCE(overtime, 0) + COALESCE(holiday_pay, 0) + COALESCE(allowances, 0) + COALESCE(bonuses, 0) - COALESCE(tardiness, 0) - COALESCE(absences, 0)) - 
                              (COALESCE(sss_contribution, 0) + COALESCE(philhealth_contribution, 0) + COALESCE(pagibig_contribution, 0) + COALESCE(withholding_tax, 0) + COALESCE(other_deductions, 0)),
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $stmt->execute([
                $data['overtime'] ?? $current['overtime'],
                $data['holiday_pay'] ?? $current['holiday_pay'],
                $data['allowances'] ?? $current['allowances'],
                $data['bonuses'] ?? $current['bonuses'],
                $data['tardiness'] ?? $current['tardiness'],
                $data['absences'] ?? $current['absences'],
                $data['other_deductions'] ?? $current['other_deductions'],
                $payroll_id
            ]);
            
            // Add adjustment if specified
            if (isset($data['adjustment_amount']) && $data['adjustment_amount'] != 0) {
                try {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO payroll_adjustments 
                        (employee_id, payroll_item_id, adjustment_type, amount, reason, 
                         effective_date, is_recurring, frequency, approved_by, approved_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $adjustment_type = $data['adjustment_amount'] > 0 ? 'addition' : 'deduction';
                    $stmt->execute([
                        $current['employee_id'],
                        $data['payroll_item_id'] ?? null,
                        $adjustment_type,
                        abs($data['adjustment_amount']),
                        $data['adjustment_reason'] ?? 'Manual adjustment',
                        $current['period_end'],
                        $data['is_recurring'] ?? 0,
                        $data['frequency'] ?? 'one_time',
                        $this->user_id
                    ]);
                } catch (Exception $e) {
                    error_log("Failed to insert adjustment: " . $e->getMessage());
                }
            }
            
            // Audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'updated', ?, 'Payroll updated', ?)
            ");
            $stmt->execute([$payroll_id, $this->user_id, $_SERVER['REMOTE_ADDR']]);
            
            // Get updated values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $new_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Log to audit_logs
            $this->logAudit('UPDATE', 'payroll', $payroll_id, $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payroll updated successfully'
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => 'Failed to update payroll: ' . $e->getMessage()]);
        }
    }
    
    private function submitForApproval($data) {
        $payroll_id = $data['payroll_id'] ?? null;
        
        if (!$payroll_id) {
            $this->sendResponse(400, ['error' => 'Payroll ID is required']);
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Get current values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                throw new Exception('Payroll not found');
            }
            
            // Update payroll status to 'For Approval'
            $stmt = $this->pdo->prepare("
                UPDATE payroll 
                SET status = 'For Approval',
                    submitted_at = NOW(),
                    generated_by = COALESCE(generated_by, ?),
                    updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
                AND status = 'Draft'
            ");
            $stmt->execute([$this->user_id, $payroll_id, $this->clinic_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Payroll cannot be submitted in current status');
            }
            
            // Get updated values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $new_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Check kung may existing approvals bago mag-insert
            $stmt_check = $this->pdo->prepare("SELECT COUNT(*) FROM payroll_approvals WHERE payroll_id = ?");
            $stmt_check->execute([$payroll_id]);
            $hasApprovals = $stmt_check->fetchColumn() > 0;
            
            // Create approval records only if wala pa
            if (!$hasApprovals) {
                $approvers = $this->getPayrollApprovers();
                foreach ($approvers as $approver) {
                    $stmt = $this->pdo->prepare("
                        INSERT INTO payroll_approvals (payroll_id, approver_id, approval_level, status)
                        VALUES (?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$payroll_id, $approver['user_id'], $approver['level']]);
                }
            }
            
            // Audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'submitted', ?, 'Submitted for approval', ?)
            ");
            $stmt->execute([$payroll_id, $this->user_id, $_SERVER['REMOTE_ADDR']]);
            
            // Log to audit_logs
            $this->logAudit('SUBMIT', 'payroll', $payroll_id, $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payroll submitted for approval',
                'payroll_id' => $payroll_id,
                'status' => 'For Approval'
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function cancelPayroll($data) {
        $payroll_id = $data['payroll_id'] ?? null;
        $remarks = $data['remarks'] ?? '';
        
        if (!$payroll_id) {
            $this->sendResponse(400, ['error' => 'Payroll ID is required']);
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Get current values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                throw new Exception('Payroll not found');
            }
            
            $stmt = $this->pdo->prepare("
                UPDATE payroll 
                SET status = 'Cancelled',
                    updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
                AND status IN ('Draft', 'For Approval')
            ");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            
            if ($stmt->rowCount() === 0) {
                throw new Exception('Payroll cannot be cancelled in current status');
            }
            
            // Get updated values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $new_values = $stmt->fetch(PDO::FETCH_ASSOC);
            $new_values['remarks'] = $remarks;
            
            // Audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'cancelled', ?, ?, ?)
            ");
            $stmt->execute([$payroll_id, $this->user_id, $remarks, $_SERVER['REMOTE_ADDR']]);
            
            // Log to audit_logs
            $this->logAudit('CANCEL', 'payroll', $payroll_id, $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payroll cancelled successfully'
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function previewPayroll($data) {
        $payroll_id = $data['payroll_id'] ?? null;
        $overtime = $data['overtime'] ?? 0;
        $holiday_pay = $data['holiday_pay'] ?? 0;
        $allowances = $data['allowances'] ?? 0;
        $bonuses = $data['bonuses'] ?? 0;
        $other_deductions = $data['other_deductions'] ?? 0;
        
        if (!$payroll_id) {
            $this->sendResponse(400, ['error' => 'Payroll ID is required']);
        }
        
        try {
            // Get current payroll data
            $stmt = $this->pdo->prepare("
                SELECT basic_salary, gross_pay, total_deductions, net_pay
                FROM payroll 
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payroll) {
                $this->sendResponse(404, ['error' => 'Payroll not found']);
            }
            
            // Calculate new values
            $new_gross_pay = floatval($payroll['basic_salary']) + floatval($overtime) + floatval($holiday_pay) + floatval($allowances) + floatval($bonuses);
            $new_total_deductions = floatval($payroll['total_deductions']) + floatval($other_deductions);
            $new_net_pay = max(0, ($new_gross_pay - $new_total_deductions));
            
            $this->sendResponse(200, [
                'success' => true,
                'gross_pay' => round($new_gross_pay, 2),
                'total_deductions' => round($new_total_deductions, 2),
                'net_pay' => round($new_net_pay, 2)
            ]);
            
        } catch (Exception $e) {
            $this->sendResponse(500, ['error' => 'Preview calculation failed: ' . $e->getMessage()]);
        }
    }
    
    private function approvePayroll($data) {
        $payroll_id = $data['payroll_id'] ?? null;
        $remarks = $data['remarks'] ?? '';
        
        if (!$payroll_id) {
            $this->sendResponse(400, ['error' => 'Payroll ID is required']);
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Get current values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                throw new Exception('Payroll not found');
            }
            
            if ($old_values['status'] != 'For Approval') {
                throw new Exception('Only payrolls pending approval can be approved');
            }
            
            // Update approval record for current user
            $stmt = $this->pdo->prepare("
                UPDATE payroll_approvals 
                SET status = 'approved', 
                    approved_at = NOW(),
                    remarks = ?
                WHERE payroll_id = ? AND approver_id = ? AND status = 'pending'
            ");
            $stmt->execute([$remarks, $payroll_id, $this->user_id]);
            
            // Check if all approvals are done
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as pending_count 
                FROM payroll_approvals 
                WHERE payroll_id = ? AND status = 'pending'
            ");
            $stmt->execute([$payroll_id]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $new_status = ($pending['pending_count'] == 0) ? 'Approved' : 'For Approval';
            
            $stmt = $this->pdo->prepare("
                UPDATE payroll 
                SET status = ?
                WHERE id = ?
            ");
            $stmt->execute([$new_status, $payroll_id]);
            
            // Get updated values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $new_values = $stmt->fetch(PDO::FETCH_ASSOC);
            $new_values['remarks'] = $remarks;
            
            // Audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'approved', ?, ?, ?)
            ");
            $stmt->execute([$payroll_id, $this->user_id, $remarks, $_SERVER['REMOTE_ADDR']]);
            
            // Log to audit_logs
            $this->logAudit('APPROVE', 'payroll', $payroll_id, $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payroll approved successfully',
                'new_status' => $new_status
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function rejectPayroll($data) {
        $payroll_id = $data['payroll_id'] ?? null;
        $remarks = $data['remarks'] ?? '';
        
        if (!$payroll_id) {
            $this->sendResponse(400, ['error' => 'Payroll ID is required']);
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Get current values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                throw new Exception('Payroll not found');
            }
            
            if ($old_values['status'] != 'For Approval') {
                throw new Exception('Only payrolls pending approval can be rejected');
            }
            
            // Update approval record for current user
            $stmt = $this->pdo->prepare("
                UPDATE payroll_approvals 
                SET status = 'rejected', 
                    approved_at = NOW(),
                    remarks = ?
                WHERE payroll_id = ? AND approver_id = ? AND status = 'pending'
            ");
            $stmt->execute([$remarks, $payroll_id, $this->user_id]);
            
            // Update payroll status
            $stmt = $this->pdo->prepare("
                UPDATE payroll 
                SET status = 'Rejected'
                WHERE id = ?
            ");
            $stmt->execute([$payroll_id]);
            
            // Get updated values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $new_values = $stmt->fetch(PDO::FETCH_ASSOC);
            $new_values['remarks'] = $remarks;
            
            // Audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'rejected', ?, ?, ?)
            ");
            $stmt->execute([$payroll_id, $this->user_id, $remarks, $_SERVER['REMOTE_ADDR']]);
            
            // Log to audit_logs
            $this->logAudit('REJECT', 'payroll', $payroll_id, $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payroll rejected'
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function releasePayroll($data) {
        $payroll_id = $data['payroll_id'] ?? null;
        
        if (!$payroll_id) {
            $this->sendResponse(400, ['error' => 'Payroll ID is required']);
        }
        
        $this->pdo->beginTransaction();
        
        try {
            // Get current values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                throw new Exception('Payroll not found');
            }
            
            if ($old_values['status'] != 'Approved') {
                throw new Exception('Only approved payrolls can be released');
            }
            
            // Generate payslip code
            $payslip_code = 'PS-' . date('Ymd') . '-' . str_pad($payroll_id, 6, '0', STR_PAD_LEFT);
            
            $stmt = $this->pdo->prepare("
                UPDATE payroll 
                SET status = 'Released',
                    released_at = NOW(),
                    released_by = ?,
                    payslip_code = ?
                WHERE id = ?
            ");
            $stmt->execute([$this->user_id, $payslip_code, $payroll_id]);
            
            // Get updated values for audit
            $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $new_values = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Audit trail
            $stmt = $this->pdo->prepare("
                INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'released', ?, 'Payroll released', ?)
            ");
            $stmt->execute([$payroll_id, $this->user_id, $_SERVER['REMOTE_ADDR']]);
            
            // Log to audit_logs
            $this->logAudit('RELEASE', 'payroll', $payroll_id, $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payroll released successfully',
                'payslip_code' => $payslip_code
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    // Helper Methods
    private function sendResponse($code, $data) {
        if (ob_get_length()) {
            ob_clean();
        }
        
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    
    private function getPayrollApprovers() {
        $stmt = $this->pdo->prepare("
            SELECT u.id as user_id, 
                   CASE 
                       WHEN u.role = 'Finance' THEN 1
                       WHEN u.role = 'HR' THEN 2
                       WHEN u.role = 'ClinicAdmin' THEN 3
                       ELSE 4
                   END as level
            FROM users u
            WHERE u.clinic_id = ? 
            AND u.role IN ('Finance', 'HR', 'ClinicAdmin')
            AND u.status = 'Active'
            ORDER BY 
                CASE u.role
                    WHEN 'Finance' THEN 1
                    WHEN 'HR' THEN 2
                    WHEN 'ClinicAdmin' THEN 3
                    ELSE 4
                END
        ");
        $stmt->execute([$this->clinic_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

        // ============= VALIDATE EMPLOYEE FOR PAYROLL GENERATION =============
    /**
     * Validates if an employee is eligible for payroll generation
     * Returns array with status and issues
     */
    private function validateEmployeeForPayroll($employee_id, $start_date, $end_date) {
        $issues = [];
        
        // 1. CHECK ATTENDANCE - dapat approved lahat ng attendance sa period
        $stmt = $this->pdo->prepare("
            SELECT a.date, a.approval_status, a.status as attendance_status
            FROM attendance a
            WHERE a.employee_id = ? 
            AND a.date BETWEEN ? AND ?
            ORDER BY a.date ASC
        ");
        $stmt->execute([$employee_id, $start_date, $end_date]);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pending_attendance = [];
        $rejected_attendance = [];
        
        foreach ($attendance_records as $record) {
            if ($record['approval_status'] === 'pending') {
                $pending_attendance[] = $record['date'];
            } elseif ($record['approval_status'] === 'rejected') {
                $rejected_attendance[] = $record['date'];
            }
        }
        
        if (!empty($pending_attendance)) {
            $issues[] = [
                'type' => 'attendance',
                'status' => 'pending',
                'dates' => $pending_attendance,
                'message' => 'Attendance on ' . implode(', ', $pending_attendance) . ' is PENDING approval'
            ];
        }
        
        if (!empty($rejected_attendance)) {
            $issues[] = [
                'type' => 'attendance',
                'status' => 'rejected',
                'dates' => $rejected_attendance,
                'message' => 'Attendance on ' . implode(', ', $rejected_attendance) . ' is REJECTED'
            ];
        }
        
        // 2. CHECK OVERTIME REQUESTS - dapat approved lahat ng OT sa period
        $stmt = $this->pdo->prepare("
            SELECT ot.overtime_date, ot.status
            FROM overtime_requests ot
            WHERE ot.employee_id = ? 
            AND ot.overtime_date BETWEEN ? AND ?
            AND ot.status != 'cancelled'
            ORDER BY ot.overtime_date ASC
        ");
        $stmt->execute([$employee_id, $start_date, $end_date]);
        $ot_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pending_ot = [];
        $rejected_ot = [];
        
        foreach ($ot_records as $record) {
            if ($record['status'] === 'pending') {
                $pending_ot[] = $record['overtime_date'];
            } elseif ($record['status'] === 'rejected') {
                $rejected_ot[] = $record['overtime_date'];
            }
        }
        
        if (!empty($pending_ot)) {
            $issues[] = [
                'type' => 'overtime',
                'status' => 'pending',
                'dates' => $pending_ot,
                'message' => 'Overtime on ' . implode(', ', $pending_ot) . ' is PENDING approval'
            ];
        }
        
        if (!empty($rejected_ot)) {
            $issues[] = [
                'type' => 'overtime',
                'status' => 'rejected',
                'dates' => $rejected_ot,
                'message' => 'Overtime on ' . implode(', ', $rejected_ot) . ' is REJECTED'
            ];
        }
        
        // 3. CHECK LEAVE REQUESTS - dapat approved lahat ng leave sa period
        $stmt = $this->pdo->prepare("
            SELECT l.start_date, l.end_date, l.status
            FROM leaves l
            WHERE l.employee_id = ? 
            AND (
                (l.start_date BETWEEN ? AND ?)
                OR (l.end_date BETWEEN ? AND ?)
                OR (l.start_date <= ? AND l.end_date >= ?)
            )
            AND l.status != 'cancelled'
            ORDER BY l.start_date ASC
        ");
        $stmt->execute([$employee_id, $start_date, $end_date, $start_date, $end_date, $start_date, $end_date]);
        $leave_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pending_leaves = [];
        $rejected_leaves = [];
        
        foreach ($leave_records as $record) {
            if ($record['status'] === 'pending') {
                $pending_leaves[] = $record['start_date'] . ' to ' . $record['end_date'];
            } elseif ($record['status'] === 'rejected') {
                $rejected_leaves[] = $record['start_date'] . ' to ' . $record['end_date'];
            }
        }
        
        if (!empty($pending_leaves)) {
            $issues[] = [
                'type' => 'leave',
                'status' => 'pending',
                'dates' => $pending_leaves,
                'message' => 'Leave request/s ' . implode(', ', $pending_leaves) . ' is PENDING approval'
            ];
        }
        
        if (!empty($rejected_leaves)) {
            $issues[] = [
                'type' => 'leave',
                'status' => 'rejected',
                'dates' => $rejected_leaves,
                'message' => 'Leave request/s ' . implode(', ', $rejected_leaves) . ' is REJECTED'
            ];
        }
        
        // Return result
        if (empty($issues)) {
            return [
                'valid' => true,
                'message' => 'All records are approved',
                'issues' => []
            ];
        } else {
            return [
                'valid' => false,
                'message' => 'Cannot generate payroll: ' . implode('; ', array_column($issues, 'message')),
                'issues' => $issues
            ];
        }
    }
}
?>