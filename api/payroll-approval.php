<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

require_once __DIR__ . '/../config/db.php';
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

// Get session data
$clinic_id = $_SESSION['clinic_id'] ?? 0;
$user_id = $_SESSION['user_id'] ?? 0;
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'User';

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

class PayrollApprovalPermission {
    private static $module = 'payroll-approval';
    
    public static function can($action) {
        $permissionMap = [
            'view' => self::$module . '_view',
            'create' => self::$module . '_create',
            'edit' => self::$module . '_edit',
            'delete' => self::$module . '_delete',
            'approve' => self::$module . '_approve',
            'reject' => self::$module . '_reject'
        ];
        
        $permission = $permissionMap[$action] ?? self::$module . '_' . $action;
        return RBACHelper::hasPermission($permission);
    }
    
    public static function check($action, $exitOnFail = true) {
        if (!self::can($action)) {
            if ($exitOnFail) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: Cannot ' . $action . ' payroll']);
                exit;
            }
            return false;
        }
        return true;
    }
}

$method = $_SERVER['REQUEST_METHOD'];

try {
if ($method === 'GET') {
    
if (isset($_GET['get_permissions'])) {
    $hasHR = false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'HR' AND status = 'Active'");
    $stmt->execute([$_SESSION['user_id']]);
    $hasHR = $stmt->fetchColumn() > 0;
    
    $permissions = [
        'view' => PayrollApprovalPermission::can('view'),
        'create' => PayrollApprovalPermission::can('create'),
        'edit' => PayrollApprovalPermission::can('edit'),
        'delete' => PayrollApprovalPermission::can('delete'),
        'approve' => PayrollApprovalPermission::can('approve'),
        'reject' => PayrollApprovalPermission::can('reject')
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'role' => $_SESSION['role'],
            'permissions' => $permissions,
            'hasHR' => $hasHR,
            'isOwner' => ($_SESSION['role'] === 'ClinicAdmin' && !$hasHR),
            'user_id' => $_SESSION['user_id'],
            'user_name' => $_SESSION['name'] ?? 'User'
        ]
    ]);
    exit;
}
// ============= CHECK VIEW PERMISSION =============
PayrollApprovalPermission::check('view');
    
    $action = $_GET['action'] ?? '';

        
        switch ($action) {
            /* ---------- DASHBOARD STATISTICS ---------- */
            case 'dashboard_stats':
    $currentMonth = date('Y-m');
    
    // Pending approvals (For Approval with pending approvals)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT p.id) AS pending_approval
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE p.status = 'For Approval'
          AND u.clinic_id = ?
          AND EXISTS (
              SELECT 1
              FROM payroll_approvals pa
              WHERE pa.payroll_id = p.id
                AND pa.status IN ('Pending', 'For Review')
          )
          AND NOT EXISTS (
              SELECT 1
              FROM payroll_approvals pa2
              WHERE pa2.payroll_id = p.id
                AND LOWER(pa2.status) = 'approved'
          )
    ");
    $stmt->execute([$_SESSION['clinic_id']]);
    $pending = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // ===== FIXED: Get count of payrolls that are ready for release =====
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as approved_payrolls
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE p.status = 'Approved'  -- Ready for release
          AND u.clinic_id = ?
    ");
    $stmt->execute([$_SESSION['clinic_id']]);
    $approved = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Monthly total net pay for For Approval and Released payrolls
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.net_pay), 0) as monthly_total
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE DATE_FORMAT(p.period_start, '%Y-%m') = ?
        AND p.status IN ('For Approval', 'Released')
        AND u.clinic_id = ?
    ");
    $stmt->execute([$currentMonth, $_SESSION['clinic_id']]);
    $monthly = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Released this month
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as released_this_month
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE DATE_FORMAT(p.released_at, '%Y-%m') = ?
        AND p.status = 'Released'
        AND u.clinic_id = ?
    ");
    $stmt->execute([$currentMonth, $_SESSION['clinic_id']]);
    $released = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'pending_approval' => $pending['pending_approval'] ?? 0,
        'approved_payrolls' => $approved['approved_payrolls'] ?? 0,  // Now shows Ready count
        'monthly_total' => $monthly['monthly_total'] ?? 0,
        'released_this_month' => $released['released_this_month'] ?? 0
    ]);
    break;
            
