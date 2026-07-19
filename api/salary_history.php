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
    // Debug: Log the request
    error_log("Salary History API Request: " . $_SERVER['REQUEST_METHOD'] . " " . json_encode($_REQUEST));

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

    // Include and instantiate SalaryHistoryBackend class
    $salaryBackend = new SalaryHistoryBackend($pdo);
    $salaryBackend->handleRequest();

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

class SalaryHistoryBackend {
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
            $this->sendResponse(401, ['error' => 'Unauthorized']);
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
            error_log("=== Salary History Backend Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Has salary-history_view: " . ($this->canView() ? 'YES' : 'NO'));
            error_log("Has salary-history_create: " . ($this->canCreate() ? 'YES' : 'NO'));
            error_log("Has salary-history_edit: " . ($this->canEdit() ? 'YES' : 'NO'));
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
    
    // ============= RBAC PERMISSION METHODS =============
    private function hasPermission($permission_name) {
        return RBACHelper::hasPermission($permission_name);
    }
    
    private function canView() {
        return $this->hasPermission('salary-history_view');
    }
    
    private function canCreate() {
        return $this->hasPermission('salary-history_create');
    }
    
    private function canEdit() {
        return $this->hasPermission('salary-history_edit');
    }
    
    private function canDelete() {
        return $this->hasPermission('salary-history_delete');
    }
    
    private function canBulk() {
        return $this->hasPermission('salary-history_bulk') || $this->canCreate();
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
            'view' => $this->canView(),
            'add' => $this->canCreate(),
            'edit' => $this->canEdit(),
            'delete' => $this->canDelete(),
            'bulk' => $this->canBulk(),
            'export' => $this->canExport()
        ];
        
        // Override for ClinicAdmin in oversight mode
        if ($role === 'ClinicAdmin' && $hasHR && !$this->canEdit()) {
            $permissions = [
                'view' => true,
                'add' => false,
                'edit' => false,
                'delete' => false,
                'bulk' => false,
                'export' => true
            ];
        }
        
