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

    // ✅ Include RBACHelper
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

    $leavesBackend = new LeavesBackend($pdo);
    $leavesBackend->handleRequest();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage(),
        'trace' => $DEV_MODE ? $e->getTraceAsString() : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

class LeavesBackend {
    private $pdo;
    private $clinic_id;
    private $user_id;
    private $user_role;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        
        // ✅ Initialize RBACHelper with PDO
        RBACHelper::init($this->pdo);
        
        $this->checkAuth();
    }
    
    private function checkAuth() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
            $this->sendResponse(401, ['success' => false, 'error' => 'Unauthorized access']);
        }
        
        $this->clinic_id = $_SESSION['clinic_id'];
        $this->user_id = $_SESSION['user_id'];
        $this->user_role = $_SESSION['role'] ?? '';
        
        // ✅ Load permissions to session if not already loaded
        if (!isset($_SESSION['permissions'])) {
            RBACHelper::loadPermissionsToSession($this->user_id, $this->clinic_id);
        }
        
        // Debug
        if ($this->user_id == 161) {
            error_log("=== Leave Backend Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Has leave_view: " . ($this->canView() ? 'YES' : 'NO'));
            error_log("Has leave_edit: " . ($this->canEdit() ? 'YES' : 'NO'));
        }
    }
    
    // ============= RBAC PERMISSION METHODS =============
    private function hasPermission($permission_name) {
        return RBACHelper::hasPermission($permission_name);
    }
    
    private function canView() {
        return $this->hasPermission('leave_view');
    }
    
    private function canEdit() {
        return $this->hasPermission('leave_edit');
    }
    
    private function canApprove() {
        return $this->hasPermission('leave_approve') || $this->canEdit();
    }
    
    private function canReject() {
        return $this->hasPermission('leave_reject') || $this->canEdit();
    }
    
    private function canManageLeaveTypes() {
        return $this->canEdit();
    }
    
    private function canManageBalances() {
        return $this->canEdit();
    }
    
    private function canExport() {
        return $this->hasPermission('reports_view');
    }
    
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
        return $this->user_role ?? '';
    }
    
    public function getCurrentUserPermissions() {
        $role = $this->getCurrentUserRole();
        $hasHR = $this->checkHRExists();
        
        $permissions = [
            'view_leaves' => $this->canView(),
            'view_employees' => $this->canView(),
            'view_leave_types' => $this->canView(),
            'view_balances' => $this->canView(),
            'approve_leave' => $this->canApprove(),
            'reject_leave' => $this->canReject(),
            'manage_leave_types' => $this->canManageLeaveTypes(),
            'manage_balances' => $this->canManageBalances(),
            'export' => $this->canExport()
        ];
        
        // Override for ClinicAdmin in oversight mode
        if ($role === 'ClinicAdmin' && $hasHR && !$this->canEdit()) {
            $permissions = [
                'view_leaves' => true,
                'view_employees' => true,
                'view_leave_types' => true,
                'view_balances' => true,
                'approve_leave' => true,
                'reject_leave' => true,
                'manage_leave_types' => false,
                'manage_balances' => false,
                'export' => true
            ];
        }
        
        // Debug
        error_log("=== Leave Permissions ===");
        error_log("view_leaves: " . ($permissions['view_leaves'] ? 'true' : 'false'));
        error_log("approve_leave: " . ($permissions['approve_leave'] ? 'true' : 'false'));
        error_log("reject_leave: " . ($permissions['reject_leave'] ? 'true' : 'false'));
        
        return [
            'role' => $role,
            'permissions' => $permissions,
            'hasHR' => $hasHR,
            'isOwner' => ($role === 'ClinicAdmin' && !$hasHR),
            'user_id' => $this->user_id
        ];
    }
    
    // ============= MAIN REQUEST HANDLER =============
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
                    $this->sendResponse(405, ['success' => false, 'error' => 'Method not allowed']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
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
    
    // ============= GET HANDLERS =============
    private function handleGet() {
        // Check for permissions request first
        if (isset($_GET['get_permissions'])) {
            $this->sendResponse(200, [
                'success' => true,
                'data' => $this->getCurrentUserPermissions()
            ]);
            return;
        }
        
        $action = $_GET['action'] ?? '';
        
        switch($action) {
            case 'get_leaves':
                // ✅ View permission check - kung walang view, empty array
                if (!$this->canView()) {
                    $this->sendResponse(200, ['success' => true, 'data' => []]);
                    return;
                }
                $this->getLeaves();
                break;
                
            case 'get_employees':
                // ✅ View permission check - kung walang view, empty array
                if (!$this->canView()) {
                    $this->sendResponse(200, ['success' => true, 'data' => []]);
                    return;
                }
                $this->getEmployees();
                break;
                
            case 'get_leave_types':
                // ✅ View permission check
                if (!$this->canView()) {
                    $this->sendResponse(200, ['success' => true, 'data' => []]);
                    return;
                }
                $this->getLeaveTypes();
                break;
                
            case 'get_leave_balances':
                // ✅ View permission check
                if (!$this->canView()) {
                    $this->sendResponse(200, ['success' => true, 'data' => []]);
                    return;
                }
                $this->getLeaveBalances();
                break;
                
            case 'get_leave_type_details':
                if (!$this->canView()) {
                    $this->sendResponse(200, ['success' => true, 'data' => null]);
                    return;
                }
                if (isset($_GET['id'])) {
                    $this->getLeaveTypeDetails($_GET['id']);
                } else {
                    $this->sendResponse(400, ['success' => false, 'error' => 'Leave type ID is required']);
                }
                break;
                
            default:
                $this->sendResponse(400, ['success' => false, 'error' => 'Invalid action']);
        }
    }  
    
private function getLeaves() {
    try {
        $stmt = $this->pdo->prepare("
            SELECT 
                l.*,
                e.id as employee_db_id,
                e.employee_no,
                e.clinic_id as employee_clinic_id,
                COALESCE(u.first_name, 'Unknown') as first_name,
                COALESCE(u.last_name, '') as last_name,
                COALESCE(lt.type_name, 'Unknown') as type_name
            FROM leaves l
            LEFT JOIN employees e ON l.employee_id = e.id
            LEFT JOIN users u ON e.user_id = u.id
            LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
            WHERE e.id IS NOT NULL 
              AND e.clinic_id = ?
            ORDER BY l.created_at DESC
        ");
        $stmt->execute([$this->clinic_id]);
        
        $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($leaves as &$leave) {
            $leave['employee_name'] = trim($leave['first_name'] . ' ' . $leave['last_name']);
            if (empty($leave['employee_name'])) {
                $leave['employee_name'] = 'Unknown Employee';
            }
        }
        
        $this->sendResponse(200, ['success' => true, 'data' => $leaves]);
        
    } catch(PDOException $e) {
        error_log("Error in getLeaves: " . $e->getMessage());
        $this->sendResponse(500, ['success' => false, 'error' => 'Failed to fetch leaves: ' . $e->getMessage()]);
    }
}
    
private function getEmployees() {
    try {
        $stmt = $this->pdo->prepare("
            SELECT 
                e.id,
                e.employee_no,
                e.clinic_id,
                COALESCE(u.first_name, 'Unknown') as first_name,
                COALESCE(u.last_name, '') as last_name
            FROM employees e
            LEFT JOIN users u ON e.user_id = u.id
            WHERE e.status = 'Active' 
              AND e.clinic_id = ?
            ORDER BY u.first_name, u.last_name
        ");
        $stmt->execute([$this->clinic_id]);
        
        $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $this->sendResponse(200, ['success' => true, 'data' => $employees]);
        
    } catch(PDOException $e) {
        $this->sendResponse(500, ['success' => false, 'error' => 'Failed to fetch employees: ' . $e->getMessage()]);
    }
}
    
    private function getLeaveTypes() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT * FROM leave_types 
                WHERE clinic_id = ? AND is_active = 1 
                ORDER BY type_name
            ");
            $stmt->execute([$this->clinic_id]);
            
            $leaveTypes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->sendResponse(200, ['success' => true, 'data' => $leaveTypes]);
            
        } catch(PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to fetch leave types: ' . $e->getMessage()]);
        }
    }
    
    private function getLeaveTypeDetails($id) {
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM leave_types WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$id, $this->clinic_id]);
            $type = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$type) {
                $this->sendResponse(404, ['success' => false, 'error' => 'Leave type not found']);
                return;
            }
            
            $this->sendResponse(200, ['success' => true, 'data' => $type]);
            
        } catch(PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to fetch leave type: ' . $e->getMessage()]);
        }
    }
    
    private function getLeaveBalances() {
        try {
            $year = $_GET['year'] ?? date('Y');
            $employee = $_GET['employee'] ?? '';
            $leave_type = $_GET['leave_type'] ?? '';
            
            $sql = "
                SELECT 
                    lb.*,
                    e.employee_no,
                    e.id as employee_db_id,
                    u.first_name,
                    u.last_name,
                    lt.type_name
                FROM leave_balances lb
                INNER JOIN employees e ON lb.employee_id = e.id
                INNER JOIN users u ON e.user_id = u.id
                INNER JOIN leave_types lt ON lb.leave_type_id = lt.id
                WHERE lb.year = :year
                AND u.clinic_id = :clinic_id
                AND e.status = 'Active'
            ";
            
            $params = [
                ':year' => $year,
                ':clinic_id' => $this->clinic_id
            ];
            
            if ($employee && $employee !== 'all') {
                $sql .= " AND lb.employee_id = :employee";
                $params[':employee'] = $employee;
            }
            
            if ($leave_type) {
                $sql .= " AND lb.leave_type_id = :leave_type";
                $params[':leave_type'] = $leave_type;
            }
            
            $sql .= " ORDER BY u.last_name, u.first_name, lt.type_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            $balances = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($balances) && !$employee) {
                $balances = $this->getDefaultBalances($year, $leave_type);
            }
            
            $this->sendResponse(200, ['success' => true, 'data' => $balances]);
            
        } catch(PDOException $e) {
            error_log("Error fetching leave balances: " . $e->getMessage());
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to fetch leave balances: ' . $e->getMessage()]);
        }
    }
    
    private function getDefaultBalances($year, $leave_type = '') {
        try {
            $empSql = "
                SELECT e.id as employee_id, e.employee_no, u.first_name, u.last_name
                FROM employees e
                INNER JOIN users u ON e.user_id = u.id
                WHERE e.status = 'Active' AND u.clinic_id = ?
                ORDER BY u.last_name, u.first_name
            ";
            $empStmt = $this->pdo->prepare($empSql);
            $empStmt->execute([$this->clinic_id]);
            $employees = $empStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $typeSql = "SELECT id, type_name, max_days_per_year FROM leave_types WHERE clinic_id = ? AND is_active = 1";
            $typeParams = [$this->clinic_id];
            
            if ($leave_type) {
                $typeSql .= " AND id = ?";
                $typeParams[] = $leave_type;
            }
            
            $typeStmt = $this->pdo->prepare($typeSql);
            $typeStmt->execute($typeParams);
            $leaveTypes = $typeStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $balances = [];
            
            foreach ($employees as $employee) {
                foreach ($leaveTypes as $type) {
                    $usedSql = "
                        SELECT COALESCE(SUM(number_of_days), 0) as used_days
                        FROM leaves 
                        WHERE employee_id = :employee_id 
                        AND leave_type_id = :type_id
                        AND YEAR(start_date) = :year
                        AND status = 'Approved'
                        AND leave_with_pay = 'with_pay'
                    ";
                    $usedStmt = $this->pdo->prepare($usedSql);
                    $usedStmt->execute([
                        ':employee_id' => $employee['employee_id'],
                        ':type_id' => $type['id'],
                        ':year' => $year
                    ]);
                    $usedResult = $usedStmt->fetch(PDO::FETCH_ASSOC);
                    $used = $usedResult['used_days'];
                    
                    $balance = [
                        'id' => null,
                        'employee_id' => $employee['employee_id'],
                        'leave_type_id' => $type['id'],
                        'year' => $year,
                        'total_entitled' => $type['max_days_per_year'],
                        'used' => $used,
                        'carried_over' => 0,
                        'balance' => $type['max_days_per_year'] - $used,
                        'employee_no' => $employee['employee_no'],
                        'first_name' => $employee['first_name'],
                        'last_name' => $employee['last_name'],
                        'type_name' => $type['type_name']
                    ];
                    
                    $balances[] = $balance;
                }
            }
            
            return $balances;
            
        } catch(PDOException $e) {
            error_log("Error getting default balances: " . $e->getMessage());
            return [];
        }
    }
    
    // ============= POST HANDLERS =============
    private function handlePost() {
        error_log("=== LEAVES POST START ===");
        
        $input = file_get_contents('php://input');
        error_log("Raw input: " . $input);
        
        if (empty($input)) {
            $this->sendResponse(400, ['success' => false, 'error' => 'No input data']);
            return;
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['success' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg()]);
            return;
        }
        
        error_log("Decoded data: " . print_r($data, true));
        
        // ============================================
        // PERMISSION CHECKS - Using RBACHelper
        // ============================================
        
        if (isset($data['approve_leave']) && $data['approve_leave']) {
            if (!$this->canApprove()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to approve leaves']);
            }
            $this->approveLeave($data);
            
        } elseif (isset($data['reject_leave']) && $data['reject_leave']) {
            if (!$this->canReject()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to reject leaves']);
            }
            $this->rejectLeave($data);
            
        } elseif (isset($data['add_leave_type']) && $data['add_leave_type']) {
            if (!$this->canManageLeaveTypes()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to add leave types']);
            }
            $this->addLeaveType($data);
            
        } elseif (isset($data['update_leave_type']) && $data['update_leave_type']) {
            if (!$this->canManageLeaveTypes()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to update leave types']);
            }
            $this->updateLeaveType($data);
            
        } elseif (isset($data['delete_leave_type']) && $data['delete_leave_type']) {
            if (!$this->canManageLeaveTypes()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to delete leave types']);
            }
            $this->deleteLeaveType($data);
            
        } elseif (isset($data['add_leave_balance']) && $data['add_leave_balance']) {
            if (!$this->canManageBalances()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to add leave balances']);
            }
            $this->addLeaveBalance($data);
            
        } elseif (isset($data['update_leave_balance']) && $data['update_leave_balance']) {
            if (!$this->canManageBalances()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to update leave balances']);
            }
            $this->updateLeaveBalance($data);
            
        } elseif (isset($data['update_single_balance']) && $data['update_single_balance']) {
            if (!$this->canManageBalances()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to update leave balances']);
            }
            $this->updateLeaveBalance($data);
            
        } elseif (isset($data['delete_leave_balance']) && $data['delete_leave_balance']) {
            if (!$this->canManageBalances()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to delete leave balances']);
            }
            $this->deleteLeaveBalance($data);
            
        } elseif (isset($data['bulk_update_balances']) && $data['bulk_update_balances']) {
            if (!$this->canManageBalances()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to bulk update balances']);
            }
            $this->bulkUpdateBalances($data);
            
        } elseif (isset($data['export_leaves']) && $data['export_leaves']) {
            if (!$this->canExport()) {
                $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to export data']);
            }
            $this->exportLeaves($data);
            
        } else {
            $this->sendResponse(400, ['success' => false, 'error' => 'Invalid action']);
        }
    }
    
