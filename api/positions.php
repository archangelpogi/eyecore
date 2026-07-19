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

    // Include and instantiate PositionsBackend class
    $positionsBackend = new PositionsBackend($pdo);
    $positionsBackend->handleRequest();

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

class PositionsBackend {
    private $pdo;
    private $clinic_id;
    private $user_id;
    private $user_role;
    
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
        
        // ✅ Load permissions to session if not already loaded
        if (!isset($_SESSION['permissions'])) {
            RBACHelper::loadPermissionsToSession($this->user_id, $this->clinic_id);
        }
        
        // Debug for Mean Mendoza
        if ($this->user_id == 161) {
            error_log("=== Positions Backend Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Permissions loaded: " . count($_SESSION['permissions'] ?? []));
            error_log("Has positions_view: " . ($this->canView() ? 'YES' : 'NO'));
            error_log("Has positions_create: " . ($this->canCreate() ? 'YES' : 'NO'));
            error_log("Has positions_edit: " . ($this->canEdit() ? 'YES' : 'NO'));
            error_log("Has positions_delete: " . ($this->canDelete() ? 'YES' : 'NO'));
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
        return $this->hasPermission('positions_view');
    }
    
    private function canCreate() {
        return $this->hasPermission('positions_create');
    }
    
    private function canEdit() {
        return $this->hasPermission('positions_edit');
    }
    
    private function canDelete() {
        return $this->hasPermission('positions_delete');
    }
    
    private function canExport() {
        return $this->hasPermission('positions_export') || $this->hasPermission('reports_view');
    }
    
    public function getCurrentUserPermissions() {
        $role = $this->getCurrentUserRole();
        $hasHR = $this->checkHRExists();
        
        $permissions = [
            'view' => $this->canView(),
            'add' => $this->canCreate(),
            'edit' => $this->canEdit(),
            'delete' => $this->canDelete(),
            'export' => $this->canExport()
        ];
        
        // Debug
        error_log("=== Positions getCurrentUserPermissions ===");
        error_log("Role: $role");
        error_log("canView(): " . ($this->canView() ? 'true' : 'false'));
        error_log("canCreate(): " . ($this->canCreate() ? 'true' : 'false'));
        error_log("canEdit(): " . ($this->canEdit() ? 'true' : 'false'));
        error_log("canDelete(): " . ($this->canDelete() ? 'true' : 'false'));
        
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
        
        // ✅ Check view permission
        if (!$this->canView()) {
            $this->sendResponse(200, []); // Return empty array for graceful degradation
            return;
        }
        
        // Get single position if ID provided
        if (isset($_GET['id'])) {
            $this->getPositionDetails($_GET['id']);
            return;
        }
        
        // Get all positions
        $this->getAllPositions();
    }
    
    private function getPositionDetails($position_id) {
        $stmt = $this->pdo->prepare("
            SELECT p.*, 
                   COUNT(e.id) as employee_count
            FROM positions p
            LEFT JOIN employees e ON p.id = e.position_id
            WHERE p.id = ? AND p.clinic_id = ?
            GROUP BY p.id
        ");
        $stmt->execute([$position_id, $this->clinic_id]);
        $position = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$position) {
            $this->sendResponse(404, ['error' => 'Position not found']);
            return;
        }
        
        // If user can't edit, hide salary information
        if (!$this->canEdit()) {
            unset($position['salary_rate']);
        }
        
        $this->sendResponse(200, ['success' => true, 'data' => $position]);
    }
    