        // Debug
        error_log("=== Salary History Permissions ===");
        error_log("view: " . ($permissions['view'] ? 'true' : 'false'));
        error_log("add: " . ($permissions['add'] ? 'true' : 'false'));
        error_log("bulk: " . ($permissions['bulk'] ? 'true' : 'false'));
        
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
                    $this->sendResponse(405, ['error' => 'Method not allowed']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function handleGet() {
        // Check for permissions request first
        if (isset($_GET['get_permissions'])) {
            $this->sendResponse(200, [
                'success' => true,
                'data' => $this->getCurrentUserPermissions()
            ]);
            return;
        }
        
        // ✅ Check view permission
        if (!$this->canView()) {
            $this->sendResponse(403, ['error' => 'You do not have permission to view salary history']);
            return;
        }
        
        // Get salary history for employee
        if (isset($_GET['employee_id'])) {
            $this->getSalaryHistory($_GET['employee_id']);
            return;
        }
        
        $this->sendResponse(400, ['error' => 'Missing employee_id parameter']);
    }
    
    private function getSalaryHistory($employee_id) {
        try {
            // Verify employee exists and belongs to current clinic
            $stmt = $this->pdo->prepare("
                SELECT e.*, u.clinic_id, u.first_name, u.last_name
                FROM employees e 
                JOIN users u ON e.user_id = u.id 
                WHERE e.id = ? AND u.clinic_id = ?
            ");
            $stmt->execute([$employee_id, $this->clinic_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                $this->sendResponse(404, ['error' => 'Employee not found in this clinic']);
                return;
            }
            
            // Get salary history
            $stmt = $this->pdo->prepare("
                SELECT sh.*, 
                       CONCAT(u.first_name, ' ', u.last_name) as changed_by_name
                FROM salary_history sh
                LEFT JOIN users u ON sh.changed_by = u.id
                WHERE sh.employee_id = ? AND sh.clinic_id = ?
                ORDER BY sh.effective_date DESC, sh.changed_at DESC
            ");
            $stmt->execute([$employee_id, $this->clinic_id]);
            
            $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format history data
            foreach ($history as &$record) {
                $record['old_salary'] = floatval($record['old_salary']);
                $record['new_salary'] = floatval($record['new_salary']);
            }
            
            // Log view action
            $this->logAudit('VIEW', 'salary-history', null, null, [
                'employee_id' => $employee_id,
                'history_count' => count($history)
            ]);
            
            $this->sendResponse(200, [
                'success' => true,
                'current_salary' => floatval($employee['basic_salary']),
                'history' => $history
            ]);
            
        } catch (Exception $e) {
            error_log("Error in getSalaryHistory: " . $e->getMessage());
            $this->sendResponse(500, ['error' => 'Failed to fetch salary history: ' . $e->getMessage()]);
        }
    }
    
    private function handlePost() {
        error_log("=== SALARY HISTORY POST START ===");
        
        $input = file_get_contents('php://input');
        error_log("Raw input: " . $input);
        
        if (empty($input)) {
            $this->sendResponse(400, ['error' => 'No input data']);
            return;
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendResponse(400, ['error' => 'Invalid JSON: ' . json_last_error_msg()]);
            return;
        }
        
        error_log("Decoded data: " . print_r($data, true));
        
        // ============================================
        // PERMISSION CHECKS - Using RBACHelper
        // ============================================
        
        if (isset($data['add_adjustment'])) {
            if (!$this->canCreate()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to add salary adjustments']);
                return;
            }
            $this->addSalaryAdjustment($data);
        } elseif (isset($data['bulk_increase'])) {
            if (!$this->canBulk()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to perform bulk salary increases']);
                return;
            }
            $this->bulkSalaryIncrease($data);
        } else {
            $this->sendResponse(400, ['error' => 'Invalid request action']);
        }
    }
    
    private function addSalaryAdjustment($data) {
        // Validate required fields
        if (empty($data['employee_id']) || empty($data['new_salary']) || empty($data['reason'])) {
            $this->sendResponse(400, ['error' => 'Missing required fields']);
            return;
        }
        
        try {
            // Verify employee belongs to this clinic
            $stmt = $this->pdo->prepare("
                SELECT e.*, u.clinic_id 
                FROM employees e 
                JOIN users u ON e.user_id = u.id 
                WHERE e.id = ? AND u.clinic_id = ?
            ");
            $stmt->execute([$data['employee_id'], $this->clinic_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                $this->sendResponse(404, ['error' => 'Employee not found in this clinic']);
                return;
            }
            
            $old_salary = floatval($employee['basic_salary']);
            $new_salary = floatval($data['new_salary']);
            
            // Get old values for audit
            $old_values = [
                'employee_id' => $data['employee_id'],
                'old_salary' => $old_salary,
                'new_salary' => $new_salary,
                'reason' => $data['reason']
            ];
            
            $this->pdo->beginTransaction();
            
            // Insert salary history
            $stmt = $this->pdo->prepare("
                INSERT INTO salary_history (
                    clinic_id, employee_id, old_salary, new_salary, reason, 
                    effective_date, changed_at, changed_by
                ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            
            $stmt->execute([
                $this->clinic_id,
                $data['employee_id'],
                $old_salary,
                $new_salary,
                $data['reason'],
                $data['effective_date'] ?? date('Y-m-d'),
                $this->user_id
            ]);
            
            $history_id = $this->pdo->lastInsertId();
            
            // Update employee current salary
            $stmt = $this->pdo->prepare("
                UPDATE employees 
                SET basic_salary = ?, updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            
            $stmt->execute([$new_salary, $data['employee_id'], $this->clinic_id]);
            
            // AUDIT LOG: Salary adjustment
            $new_values = [
                'employee_id' => $data['employee_id'],
                'old_salary' => $old_salary,
                'new_salary' => $new_salary,
                'reason' => $data['reason'],
                'effective_date' => $data['effective_date'] ?? date('Y-m-d'),
                'changed_by' => $this->user_id,
                'clinic_id' => $this->clinic_id
            ];
            
            $this->logAudit('CREATE', 'salary_history', $history_id, $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Salary adjustment added successfully',
                'adjustment_id' => $history_id,
                'change_amount' => $new_salary - $old_salary,
                'change_percentage' => $old_salary > 0 ? round((($new_salary - $old_salary) / $old_salary) * 100, 2) : 0
            ]);
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error in addSalaryAdjustment: " . $e->getMessage());
            $this->sendResponse(500, ['error' => 'Failed to add salary adjustment: ' . $e->getMessage()]);
        }
    }
    
    private function bulkSalaryIncrease($data) {
        $percentage = floatval($data['percentage']);
        $effective_date = $data['effective_date'] ?? date('Y-m-d');
        $reason = $data['reason'] ?? "Bulk salary increase ($percentage%)";
        
        if ($percentage <= 0) {
            $this->sendResponse(400, ['error' => 'Percentage must be greater than 0']);
            return;
        }
        
        try {
            $this->pdo->beginTransaction();
            
            // Get all active employees in this clinic
            $stmt = $this->pdo->prepare("
                SELECT e.id, e.basic_salary, e.employee_no, 
                       CONCAT(u.first_name, ' ', u.last_name) as employee_name
                FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.status = 'Active' 
                AND u.clinic_id = ?
                AND e.basic_salary > 0
            ");
            $stmt->execute([$this->clinic_id]);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $updated_count = 0;
            $updated_employees = [];
            
            // Log bulk action summary
            $bulk_info = [
                'action' => 'bulk_increase',
                'percentage' => $percentage,
                'affected_count' => count($employees),
                'effective_date' => $effective_date,
                'reason' => $reason
            ];
            
            // Process each employee
            foreach ($employees as $employee) {
                $old_salary = floatval($employee['basic_salary']);
                $new_salary = round($old_salary * (1 + ($percentage / 100)), 2);
                
                // Get old values for audit
                $old_values = [
                    'employee_id' => $employee['id'],
                    'old_salary' => $old_salary,
                    'new_salary' => $new_salary,
                    'reason' => $reason
                ];
                
                // Insert salary history
                $stmt = $this->pdo->prepare("
                    INSERT INTO salary_history (
                        clinic_id, employee_id, old_salary, new_salary, reason, 
                        effective_date, changed_at, changed_by
                    ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                
                $stmt->execute([
                    $this->clinic_id,
                    $employee['id'],
                    $old_salary,
                    $new_salary,
                    $reason,
                    $effective_date,
                    $this->user_id
                ]);
                
                $history_id = $this->pdo->lastInsertId();
                
                // Update employee salary
                $stmt = $this->pdo->prepare("
                    UPDATE employees 
                    SET basic_salary = ?, updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                
                $stmt->execute([$new_salary, $employee['id'], $this->clinic_id]);
                
                // AUDIT LOG: Individual adjustment
                $new_values = [
                    'employee_id' => $employee['id'],
                    'old_salary' => $old_salary,
                    'new_salary' => $new_salary,
                    'reason' => $reason,
                    'effective_date' => $effective_date,
                    'changed_by' => $this->user_id,
                    'clinic_id' => $this->clinic_id,
                    'bulk_action' => true
                ];
                
                $this->logAudit('CREATE', 'salary_history', $history_id, $old_values, $new_values);
                
                $updated_count++;
                $updated_employees[] = [
                    'id' => $employee['id'],
                    'name' => $employee['employee_name'],
                    'employee_no' => $employee['employee_no'],
                    'old_salary' => $old_salary,
                    'new_salary' => $new_salary
                ];
            }
            
            // AUDIT LOG: Bulk action summary
            $this->logAudit('BULK_ACTION', 'salary_history', null, null, $bulk_info);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => "Bulk salary increase applied to $updated_count employees",
                'updated_count' => $updated_count,
                'percentage' => $percentage,
                'employees' => $updated_employees
            ]);
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log("Error in bulkSalaryIncrease: " . $e->getMessage());
            $this->sendResponse(500, ['error' => 'Failed to apply bulk increase: ' . $e->getMessage()]);
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