/* ---------- PENDING APPROVALS ---------- */
case 'pending_approvals':

    $stmt = $pdo->prepare("
        SELECT DISTINCT
            p.*,
            e.employee_no,
            CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
            e.position,
            e.department,
            DATEDIFF(NOW(), COALESCE(p.submitted_at, p.generated_at)) AS days_pending
        FROM payroll p
        INNER JOIN employees e ON p.employee_id = e.id
        INNER JOIN users u ON e.user_id = u.id
        WHERE p.status = 'For Approval'
        AND u.clinic_id = ?
        AND EXISTS (
            SELECT 1 
            FROM payroll_approvals pa
            WHERE pa.payroll_id = p.id
              AND pa.status IN ('Pending', 'For Review')  -- Only consider truly pending approvals
        )
        AND NOT EXISTS (
            SELECT 1 
            FROM payroll_approvals pa2
            WHERE pa2.payroll_id = p.id
              AND LOWER(pa2.status) = 'approved'        -- Exclude if any approver already approved
        )
        ORDER BY COALESCE(p.submitted_at, p.generated_at) ASC
    ");

    $stmt->execute([$_SESSION['clinic_id']]);
    $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($approvals);
    break;


            /* ---------- APPROVED PAYROLLS ---------- */
case 'approved_payrolls':
    $search     = $_GET['search'] ?? '';
    $period     = $_GET['period'] ?? '';
    $department = $_GET['department'] ?? '';
    
    $where = ["u.clinic_id = ?"];
    $params = [$_SESSION['clinic_id']];
    
    // Only show payrolls that are in 'For Approval' status (not yet 'Ready')
    $where[] = "p.status = 'Approved'";
    
    if ($search) {
        $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR e.employee_no LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($period) {
        $where[] = "p.payroll_period = ?";
        $params[] = $period;
    }
    
    if ($department) {
        $where[] = "e.department = ?";
        $params[] = $department;
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.employee_no,
            CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
            e.department,
            e.bank_account_number AS bank_account_number,
            e.bank_name,
            MAX(pa.approved_at) AS approved_at,
            COUNT(DISTINCT pa.id) AS total_approvers,
            SUM(CASE WHEN LOWER(pa.status) = 'approved' THEN 1 ELSE 0 END) AS approved_count
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        LEFT JOIN payroll_approvals pa ON p.id = pa.payroll_id
        $where_clause
        GROUP BY p.id
        HAVING approved_count > 0  -- At least one approval
        ORDER BY approved_at DESC
    ");
    
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    break;

            
            /* ---------- RELEASED PAYROLLS ---------- */
            case 'released_payrolls':
                $month = $_GET['month'] ?? date('Y-m');
                
                $stmt = $pdo->prepare("
                                SELECT p.*, 
                    e.employee_no, 
                    CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                    CONCAT(r.first_name, ' ', r.last_name) as released_by_name,  
                    e.department,
                    e.position,
                    e.bank_account_number AS bank_account_number,
                    e.bank_name,
                    e.sss_number,
                    e.philhealth_number,
                    e.pagibig_number,
                    e.tin_number,
                    COALESCE(p.submitted_at, p.generated_at) as approval_date,
                    p.payment_method
                FROM payroll p
                JOIN employees e ON p.employee_id = e.id
                JOIN users u ON e.user_id = u.id
                LEFT JOIN users r ON p.released_by = r.id
                WHERE p.status = 'Released'
                AND DATE_FORMAT(p.released_at, '%Y-%m') = ?
                AND u.clinic_id = ?
                ORDER BY p.period_start DESC
                ");
                $stmt->execute([$month, $_SESSION['clinic_id']]);
                
                echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
                break;
case 'payroll_details':
    $payroll_id = $_GET['id'] ?? 0;
    
    if (!$payroll_id) {
        echo json_encode(['error' => 'Payroll ID is required']);
        exit;
    }
    
    // Get payroll details
    $stmt = $pdo->prepare("
        SELECT 
            p.*, 
            e.employee_no, 
            CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
            e.department,
            e.position,
            e.bank_account_number,
            e.bank_account_holder,
            e.bank_name,
            e.sss_number,
            e.philhealth_number,
            e.pagibig_number,
            e.tin_number,
            e.salary_frequency,  
            e.salary_type,       
            CONCAT(ug.first_name, ' ', ug.last_name) as generated_by_name,
            CONCAT(ur.first_name, ' ', ur.last_name) as released_by_name,
            (p.gross_pay - p.total_deductions) as calculated_net_pay
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        LEFT JOIN users ug ON p.generated_by = ug.id
        LEFT JOIN users ur ON p.released_by = ur.id
        WHERE p.id = ?
        AND u.clinic_id = ?
    ");
    $stmt->execute([$payroll_id, $_SESSION['clinic_id']]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payroll) {
        echo json_encode(['error' => 'Payroll not found']);
        exit;
    }
    
    // ===== GET ATTENDANCE RECORDS with approval status =====
    $attendance_records = [];
    try {
        $stmt = $pdo->prepare("
            SELECT date, time_in, time_out, total_hours, status, approval_status
            FROM attendance 
            WHERE employee_id = ? 
            AND date BETWEEN ? AND ?
            ORDER BY date ASC
        ");
        $stmt->execute([$payroll['employee_id'], $payroll['period_start'], $payroll['period_end']]);
        $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to get attendance records: " . $e->getMessage());
    }
    
    // ===== GET OVERTIME RECORDS with approval status =====
    $overtime_records = [];
    try {
        $stmt = $pdo->prepare("
            SELECT ot.overtime_date, ot.total_hours, ot.overtime_type, ot.status,
                   ot.approved_at, ot.approved_by, ot.reason
            FROM overtime_requests ot
            WHERE ot.employee_id = ? 
            AND ot.overtime_date BETWEEN ? AND ?
            ORDER BY ot.overtime_date ASC
        ");
        $stmt->execute([$payroll['employee_id'], $payroll['period_start'], $payroll['period_end']]);
        $overtime_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to get overtime records: " . $e->getMessage());
    }
    
    // ===== GET LEAVE RECORDS with approval status =====
    $leave_records = [];
    try {
        $stmt = $pdo->prepare("
            SELECT l.start_date, l.end_date, l.leave_type, l.number_of_days, 
                   l.status, l.reason, l.approved_at, l.approved_by
            FROM leaves l
            WHERE l.employee_id = ? 
            AND (
                (l.start_date BETWEEN ? AND ?)
                OR (l.end_date BETWEEN ? AND ?)
                OR (l.start_date <= ? AND l.end_date >= ?)
            )
            ORDER BY l.start_date ASC
        ");
        $stmt->execute([
            $payroll['employee_id'], 
            $payroll['period_start'], $payroll['period_end'],
            $payroll['period_start'], $payroll['period_end'],
            $payroll['period_start'], $payroll['period_end']
        ]);
        $leave_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to get leave records: " . $e->getMessage());
    }
    
    // ===== GET BUDGET PLAN for budget check =====
    $budget_plan = null;
    try {
        $stmt = $pdo->prepare("
            SELECT id, year, month, category_id, allocated_amount, spent_amount, 
                   remaining_amount, notes
            FROM budget_plans
            WHERE clinic_id = ?
            AND year = ?
            AND month = ?
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $period_month = date('m', strtotime($payroll['period_start']));
        $period_year = date('Y', strtotime($payroll['period_start']));
        $stmt->execute([$_SESSION['clinic_id'], $period_year, $period_month]);
        $budget_plan = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Failed to get budget plan: " . $e->getMessage());
    }
    
    // ===== BUDGET CHECK =====
    $budget_check = [
        'has_budget_plan' => false,
        'sufficient' => true,
        'allocated' => 0,
        'remaining' => 0,
        'payroll_amount' => floatval($payroll['net_pay'] ?? 0),
        'message' => ''
    ];
    
    if ($budget_plan) {
        $budget_check['has_budget_plan'] = true;
        $budget_check['allocated'] = floatval($budget_plan['allocated_amount'] ?? 0);
        $budget_check['remaining'] = floatval($budget_plan['remaining_amount'] ?? 0);
        
        if ($budget_check['payroll_amount'] > $budget_check['remaining']) {
            $budget_check['sufficient'] = false;
            $budget_check['message'] = sprintf(
                '⚠️ Insufficient budget! Required: ₱%s, Available: ₱%s, Shortage: ₱%s',
                number_format($budget_check['payroll_amount'], 2),
                number_format($budget_check['remaining'], 2),
                number_format($budget_check['payroll_amount'] - $budget_check['remaining'], 2)
            );
        } else {
            $budget_check['message'] = '✅ Sufficient budget available';
        }
    } else {
        $budget_check['message'] = '⚠️ No budget plan set for this period. Please create a budget plan first.';
    }
    
    // Get approval history
    $approvals = [];
    try {
        $stmt = $pdo->prepare("
            SELECT pa.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as approver_name,
                   u.role as approver_role
            FROM payroll_approvals pa
            JOIN users u ON pa.approver_id = u.id
            WHERE pa.payroll_id = ?
            ORDER BY pa.approval_level, pa.approved_at
        ");
        $stmt->execute([$payroll_id]);
        $approvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Table might not exist
    }
    
    echo json_encode([
        'success' => true,
        'payroll' => $payroll,
        'attendance_records' => $attendance_records,
        'overtime_records' => $overtime_records,
        'leave_records' => $leave_records,
        'approvals' => $approvals,
        'budget_check' => $budget_check
    ]);
    break;
            
/* ---------- REMITTANCE SUMMARY ---------- */
case 'remittance_summary':
    $month = $_GET['month'] ?? date('Y-m');
    list($year, $monthNum) = explode('-', $month);
    
    // SSS Summary
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(p.sss_contribution), 0) as sss_employee,
            COUNT(DISTINCT p.employee_id) as employee_count
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $monthNum, $year]);
    $sss = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate employer share
    $sss_employer = 0;
    $stmt = $pdo->prepare("
        SELECT p.basic_salary
        FROM payroll p
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $monthNum, $year]);
    $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($payrolls as $payroll) {
        $sss_employer += calculateEmployerSSS($payroll['basic_salary']);
    }
    
    // PhilHealth Summary
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.philhealth_contribution), 0) as philhealth_employee
        FROM payroll p
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $monthNum, $year]);
    $philhealth = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // PagIBIG Summary
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.pagibig_contribution), 0) as pagibig_employee
        FROM payroll p
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $monthNum, $year]);
    $pagibig = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // BIR Summary
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(p.withholding_tax), 0) as tax_withheld
        FROM payroll p
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $monthNum, $year]);
    $bir = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'sss_employee' => $sss['sss_employee'] ?? 0,
        'sss_employer' => $sss_employer,
        'sss_total' => ($sss['sss_employee'] ?? 0) + $sss_employer,
        'philhealth_employee' => $philhealth['philhealth_employee'] ?? 0,
        'philhealth_employer' => $philhealth['philhealth_employee'] ?? 0, // Same as employee
        'philhealth_total' => ($philhealth['philhealth_employee'] ?? 0) * 2,
        'pagibig_employee' => $pagibig['pagibig_employee'] ?? 0,
        'pagibig_employer' => $pagibig['pagibig_employee'] ?? 0, // Same as employee
        'pagibig_total' => ($pagibig['pagibig_employee'] ?? 0) * 2,
        'tax_withheld' => $bir['tax_withheld'] ?? 0,
        'employee_count' => $sss['employee_count'] ?? 0
    ]);
    break;

            case 'ready_for_release':
    $search = $_GET['search'] ?? '';
    
    $where = ["u.clinic_id = ?", "p.status = 'Ready'"];
    $params = [$_SESSION['clinic_id']];
    
    if ($search) {
        $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR e.employee_no LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $where_clause = "WHERE " . implode(" AND ", $where);
    
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.employee_no,
            CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
            e.department,
            e.position,
            e.bank_name,
            e.bank_account_number,
            e.bank_account_holder,
            e.sss_number,
            e.philhealth_number,
            e.pagibig_number,
            e.tin_number,
            COALESCE(p.updated_at, p.generated_at) as ready_date
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        $where_clause
        ORDER BY p.updated_at DESC, p.id DESC
    ");
    
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    break;

case 'get_sss_data':
    $period = $_GET['period'] ?? date('Y-m');
    list($year, $month) = explode('-', $period);
    
    $stmt = $pdo->prepare("
        SELECT 
            e.sss_number,
            CONCAT(u.first_name, ' ', u.last_name) as employee_name,
            p.sss_contribution as sss_employee,
            p.basic_salary
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $month, $year]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add employer share (SSS employer share = employee share)
    foreach ($employees as &$emp) {
        $emp['sss_employer'] = calculateEmployerSSS($emp['basic_salary']);
    }
    
    echo json_encode(['success' => true, 'employees' => $employees]);
    break;

    /* ---------- GET PHILHEALTH DATA ---------- */
case 'get_philhealth_data':
    $period = $_GET['period'] ?? date('Y-m');
    list($year, $month) = explode('-', $period);
    
    $stmt = $pdo->prepare("
        SELECT 
            e.philhealth_number,
            CONCAT(u.first_name, ' ', u.last_name) as employee_name,
            p.philhealth_contribution as philhealth_employee,
            p.basic_salary
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $month, $year]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add employer share (PhilHealth employer = employee share)
    foreach ($employees as &$emp) {
        $emp['philhealth_employer'] = calculateEmployerPhilhealth($emp['basic_salary']);
    }
    
    echo json_encode(['success' => true, 'employees' => $employees]);
    break;

/* ---------- GET PAGIBIG DATA ---------- */
case 'get_pagibig_data':
    $period = $_GET['period'] ?? date('Y-m');
    list($year, $month) = explode('-', $period);
    
    $stmt = $pdo->prepare("
        SELECT 
            e.pagibig_number,
            CONCAT(u.first_name, ' ', u.last_name) as employee_name,
            p.pagibig_contribution as pagibig_employee,
            p.basic_salary
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $month, $year]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Add employer share
    foreach ($employees as &$emp) {
        $emp['pagibig_employer'] = calculateEmployerPagibig($emp['basic_salary']);
    }
    
    echo json_encode(['success' => true, 'employees' => $employees]);
    break;

/* ---------- GET BIR DATA ---------- */
case 'get_bir_data':
    $period = $_GET['period'] ?? date('Y-m');
    list($year, $month) = explode('-', $period);
    
    $stmt = $pdo->prepare("
        SELECT 
            e.tin_number,
            CONCAT(u.first_name, ' ', u.last_name) as employee_name,
            p.withholding_tax,
            p.gross_pay,
            e.civil_status
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE p.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released', 'Ready')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $month, $year]);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'employees' => $employees]);
    break;


case 'remittances':
    // Check if table exists
    try {
        // FIXED: Get remittances with period formatting
        $stmt = $pdo->prepare("
            SELECT 
                gr.*,
                u.first_name,
                u.last_name,
                DATE_FORMAT(CONCAT(gr.period_year, '-', LPAD(gr.period_month, 2, '0'), '-10'), '%Y-%m-%d') as due_date,
                CASE 
                    WHEN gr.status = 'pending' AND DATE(CONCAT(gr.period_year, '-', LPAD(gr.period_month, 2, '0'), '-10')) < CURDATE() 
                    THEN 'overdue'
                    ELSE gr.status
                END as display_status
            FROM government_remittances gr
            LEFT JOIN users u ON gr.submitted_by = u.id
            WHERE gr.clinic_id = ?
            ORDER BY gr.period_year DESC, gr.period_month DESC, gr.created_at DESC
        ");
        $stmt->execute([$_SESSION['clinic_id']]);
        $remittances = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($remittances);
    } catch (Exception $e) {
        // Table doesn't exist, return empty array
        echo json_encode([]);
    }
    break;
    
    case 'view_remittance':
    $remittance_id = $_GET['id'] ?? 0;
    
    if (!$remittance_id) {
        echo json_encode(['success' => false, 'error' => 'Remittance ID is required']);
        exit;
    }
    
    try {
        // Get remittance details
        $stmt = $pdo->prepare("
            SELECT * FROM government_remittances 
            WHERE id = ? AND clinic_id = ?
        ");
        $stmt->execute([$remittance_id, $_SESSION['clinic_id']]);
        $remittance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$remittance) {
            echo json_encode(['success' => false, 'error' => 'Remittance not found']);
            exit;
        }
        
        // Get employee breakdown
        $stmt = $pdo->prepare("
            SELECT 
                p.id as payroll_id,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                e.employee_no,
                CASE ?
                    WHEN 'SSS' THEN p.sss_contribution
                    WHEN 'PhilHealth' THEN p.philhealth_contribution
                    WHEN 'PagIBIG' THEN p.pagibig_contribution
                    WHEN 'BIR' THEN p.withholding_tax
                    ELSE 0
                END as employee_share,
                p.net_pay,
                p.basic_salary
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE p.clinic_id = ?
            AND MONTH(p.period_end) = ?
            AND YEAR(p.period_end) = ?
            AND p.status IN ('Released', 'Ready')
            ORDER BY u.last_name, u.first_name
        ");
        $stmt->execute([
            $remittance['remittance_type'],
            $remittance['clinic_id'],
            $remittance['period_month'],
            $remittance['period_year']
        ]);
        $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'remittance' => $remittance,
            'breakdown' => $breakdown
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    break;
        }
        exit;
    }

if ($method === 'POST') {
    $data = [];
    
    // Check kung may JSON payload
    $json_input = file_get_contents('php://input');
    if (!empty($json_input) && $json_input[0] === '{') {
        $json_data = json_decode($json_input, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $data = $json_data; // JSON ang gamitin
        }
    }
    
    // Kung walang JSON, gamitin ang $_POST
    if (empty($data) && !empty($_POST)) {
        $data = $_POST; // FormData ang gamitin
    }
    
    // Special handling para sa payroll_ids (baka naka-JSON string)
    if (isset($data['payroll_ids']) && is_string($data['payroll_ids'])) {
        $decoded = json_decode($data['payroll_ids'], true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $data['payroll_ids'] = $decoded;
        }
    }
    
    // Debug: I-log kung ano ang natanggap (remove in production)
    error_log("POST Data received: " . print_r($data, true));
    error_log("FILES: " . print_r($_FILES, true));
    
    $action = $data['action'] ?? '';
    
    switch ($action) {
/* ---------- APPROVE PAYROLL ---------- */
case 'approve_payroll':
PayrollApprovalPermission::check('approve');
    
    $payroll_id = $data['payroll_id'] ?? 0;
    $remarks = $data['remarks'] ?? '';
   
    
    if (!$payroll_id) {
        echo json_encode(['error' => 'Payroll ID is required']);
        exit;
    }
    
    $pdo->beginTransaction();
    
    try {
        // FIXED: Update status to 'Approved' instead of just updated_at
        $stmt = $pdo->prepare("
            UPDATE payroll 
            SET status = 'Approved',  -- ADD THIS LINE
                updated_at = NOW()
            WHERE id = ?
            AND status = 'For Approval'
            AND EXISTS (
                SELECT 1 FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.id = payroll.employee_id
                AND u.clinic_id = ?
            )
        ");
        $stmt->execute([$payroll_id, $_SESSION['clinic_id']]);
        
        if ($stmt->rowCount() === 0) {
            // Check if payroll already approved
            $stmt = $pdo->prepare("SELECT status FROM payroll WHERE id = ?");
            $stmt->execute([$payroll_id]);
            $payroll = $stmt->fetch();
            
            if ($payroll && $payroll['status'] == 'Approved') {
                throw new Exception('Payroll is already approved');
            } else {
                throw new Exception('Payroll is not in For Approval status or not found');
            }
        }
        
        // Check if payroll_approvals table exists and update it
        $tableExists = $pdo->query("SHOW TABLES LIKE 'payroll_approvals'")->rowCount() > 0;
        
        if ($tableExists) {
            $stmt = $pdo->prepare("
                UPDATE payroll_approvals 
                SET status = 'approved',
                    remarks = ?,
                    approved_at = NOW()
                WHERE payroll_id = ? 
                AND approver_id = ?
                AND status = 'pending'
            ");
            $stmt->execute([$remarks, $payroll_id, $_SESSION['user_id']]);
            
            // If no row was updated, try to insert instead
            if ($stmt->rowCount() === 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO payroll_approvals (payroll_id, approver_id, status, remarks, approved_at)
                    VALUES (?, ?, 'approved', ?, NOW())
                ");
                $stmt->execute([$payroll_id, $_SESSION['user_id'], $remarks]);
            }
        }
        
        // Create audit trail
        $stmt = $pdo->prepare("
            INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
            VALUES (?, 'approved', ?, ?, ?)
        ");
        $stmt->execute([$payroll_id, $_SESSION['user_id'], $remarks, $_SERVER['REMOTE_ADDR']]);
        
        // Send notification to HR if function exists
        if (function_exists('sendNotificationToHR')) {
            try {
                sendNotificationToHR($pdo, $payroll_id, 'approved', $remarks);
            } catch (Exception $e) {
                // Log error but don't stop the process
                error_log("Notification error: " . $e->getMessage());
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Payroll approved successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => $e->getMessage()]);
    }
    break;
            
            /* ---------- REJECT PAYROLL ---------- */
case 'reject_payroll':
PayrollApprovalPermission::check('reject');
    
    $payroll_id = $data['payroll_id'] ?? 0;
    $remarks = $data['remarks'] ?? '';
    
    if (!$payroll_id) {
        echo json_encode(['error' => 'Payroll ID is required']);
        exit;
    }
    
    if (empty(trim($remarks))) {
        echo json_encode(['error' => 'Remarks are required for rejection']);
        exit;
    }
    
    $pdo->beginTransaction();
    
    try {
        // UPDATE 1: Set status to 'Cancelled' in payroll table
        $stmt = $pdo->prepare("
            UPDATE payroll 
            SET status = 'Cancelled',
                updated_at = NOW(),
                rejected_by = ?,
                rejected_at = NOW(),
                rejection_remarks = ?
            WHERE id = ?
            AND status IN ('For Approval', 'Pending')  -- Allow rejection from multiple statuses
            AND EXISTS (
                SELECT 1 FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.id = payroll.employee_id
                AND u.clinic_id = ?
            )
        ");
        $stmt->execute([$_SESSION['user_id'], $remarks, $payroll_id, $_SESSION['clinic_id']]);
        
        if ($stmt->rowCount() === 0) {
            // Check current status
            $stmt = $pdo->prepare("SELECT status FROM payroll WHERE id = ?");
            $stmt->execute([$payroll_id]);
            $payroll = $stmt->fetch();
            
            if ($payroll) {
                if ($payroll['status'] == 'Cancelled') {
                    throw new Exception('Payroll is already cancelled');
                } elseif ($payroll['status'] == 'Approved') {
                    throw new Exception('Cannot reject an already approved payroll');
                } elseif ($payroll['status'] == 'Released') {
                    throw new Exception('Cannot reject a released payroll');
                } else {
                    throw new Exception('Payroll is not in a rejectable status (For Approval/Pending)');
                }
            } else {
                throw new Exception('Payroll not found');
            }
        }
        
        // UPDATE 2: Update payroll_approvals table with 'rejected' status
        $tableExists = $pdo->query("SHOW TABLES LIKE 'payroll_approvals'")->rowCount() > 0;
        
        if ($tableExists) {
            // First try to update existing approval record
            $stmt = $pdo->prepare("
                UPDATE payroll_approvals 
                SET status = 'rejected',
                    remarks = ?,
                    approved_at = NOW(),
                    action_date = NOW()
                WHERE payroll_id = ? 
                AND approver_id = ?
                AND status = 'pending'
            ");
            $stmt->execute([$remarks, $payroll_id, $_SESSION['user_id']]);
            
            // If no row was updated (no pending approval), insert a new rejection record
            if ($stmt->rowCount() === 0) {
                // Check if there's already a rejection record
                $stmt = $pdo->prepare("
                    SELECT id FROM payroll_approvals 
                    WHERE payroll_id = ? 
                    AND approver_id = ?
                    AND status = 'rejected'
                ");
                $stmt->execute([$payroll_id, $_SESSION['user_id']]);
                
                if ($stmt->rowCount() === 0) {
                    // Insert new rejection record
                    $stmt = $pdo->prepare("
                        INSERT INTO payroll_approvals 
                        (payroll_id, approver_id, status, remarks, approved_at, action_date)
                        VALUES (?, ?, 'rejected', ?, NOW(), NOW())
                    ");
                    $stmt->execute([$payroll_id, $_SESSION['user_id'], $remarks]);
                }
            }
            
            // Also update any other pending approvals for this payroll to 'cancelled'
            $stmt = $pdo->prepare("
                UPDATE payroll_approvals 
                SET status = 'cancelled',
                    remarks = CONCAT('Auto-cancelled: Payroll was rejected by ', 
                                     (SELECT CONCAT(first_name, ' ', last_name) 
                                      FROM users WHERE id = ?), 
                                     ' - ', ?),
                    action_date = NOW()
                WHERE payroll_id = ? 
                AND status = 'pending'
                AND approver_id != ?
            ");
            $stmt->execute([$_SESSION['user_id'], $remarks, $payroll_id, $_SESSION['user_id']]);
        }
        
        // Create audit trail
        $stmt = $pdo->prepare("
            INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
            VALUES (?, 'rejected', ?, ?, ?)
        ");
        $stmt->execute([$payroll_id, $_SESSION['user_id'], $remarks, $_SERVER['REMOTE_ADDR']]);
        
        // Send notification
        try {
            if (function_exists('sendNotificationToHR')) {
                sendNotificationToHR($pdo, $payroll_id, 'rejected', $remarks);
            }
            
            // Also notify the employee/payroll generator
            $stmt = $pdo->prepare("
                SELECT u.email, CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                       e.user_id as employee_user_id
                FROM payroll p
                JOIN employees e ON p.employee_id = e.id
                JOIN users u ON e.user_id = u.id
                WHERE p.id = ?
            ");
            $stmt->execute([$payroll_id]);
            $employee = $stmt->fetch();
            
            if ($employee) {
                // You can add email notification logic here
                // sendRejectionEmail($employee['email'], $employee['employee_name'], $remarks);
            }
        } catch (Exception $e) {
            // Notification might fail, but don't rollback the transaction
            error_log("Notification error: " . $e->getMessage());
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Payroll rejected successfully. Status changed to Cancelled.'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => $e->getMessage()]);
    }
    break;
            
            /* ---------- RELEASE SINGLE PAYROLL ---------- */
           case 'release_single':
     PayrollApprovalPermission::check('approve');
    $payroll_id = $data['payroll_id'] ?? 0;
    $payment_method = $data['payment_method'] ?? 'Bank Transfer';
    $payment_reference = $data['payment_reference'] ?? '';

    if (!$payroll_id) {
        echo json_encode(['error' => 'Payroll ID is required']);
        exit;
    }

    $pdo->beginTransaction();

    try {
        // FETCH payroll in For Approval status
        $stmt = $pdo->prepare("
            SELECT * FROM payroll 
            WHERE id = ? 
            AND status = 'For Approval'
            AND EXISTS (
                SELECT 1 FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.id = payroll.employee_id
                AND u.clinic_id = ?
            )
        ");
        $stmt->execute([$payroll_id, $_SESSION['clinic_id']]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payroll) {
            throw new Exception('Payroll must be in For Approval status before release');
        }

        // UPDATE payroll table to Released
        $stmt = $pdo->prepare("
            UPDATE payroll 
            SET status = 'Released',
                released_at = NOW(),
                released_by = ?,
                payment_method = ?,
                payment_reference = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $_SESSION['user_id'],
            $payment_method,
            $payment_reference,
            $payroll_id
        ]);

        // ✅ UPDATE payroll_approvals table to Released
        $stmt = $pdo->prepare("
            UPDATE payroll_approvals
            SET status = 'released'
            WHERE payroll_id = ?
            AND status IN ('pending', 'approved')  -- only update those not already released
        ");
        $stmt->execute([$payroll_id]);

        // Generate payslip code
        $payslip_code = 'PS' . date('Ym') . str_pad($payroll_id, 4, '0', STR_PAD_LEFT);

        // Insert into payslips table if exists
        try {
            $stmt = $pdo->prepare("
                INSERT INTO payslips (payroll_id, employee_id, payslip_code, released_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$payroll_id, $payroll['employee_id'], $payslip_code]);
        } catch (Exception $e) {
            // Table might not exist
        }

        // Mark overtime as paid if table exists
        try {
            $stmt = $pdo->prepare("
                UPDATE overtime_requests 
                SET status = 'paid'
                WHERE employee_id = ? 
                AND overtime_date BETWEEN ? AND ?
                AND status = 'approved'
            ");
            $stmt->execute([$payroll['employee_id'], $payroll['period_start'], $payroll['period_end']]);
        } catch (Exception $e) {
            // Table might not exist
        }

        // Create audit trail
        $stmt = $pdo->prepare("
            INSERT INTO payroll_audit_trail (payroll_id, action, performed_by, remarks, ip_address)
            VALUES (?, 'released', ?, 'Payroll released', ?)
        ");
        $stmt->execute([$payroll_id, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);

        // Send notification to employee
        try {
            sendPayslipNotification($pdo, $payroll_id, $payslip_code);
        } catch (Exception $e) {
            // Notification might fail
        }

        $pdo->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Payroll released successfully',
            'payslip_code' => $payslip_code
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['error' => $e->getMessage()]);
    }
    break;

            
case 'release_batch_ready':
	PayrollApprovalPermission::check('approve');
    try {
        // Kunin ang data (puwedeng galing sa JSON or POST)
        $payroll_ids = $data['payroll_ids'] ?? [];
        $payment_method = $data['payment_method'] ?? '';
        $reference_number = $data['reference_number'] ?? '';
        $release_datetime = $data['release_datetime'] ?? date('Y-m-d H:i:s');
        $approver_id = $data['approver_id'] ?? '';
        $witness_name = $data['witness_name'] ?? '';
        $remarks = $data['remarks'] ?? '';
        $send_notification = isset($data['send_notification']) && 
                             filter_var($data['send_notification'], FILTER_VALIDATE_BOOLEAN);
        
        // Validate required fields
        if (empty($payroll_ids)) {
            echo json_encode(['error' => 'No payrolls selected']);
            exit;
        }
        
        if (empty($payment_method)) {
            echo json_encode(['error' => 'Payment method is required']);
            exit;
        }
        
        if (empty($reference_number)) {
            echo json_encode(['error' => 'Reference number is required']);
            exit;
        }
        
        if (empty($approver_id)) {
            echo json_encode(['error' => 'Approver is required']);
            exit;
        }
        
$uploaded_files = [];
if (!empty($_FILES['proof_files']['name'][0])) {
    $upload_dir = 'uploads/proof_of_payment/' . date('Y/m/d/');
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    foreach ($_FILES['proof_files']['tmp_name'] as $key => $tmp_name) {
        if ($_FILES['proof_files']['error'][$key] === 0) {
            $original_name = $_FILES['proof_files']['name'][$key];
            $file_size = $_FILES['proof_files']['size'][$key];
            $file_type = $_FILES['proof_files']['type'][$key];
            
            // Generate unique filename
            $filename = time() . '_' . uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $original_name);
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($tmp_name, $filepath)) {
                $uploaded_files[] = [
                    'path' => $filepath,
                    'name' => $original_name,
                    'size' => $file_size,
                    'type' => $file_type
                ];
                error_log("File saved: " . $filepath);
            }
        }
    }
}
        
        // I-convert sa array kung hindi pa
        if (!is_array($payroll_ids)) {
            $payroll_ids = [$payroll_ids];
        }
        
        $payroll_ids = array_filter(array_map('intval', $payroll_ids));
        
        if (empty($payroll_ids)) {
            echo json_encode(['error' => 'Invalid payroll IDs']);
            exit;
        }
        
        $placeholders = str_repeat('?,', count($payroll_ids) - 1) . '?';
        
        $pdo->beginTransaction();
        
        // Verify payrolls exist and are in Ready status
        $stmt = $pdo->prepare("
            SELECT p.*, e.user_id as employee_user_id, 
                   CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                   u.email as employee_email
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE p.id IN ($placeholders)
            AND p.status = 'Ready'
            AND u.clinic_id = ?
        ");
        
        $params = array_merge($payroll_ids, [$_SESSION['clinic_id']]);
        $stmt->execute($params);
        $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($payrolls) !== count($payroll_ids)) {
            throw new Exception('Some payrolls are not in Ready status or not found');
        }
        
        $released_count = 0;
        $total_amount = 0;
        $payslip_codes = [];
        
        foreach ($payrolls as $payroll) {
            // Generate payslip code
            $payslip_code = 'PS' . date('Ym') . str_pad($payroll['id'], 4, '0', STR_PAD_LEFT);
            
            // Check which columns exist
            $columns = [];
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM payroll");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
            } catch (Exception $e) {
                $columns = [];
            }
            
            // Build dynamic SQL
            $set_clause = "status = 'Released', released_at = ?, released_by = ?, payment_method = ?, payment_reference = ?, updated_at = NOW()";
            $execute_params = [
                $release_datetime,
                $_SESSION['user_id'],
                $payment_method,
                $remarks
            ];
            
            if (in_array('reference_number', $columns)) {
                $set_clause .= ", reference_number = ?";
                $execute_params[] = $reference_number;
            }
            
            if (in_array('approver_id', $columns)) {
                $set_clause .= ", approver_id = ?";
                $execute_params[] = $approver_id;
            }
            
            if (in_array('witness_name', $columns) && !empty($witness_name)) {
                $set_clause .= ", witness_name = ?";
                $execute_params[] = $witness_name;
            }
            
            if (in_array('proof_files', $columns) && !empty($uploaded_files)) {
                $set_clause .= ", proof_files = ?";
                $execute_params[] = json_encode($uploaded_files);
            }
            
            $execute_params[] = $payroll['id'];
            
            $stmt = $pdo->prepare("UPDATE payroll SET $set_clause WHERE id = ?");
            $stmt->execute($execute_params);
            
            if (!empty($uploaded_files)) {
                try {
                    // Check if payroll_attachments table exists
                    $stmt = $pdo->query("SHOW TABLES LIKE 'payroll_attachments'");
                    if ($stmt->rowCount() > 0) {
                        $stmt = $pdo->prepare("
                            INSERT INTO payroll_attachments 
                            (payroll_id, file_path, file_name, file_size, file_type, uploaded_by, uploaded_at)
                            VALUES (?, ?, ?, ?, ?, ?, NOW())
                        ");
                        
                        foreach ($uploaded_files as $file) {
                            $stmt->execute([
                                $payroll['id'], 
                                $file['path'], 
                                $file['name'],
                                $file['size'],
                                $file['type'],
                                $_SESSION['user_id']
                            ]);
                            error_log("Saved to database: payroll_id={$payroll['id']}, file={$file['name']}");
                        }
                    } else {
                        error_log("payroll_attachments table does not exist");
                    }
                } catch (Exception $e) {
                    error_log("Attachments table error: " . $e->getMessage());
                }
            }

            // Update payroll_approvals
            try {
                $stmt = $pdo->prepare("
                    UPDATE payroll_approvals 
                    SET status = 'released', approved_at = NOW()
                    WHERE payroll_id = ?
                ");
                $stmt->execute([$payroll['id']]);
            } catch (Exception $e) {
                // Table might not exist
            }
            
            // Insert into payslips table
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO payslips (payroll_id, employee_id, payslip_code, released_at)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([
                    $payroll['id'],
                    $payroll['employee_id'],
                    $payslip_code,
                    $release_datetime
                ]);
            } catch (Exception $e) {
                // Table might not exist
            }
            
            // Create audit trail
            try {
                $audit_remarks = "Batch release: $payment_method - Ref: $reference_number";
                if (!empty($witness_name)) $audit_remarks .= " - Witness: $witness_name";
                if (!empty($remarks)) $audit_remarks .= " - $remarks";
                if (!empty($uploaded_files)) $audit_remarks .= " - Files: " . count($uploaded_files);
                
                $stmt = $pdo->prepare("
                    INSERT INTO payroll_audit_trail 
                    (payroll_id, action, performed_by, remarks, ip_address, reference_number)
                    VALUES (?, 'released', ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $payroll['id'],
                    $_SESSION['user_id'],
                    $audit_remarks,
                    $_SERVER['REMOTE_ADDR'],
                    $reference_number
                ]);
            } catch (Exception $e) {
                // Table might not exist
            }
            
            // Send notification if requested
            if ($send_notification) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO notifications 
                        (user_id, title, message, type, created_at, reference_number)
                        VALUES (?, 'Payslip Released', ?, 'success', NOW(), ?)
                    ");
                    $message = "Your payslip for payroll period {$payroll['payroll_period']} has been released. Reference: $reference_number. Payslip Code: $payslip_code";
                    $stmt->execute([$payroll['employee_user_id'], $message, $reference_number]);
                } catch (Exception $e) {
                    // Notifications table might not exist
                }
            }
            
            $released_count++;
            $total_amount += floatval($payroll['net_pay']);
            $payslip_codes[] = $payslip_code;
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "Successfully released $released_count payroll(s)",
            'released_count' => $released_count,
            'total_amount' => $total_amount,
            'payslip_codes' => $payslip_codes,
            'reference_number' => $reference_number,
            'files_uploaded' => count($uploaded_files)
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['error' => 'Batch release failed: ' . $e->getMessage()]);
    }
    break;

    case 'generate_bank_csv':
PayrollApprovalPermission::check('view');
    
    $payroll_ids = $data['payroll_ids'] ?? [];

    if (empty($payroll_ids)) {
        echo json_encode(['success' => false, 'error' => 'No payroll IDs provided']);
        exit;
    }

    $payroll_ids = array_filter(array_map('intval', $payroll_ids));

    if (empty($payroll_ids)) {
        echo json_encode(['success' => false, 'error' => 'Invalid payroll IDs']);
        exit;
    }

    $placeholders = str_repeat('?,', count($payroll_ids) - 1) . '?';

    try {
        // Get payroll details with employee bank information
        $stmt = $pdo->prepare("
            SELECT 
                p.id,
                p.employee_id,
                p.payroll_period,
                p.net_pay,
                p.basic_salary,
                e.employee_no,
                e.bank_account_number,
                e.bank_account_holder,
                e.bank_name,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                u.email
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE p.id IN ($placeholders)
            AND p.clinic_id = ?
            ORDER BY u.last_name, u.first_name
        ");
        
        $params = array_merge($payroll_ids, [$_SESSION['clinic_id']]);
        $stmt->execute($params);
        $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($payrolls)) {
            echo json_encode(['success' => false, 'error' => 'No valid payrolls found']);
            exit;
        }
        
        // ===== GENERATE CSV CONTENT =====
        $filename = 'bank_transfer_' . date('Ymd_His') . '.csv';
        
        // Create CSV content as string directly (MAS SIMPLE)
        $csv_content = '';
        
        // Add headers
        $csv_content .= "Account Number,Account Name,Bank,Amount,Employee ID,Employee Name\n";
        
        $total_amount = 0;
        
        foreach ($payrolls as $payroll) {
            // Clean account number (remove non-numeric)
            $account_number = preg_replace('/[^0-9]/', '', $payroll['bank_account_number'] ?? '');
            
            // Escape fields that might contain commas or quotes
            $account_name = str_replace('"', '""', $payroll['bank_account_holder'] ?? '');
            $bank_name = str_replace('"', '""', $payroll['bank_name'] ?? '');
            $employee_name = str_replace('"', '""', $payroll['employee_name'] ?? '');
            
            $csv_content .= implode(',', [
                '"' . $account_number . '"',
                '"' . $account_name . '"',
                '"' . $bank_name . '"',
                number_format($payroll['net_pay'] ?? 0, 2, '.', ''),
                '"' . ($payroll['employee_no'] ?? '') . '"',
                '"' . $employee_name . '"'
            ]) . "\n";
            
            $total_amount += floatval($payroll['net_pay'] ?? 0);
        }
        
        // Add total row
        $csv_content .= ",,TOTAL:," . number_format($total_amount, 2, '.', '') . ",,\n";
        
        // DEBUG: Check if content is generated
        error_log("CSV content generated. Length: " . strlen($csv_content));
        error_log("First 100 chars: " . substr($csv_content, 0, 100));
        
        if (empty($csv_content)) {
            echo json_encode(['success' => false, 'error' => 'For Approval CSV is empty']);
            exit;
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Bank CSV generated successfully',
            'filename' => $filename,
            'csv_content' => base64_encode($csv_content),
            'record_count' => count($payrolls),
            'total_amount' => $total_amount
        ]);
        exit;
        
    } catch (Exception $e) {
        error_log("Generate Bank CSV Error: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'error' => 'Failed to generate CSV: ' . $e->getMessage()
        ]);
        exit;
    }
    break;
    /* ---------- RESEND PAYSLIP NOTIFICATION ---------- */
case 'resend_payslip':
    PayrollApprovalPermission::check('edit');
    $payroll_id = $data['payroll_id'] ?? 0;
    
    if (!$payroll_id) {
        echo json_encode(['error' => 'Payroll ID is required']);
        exit;
    }
    
    try {
        // Get payroll and employee details
        $stmt = $pdo->prepare("
            SELECT p.*, e.user_id as employee_user_id, 
                   CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                   u.email as employee_email,
                   ps.payslip_code
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            LEFT JOIN payslips ps ON p.id = ps.payroll_id
            WHERE p.id = ?
            AND p.status = 'Released'
            AND u.clinic_id = ?
        ");
        $stmt->execute([$payroll_id, $_SESSION['clinic_id']]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payroll) {
            throw new Exception('Released payroll not found');
        }
        
        $payslip_code = $payroll['payslip_code'] ?? 'PS-' . str_pad($payroll_id, 5, '0', STR_PAD_LEFT);
        
        // Create notification
        try {
            $stmt = $pdo->prepare("
                INSERT INTO notifications 
                (user_id, title, message, type, created_at)
                VALUES (?, 'Payslip Resent', ?, 'info', NOW())
            ");
            $message = "Your payslip for payroll period {$payroll['payroll_period']} has been resent. Payslip Code: $payslip_code";
            $stmt->execute([$payroll['employee_user_id'], $message]);
            
            // In real implementation, send email here
            // sendEmail($payroll['employee_email'], 'Payslip Resent', $message);
            
        } catch (Exception $e) {
            // Notifications table might not exist
        }
        
        // Create audit trail
        $stmt = $pdo->prepare("
            INSERT INTO payroll_audit_trail 
            (payroll_id, action, performed_by, remarks, ip_address)
            VALUES (?, 'payslip_resent', ?, 'Payslip notification resent to employee', ?)
        ");
        $stmt->execute([$payroll_id, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Payslip notification resent successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    break;

case 'move_to_ready':
PayrollApprovalPermission::check('approve');
    
    $payroll_ids = $data['payroll_ids'] ?? [];
    
    if (empty($payroll_ids)) {
        echo json_encode(['success' => false, 'error' => 'No payroll IDs provided']);
        exit;
    }
    
    // Validate payroll IDs
    $payroll_ids = array_filter(array_map('intval', $payroll_ids));
    
    if (empty($payroll_ids)) {
        echo json_encode(['success' => false, 'error' => 'Invalid payroll IDs']);
        exit;
    }
    
    // Create placeholders
    $placeholders = str_repeat('?,', count($payroll_ids) - 1) . '?';
    
    $pdo->beginTransaction();
    
    try {
        // Check if all have at least one approval
        $check = $pdo->prepare("
            SELECT payroll_id 
            FROM payroll_approvals 
            WHERE payroll_id IN ($placeholders) 
            AND status = 'approved'
            GROUP BY payroll_id
        ");
        $check->execute($payroll_ids);
        $approved = $check->fetchAll(PDO::FETCH_COLUMN);
        
        if (count($approved) !== count($payroll_ids)) {
            throw new Exception('Some payrolls are not approved yet');
        }
        
        // Update payroll_approvals
        $stmt1 = $pdo->prepare("
            UPDATE payroll_approvals 
            SET status = 'ready',
                approved_at = NOW()
            WHERE payroll_id IN ($placeholders) 
            AND status = 'approved'
        ");
        $stmt1->execute($payroll_ids);
        
        // Update payroll - using 'Ready' status (based sa sinabi mo)
        $stmt2 = $pdo->prepare("
            UPDATE payroll 
            SET status = 'Ready', 
                updated_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $stmt2->execute($payroll_ids);
        $payrolls_updated = $stmt2->rowCount();
        
        // Create audit trail
        foreach ($payroll_ids as $payroll_id) {
            $audit = $pdo->prepare("
                INSERT INTO payroll_audit_trail 
                (payroll_id, action, performed_by, remarks, ip_address)
                VALUES (?, 'moved_to_ready', ?, 'Moved to Ready for Release', ?)
            ");
            $audit->execute([$payroll_id, $_SESSION['user_id'], $_SERVER['REMOTE_ADDR']]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "$payrolls_updated payroll(s) moved to Ready for Release",
            'moved_count' => $payrolls_updated
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false, 
            'error' => $e->getMessage()
        ]);
        exit;
    }
    break;
            
/* ---------- CALCULATE REMITTANCE ---------- */
case 'calculate_remittance':
    PayrollApprovalPermission::check('view');
    $remittance_type = $data['remittance_type'] ?? '';
    $period = $data['period'] ?? date('Y-m');
    
    if (!$remittance_type) {
        echo json_encode(['error' => 'Remittance type is required']);
        exit;
    }
    
    list($year, $month) = explode('-', $period);
    $month = (int)$month;
    $year = (int)$year;
    
    // Get payrolls for the period
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.sss_number,
            e.philhealth_number,
            e.pagibig_number,
            e.tin_number
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        WHERE u.clinic_id = ?
        AND MONTH(p.period_end) = ?
        AND YEAR(p.period_end) = ?
        AND p.status IN ('Released')
    ");
    $stmt->execute([$_SESSION['clinic_id'], $month, $year]);
    $payrolls = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($payrolls)) {
        echo json_encode(['error' => 'No payroll records found for this period']);
        return;
    }
    
    // Calculate totals based on type
    $employee_share = 0;
    $employer_share = 0;
    $employee_count = count($payrolls);
    
    foreach ($payrolls as $payroll) {
        $basic_salary = $payroll['basic_salary'] ?? 0;
        
        switch ($remittance_type) {
            case 'SSS':
                $employee_share += $payroll['sss_contribution'] ?? 0;
                $employer_share += calculateEmployerSSS($basic_salary);
                break;
                
            case 'PhilHealth':
                $employee_share += $payroll['philhealth_contribution'] ?? 0;
                $employer_share += calculateEmployerPhilhealth($basic_salary);
                break;
                
            case 'PagIBIG':
                $employee_share += $payroll['pagibig_contribution'] ?? 0;
                $employer_share += calculateEmployerPagibig($basic_salary);
                break;
                
            case 'BIR':
                $employee_share += $payroll['withholding_tax'] ?? 0;
                $employer_share += 0; // BIR is employee only
                break;
        }
    }
    
    $total_amount = $employee_share + $employer_share;
    
    echo json_encode([
        'success' => true,
        'remittance_type' => $remittance_type,
        'period' => $period,
        'employee_count' => $employee_count,
        'employee_share' => $employee_share,
        'employer_share' => $employer_share,
        'total_amount' => $total_amount,
        'payroll_count' => count($payrolls)
    ]);
    break;

/* ---------- SAVE REMITTANCE ---------- */
case 'save_remittance':
PayrollApprovalPermission::check('create');
    
    $remittance_type = $data['remittance_type'] ?? '';
    $period = $data['period'] ?? '';
    $employee_share = floatval($data['employee_share'] ?? 0);
    $employer_share = floatval($data['employer_share'] ?? 0);
    $total_amount = floatval($data['total_amount'] ?? 0);
    
    if (!$remittance_type || !$period) {
        echo json_encode(['error' => 'Remittance type and period are required']);
        exit;
    }
    
    list($year, $month) = explode('-', $period);
    
    // Check if already exists
    $stmt = $pdo->prepare("
        SELECT id FROM government_remittances 
        WHERE clinic_id = ? 
        AND remittance_type = ?
        AND period_month = ?
        AND period_year = ?
    ");
    $stmt->execute([$_SESSION['clinic_id'], $remittance_type, $month, $year]);
    
    if ($stmt->rowCount() > 0) {
        echo json_encode(['error' => 'Remittance for this period already exists']);
        exit;
    }
    
    // Insert new remittance
// Insert new remittance
$stmt = $pdo->prepare("
    INSERT INTO government_remittances (
        clinic_id, remittance_type, period_month, period_year,
        total_amount, employee_share, employer_share,
        status, submitted_by, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, NOW())
");

$success = $stmt->execute([
    $_SESSION['clinic_id'], 
    $remittance_type, 
    $month, 
    $year,
    $total_amount, 
    $employee_share, 
    $employer_share,
    $_SESSION['user_id']
]);

if ($success) {
    $remittance_id = $pdo->lastInsertId();
    
    // ✅ Insert audit trail
    $stmt = $pdo->prepare("
        INSERT INTO remittance_audit_trail
        (remittance_id, action, performed_by, remarks, ip_address)
        VALUES (?, 'created', ?, ?, ?)
    ");
    $stmt->execute([
        $remittance_id,
        $_SESSION['user_id'],
        "Government remittance created: {$remittance_type} for {$period}",
        $_SERVER['REMOTE_ADDR']
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Remittance saved successfully',
        'remittance_id' => $remittance_id
    ]);
} else {
    echo json_encode(['error' => 'Failed to save remittance']);
}

    break;

/* ---------- MARK REMITTANCE PAID ---------- */
case 'mark_remittance_paid':
PayrollApprovalPermission::check('edit');
    // Check if it's FormData or JSON
    if (!empty($_POST)) {
        // FormData submission
        $remittance_id = $_POST['id'] ?? 0;
        $payment_method = $_POST['payment_method'] ?? '';
        $reference_number = $_POST['reference_number'] ?? '';
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $amount_paid = floatval($_POST['amount_paid'] ?? 0);
        $bank_provider = $_POST['bank_provider'] ?? '';
        $remarks = $_POST['remarks'] ?? '';
    } else {
        // JSON submission (old format)
        $remittance_id = $data['id'] ?? 0;
        $payment_method = $data['payment_method'] ?? '';
        $reference_number = $data['reference_number'] ?? '';
        $payment_date = $data['payment_date'] ?? date('Y-m-d');
        $amount_paid = floatval($data['amount_paid'] ?? 0);
        $bank_provider = $data['bank_provider'] ?? '';
        $remarks = $data['remarks'] ?? '';
    }
    
    if (!$remittance_id) {
        echo json_encode(['error' => 'Remittance ID is required']);
        exit;
    }
    
    if (empty($payment_method)) {
        echo json_encode(['error' => 'Payment method is required']);
        exit;
    }
    
    if (empty($reference_number)) {
        echo json_encode(['error' => 'Reference number is required']);
        exit;
    }
    
    try {
        // Handle file upload
        $proof_file_path = null;
        if (!empty($_FILES['proof_file']['name'])) {
            $upload_dir = 'uploads/remittance_proofs/' . date('Y/m/d/');
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['proof_file']['name']);
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($_FILES['proof_file']['tmp_name'], $filepath)) {
                $proof_file_path = $filepath;
            }
        }
        
        // Update remittance
        $stmt = $pdo->prepare("
            UPDATE government_remittances 
            SET status = 'paid',
                payment_method = ?,
                reference_number = ?,
                payment_date = ?,
                amount_paid = ?,
                bank_provider = ?,
                remarks = ?,
                proof_file = ?,
                paid_by = ?,
                paid_at = NOW()
            WHERE id = ?
            AND clinic_id = ?
        ");
        $stmt->execute([
            $payment_method,
            $reference_number,
            $payment_date,
            $amount_paid,
            $bank_provider,
            $remarks,
            $proof_file_path,
            $_SESSION['user_id'],
            $remittance_id,
            $_SESSION['clinic_id']
        ]);
        
        if ($stmt->rowCount() > 0) {
            // Create audit trail
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO remittance_audit_trail
                    (remittance_id, action, performed_by, remarks, ip_address, reference_number)
                    VALUES (?, 'paid', ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $remittance_id,
                    $_SESSION['user_id'],
                    "Payment recorded - Ref: $reference_number, Method: $payment_method" . ($proof_file_path ? ", with proof" : ""),
                    $_SERVER['REMOTE_ADDR'],
                    $reference_number
                ]);
            } catch (Exception $e) {
                // Audit trail table might not exist
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Remittance marked as paid'
            ]);
        } else {
            echo json_encode(['error' => 'Remittance not found or already paid']);
        }
    } catch (Exception $e) {
        echo json_encode(['error' => 'Failed to process payment: ' . $e->getMessage()]);
    }
    break;
/* ---------- VIEW REMITTANCE ---------- */
case 'view_remittance':
     PayrollApprovalPermission::check('view');
    $remittance_id = $data['id'] ?? $_GET['id'] ?? 0;
    
    if (!$remittance_id) {
        echo json_encode(['error' => 'Remittance ID is required']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                gr.*,
                u.first_name,
                u.last_name,
                c.clinic_name,
                c.clinic_code,
                DATE_FORMAT(CONCAT(gr.period_year, '-', LPAD(gr.period_month, 2, '0'), '-10'), '%M %d, %Y') as due_date_formatted
            FROM government_remittances gr
            LEFT JOIN users u ON gr.submitted_by = u.id
            JOIN clinics c ON gr.clinic_id = c.id
            WHERE gr.id = ?
            AND gr.clinic_id = ?
        ");
        $stmt->execute([$remittance_id, $_SESSION['clinic_id']]);
        $remittance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$remittance) {
            echo json_encode(['error' => 'Remittance not found']);
            return;
        }
        
        // Get breakdown by employee
        $stmt = $pdo->prepare("
            SELECT 
                p.id as payroll_id,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                e.employee_no,
                CASE ?
                    WHEN 'SSS' THEN p.sss_contribution
                    WHEN 'PhilHealth' THEN p.philhealth_contribution
                    WHEN 'PagIBIG' THEN p.pagibig_contribution
                    WHEN 'BIR' THEN p.withholding_tax
                    ELSE 0
                END as employee_share,
                p.net_pay,
                p.basic_salary
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE p.clinic_id = ?
            AND MONTH(p.period_end) = ?
            AND YEAR(p.period_end) = ?
            AND p.status IN ('For Approval', 'Ready', 'Released')
            ORDER BY u.last_name, u.first_name
        ");
        $stmt->execute([
            $remittance['remittance_type'],
            $remittance['clinic_id'],
            $remittance['period_month'],
            $remittance['period_year']
        ]);
        $breakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'remittance' => $remittance,
            'breakdown' => $breakdown
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['error' => 'Error loading remittance: ' . $e->getMessage()]);
    }
    break;

        default:
            echo json_encode(['error' => 'Invalid action: ' . $action]);
            exit;
    }
    exit;
    }
    
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

// Helper Functions
function sendNotificationToHR($pdo, $payroll_id, $status, $remarks) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$payroll_id]);
        $hr_user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($hr_user) {
            $title = $status === 'approved' ? 'Payroll Approved' : 'Payroll Rejected';
            $message = "Payroll ID: $payroll_id has been $status. Remarks: $remarks";
            
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (?, ?, ?, ?)
            ");
            $type = $status === 'approved' ? 'success' : 'danger';
            $stmt->execute([$hr_user['id'], $title, $message, $type]);
        }
    } catch (Exception $e) {
        // Notifications table might not exist
    }
}

function sendPayslipNotification($pdo, $payroll_id, $payslip_code) {
    try {
        $stmt = $pdo->prepare("
            SELECT u.id, u.email, CONCAT(u.first_name, ' ', u.last_name) as name
            FROM payroll p
            JOIN employees e ON p.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE p.id = ?
        ");
        $stmt->execute([$payroll_id]);
        $employee = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($employee) {
            $stmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type)
                VALUES (?, 'Payslip Available', ?, 'info')
            ");
            $message = "Your payslip is now available. Payslip Code: $payslip_code";
            $stmt->execute([$employee['id'], $message]);
        }
    } catch (Exception $e) {
        // Notifications table might not exist
    }
}

// Add these functions BEFORE the switch statements
function calculateEmployerSSS($salary) {
    // Same as employee share for SSS
    if ($salary <= 4249.99) return 180.00;
    if ($salary <= 4749.99) return 202.50;
    if ($salary <= 5249.99) return 225.00;
    if ($salary <= 5749.99) return 247.50;
    if ($salary <= 6249.99) return 270.00;
    if ($salary <= 6749.99) return 292.50;
    if ($salary <= 7249.99) return 315.00;
    if ($salary <= 7749.99) return 337.50;
    if ($salary <= 8249.99) return 360.00;
    if ($salary <= 8749.99) return 382.50;
    if ($salary <= 9249.99) return 405.00;
    if ($salary <= 9749.99) return 427.50;
    if ($salary <= 10249.99) return 450.00;
    if ($salary <= 10749.99) return 472.50;
    if ($salary <= 11249.99) return 495.00;
    if ($salary <= 11749.99) return 517.50;
    if ($salary <= 12249.99) return 540.00;
    if ($salary <= 12749.99) return 562.50;
    if ($salary <= 13249.99) return 585.00;
    if ($salary <= 13749.99) return 607.50;
    if ($salary <= 14249.99) return 630.00;
    if ($salary <= 14749.99) return 652.50;
    if ($salary <= 15249.99) return 675.00;
    if ($salary <= 15749.99) return 697.50;
    if ($salary <= 16249.99) return 720.00;
    if ($salary <= 16749.99) return 742.50;
    if ($salary <= 17249.99) return 765.00;
    if ($salary <= 17749.99) return 787.50;
    if ($salary <= 18249.99) return 810.00;
    if ($salary <= 18749.99) return 832.50;
    if ($salary <= 19249.99) return 855.00;
    if ($salary <= 19749.99) return 877.50;
    if ($salary <= 20249.99) return 900.00;
    if ($salary <= 20749.99) return 922.50;
    if ($salary <= 21249.99) return 945.00;
    if ($salary <= 21749.99) return 967.50;
    if ($salary <= 22249.99) return 990.00;
    if ($salary <= 22749.99) return 1012.50;
    if ($salary <= 23249.99) return 1035.00;
    if ($salary <= 23749.99) return 1057.50;
    if ($salary <= 24249.99) return 1080.00;
    if ($salary <= 24749.99) return 1102.50;
    if ($salary <= 25249.99) return 1125.00;
    if ($salary <= 25749.99) return 1147.50;
    if ($salary <= 26249.99) return 1170.00;
    if ($salary <= 26749.99) return 1192.50;
    if ($salary <= 27249.99) return 1215.00;
    if ($salary <= 27749.99) return 1237.50;
    if ($salary <= 28249.99) return 1260.00;
    if ($salary <= 28749.99) return 1282.50;
    if ($salary <= 29249.99) return 1305.00;
    return 1350.00;
}

function calculateEmployerPhilhealth($salary) {
    // Employer share = employee share (2% each)
    if ($salary <= 10000) return 150.00;
    if ($salary >= 70000) return 2450.00;
    
    $premium_rate = 0.04; // 4% total
    $monthly_premium = ($salary * $premium_rate) / 2; // Employer share
    return min(2450.00, max(150.00, round($monthly_premium, 2)));
}

function calculateEmployerPagibig($salary) {
    // Employer share = 2% always
    if ($salary <= 1500) {
        return $salary * 0.02; // 2% for employer
    } else {
        return min(100.00, $salary * 0.02); // Max 100 pesos
    }
}
?>