    private function getAllPositions() {
        $stmt = $this->pdo->prepare("
            SELECT p.*, 
                   COUNT(e.id) as employee_count
            FROM positions p
            LEFT JOIN employees e ON p.id = e.position_id
            WHERE p.clinic_id = ?
            GROUP BY p.id
            ORDER BY p.position_name
        ");
        $stmt->execute([$this->clinic_id]);
        $positions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // If user can't edit, hide salary information
        if (!$this->canEdit()) {
            foreach ($positions as &$position) {
                unset($position['salary_rate']);
            }
        }
        
        $this->sendResponse(200, $positions);
    }
    
    // ============= POST HANDLERS =============
    private function handlePost() {
        error_log("=== POSITIONS POST START ===");
        
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
        
        // Route to appropriate handler with permission checks
        if (isset($data['add_position'])) {
            if (!$this->canCreate()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to add positions']);
            }
            $this->addPosition($data);
        } elseif (isset($data['update_position'])) {
            if (!$this->canEdit()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to edit positions']);
            }
            $this->updatePosition($data);
        } elseif (isset($data['delete_position'])) {
            if (!$this->canDelete()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to delete positions']);
            }
            $this->deletePosition($data);
        } else {
            $this->sendResponse(400, ['error' => 'Invalid request action']);
        }
    }
    
    private function addPosition($data) {
        // Validate
        if (empty(trim($data['position_name'] ?? ''))) {
            $this->sendResponse(400, ['error' => 'Position name is required']);
            return;
        }
        
        // Check for duplicates
        if ($this->positionExists(trim($data['position_name']))) {
            $this->sendResponse(400, ['error' => 'Position name already exists']);
            return;
        }
        
        // Validate salary if provided
        if (!empty($data['salary_rate']) && !is_numeric($data['salary_rate'])) {
            $this->sendResponse(400, ['error' => 'Salary rate must be a number']);
            return;
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO positions (clinic_id, position_name, salary_rate, department, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->clinic_id,
                trim($data['position_name']),
                !empty($data['salary_rate']) ? floatval($data['salary_rate']) : null,
                $data['department'] ?? null
            ]);
            
            $position_id = $this->pdo->lastInsertId();
            
            // AUDIT LOG: Position creation
            $new_values = [
                'position_name' => trim($data['position_name']),
                'salary_rate' => $data['salary_rate'] ?? null,
                'department' => $data['department'] ?? null
            ];
            $this->logAudit('CREATE', 'positions', $position_id, null, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Position added successfully',
                'position_id' => $position_id
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => 'Failed to add position: ' . $e->getMessage()]);
        }
    }
    
    private function updatePosition($data) {
        if (empty($data['position_id'])) {
            $this->sendResponse(400, ['error' => 'Position ID is required']);
            return;
        }
        
        // Get old values for audit
        $old_values = $this->getPositionForAudit($data['position_id']);
        if (!$old_values) {
            $this->sendResponse(404, ['error' => 'Position not found or access denied']);
            return;
        }
        
        // Validate
        if (empty(trim($data['position_name'] ?? ''))) {
            $this->sendResponse(400, ['error' => 'Position name is required']);
            return;
        }
        
        // Check for duplicates (excluding current position)
        if ($this->positionExists(trim($data['position_name']), $data['position_id'])) {
            $this->sendResponse(400, ['error' => 'Position name already exists']);
            return;
        }
        
        // Validate salary if provided
        if (!empty($data['salary_rate']) && !is_numeric($data['salary_rate'])) {
            $this->sendResponse(400, ['error' => 'Salary rate must be a number']);
            return;
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE positions 
                SET position_name = ?, 
                    salary_rate = ?, 
                    department = ?,
                    updated_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            
            $stmt->execute([
                trim($data['position_name']),
                !empty($data['salary_rate']) ? floatval($data['salary_rate']) : null,
                $data['department'] ?? null,
                $data['position_id'],
                $this->clinic_id
            ]);
            
            // AUDIT LOG: Position update
            $new_values = [
                'position_name' => trim($data['position_name']),
                'salary_rate' => $data['salary_rate'] ?? null,
                'department' => $data['department'] ?? null
            ];
            $this->logAudit('UPDATE', 'positions', $data['position_id'], $old_values, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Position updated successfully'
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => 'Failed to update position: ' . $e->getMessage()]);
        }
    }
    
    private function deletePosition($data) {
        if (empty($data['position_id'])) {
            $this->sendResponse(400, ['error' => 'Position ID is required']);
            return;
        }
        
        // Get old values for audit
        $old_values = $this->getPositionForAudit($data['position_id']);
        if (!$old_values) {
            $this->sendResponse(404, ['error' => 'Position not found or access denied']);
            return;
        }
        
        // Check if position has employees
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) as count 
            FROM employees 
            WHERE position_id = ?
        ");
        $stmt->execute([$data['position_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            $this->sendResponse(400, [
                'error' => 'Cannot delete position. There are ' . $result['count'] . ' employee(s) assigned to this position.'
            ]);
            return;
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("DELETE FROM positions WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$data['position_id'], $this->clinic_id]);
            
            // AUDIT LOG: Position deletion
            $this->logAudit('DELETE', 'positions', $data['position_id'], $old_values, null);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Position deleted successfully'
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => 'Failed to delete position: ' . $e->getMessage()]);
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
    
    private function positionExists($position_name, $exclude_id = null) {
        $sql = "SELECT id FROM positions WHERE position_name = ? AND clinic_id = ?";
        $params = [trim($position_name), $this->clinic_id];
        
        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetch() ? true : false;
    }
    
    private function getPositionForAudit($position_id) {
        $stmt = $this->pdo->prepare("
            SELECT id, position_name, salary_rate, department 
            FROM positions 
            WHERE id = ? AND clinic_id = ?
        ");
        $stmt->execute([$position_id, $this->clinic_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Main execution
try {
    if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
        $positionsBackend = new PositionsBackend($pdo);
        $positionsBackend->handleRequest();
    }
} catch (Exception $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
    }
    
    echo json_encode(['error' => 'Internal server error: ' . $e->getMessage()]);
    exit;
}
?>