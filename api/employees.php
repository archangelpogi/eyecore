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
    
    // Initialize RBACHelper with PDO
    RBACHelper::init($pdo);
    
    // Load permissions to session if not already loaded
    if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
        RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
    }

    // Include and instantiate EmployeesAPI class
    $employeesAPI = new EmployeesAPI($pdo);
    $employeesAPI->handleRequest();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage(),
        'trace' => $DEV_MODE ? $e->getTraceAsString() : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

class EmployeesAPI {
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
            $this->sendResponse(401, ['success' => false, 'error' => 'Unauthorized']);
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
            error_log("=== Employees API Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Has users_view: " . ($this->canViewEmployees() ? 'YES' : 'NO'));
        }
    }
    
    // ============= RBAC PERMISSION METHODS =============
    private function hasPermission($permission_name) {
        return RBACHelper::hasPermission($permission_name);
    }
    
    private function canViewEmployees() {
        return $this->hasPermission('users_view');
    }
    
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        try {
            switch ($method) {
                case 'GET':
                    $this->handleGet();
                    break;
                default:
                    $this->sendResponse(405, ['success' => false, 'error' => 'Method not allowed']);
            }
        } catch (Exception $e) {
            $this->sendResponse(500, ['success' => false, 'error' => $e->getMessage()]);
        }
    }
    
    private function handleGet() {
        // ✅ Check permission
        if (!$this->canViewEmployees()) {
            $this->sendResponse(403, ['success' => false, 'error' => 'You do not have permission to view employees']);
            return;
        }
        
        $active_only = isset($_GET['active_only']) && $_GET['active_only'] == 'true';
        $has_salary = isset($_GET['has_salary']) && $_GET['has_salary'] == 'true';
        
        try {
            $sql = "
                SELECT 
                    e.id,
                    e.employee_no,
                    e.basic_salary,
                    e.employment_type,
                    e.status as employment_status,
                    CONCAT(u.first_name, ' ', u.last_name) as full_name,
                    u.first_name,
                    u.last_name,
                    u.role
                FROM employees e
                INNER JOIN users u ON e.user_id = u.id
                WHERE u.clinic_id = :clinic_id
            ";
            
            $params = [':clinic_id' => $this->clinic_id];
            
            if ($active_only) {
                $sql .= " AND e.status = 'Active' AND u.status = 'Active'";
            }
            
            if ($has_salary) {
                $sql .= " AND e.basic_salary > 0";
            }
            
            $sql .= " ORDER BY u.last_name, u.first_name";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $this->sendResponse(200, $employees);
            
        } catch (Exception $e) {
            error_log("Error in getEmployees: " . $e->getMessage());
            $this->sendResponse(500, ['success' => false, 'error' => 'Failed to fetch employees: ' . $e->getMessage()]);
        }
    }
    
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