private function approveLeave($data) {
    try {
        $this->pdo->beginTransaction();
        
        // Get leave details with employee clinic_id
        $stmt = $this->pdo->prepare("
            SELECT l.*, e.user_id as employee_user_id, e.clinic_id
            FROM leaves l
            LEFT JOIN employees e ON l.employee_id = e.id
            WHERE l.id = ? AND e.clinic_id = ?
        ");
        $stmt->execute([$data['leave_id'], $this->clinic_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$leave) {
            $this->sendResponse(404, ['success' => false, 'error' => 'Leave not found']);
            return;
        }
        
        if ($leave['status'] !== 'Pending') {
            $this->sendResponse(400, ['success' => false, 'error' => 'Only pending leaves can be approved']);
            return;
        }
        
        $old_values = $leave;
        
        // Update leave status
        $updateStmt = $this->pdo->prepare("
            UPDATE leaves 
            SET status = 'Approved',
                approved_by = ?,
                approved_at = NOW()
            WHERE id = ?
        ");
        $updateStmt->execute([$this->user_id, $data['leave_id']]);
        
        // Update leave balance if with pay
        if ($leave['leave_with_pay'] === 'with_pay' && $leave['leave_type_id']) {
            $year = date('Y', strtotime($leave['start_date']));
            
            $balanceStmt = $this->pdo->prepare("
                SELECT * FROM leave_balances 
                WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND clinic_id = ?
            ");
            $balanceStmt->execute([$leave['employee_id'], $leave['leave_type_id'], $year, $this->clinic_id]);
            $balance = $balanceStmt->fetch(PDO::FETCH_ASSOC);
            
            $old_balance = $balance;
            
            if ($balance) {
                $updateBalanceStmt = $this->pdo->prepare("
                    UPDATE leave_balances 
                    SET used = used + ?,
                        balance = balance - ?,
                        updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $updateBalanceStmt->execute([
                    $leave['number_of_days'],
                    $leave['number_of_days'],
                    $balance['id'],
                    $this->clinic_id
                ]);
                
                $newBalanceStmt = $this->pdo->prepare("
                    SELECT * FROM leave_balances 
                    WHERE id = ? AND clinic_id = ?
                ");
                $newBalanceStmt->execute([$balance['id'], $this->clinic_id]);
                $new_balance = $newBalanceStmt->fetch(PDO::FETCH_ASSOC);
                
                $this->logAudit('UPDATE', 'leave_balances', $balance['id'], $old_balance, $new_balance);
            }
        }
        
        // Send notification to employee - REMOVED clinic_id
        if ($leave['employee_user_id']) {
            $notificationStmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, 'Leave Approved', 'Your leave request has been approved.', 'success', NOW())
            ");
            $notificationStmt->execute([$leave['employee_user_id']]);
        }
        
        $new_leave = $leave;
        $new_leave['status'] = 'Approved';
        $new_leave['approved_by'] = $this->user_id;
        $new_leave['approved_at'] = date('Y-m-d H:i:s');
        
        $this->logAudit('APPROVE', 'leaves', $data['leave_id'], $old_values, $new_leave);
        
        $this->pdo->commit();
        
        $this->sendResponse(200, ['success' => true, 'message' => 'Leave approved successfully']);
        
    } catch(PDOException $e) {
        $this->pdo->rollBack();
        error_log("Approve leave error: " . $e->getMessage());
        $this->sendResponse(500, ['success' => false, 'error' => 'Failed to approve leave: ' . $e->getMessage()]);
    }
}
    
private function rejectLeave($data) {
    try {
        // Get leave details with employee clinic_id
        $stmt = $this->pdo->prepare("
            SELECT l.*, e.user_id as employee_user_id, e.clinic_id
            FROM leaves l
            LEFT JOIN employees e ON l.employee_id = e.id
            WHERE l.id = ? AND e.clinic_id = ?
        ");
        $stmt->execute([$data['leave_id'], $this->clinic_id]);
        $leave = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$leave) {
            $this->sendResponse(404, ['success' => false, 'error' => 'Leave not found']);
            return;
        }
        
        if ($leave['status'] !== 'Pending') {
            $this->sendResponse(400, ['success' => false, 'error' => 'Only pending leaves can be rejected']);
            return;
        }
        
        $old_values = $leave;
        
        // Update leave status
        $updateStmt = $this->pdo->prepare("
            UPDATE leaves 
            SET status = 'Rejected',
                approved_by = ?,
                approved_at = NOW(),
                rejection_notes = ?
            WHERE id = ?
        ");
        $updateStmt->execute([
            $this->user_id,
            $data['reason'] ?? 'No reason provided',
            $data['leave_id']
        ]);
        
        // Send notification to employee - REMOVED clinic_id
        if ($leave['employee_user_id']) {
            $notificationStmt = $this->pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, created_at)
                VALUES (?, 'Leave Rejected', ?, 'danger', NOW())
            ");
            $notificationStmt->execute([
                $leave['employee_user_id'],
                'Your leave request has been rejected. Reason: ' . ($data['reason'] ?? 'No reason provided')
            ]);
        }
        
        $new_leave = $leave;
        $new_leave['status'] = 'Rejected';
        $new_leave['approved_by'] = $this->user_id;
        $new_leave['approved_at'] = date('Y-m-d H:i:s');
        $new_leave['rejection_notes'] = $data['reason'] ?? 'No reason provided';
        
        $this->logAudit('REJECT', 'leaves', $data['leave_id'], $old_values, $new_leave);
        
        $this->sendResponse(200, ['success' => true, 'message' => 'Leave rejected successfully']);
        
    } catch(PDOException $e) {
        error_log("Reject leave error: " . $e->getMessage());
        $this->sendResponse(500, ['success' => false, 'error' => 'Failed to reject leave: ' . $e->getMessage()]);
    }
}
    
    private function addLeaveType($data) {
        try {
            $checkStmt = $this->pdo->prepare("SELECT id FROM leave_types WHERE code = ? AND clinic_id = ?");
            $checkStmt->execute([$data['code'], $this->clinic_id]);
            if ($checkStmt->fetch()) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Leave type code already exists']);
                return;
            }
            
            $sql = "INSERT INTO leave_types (clinic_id, type_name, code, max_days_per_year, max_carry_over, with_pay, requires_attachment, is_active, description) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $this->clinic_id,
                $data['type_name'],
                $data['code'],
                $data['max_days_per_year'],
                $data['max_carry_over'] ?? 0,
                $data['with_pay'],
                $data['requires_attachment'],
                $data['is_active'],
                $data['description'] ?? ''
            ]);
            
            $new_id = $this->pdo->lastInsertId();
            
            $new_values = [
                'type_name' => $data['type_name'],
                'code' => $data['code'],
                'max_days_per_year' => $data['max_days_per_year'],
                'max_carry_over' => $data['max_carry_over'] ?? 0,
                'with_pay' => $data['with_pay'],
                'requires_attachment' => $data['requires_attachment'],
                'is_active' => $data['is_active'],
                'description' => $data['description'] ?? ''
            ];
            
            $this->logAudit('CREATE', 'leave_types', $new_id, null, $new_values);
            
            $this->sendResponse(200, ['success' => true, 'message' => 'Leave type added successfully']);
            
        } catch(PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to add leave type: ' . $e->getMessage()]);
        }
    }
    
    private function updateLeaveType($data) {
        try {
            $oldStmt = $this->pdo->prepare("SELECT * FROM leave_types WHERE id = ? AND clinic_id = ?");
            $oldStmt->execute([$data['id'], $this->clinic_id]);
            $old_values = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                $this->sendResponse(404, ['success' => false, 'error' => 'Leave type not found']);
                return;
            }
            
            $checkStmt = $this->pdo->prepare("SELECT id FROM leave_types WHERE code = ? AND clinic_id = ? AND id != ?");
            $checkStmt->execute([$data['code'], $this->clinic_id, $data['id']]);
            if ($checkStmt->fetch()) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Leave type code already exists']);
                return;
            }
            
            $sql = "UPDATE leave_types SET 
                    type_name = ?,
                    code = ?,
                    max_days_per_year = ?,
                    max_carry_over = ?,
                    with_pay = ?,
                    requires_attachment = ?,
                    is_active = ?,
                    description = ?
                    WHERE id = ? AND clinic_id = ?";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $data['type_name'],
                $data['code'],
                $data['max_days_per_year'],
                $data['max_carry_over'] ?? 0,
                $data['with_pay'],
                $data['requires_attachment'],
                $data['is_active'],
                $data['description'] ?? '',
                $data['id'],
                $this->clinic_id
            ]);
            
            $new_values = [
                'type_name' => $data['type_name'],
                'code' => $data['code'],
                'max_days_per_year' => $data['max_days_per_year'],
                'max_carry_over' => $data['max_carry_over'] ?? 0,
                'with_pay' => $data['with_pay'],
                'requires_attachment' => $data['requires_attachment'],
                'is_active' => $data['is_active'],
                'description' => $data['description'] ?? ''
            ];
            
            $this->logAudit('UPDATE', 'leave_types', $data['id'], $old_values, $new_values);
            
            $this->sendResponse(200, ['success' => true, 'message' => 'Leave type updated successfully']);
            
        } catch(PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to update leave type: ' . $e->getMessage()]);
        }
    }
    
    private function deleteLeaveType($data) {
        try {
            $oldStmt = $this->pdo->prepare("SELECT * FROM leave_types WHERE id = ? AND clinic_id = ?");
            $oldStmt->execute([$data['id'], $this->clinic_id]);
            $old_values = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                $this->sendResponse(404, ['success' => false, 'error' => 'Leave type not found']);
                return;
            }
            
            $checkStmt = $this->pdo->prepare("SELECT id FROM leaves WHERE leave_type_id = ? LIMIT 1");
            $checkStmt->execute([$data['id']]);
            if ($checkStmt->fetch()) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Cannot delete leave type that is in use']);
                return;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM leave_types WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$data['id'], $this->clinic_id]);
            
            $this->logAudit('DELETE', 'leave_types', $data['id'], $old_values, null);
            
            $this->sendResponse(200, ['success' => true, 'message' => 'Leave type deleted successfully']);
            
        } catch(PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to delete leave type: ' . $e->getMessage()]);
        }
    }
    
    private function addLeaveBalance($data) {
        try {
            $this->pdo->beginTransaction();
            
            $year = $data['year'];
            $leave_type_id = $data['leave_type_id'];
            $total_entitled = (float)$data['total_entitled'];
            $used = (float)($data['used'] ?? 0);
            $carried_over = (float)($data['carried_over'] ?? 0);
            $balance = $total_entitled + $carried_over - $used;
            
            if ($data['apply_to'] === 'all') {
                $empStmt = $this->pdo->prepare("
                    SELECT e.id 
                    FROM employees e
                    INNER JOIN users u ON e.user_id = u.id
                    WHERE e.status = 'Active' AND u.clinic_id = ?
                ");
                $empStmt->execute([$this->clinic_id]);
                $employees = $empStmt->fetchAll(PDO::FETCH_COLUMN);
                
                $count = 0;
                foreach ($employees as $employee_id) {
                    $checkStmt = $this->pdo->prepare("
                        SELECT * FROM leave_balances 
                        WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND clinic_id = ?
                    ");
                    $checkStmt->execute([$employee_id, $leave_type_id, $year, $this->clinic_id]);
                    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $old_values = $existing;
                        
                        $updateStmt = $this->pdo->prepare("
                            UPDATE leave_balances 
                            SET total_entitled = ?,
                                used = ?,
                                carried_over = ?,
                                balance = ?,
                                updated_at = NOW()
                            WHERE id = ? AND clinic_id = ?
                        ");
                        $updateStmt->execute([
                            $total_entitled,
                            $used,
                            $carried_over,
                            $balance,
                            $existing['id'],
                            $this->clinic_id
                        ]);
                        
                        $newStmt = $this->pdo->prepare("
                            SELECT * FROM leave_balances 
                            WHERE id = ? AND clinic_id = ?
                        ");
                        $newStmt->execute([$existing['id'], $this->clinic_id]);
                        $new_values = $newStmt->fetch(PDO::FETCH_ASSOC);
                        
                        $this->logAudit('UPDATE', 'leave_balances', $existing['id'], $old_values, $new_values);
                        
                    } else {
                        $insertStmt = $this->pdo->prepare("
                            INSERT INTO leave_balances 
                            (clinic_id, employee_id, leave_type_id, year, total_entitled, used, carried_over, balance, created_at, updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                        ");
                        $insertStmt->execute([
                            $this->clinic_id,
                            $employee_id,
                            $leave_type_id,
                            $year,
                            $total_entitled,
                            $used,
                            $carried_over,
                            $balance
                        ]);
                        
                        $new_id = $this->pdo->lastInsertId();
                        
                        $new_values = [
                            'clinic_id' => $this->clinic_id,
                            'employee_id' => $employee_id,
                            'leave_type_id' => $leave_type_id,
                            'year' => $year,
                            'total_entitled' => $total_entitled,
                            'used' => $used,
                            'carried_over' => $carried_over,
                            'balance' => $balance
                        ];
                        
                        $this->logAudit('CREATE', 'leave_balances', $new_id, null, $new_values);
                    }
                    $count++;
                }
                
                $message = "Leave balance set for {$count} employees";
                
            } else {
                $employee_id = $data['employee_id'];
                
                if (!$employee_id) {
                    throw new Exception("Employee ID is required");
                }
                
                $checkEmpStmt = $this->pdo->prepare("
                    SELECT e.id FROM employees e
                    INNER JOIN users u ON e.user_id = u.id
                    WHERE e.id = ? AND u.clinic_id = ?
                ");
                $checkEmpStmt->execute([$employee_id, $this->clinic_id]);
                if (!$checkEmpStmt->fetch()) {
                    throw new Exception("Employee not found in this clinic");
                }
                
                $checkStmt = $this->pdo->prepare("
                    SELECT * FROM leave_balances 
                    WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND clinic_id = ?
                ");
                $checkStmt->execute([$employee_id, $leave_type_id, $year, $this->clinic_id]);
                $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($existing) {
                    $old_values = $existing;
                    
                    $updateStmt = $this->pdo->prepare("
                        UPDATE leave_balances 
                        SET total_entitled = ?,
                            used = ?,
                            carried_over = ?,
                            balance = ?,
                            updated_at = NOW()
                        WHERE id = ? AND clinic_id = ?
                    ");
                    $updateStmt->execute([
                        $total_entitled,
                        $used,
                        $carried_over,
                        $balance,
                        $existing['id'],
                        $this->clinic_id
                    ]);
                    
                    $newStmt = $this->pdo->prepare("
                        SELECT * FROM leave_balances 
                        WHERE id = ? AND clinic_id = ?
                    ");
                    $newStmt->execute([$existing['id'], $this->clinic_id]);
                    $new_values = $newStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $this->logAudit('UPDATE', 'leave_balances', $existing['id'], $old_values, $new_values);
                    $message = "Leave balance updated successfully";
                    
                } else {
                    $insertStmt = $this->pdo->prepare("
                        INSERT INTO leave_balances 
                        (clinic_id, employee_id, leave_type_id, year, total_entitled, used, carried_over, balance, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                    ");
                    $insertStmt->execute([
                        $this->clinic_id,
                        $employee_id,
                        $leave_type_id,
                        $year,
                        $total_entitled,
                        $used,
                        $carried_over,
                        $balance
                    ]);
                    
                    $new_id = $this->pdo->lastInsertId();
                    
                    $new_values = [
                        'clinic_id' => $this->clinic_id,
                        'employee_id' => $employee_id,
                        'leave_type_id' => $leave_type_id,
                        'year' => $year,
                        'total_entitled' => $total_entitled,
                        'used' => $used,
                        'carried_over' => $carried_over,
                        'balance' => $balance
                    ];
                    
                    $this->logAudit('CREATE', 'leave_balances', $new_id, null, $new_values);
                    $message = "Leave balance added successfully";
                }
            }
            
            $this->pdo->commit();
            
            $this->sendResponse(200, ['success' => true, 'message' => $message]);
            
        } catch(PDOException $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        } catch(Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function updateLeaveBalance($data) {
        try {
            error_log("updateLeaveBalance received: " . json_encode($data));
            
            $balance_id = $data['id'] ?? $data['balance_id'] ?? null;
            
            if (!$balance_id) {
                $this->sendResponse(400, ['success' => false, 'error' => 'Balance ID is required']);
                return;
            }
            
            $oldStmt = $this->pdo->prepare("
                SELECT lb.* FROM leave_balances lb
                INNER JOIN employees e ON lb.employee_id = e.id
                INNER JOIN users u ON e.user_id = u.id
                WHERE lb.id = ? AND u.clinic_id = ?
            ");
            $oldStmt->execute([$balance_id, $this->clinic_id]);
            $old_values = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                $this->sendResponse(404, ['success' => false, 'error' => 'Leave balance not found']);
                return;
            }
            
            $total_entitled = isset($data['total_entitled']) ? (float)$data['total_entitled'] : (float)$old_values['total_entitled'];
            $used = isset($data['used']) ? (float)$data['used'] : (float)$old_values['used'];
            $carried_over = isset($data['carried_over']) ? (float)$data['carried_over'] : (float)$old_values['carried_over'];
            
            $balance = $total_entitled + $carried_over - $used;
            
            $stmt = $this->pdo->prepare("
                UPDATE leave_balances 
                SET total_entitled = ?,
                    used = ?,
                    carried_over = ?,
                    balance = ?,
                    updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([
                $total_entitled,
                $used,
                $carried_over,
                $balance,
                $balance_id,
                $this->clinic_id
            ]);
            
            $newStmt = $this->pdo->prepare("
                SELECT * FROM leave_balances 
                WHERE id = ? AND clinic_id = ?
            ");
            $newStmt->execute([$balance_id, $this->clinic_id]);
            $new_values = $newStmt->fetch(PDO::FETCH_ASSOC);
            
            $this->logAudit('UPDATE', 'leave_balances', $balance_id, $old_values, $new_values);
            
            $this->sendResponse(200, ['success' => true, 'message' => 'Leave balance updated successfully']);
            
        } catch(PDOException $e) {
            error_log("Update leave balance error: " . $e->getMessage());
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to update leave balance: ' . $e->getMessage()]);
        }
    }
    
    private function deleteLeaveBalance($data) {
        try {
            $oldStmt = $this->pdo->prepare("
                SELECT lb.* FROM leave_balances lb
                INNER JOIN employees e ON lb.employee_id = e.id
                INNER JOIN users u ON e.user_id = u.id
                WHERE lb.id = ? AND u.clinic_id = ?
            ");
            $oldStmt->execute([$data['id'], $this->clinic_id]);
            $old_values = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$old_values) {
                $this->sendResponse(404, ['success' => false, 'error' => 'Leave balance not found']);
                return;
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM leave_balances WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$data['id'], $this->clinic_id]);
            
            $this->logAudit('DELETE', 'leave_balances', $data['id'], $old_values, null);
            
            $this->sendResponse(200, ['success' => true, 'message' => 'Leave balance deleted successfully']);
            
        } catch(PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to delete leave balance: ' . $e->getMessage()]);
        }
    }
    
    private function bulkUpdateBalances($data) {
        try {
            $this->pdo->beginTransaction();
            
            $year = $data['year'];
            $leave_type_id = $data['leave_type_id'];
            $days = (float)$data['days'];
            $action_type = $data['action_type'];
            
            $empStmt = $this->pdo->prepare("
                SELECT e.id 
                FROM employees e
                INNER JOIN users u ON e.user_id = u.id
                WHERE e.status = 'Active' AND u.clinic_id = ?
            ");
            $empStmt->execute([$this->clinic_id]);
            $employees = $empStmt->fetchAll(PDO::FETCH_COLUMN);
            
            $count = 0;
            foreach ($employees as $employee_id) {
                $checkStmt = $this->pdo->prepare("
                    SELECT * FROM leave_balances 
                    WHERE employee_id = ? AND leave_type_id = ? AND year = ? AND clinic_id = ?
                ");
                $checkStmt->execute([$employee_id, $leave_type_id, $year, $this->clinic_id]);
                $current = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($current) {
                    $old_values = $current;
                    
                    if ($action_type === 'set') {
                        $new_entitled = $days;
                    } else {
                        $new_entitled = (float)$current['total_entitled'] + $days;
                    }
                    
                    $new_balance = $new_entitled + (float)$current['carried_over'] - (float)$current['used'];
                    
                    $updateStmt = $this->pdo->prepare("
                        UPDATE leave_balances 
                        SET total_entitled = ?,
                            balance = ?,
                            updated_at = NOW()
                        WHERE id = ? AND clinic_id = ?
                    ");
                    $updateStmt->execute([$new_entitled, $new_balance, $current['id'], $this->clinic_id]);
                    
                    $newStmt = $this->pdo->prepare("
                        SELECT * FROM leave_balances 
                        WHERE id = ? AND clinic_id = ?
                    ");
                    $newStmt->execute([$current['id'], $this->clinic_id]);
                    $new_values = $newStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $this->logAudit('UPDATE', 'leave_balances', $current['id'], $old_values, $new_values);
                    
                } else {
                    $typeStmt = $this->pdo->prepare("
                        SELECT max_days_per_year 
                        FROM leave_types 
                        WHERE id = ? AND clinic_id = ?
                    ");
                    $typeStmt->execute([$leave_type_id, $this->clinic_id]);
                    $type = $typeStmt->fetch(PDO::FETCH_ASSOC);
                    
                    $default_entitled = $type ? $type['max_days_per_year'] : 15;
                    $new_entitled = $action_type === 'set' ? $days : ($default_entitled + $days);
                    
                    $insertStmt = $this->pdo->prepare("
                        INSERT INTO leave_balances 
                        (clinic_id, employee_id, leave_type_id, year, total_entitled, used, carried_over, balance, created_at, updated_at)
                        VALUES (?, ?, ?, ?, ?, 0, 0, ?, NOW(), NOW())
                    ");
                    $insertStmt->execute([
                        $this->clinic_id,
                        $employee_id,
                        $leave_type_id,
                        $year,
                        $new_entitled,
                        $new_entitled
                    ]);
                    
                    $new_id = $this->pdo->lastInsertId();
                    
                    $new_values = [
                        'clinic_id' => $this->clinic_id,
                        'employee_id' => $employee_id,
                        'leave_type_id' => $leave_type_id,
                        'year' => $year,
                        'total_entitled' => $new_entitled,
                        'used' => 0,
                        'carried_over' => 0,
                        'balance' => $new_entitled
                    ];
                    
                    $this->logAudit('CREATE', 'leave_balances', $new_id, null, $new_values);
                }
                $count++;
            }
            
            $this->pdo->commit();
            
            $this->sendResponse(200, ['success' => true, 'message' => "Bulk action applied to {$count} employees"]);
            
        } catch(PDOException $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to apply bulk action: ' . $e->getMessage()]);
        }
    }
    
    private function exportLeaves($data) {
        try {
            $format = $data['format'] ?? 'json';
            $year = $data['year'] ?? date('Y');
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    l.*,
                    e.employee_no,
                    u.first_name,
                    u.last_name,
                    lt.type_name
                FROM leaves l
                LEFT JOIN employees e ON l.employee_id = e.id
                LEFT JOIN users u ON e.user_id = u.id
                LEFT JOIN leave_types lt ON l.leave_type_id = lt.id
                WHERE u.clinic_id = ? AND YEAR(l.start_date) = ?
                ORDER BY l.created_at DESC
            ");
            $stmt->execute([$this->clinic_id, $year]);
            $leaves = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->logAudit('EXPORT', 'leaves', null, null, ['format' => $format, 'count' => count($leaves)]);
            
            if ($format === 'csv') {
                $output = fopen('php://temp', 'r+');
                fputcsv($output, ['ID', 'Employee', 'Leave Type', 'Start Date', 'End Date', 'Days', 'Status', 'Reason']);
                
                foreach ($leaves as $leave) {
                    fputcsv($output, [
                        $leave['id'],
                        $leave['first_name'] . ' ' . $leave['last_name'] . ' (' . $leave['employee_no'] . ')',
                        $leave['type_name'],
                        $leave['start_date'],
                        $leave['end_date'],
                        $leave['number_of_days'],
                        $leave['status'],
                        $leave['reason']
                    ]);
                }
                
                rewind($output);
                $csv = stream_get_contents($output);
                fclose($output);
                
                $this->sendResponse(200, [
                    'success' => true,
                    'data' => $csv,
                    'filename' => 'leaves_export_' . $year . '_' . date('Y-m-d') . '.csv'
                ]);
                
            } else {
                $this->sendResponse(200, ['success' => true, 'data' => $leaves]);
            }
            
        } catch(PDOException $e) {
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to export leaves: ' . $e->getMessage()]);
        }
    }
    
    // ============= HELPER METHODS =============
    private function sendResponse($code, $data) {
        if (ob_get_length()) {
            ob_clean();
        }
        
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
?>