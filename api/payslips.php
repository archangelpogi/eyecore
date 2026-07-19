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
    error_log("Payslips API Request: " . $_SERVER['REQUEST_METHOD'] . " " . json_encode($_REQUEST));

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

    $payslipBackend = new PayslipBackend($pdo);
    $payslipBackend->handleRequest();

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Exception: ' . $e->getMessage(),
        'trace' => $DEV_MODE ? $e->getTraceAsString() : null
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

class PayslipBackend {
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
            error_log("=== Payslip Backend Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Has payslip_view: " . ($this->canView() ? 'YES' : 'NO'));
            error_log("Has payslip_create: " . ($this->canCreate() ? 'YES' : 'NO'));
            error_log("Has payslip_release: " . ($this->canRelease() ? 'YES' : 'NO'));
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
        return $this->hasPermission('payslip_view');
    }
    
    private function canCreate() {
        return $this->hasPermission('payslip_create');
    }
    
    private function canEdit() {
        return $this->hasPermission('payslip_edit');
    }
    
    private function canDelete() {
        return $this->hasPermission('payslip_delete');
    }
    
    private function canRelease() {
        return $this->hasPermission('payslip_release');
    }
    
    private function canBulkRelease() {
        return $this->hasPermission('payslip_bulk') || $this->canRelease();
    }
    
    private function canDownload() {
        return $this->hasPermission('payslip_download') || $this->canView();
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
        return $this->user_role;
    }
    
    public function getCurrentUserPermissions() {
        $role = $this->getCurrentUserRole();
        $hasHR = $this->checkHRExists();
        
        $permissions = [
            'view_all' => $this->canView(),
            'view_own' => $this->canView(),
            'generate' => $this->canCreate(),
            'upload' => $this->canCreate(),
            'release' => $this->canRelease(),
            'bulk_release' => $this->canBulkRelease(),
            'delete' => $this->canDelete(),
            'export' => $this->canExport(),
            'download' => $this->canDownload()
        ];
        
        // Override for ClinicAdmin in oversight mode
        if ($role === 'ClinicAdmin' && $hasHR && !$this->canCreate()) {
            $permissions = [
                'view_all' => true,
                'view_own' => true,
                'generate' => false,
                'upload' => false,
                'release' => true,
                'bulk_release' => true,
                'delete' => false,
                'export' => true,
                'download' => true
            ];
        }
        
        // Debug
        error_log("=== Payslip Permissions ===");
        error_log("view_all: " . ($permissions['view_all'] ? 'true' : 'false'));
        error_log("generate: " . ($permissions['generate'] ? 'true' : 'false'));
        error_log("release: " . ($permissions['release'] ? 'true' : 'false'));
        
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
            $this->sendResponse(403, ['error' => 'You do not have permission to view payslips']);
            return;
        }
        
        if (isset($_GET['payslip_id'])) {
            $this->getPayslipDetails($_GET['payslip_id']);
            return;
        }
        
        if (isset($_GET['download'])) {
            if (!isset($_GET['payslip_id'])) {
                $this->sendResponse(400, ['error' => 'Payslip ID required for download']);
            }
            $this->downloadPayslip($_GET['payslip_id']);
            return;
        }
        
        if (isset($_GET['employee_id'])) {
            $this->getEmployeePayslips($_GET['employee_id']);
            return;
        }
        
        $this->getAllPayslips();
    }
    
    private function getPayslipDetails($payslip_id) {
        $stmt = $this->pdo->prepare("
            SELECT ps.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                   e.employee_no,
                   p.position_name,
                   py.payroll_period,
                   py.period_start,
                   py.period_end,
                   py.gross_pay,
                   py.total_deductions,
                   py.net_pay,
                   CONCAT(au.first_name, ' ', au.last_name) as released_by_name
            FROM payslips ps
            JOIN payroll py ON ps.payroll_id = py.id
            JOIN employees e ON ps.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            LEFT JOIN positions p ON e.position_id = p.id
            LEFT JOIN users au ON ps.released_by = au.id
            WHERE ps.id = ? AND u.clinic_id = ?
        ");
        
        $stmt->execute([$payslip_id, $this->clinic_id]);
        $payslip = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payslip) {
            $this->sendResponse(404, ['error' => 'Payslip not found']);
        }
        
        // Get payroll breakdown
        $stmt = $this->pdo->prepare("SELECT * FROM payroll WHERE id = ?");
        $stmt->execute([$payslip['payroll_id']]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Log view action
        $this->logAudit('VIEW', 'payslips', $payslip_id, null, null);
        
        $this->sendResponse(200, [
            'success' => true,
            'data' => $payslip,
            'breakdown' => $payroll
        ]);
    }
    
    private function downloadPayslip($payslip_id) {
        if (!$this->canDownload()) {
            $this->sendResponse(403, ['error' => 'You do not have permission to download payslips']);
            return;
        }
        
        $stmt = $this->pdo->prepare("
            SELECT ps.file_path, e.user_id
            FROM payslips ps
            JOIN employees e ON ps.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE ps.id = ? AND u.clinic_id = ?
        ");
        
        $stmt->execute([$payslip_id, $this->clinic_id]);
        $payslip = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payslip) {
            $this->sendResponse(404, ['error' => 'Payslip not found']);
        }
        
        // Check if user can only view own
        $canViewAll = $this->canView();
        if (!$canViewAll && $payslip['user_id'] != $this->user_id) {
            $this->sendResponse(403, ['error' => 'You can only download your own payslips']);
        }
        
        if (!$payslip['file_path'] || !file_exists(__DIR__ . '/../' . $payslip['file_path'])) {
            $this->sendResponse(404, ['error' => 'Payslip file not found']);
        }
        
        $this->logAudit('DOWNLOAD', 'payslips', $payslip_id, null, null);
        
        $file_path = __DIR__ . '/../' . $payslip['file_path'];
        $file_name = basename($file_path);
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        readfile($file_path);
        exit;
    }
    
    private function getEmployeePayslips($employee_id) {
        // If can only view own, check if employee belongs to current user
        $canViewAll = $this->canView();
        if (!$canViewAll) {
            $stmt = $this->pdo->prepare("
                SELECT e.id 
                FROM employees e
                WHERE e.id = ? AND e.user_id = ?
            ");
            $stmt->execute([$employee_id, $this->user_id]);
            if (!$stmt->fetch()) {
                $this->sendResponse(403, ['error' => 'You can only view your own payslips']);
            }
        }
        
        $stmt = $this->pdo->prepare("
            SELECT ps.*, 
                   py.payroll_period,
                   py.period_start,
                   py.period_end,
                   py.net_pay,
                   CONCAT(au.first_name, ' ', au.last_name) as released_by_name
            FROM payslips ps
            JOIN payroll py ON ps.payroll_id = py.id
            LEFT JOIN users au ON ps.released_by = au.id
            WHERE ps.employee_id = ?
            ORDER BY py.period_end DESC
        ");
        
        $stmt->execute([$employee_id]);
        
        $this->sendResponse(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
    private function getAllPayslips() {
        $month = $_GET['month'] ?? null;
        $status = $_GET['status'] ?? null;
        $employee_id = $_GET['employee_filter'] ?? null;
        
        $query = "
            SELECT ps.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as employee_name,
                   e.employee_no,
                   p.position_name,
                   py.payroll_period,
                   py.period_start,
                   py.period_end,
                   py.net_pay,
                   CONCAT(au.first_name, ' ', au.last_name) as released_by_name
            FROM payslips ps
            JOIN payroll py ON ps.payroll_id = py.id
            JOIN employees e ON ps.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            LEFT JOIN positions p ON e.position_id = p.id
            LEFT JOIN users au ON ps.released_by = au.id
            WHERE u.clinic_id = ?
        ";
        
        $params = [$this->clinic_id];
        
        if ($month) {
            $query .= " AND DATE_FORMAT(py.period_end, '%Y-%m') = ?";
            $params[] = $month;
        }
        
        if ($status === 'released') {
            $query .= " AND ps.released_at IS NOT NULL";
        } elseif ($status === 'pending') {
            $query .= " AND ps.released_at IS NULL";
        }
        
        if ($employee_id) {
            $query .= " AND ps.employee_id = ?";
            $params[] = $employee_id;
        }
        
        $query .= " ORDER BY py.period_end DESC, ps.created_at DESC";
        
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        
        $payslips = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logAudit('VIEW_LIST', 'payslips', null, null, ['count' => count($payslips)]);
        
        $this->sendResponse(200, $payslips);
    }
    
    // ============= POST HANDLERS =============
    private function handlePost() {
        error_log("=== PAYSLIPS POST START ===");
        
        if (isset($_FILES['payslip_file'])) {
            $this->uploadPayslipFile();
            return;
        }
        
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
        
        if (isset($data['generate_payslip'])) {
            if (!$this->canCreate()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to generate payslips']);
                return;
            }
            $this->generatePayslip($data);
        } elseif (isset($data['release_payslip'])) {
            if (!$this->canRelease()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to release payslips']);
                return;
            }
            $this->releasePayslip($data);
        } elseif (isset($data['bulk_release_payslips'])) {
            if (!$this->canBulkRelease()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to bulk release payslips']);
                return;
            }
            $this->bulkReleasePayslips($data);
        } elseif (isset($data['delete_payslip'])) {
            if (!$this->canDelete()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to delete payslips']);
                return;
            }
            $this->deletePayslip($data);
        } else {
            $this->sendResponse(400, ['error' => 'Invalid request action']);
        }
    }
    
    private function uploadPayslipFile() {
        if (!$this->canCreate()) {
            $this->sendResponse(403, ['error' => 'You do not have permission to upload payslips']);
            return;
        }
        
        $payslip_id = $_POST['payslip_id'] ?? null;
        
        if (!$payslip_id) {
            $this->sendResponse(400, ['error' => 'Payslip ID required']);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT ps.*, e.user_id
            FROM payslips ps
            JOIN employees e ON ps.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE ps.id = ? AND u.clinic_id = ?
        ");
        
        $stmt->execute([$payslip_id, $this->clinic_id]);
        $payslip = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payslip) {
            $this->sendResponse(404, ['error' => 'Payslip not found']);
        }
        
        $file = $_FILES['payslip_file'];
        
        $allowed_types = ['application/pdf'];
        $max_size = 5 * 1024 * 1024;
        
        if (!in_array($file['type'], $allowed_types)) {
            $this->sendResponse(400, ['error' => 'Only PDF files are allowed']);
        }
        
        if ($file['size'] > $max_size) {
            $this->sendResponse(400, ['error' => 'File size exceeds 5MB limit']);
        }
        
        $upload_dir = __DIR__ . "/../uploads/payslips/" . date('Y/m');
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file_ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = "payslip_{$payslip['payslip_code']}_" . time() . ".{$file_ext}";
        $file_path = "uploads/payslips/" . date('Y/m') . "/{$filename}";
        $full_path = __DIR__ . "/../" . $file_path;
        
        $old_file = $payslip['file_path'];
        $old_values = ['file_path' => $old_file];
        
        if (move_uploaded_file($file['tmp_name'], $full_path)) {
            $stmt = $this->pdo->prepare("UPDATE payslips SET file_path = ? WHERE id = ?");
            $stmt->execute([$file_path, $payslip_id]);
            
            $this->logAudit('UPLOAD', 'payslips', $payslip_id, $old_values, ['file_path' => $file_path]);
            
            if ($old_file && file_exists(__DIR__ . '/../' . $old_file)) {
                unlink(__DIR__ . '/../' . $old_file);
            }
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payslip file uploaded successfully',
                'file_path' => $file_path
            ]);
        } else {
            $this->sendResponse(500, ['error' => 'Failed to upload file']);
        }
    }
    
    private function generatePayslip($data) {
        $payroll_id = $data['payroll_id'];
        $employee_id = $data['employee_id'];
        
        $stmt = $this->pdo->prepare("
            SELECT py.* 
            FROM payroll py
            JOIN employees e ON py.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE py.id = ? AND u.clinic_id = ?
        ");
        
        $stmt->execute([$payroll_id, $this->clinic_id]);
        $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payroll) {
            $this->sendResponse(404, ['error' => 'Payroll not found']);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT id FROM payslips 
            WHERE payroll_id = ? AND employee_id = ?
        ");
        
        $stmt->execute([$payroll_id, $employee_id]);
        
        if ($stmt->fetch()) {
            $this->sendResponse(400, ['error' => 'Payslip already exists for this payroll']);
        }
        
        $payslip_code = 'PSL-' . date('Ymd') . '-' . str_pad($employee_id, 4, '0', STR_PAD_LEFT);
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO payslips 
                (payroll_id, employee_id, payslip_code, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            
            $stmt->execute([$payroll_id, $employee_id, $payslip_code]);
            $payslip_id = $this->pdo->lastInsertId();
            
            $new_values = [
                'payroll_id' => $payroll_id,
                'employee_id' => $employee_id,
                'payslip_code' => $payslip_code
            ];
            $this->logAudit('GENERATE', 'payslips', $payslip_id, null, $new_values);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Payslip generated successfully',
                'payslip_id' => $payslip_id,
                'payslip_code' => $payslip_code
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => 'Failed to generate payslip: ' . $e->getMessage()]);
        }
    }
    
    private function releasePayslip($data) {
        $payslip_id = $data['payslip_id'];
        
        $stmt = $this->pdo->prepare("
            SELECT released_at, released_by 
            FROM payslips 
            WHERE id = ?
        ");
        $stmt->execute([$payslip_id]);
        $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                UPDATE payslips 
                SET released_at = NOW(),
                    released_by = ?
                WHERE id = ?
                AND employee_id IN (
                    SELECT e.id FROM employees e
                    JOIN users u ON e.user_id = u.id
                    WHERE u.clinic_id = ?
                )
            ");
            
            $stmt->execute([$this->user_id, $payslip_id, $this->clinic_id]);
            
            if ($stmt->rowCount() > 0) {
                $new_values = ['released_at' => date('Y-m-d H:i:s'), 'released_by' => $this->user_id];
                $this->logAudit('RELEASE', 'payslips', $payslip_id, $old_values, $new_values);
                
                $this->pdo->commit();
                
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Payslip released successfully'
                ]);
            } else {
                $this->pdo->rollBack();
                $this->sendResponse(400, ['error' => 'Failed to release payslip']);
            }
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function bulkReleasePayslips($data) {
        $payroll_id = $data['payroll_id'];
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT ps.id, ps.released_at, ps.released_by
                FROM payslips ps
                JOIN employees e ON ps.employee_id = e.id
                JOIN users u ON e.user_id = u.id
                WHERE ps.payroll_id = ? 
                  AND u.clinic_id = ?
                  AND ps.released_at IS NULL
            ");
            
            $stmt->execute([$payroll_id, $this->clinic_id]);
            $payslips = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $released_count = 0;
            $released_ids = [];
            
            foreach ($payslips as $payslip) {
                $old_values = ['released_at' => $payslip['released_at'], 'released_by' => $payslip['released_by']];
                
                $stmt = $this->pdo->prepare("
                    UPDATE payslips 
                    SET released_at = NOW(),
                        released_by = ?
                    WHERE id = ?
                ");
                
                $stmt->execute([$this->user_id, $payslip['id']]);
                
                if ($stmt->rowCount() > 0) {
                    $released_count++;
                    $released_ids[] = $payslip['id'];
                    
                    $new_values = ['released_at' => date('Y-m-d H:i:s'), 'released_by' => $this->user_id];
                    $this->logAudit('RELEASE', 'payslips', $payslip['id'], $old_values, $new_values);
                }
            }
            
            $bulk_info = [
                'action' => 'bulk_release',
                'payroll_id' => $payroll_id,
                'released_count' => $released_count,
                'released_ids' => $released_ids
            ];
            $this->logAudit('BULK_ACTION', 'payslips', null, null, $bulk_info);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => "Released {$released_count} payslips successfully"
            ]);
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function deletePayslip($data) {
        $payslip_id = $data['payslip_id'];
        
        $stmt = $this->pdo->prepare("
            SELECT file_path, payroll_id, employee_id, payslip_code, released_at, released_by
            FROM payslips 
            WHERE id = ?
            AND employee_id IN (
                SELECT e.id FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE u.clinic_id = ?
            )
        ");
        $stmt->execute([$payslip_id, $this->clinic_id]);
        $old_values = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_values) {
            $this->sendResponse(404, ['error' => 'Payslip not found or access denied']);
        }
        
        $this->pdo->beginTransaction();
        
        try {
            $stmt = $this->pdo->prepare("DELETE FROM payslips WHERE id = ?");
            $stmt->execute([$payslip_id]);
            
            if ($stmt->rowCount() > 0) {
                $this->logAudit('DELETE', 'payslips', $payslip_id, $old_values, null);
                
                $this->pdo->commit();
                
                if ($old_values['file_path'] && file_exists(__DIR__ . '/../' . $old_values['file_path'])) {
                    unlink(__DIR__ . '/../' . $old_values['file_path']);
                }
                
                $this->sendResponse(200, [
                    'success' => true,
                    'message' => 'Payslip deleted successfully'
                ]);
            } else {
                $this->pdo->rollBack();
                $this->sendResponse(400, ['error' => 'Failed to delete payslip']);
            }
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            $this->sendResponse(500, ['error' => $e->getMessage()]);
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