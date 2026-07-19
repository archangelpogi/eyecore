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
    
    // Initialize RBACHelper with PDO
    RBACHelper::init($pdo);
    
    // Load permissions to session if not already loaded
    if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
        RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
    }

    // Include and instantiate OvertimeBackend class
    $overtimeBackend = new OvertimeBackend($pdo);
    $overtimeBackend->handleRequest();

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

class OvertimeBackend {
    private $pdo;
    private $clinic_id;
    private $user_id;
    private $user_role;
    private $employee_id;
    
    // ✅ CONSTRUCTOR with RBACHelper initialization
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
        $this->employee_id = $_SESSION['employee_id'] ?? $this->getEmployeeIdFromUserId($this->user_id);
        
        // ✅ Load permissions to session if not already loaded
        if (!isset($_SESSION['permissions'])) {
            RBACHelper::loadPermissionsToSession($this->user_id, $this->clinic_id);
        }
        
        // Debug for Mean Mendoza
        if ($this->user_id == 161) {
            error_log("=== Overtime Backend Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Permissions loaded: " . count($_SESSION['permissions'] ?? []));
            error_log("Has overtime_view: " . ($this->canView() ? 'YES' : 'NO'));
            error_log("Has overtime_approve: " . ($this->canApprove() ? 'YES' : 'NO'));
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
    
    private function getEmployeeIdFromUserId($user_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT e.id 
                FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.user_id = ? AND u.clinic_id = ? AND e.status = 'Active'
                LIMIT 1
            ");
            $stmt->execute([$user_id, $this->clinic_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $employee ? $employee['id'] : null;
        } catch(Exception $e) {
            error_log("Get Employee ID Error: " . $e->getMessage());
            return null;
        }
    }
    
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
    private function hasPermission($permission_name) {
        return RBACHelper::hasPermission($permission_name);
    }
    
    private function canView() {
        return $this->hasPermission('overtime_requests_view');
    }
    
    private function canManage() {
        return $this->hasPermission('overtime_requests_edit');
    }
    
    private function canApprove() {
        return $this->hasPermission('overtime_requests_approve');
    }
    
    private function canReject() {
        return $this->hasPermission('overtime_requests_reject');
    }
    
    private function canViewOwn() {
        return $this->hasPermission('overtime_requests_view'); // Same as view
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
        return $this->user_role;
    }
    
// Sa getCurrentUserPermissions() method
public function getCurrentUserPermissions() {
    $role = $this->getCurrentUserRole();
    $hasHR = $this->checkHRExists();
    
    $permissions = [
        'view' => $this->canView(),
        'manage' => $this->canManage(),
        'approve' => $this->canApprove(),
        'reject' => $this->canReject(),
        'view_own' => $this->canViewOwn()
    ];
    
    // ✅ Add debug
    error_log("=== Overtime Permissions Debug ===");
    error_log("canView(): " . ($this->canView() ? 'true' : 'false'));
    error_log("canManage(): " . ($this->canManage() ? 'true' : 'false'));
    error_log("canApprove(): " . ($this->canApprove() ? 'true' : 'false'));
    error_log("canReject(): " . ($this->canReject() ? 'true' : 'false'));
    
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
        
        // Check view permission
        if (!$this->canView() && !$this->canViewOwn()) {
            $this->sendResponse(403, ['error' => 'You do not have permission to view overtime requests']);
            return;
        }
        
        // Get single overtime request
        if (isset($_GET['id'])) {
            $this->getOvertimeDetails((int)$_GET['id']);
            return;
        }
        
        // Get overtime stats
        if (isset($_GET['stats'])) {
            $this->getStats();
            return;
        }
        
        // List overtime requests
        $this->getOvertimeList();
    }
    
    private function getOvertimeDetails($id) {
        $stmt = $this->pdo->prepare("
            SELECT 
                ot.*,
                e.employee_no,
                u.first_name,
                u.last_name,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                p.position_name,
                approver.first_name as approver_first,
                approver.last_name as approver_last,
                CONCAT(approver.first_name, ' ', approver.last_name) as approved_by_name
            FROM overtime_requests ot
            INNER JOIN employees e ON ot.employee_id = e.id
            INNER JOIN users u ON e.user_id = u.id
            LEFT JOIN positions p ON e.position_id = p.id
            LEFT JOIN users approver ON ot.approved_by = approver.id
            WHERE ot.id = ? AND u.clinic_id = ?
        ");
        $stmt->execute([$id, $this->clinic_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            $this->sendResponse(404, ['error' => 'Overtime request not found']);
            return;
        }
        
        // Check if user can view this specific record
        if (!$this->canView() && $data['employee_id'] != $this->employee_id) {
            $this->sendResponse(403, ['error' => 'You do not have permission to view this record']);
            return;
        }
        
        $this->sendResponse(200, $data);
    }
    
    private function getStats() {
        if ($this->canManage() || $this->canApprove()) {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as pending
                FROM overtime_requests ot
                INNER JOIN employees e ON ot.employee_id = e.id
                INNER JOIN users u ON e.user_id = u.id
                WHERE u.clinic_id = ? AND ot.status = 'pending'
            ");
            $stmt->execute([$this->clinic_id]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as pending
                FROM overtime_requests
                WHERE employee_id = ? AND status = 'pending'
            ");
            $stmt->execute([$this->employee_id]);
        }
        
        $this->sendResponse(200, $stmt->fetch(PDO::FETCH_ASSOC));
    }
    
    private function getOvertimeList() {
        $params = [];
        $sql = "
            SELECT 
                ot.*,
                e.employee_no,
                CONCAT(u.first_name, ' ', u.last_name) as employee_name
            FROM overtime_requests ot
            INNER JOIN employees e ON ot.employee_id = e.id
            INNER JOIN users u ON e.user_id = u.id
            WHERE u.clinic_id = ?
        ";
        $params[] = $this->clinic_id;
        
        // Filter by user if not manager
        if (!$this->canView() && !$this->canManage()) {
            $sql .= " AND ot.employee_id = ?";
            $params[] = $this->employee_id;
        }
        
        // Status filter
        if (isset($_GET['status']) && $_GET['status'] !== 'all') {
            $sql .= " AND ot.status = ?";
            $params[] = $_GET['status'];
        }
        
        // Employee filter (for managers only)
        if (isset($_GET['employee_id']) && $_GET['employee_id'] && ($this->canView() || $this->canManage())) {
            $sql .= " AND ot.employee_id = ?";
            $params[] = $_GET['employee_id'];
        }
        
        // Type filter
        if (isset($_GET['type']) && $_GET['type']) {
            $sql .= " AND ot.overtime_type = ?";
            $params[] = $_GET['type'];
        }
        
        // Search
        if (isset($_GET['search']) && $_GET['search']) {
            $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR ot.reason LIKE ?)";
            $search = '%' . $_GET['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY ot.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->sendResponse(200, $data);
    }
    
    // ============= POST HANDLERS =============
    private function handlePost() {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Invalid input']);
            return;
        }
        
        // Check approve permission
        if (isset($input['action']) && $input['action'] === 'approve') {
            if (!$this->canApprove()) {
                $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to approve overtime']);
                return;
            }
            
            $this->approveOvertime($input);
        }
        // Check reject permission
        elseif (isset($input['action']) && $input['action'] === 'reject') {
            if (!$this->canReject()) {
                $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to reject overtime']);
                return;
            }
            
            $this->rejectOvertime($input);
        }
        else {
            $this->sendResponse(400, ['success' => false, 'message' => 'Invalid action']);
        }
    }
    
    private function approveOvertime($input) {
        $id = $input['id'] ?? 0;
        
        if (!$id) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Overtime ID required']);
            return;
        }
        
        // Get old values for audit
        $oldStmt = $this->pdo->prepare("SELECT * FROM overtime_requests WHERE id = ?");
        $oldStmt->execute([$id]);
        $old_values = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_values) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Overtime request not found']);
            return;
        }
        
        $stmt = $this->pdo->prepare("
            UPDATE overtime_requests 
            SET status = 'approved', approved_by = ?, approved_at = NOW() 
            WHERE id = ?
        ");
        $result = $stmt->execute([$this->user_id, $id]);
        
        if ($result) {
            // Log audit
            $new_values = ['status' => 'approved', 'approved_by' => $this->user_id, 'approved_at' => date('Y-m-d H:i:s')];
            $this->logAudit('UPDATE', 'overtime_requests', $id, $old_values, $new_values);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Overtime approved successfully'
            ]);
        } else {
            $this->sendResponse(500, [
                'success' => false,
                'message' => 'Failed to approve overtime'
            ]);
        }
    }
    
    private function rejectOvertime($input) {
        $id = $input['id'] ?? 0;
        
        if (!$id) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Overtime ID required']);
            return;
        }
        
        // Get old values for audit
        $oldStmt = $this->pdo->prepare("SELECT * FROM overtime_requests WHERE id = ?");
        $oldStmt->execute([$id]);
        $old_values = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_values) {
            $this->sendResponse(404, ['success' => false, 'message' => 'Overtime request not found']);
            return;
        }
        
        $stmt = $this->pdo->prepare("
            UPDATE overtime_requests 
            SET status = 'rejected', approved_by = ?, approved_at = NOW() 
            WHERE id = ?
        ");
        $result = $stmt->execute([$this->user_id, $id]);
        
        if ($result) {
            // Log audit
            $new_values = ['status' => 'rejected', 'approved_by' => $this->user_id, 'approved_at' => date('Y-m-d H:i:s')];
            $this->logAudit('UPDATE', 'overtime_requests', $id, $old_values, $new_values);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Overtime rejected successfully'
            ]);
        } else {
            $this->sendResponse(500, [
                'success' => false,
                'message' => 'Failed to reject overtime'
            ]);
        }
    }
}
?>