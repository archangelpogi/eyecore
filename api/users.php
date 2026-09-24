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
    error_log("API Request: " . $_SERVER['REQUEST_METHOD'] . " " . json_encode($_REQUEST));

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

    // Debug PHPMailer path
    $phpmailerPath = __DIR__ . '/../PHPMailer/';
    error_log("PHPMailer path: " . $phpmailerPath);
    
    if (!file_exists($phpmailerPath . 'PHPMailer.php')) {
        error_log("PHPMailer files not found at: " . $phpmailerPath);
        // Don't throw error, just log - email is optional
    } else {
        // Include PHPMailer if needed
        require $phpmailerPath . 'PHPMailer.php';
        require $phpmailerPath . 'SMTP.php';
        require $phpmailerPath . 'Exception.php';
    }

    // Include your EmployeeBackend class here
    $employeeBackend = new EmployeeBackend($pdo);
    $employeeBackend->handleRequest();

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

class EmployeeBackend {
    private $pdo;
    private $clinic_id;
    private $user_id;
    private $allowedRoles;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->allowedRoles = ['HR', 'Finance', 'CRM', 'SCM', 'Staff', 'Optometrist', 'Rider'];
        
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
        
        // ✅ Load permissions to session if not already loaded
        if (!isset($_SESSION['permissions'])) {
            RBACHelper::loadPermissionsToSession($this->user_id, $this->clinic_id);
        }
        
        // Debug for Mean Mendoza
        if ($this->user_id == 161) {
            error_log("=== Mean Mendoza Auth Debug ===");
            error_log("User ID: " . $this->user_id);
            error_log("Clinic ID: " . $this->clinic_id);
            error_log("Permissions in session: " . count($_SESSION['permissions'] ?? []) . " permissions");
            error_log("Has users_view: " . (in_array('users_view', $_SESSION['permissions'] ?? []) ? 'YES' : 'NO'));
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
        if (!isset($_SESSION['user_id'])) {
            return null;
        }
        
        $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['role'] : null;
    }

    // ✅ RBAC Permission Methods
    private function hasPermission($permissionName) {
        return RBACHelper::hasPermission($permissionName);
    }

    private function canView($module) {
        return $this->hasPermission($module . '_view');
    }

    private function canCreate($module) {
        return $this->hasPermission($module . '_create');
    }

    private function canEdit($module) {
        return $this->hasPermission($module . '_edit');
    }

    private function canDelete($module) {
        return $this->hasPermission($module . '_delete');
    }

    private function canApprove($module) {
        return $this->hasPermission($module . '_approve');
    }

    private function canReject($module) {
        return $this->hasPermission($module . '_reject');
    }
    
    // Legacy permission method for backward compatibility
    private function hasLegacyPermission($permission) {
        $permissionMap = [
            'employee.create'               => 'employees',
            'employee.read'                 => 'employees',
            'employee.update'               => 'employees',
            'employee.delete'               => 'employees',
            'employee.export'               => 'employees',
            'employee.manage_documents'     => 'employees',
            'employee.view_salary'          => 'employees',
            'employee.manage_government_ids'=> 'employees',
            'employee.oversight'            => 'employees',
            'employee.view_basic'           => 'employees',
            'employee.read_own'             => 'employees',
            'payroll.manage'                => 'payroll',
            'reports.view'                  => 'reports',
            'reports.manage'                => 'reports',
            'audit.view'                    => 'logs',
            'settings.manage'               => 'settings'
        ];
        
        $module = $permissionMap[$permission] ?? null;
        if (!$module) {
            return false;
        }
        
        if (strpos($permission, 'create') !== false || 
            strpos($permission, 'update') !== false || 
            strpos($permission, 'delete') !== false ||
            strpos($permission, 'manage') !== false) {
            return $this->canEdit($module);
        }
        
        return $this->canView($module);
    }
    
    private function getUserPermissions($role = null) {
        return [];
    }
    
    public function getCurrentUserPermissions() {
        $role = $this->getCurrentUserRole();
        return [
            'role' => $role,
            'permissions' => [
                'can_view_employees'    => $this->canView('users'),
                'can_create_employees'  => $this->canCreate('users'),
                'can_edit_employees'    => $this->canEdit('users'),
                'can_delete_employees'  => $this->canDelete('users'),
                'can_view_salary'       => $this->canView('salary-history'),
                'can_manage_documents'  => $this->canEdit('users'),
                'can_view_positions'    => $this->canView('positions'),
                'can_edit_positions'    => $this->canEdit('positions')
            ],
            'hasHR' => $this->checkHRExists()
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
                    if (isset($_FILES['document'])) {
                        $this->handleDocumentUpload();
                    } else {
                        $this->handlePost();
                    }
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
        // Debug for Mean Mendoza
        if ($this->user_id == 161) {
            error_log("=== Mean Mendoza Permission Check ===");
            error_log("Can view users: " . ($this->canView('users') ? 'YES' : 'NO'));
            error_log("Can create users: " . ($this->canCreate('users') ? 'YES' : 'NO'));
            error_log("Can edit users: " . ($this->canEdit('users') ? 'YES' : 'NO'));
            error_log("Can delete users: " . ($this->canDelete('users') ? 'YES' : 'NO'));
        }
        
        if (isset($_GET['user_id'])) {
            $this->getUserDetails($_GET['user_id']);
        } elseif (isset($_GET['get_positions'])) {
            $this->getAllPositions();
        } elseif (isset($_GET['get_riders'])) {
            $this->getRidersList();
        } elseif (isset($_GET['get_documents'])) {
            $this->getEmployeeDocuments($_GET['get_documents']);
        } elseif (isset($_GET['get_permissions'])) {
            $this->sendResponse(200, [
                'success' => true,
                'data' => $this->getCurrentUserPermissions()
            ]);
        } else {
            $this->getAllUsers();
        }
    }
    
    private function getUserDetails($user_id) {
        if (!$this->canView('users')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to view employee details']);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                u.*,
                e.*,
                p.position_name,
                p.salary_rate as position_salary,
                p.department,
                r.vehicle_type AS rider_vehicle_type,
                r.plate_number AS rider_plate_number,
                r.driver_license_no AS rider_driver_license_no,
                r.license_expiration_date AS rider_license_expiration_date,
                r.vehicle_brand_model AS rider_vehicle_brand_model,
                r.or_cr_number AS rider_or_cr_number
            FROM users u
            LEFT JOIN employees e ON u.id = e.user_id
            LEFT JOIN positions p ON e.position_id = p.id
            LEFT JOIN riders r ON u.id = r.user_id
            WHERE u.id = ? AND u.clinic_id = ?
        ");
        
        $stmt->execute([$user_id, $this->clinic_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            $this->sendResponse(404, ['error' => 'User not found']);
        }
        
        $role = $this->getCurrentUserRole();
        if ($role === 'ClinicAdmin' && !$this->canEdit('users')) {
            unset($user['basic_salary']);
            unset($user['sss_number']);
            unset($user['philhealth_number']);
            unset($user['pagibig_number']);
            unset($user['tin_number']);
            unset($user['bank_account_number']);
        }
        
        if (isset($user['employee_id'])) {
            if ($this->canEdit('users')) {
                $docStmt = $this->pdo->prepare("
                    SELECT COUNT(*) as doc_count 
                    FROM employee_documents 
                    WHERE employee_id = ?
                ");
                $docStmt->execute([$user['employee_id']]);
                $docCount = $docStmt->fetch(PDO::FETCH_ASSOC);
                $user['document_count'] = $docCount['doc_count'] ?? 0;
            } else {
                $user['document_count'] = 0;
            }
        }
        
        unset($user['password']);
        $this->sendResponse(200, ['success' => true, 'data' => $user]);
    }
    
    private function getAllPositions() {
        if (!$this->canView('positions')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to view positions']);
        }
        
        $stmt = $this->pdo->prepare("SELECT * FROM positions ORDER BY position_name");
        $stmt->execute();
        $this->sendResponse(200, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
    
        private function getRidersList() {
        if (!$this->canView('users')) {
            $this->sendResponse(403, ['error' => 'No permission to view riders']);
        }
        
        $stmt = $this->pdo->prepare("
            SELECT 
                r.id, r.name, r.phone, r.email, r.vehicle_type, r.plate_number,
                r.is_available, r.status, r.user_id,
                u.status as user_status
            FROM riders r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.clinic_id = ?
            ORDER BY r.is_available DESC, r.name ASC
        ");
        $stmt->execute([$this->clinic_id]);
        $this->sendResponse(200, [
            'success' => true,
            'riders'  => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ]);
    }


    private function getAllUsers() {
        if (!$this->canView('users')) {
            $this->sendResponse(403, [
                'error' => 'You do not have permission to view employees',
                'role' => $this->getCurrentUserRole()
            ]);
        }
        
        $in = str_repeat('?,', count($this->allowedRoles) - 1) . '?';
        
        $stmt = $this->pdo->prepare("
            SELECT 
                u.id,
                u.user_code,
                u.first_name,
                u.last_name,
                u.email,
                u.role,
                u.status as user_status,
                u.created_at,
                e.employee_no,
                e.position_id,
                p.position_name,
                p.department,
                e.employment_type,
                e.date_hired,
                e.basic_salary,
                e.status as employment_status
            FROM users u
            LEFT JOIN employees e ON u.id = e.user_id
            LEFT JOIN positions p ON e.position_id = p.id
            WHERE u.clinic_id = ?
              AND u.role IN ($in)
            ORDER BY u.created_at DESC
        ");
        
        $params = array_merge([$this->clinic_id], $this->allowedRoles);
        $stmt->execute($params);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $role = $this->getCurrentUserRole();
        if ($role === 'ClinicAdmin' && !$this->canEdit('users')) {
            foreach ($users as &$user) {
                unset($user['basic_salary']);
            }
        }
        
        $this->sendResponse(200, $users);
    }
    
    private function getEmployeeDocuments($employee_id) {
        try {
            error_log("=== GET DOCUMENTS START ===");
            error_log("Employee ID: $employee_id");
            
            if (!$this->canView('users')) {
                $this->sendResponse(403, ['error' => 'You do not have permission to view documents']);
            }
            
            $this->verifyEmployeeAccess($employee_id);
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    d.id,
                    d.employee_id,
                    d.document_type,
                    d.document_name,
                    d.file_path,
                    COALESCE(d.uploaded_at, NOW()) as uploaded_at
                FROM employee_documents d
                WHERE d.employee_id = ? 
                ORDER BY d.uploaded_at DESC, d.id DESC
            ");
            
            $stmt->execute([$employee_id]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($documents) . " documents in database");
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https://" : "http://";
            $host     = $_SERVER['HTTP_HOST'];
            $basePath = '/';
            
            foreach ($documents as &$doc) {
                error_log("Processing document: {$doc['document_name']}");
                error_log("Original file_path: {$doc['file_path']}");
                
                $filename            = basename($doc['file_path']);
                $correctRelativePath = "uploads/employee_documents/employee_{$employee_id}/{$filename}";
                
                $doc['file_url_correct']  = $protocol . $host . $basePath . '/' . $correctRelativePath;
                $doc['file_url_relative'] = $basePath . '/' . $correctRelativePath;
                
                $absolutePath = $_SERVER['DOCUMENT_ROOT'] . $basePath . '/' . $correctRelativePath;
                $absolutePath = str_replace('//', '/', $absolutePath);
                
                error_log("Absolute path to check: $absolutePath");
                
                if (file_exists($absolutePath)) {
                    $doc['file_exists']          = true;
                    $doc['file_size']             = filesize($absolutePath);
                    $doc['file_size_formatted']   = $this->formatFileSize($doc['file_size']);
                    error_log("File EXISTS!");
                } else {
                    $doc['file_exists'] = false;
                    error_log("File NOT FOUND!");
                    
                    $altPath = __DIR__ . '/../../' . $correctRelativePath;
                    if (file_exists($altPath)) {
                        $doc['file_exists']        = true;
                        $doc['file_size']           = filesize($altPath);
                        $doc['file_size_formatted'] = $this->formatFileSize($doc['file_size']);
                        error_log("Found at alternative: $altPath");
                    }
                }
                
                error_log("Correct URL: {$doc['file_url_correct']}");
                
                if (isset($doc['uploaded_at'])) {
                    $doc['upload_date_formatted'] = date('F j, Y h:i A', strtotime($doc['uploaded_at']));
                }
            }
            
            $this->sendResponse(200, [
                'success'   => true, 
                'documents' => $documents,
                'debug'     => [
                    'base_url'      => $protocol . $host . $basePath,
                    'document_root' => $_SERVER['DOCUMENT_ROOT']
                ]
            ]);
        } catch (Exception $e) {
            error_log("Error in getEmployeeDocuments: " . $e->getMessage());
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
   
    // ============= POST REQUEST HANDLERS =============
    private function handlePost() {
        error_log("=== HANDLE POST START ===");
        error_log("Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
        error_log("REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD']);
        error_log("POST data: " . print_r($_POST, true));
        error_log("FILES data: " . print_r($_FILES, true));

        // Option 1: Files uploaded (FormData)
        if (!empty($_FILES)) {
            error_log("Detected as FormData (has FILES)");
            
            if (isset($_POST['add_user_with_employee']) && $_POST['add_user_with_employee'] == true) {
                error_log("Route: addUserWithEmployeeAndDocuments");
                $this->addUserWithEmployeeAndDocuments($_POST, $_FILES);
            } else {
                error_log("Route: handleDocumentUpload");
                $this->handleDocumentUpload();
            }
            return;
        }
        
        // Option 2: Multipart but no files
        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        if (strpos($contentType, 'multipart/form-data') !== false) {
            error_log("Detected as FormData (multipart content-type)");
            
            if (isset($_POST['add_user_with_employee']) && $_POST['add_user_with_employee'] == true) {
                error_log("Route: addUserWithEmployeeAndDocuments (no files)");
                $this->addUserWithEmployeeAndDocuments($_POST, []);
            } else {
                error_log("Route: handleDocumentUpload (no files)");
                $this->handleDocumentUpload();
            }
            return;
        }
        
        // Option 3: JSON request
        error_log("Detected as JSON request");
        
        $input = file_get_contents('php://input');
        error_log("Raw input length: " . strlen($input));
        error_log("Raw input (first 500 chars): " . substr($input, 0, 500));
        
        if (empty($input)) {
            error_log("ERROR: No input data");
            $this->sendResponse(400, ['error' => 'No input data']);
            return;
        }

        $data = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON decode error: " . json_last_error_msg());
            $this->sendResponse(400, [
                'error' => 'Invalid JSON: ' . json_last_error_msg()
            ]);
            return;
        }
        
        error_log("Decoded JSON data: " . print_r($data, true));

        if (isset($data['get_positions'])) {
            error_log("Route: getAllPositions");
            $this->getAllPositions();

        } elseif (isset($data['add_position'])) {
            error_log("Route: addPosition");
            $this->addPosition($data);

        } elseif (isset($data['add_user_with_employee'])) {
            error_log("Route: addUserWithEmployee (JSON)");
            $this->addUserWithEmployee($data);

        } elseif (isset($data['update_employee_info'])) {
            error_log("Route: updateEmployeeInfo");
            $this->updateEmployeeInfo($data);

        } elseif (isset($data['toggle_status_id'])) {
            error_log("Route: toggleUserStatus");
            $this->toggleUserStatus($data['toggle_status_id']);

        } elseif (isset($data['delete_document'])) {
            error_log("Route: deleteDocument");
            $this->deleteDocument($data['document_id']);

        } elseif (isset($data['delete_employee_id'])) {
            error_log("Route: deleteEmployee");
            $this->deleteEmployee($data['delete_employee_id']);

        } else {
            error_log("ERROR: Invalid request action");
            $this->sendResponse(400, ['error' => 'Invalid request action']);
        }
    }

    // ============= CREATE USER + EMPLOYEE (JSON) =============
    private function addUserWithEmployee($data) {
        if (!$this->canCreate('users')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to create employees']);
        }
        
        $inTransaction = $this->pdo->inTransaction();
        if (!$inTransaction) {
            $this->pdo->beginTransaction();
        }
        
        try {
            $this->validateUserData($data);
            $this->checkEmailExists($data['email']);
            
            $user_code   = $this->generateUserCode($data['role']);
            $user_id     = $this->createUser($data, $user_code);
            $employee_no = $this->generateEmployeeNumber();
            $employee_id = $this->createEmployee($user_id, $data, $employee_no);
            $this->recordInitialSalary($employee_id, $data['basic_salary']);
            
            // ✅ Insert into doctors table if role is Optometrist
            $doctor_id = null;
            if ($data['role'] === 'Optometrist') {
                $doctor_id = $this->createDoctorRecord($user_id, $data);
            }

                        // ✅ Insert into riders table if role is Rider
            $rider_id = null;
            if ($data['role'] === 'Rider') {
                $rider_id = $this->createRiderRecord($user_id, $data);
            }
            
            // Audit: User creation
            $this->logAudit('CREATE', 'users', $user_id, null, [
                'user_code'  => $user_code,
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'],
                'email'      => $data['email'],
                'role'       => $data['role']
            ]);
            
            // Audit: Employee creation
            $this->logAudit('CREATE', 'employees', $employee_id, null, [
                'employee_no'     => $employee_no,
                'position_id'     => $data['position_id'],
                'employment_type' => $data['employment_type'],
                'basic_salary'    => $data['basic_salary']
            ]);
            
            $emailSent = false;
            try {
                $emailSent = $this->sendWelcomeEmail($data, $user_code);
            } catch (Exception $emailError) {
                error_log("Email sending failed: " . $emailError->getMessage());
            }
            
            if (!$inTransaction) {
                $this->pdo->commit();
            }
            
            $this->sendResponse(200, [
                'success'     => true,
                'message'     => 'User and employee record created successfully',
                'user_code'   => $user_code,
                'employee_no' => $employee_no,
                'user_id'     => $user_id,
                'employee_id' => $employee_id,
                'doctor_id'   => $doctor_id,
                'rider_id'    => $rider_id,
                'email_sent'  => $emailSent
            ]);
        } catch (Exception $e) {
            if (!$inTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }

// ============= CREATE USER + EMPLOYEE + DOCUMENTS (FormData) =============
private function addUserWithEmployeeAndDocuments($postData, $files) {
    if (!$this->canCreate('users')) {
        $this->sendResponse(403, ['error' => 'You do not have permission to create employees']);
    }
    
    $this->pdo->beginTransaction();
    
    try {
        $this->validateUserData($postData);
        $this->checkEmailExists($postData['email']);
        
        $user_code   = $this->generateUserCode($postData['role']);
        $user_id     = $this->createUser($postData, $user_code);
        $employee_no = $this->generateEmployeeNumber();
        $employee_id = $this->createEmployee($user_id, $postData, $employee_no);
        $this->recordInitialSalary($employee_id, $postData['basic_salary']);
        
        // ✅ Insert into doctors table if role is Optometrist
        $doctor_id = null;
        if ($postData['role'] === 'Optometrist') {
            $doctor_id = $this->createDoctorRecord($user_id, $postData);
        }
        
        // ✅ INSERT INTO RIDERS TABLE IF ROLE IS RIDER — IDAGDAG ITO!
        $rider_id = null;
        if ($postData['role'] === 'Rider') {
            $rider_id = $this->createRiderRecord($user_id, $postData);
        }
        
        // Audit: User creation
        $this->logAudit('CREATE', 'users', $user_id, null, [
            'user_code'  => $user_code,
            'first_name' => $postData['first_name'],
            'last_name'  => $postData['last_name'],
            'email'      => $postData['email'],
            'role'       => $postData['role']
        ]);
        
        // Audit: Employee creation
        $this->logAudit('CREATE', 'employees', $employee_id, null, [
            'employee_no'     => $employee_no,
            'position_id'     => $postData['position_id'],
            'employment_type' => $postData['employment_type'],
            'basic_salary'    => $postData['basic_salary']
        ]);
        
        $documents_uploaded = 0;
        
        $documentTypes = [
            'add_resume'       => 'Resume',
            'add_government_id'=> 'Government ID',
            'add_medical_cert' => 'Medical Certificate',
            'add_clearance'    => 'Police Clearance'
        ];
        
        foreach ($documentTypes as $field => $docType) {
            if (isset($files[$field . '_file']) && $files[$field . '_file']['error'] === UPLOAD_ERR_OK) {
                $doc_id = $this->uploadSingleDocument($employee_id, $docType, $files[$field . '_file']);
                
                $this->logAudit('UPLOAD', 'employee_documents', $doc_id, null, [
                    'employee_id'   => $employee_id,
                    'document_type' => $docType,
                    'document_name' => $files[$field . '_file']['name']
                ]);
                
                $documents_uploaded++;
            }
        }
        
        $emailSent = false;
        try {
            $emailSent = $this->sendWelcomeEmail($postData, $user_code);
        } catch (Exception $emailError) {
            error_log("Email sending failed: " . $emailError->getMessage());
        }
        
        $this->pdo->commit();
        
        // ✅ Response — may rider_id na
        $this->sendResponse(200, [
            'success'            => true,
            'message'            => 'User and employee record created successfully' . 
                                    ($documents_uploaded > 0 ? ' with ' . $documents_uploaded . ' document(s)' : ''),
            'user_code'          => $user_code,
            'employee_no'        => $employee_no,
            'user_id'            => $user_id,
            'employee_id'        => $employee_id,
            'doctor_id'          => $doctor_id,
            'rider_id'           => $rider_id,   // ✅ IDAGDAG ITO
            'documents_uploaded' => $documents_uploaded,
            'email_sent'         => $emailSent
        ]);
    } catch (Exception $e) {
        $this->pdo->rollBack();
        $this->sendResponse(400, ['error' => $e->getMessage()]);
    }
}
    
    // ============= ✅ CREATE DOCTOR RECORD =============
    private function createDoctorRecord($user_id, $data) {
        try {
            $full_name = trim($data['first_name']) . ' ' . trim($data['last_name']);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO doctors 
                    (clinic_id, name, specialty, schedule, is_active, created_at)
                VALUES 
                    (?, ?, ?, ?, 1, NOW())
            ");
            
            $stmt->execute([
                $this->clinic_id,
                $full_name,
                $data['specialty'] ?? null,
                $data['schedule']  ?? null
            ]);
            
            $doctor_id = $this->pdo->lastInsertId();
            
            // Audit: Doctor creation
            $this->logAudit('CREATE', 'doctors', $doctor_id, null, [
                'clinic_id' => $this->clinic_id,
                'name'      => $full_name,
                'specialty' => $data['specialty'] ?? null,
                'schedule'  => $data['schedule']  ?? null,
                'user_id'   => $user_id
            ]);
            
            error_log("Doctor record created: ID $doctor_id for user_id $user_id");
            return $doctor_id;
            
        } catch (Exception $e) {
            error_log("Failed to create doctor record: " . $e->getMessage());
            throw new Exception('Failed to create doctor record: ' . $e->getMessage());
        }
    }
    
private function createRiderRecord($user_id, $data) {
    try {
        $stmt = $this->pdo->prepare("
            SELECT first_name, last_name, email, contact, password 
            FROM users WHERE id = ?
        ");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('User not found for rider creation');
        }
        
        $full_name = trim($user['first_name'] . ' ' . $user['last_name']);
        
        // ✅ FIXED: 14 placeholders + NOW() + NOW()
        $stmt = $this->pdo->prepare("
            INSERT INTO riders (
                user_id, clinic_id, name, phone, email, password,
                driver_license_no, license_expiration_date,
                vehicle_type, vehicle_brand_model, plate_number, or_cr_number,
                status, is_available,
                created_at, updated_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        
        // ✅ FIXED: 14 values (including is_available = 1)
        $stmt->execute([
            $user_id,                                        // 1
            $this->clinic_id,                                // 2
            $full_name,                                      // 3
            $data['phone_number'] ?? $user['contact'] ?? null, // 4
            $user['email'],                                  // 5
            $user['password'],                               // 6
            $data['driver_license_no'] ?? null,              // 7
            !empty($data['license_expiration_date']) ? $data['license_expiration_date'] : null, // 8
            $data['vehicle_type'] ?? null,                   // 9
            $data['vehicle_brand_model'] ?? null,            // 10
            $data['plate_number'] ?? null,                   // 11
            $data['or_cr_number'] ?? null,                   // 12
            'active',                                        // 13
            1                                                // 14 ✅ is_available
        ]);
        
        $rider_id = $this->pdo->lastInsertId();
        
        // Audit log
        $this->logAudit('CREATE', 'riders', $rider_id, null, [
            'user_id'               => $user_id,
            'clinic_id'             => $this->clinic_id,
            'name'                  => $full_name,
            'driver_license_no'     => $data['driver_license_no'] ?? null,
            'license_expiration_date' => $data['license_expiration_date'] ?? null,
            'vehicle_type'          => $data['vehicle_type'] ?? null,
            'vehicle_brand_model'   => $data['vehicle_brand_model'] ?? null,
            'plate_number'          => $data['plate_number'] ?? null,
            'or_cr_number'          => $data['or_cr_number'] ?? null
        ]);
        
        error_log("Rider record created: ID $rider_id for user_id $user_id");
        return $rider_id;
        
    } catch (Exception $e) {
        error_log("Failed to create rider record: " . $e->getMessage());
        throw new Exception('Failed to create rider record: ' . $e->getMessage());
    }
}
    // ============= ADD POSITION =============
    private function addPosition($data) {
        if (!$this->canCreate('positions')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to add positions']);
        }
        
        try {
            if (empty($data['position_name'])) {
                throw new Exception('Position name is required');
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO positions (position_name, salary_rate, department)
                VALUES (?, ?, ?)
            ");
            
            $stmt->execute([
                $data['position_name'],
                $data['salary_rate'] ?? null,
                $data['department']  ?? null
            ]);
            
            $position_id = $this->pdo->lastInsertId();
            
            $this->logAudit('CREATE', 'positions', $position_id, null, [
                'position_name' => $data['position_name'],
                'salary_rate'   => $data['salary_rate'] ?? null,
                'department'    => $data['department']  ?? null
            ]);
            
            $this->sendResponse(200, [
                'success'     => true,
                'message'     => 'Position added successfully',
                'position_id' => $position_id
            ]);
        } catch (Exception $e) {
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    // ============= UPDATE EMPLOYEE INFO =============
    private function updateEmployeeInfo($data) {
        if (!$this->canEdit('users')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to update employees']);
        }
        
        try {
            if (!isset($data['employee_id'])) {
                throw new Exception('Employee ID is required');
            }
            
            $employee_id    = $data['employee_id'];
            $current_salary = $this->getCurrentSalary($employee_id);
            
            $old_user_data     = null;
            $old_employee_data = null;
            
            if (isset($data['user_id'])) {
                $stmt = $this->pdo->prepare("SELECT first_name, last_name, email, role FROM users WHERE id = ?");
                $stmt->execute([$data['user_id']]);
                $old_user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            $stmt = $this->pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $old_employee_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->pdo->beginTransaction();
            
            if (isset($data['user_id'])) {
                $this->updateUser($data['user_id'], $data);
                
                $new_user_data = [
                    'first_name' => $data['first_name'] ?? $old_user_data['first_name'],
                    'last_name'  => $data['last_name']  ?? $old_user_data['last_name'],
                    'email'      => $data['email']       ?? $old_user_data['email'],
                    'role'       => $data['role']        ?? $old_user_data['role']
                ];
                
                $changes = [];
                foreach ($new_user_data as $key => $value) {
                    if ($old_user_data[$key] != $value) {
                        $changes[$key] = ['old' => $old_user_data[$key], 'new' => $value];
                    }
                }
                
                if (!empty($changes)) {
                    $this->logAudit('UPDATE', 'users', $data['user_id'], $old_user_data, $new_user_data);
                }
            }
            
            $this->updateEmployee($employee_id, $data);

                        // ✅ Update rider-specific fields if role is Rider
            if (isset($data['role']) && $data['role'] === 'Rider' && isset($data['user_id'])) {
                $this->updateRiderInfo($data['user_id'], $data);
            }
            
            $stmt = $this->pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            $new_employee_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $employee_changes = [];
            foreach ($new_employee_data as $key => $value) {
                if (isset($old_employee_data[$key]) && $old_employee_data[$key] != $value && $key != 'updated_at') {
                    $employee_changes[$key] = ['old' => $old_employee_data[$key], 'new' => $value];
                }
            }
            
            if (!empty($employee_changes)) {
                $this->logAudit('UPDATE', 'employees', $employee_id, $old_employee_data, $new_employee_data);
            }
            
            if (isset($data['basic_salary']) && $data['basic_salary'] != $current_salary) {
                $this->recordSalaryChange(
                    $employee_id, 
                    $current_salary, 
                    $data['basic_salary'], 
                    $data['salary_change_reason'] ?? 'Salary adjustment'
                );
            }
            
            $this->pdo->commit();
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Employee information updated successfully'
            ]);
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    // ============= TOGGLE USER STATUS =============
    private function toggleUserStatus($user_id) {
        if (!$this->canEdit('users')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to modify employee status']);
        }
        
        try {
            $stmt = $this->pdo->prepare("SELECT status FROM users WHERE id = :id AND clinic_id = :clinic_id");
            $stmt->execute(['id' => $user_id, 'clinic_id' => $this->clinic_id]);
            $old_status = $stmt->fetchColumn();
            
            $new_status = ($old_status == 'Active') ? 'Inactive' : 'Active';
            
            $stmt = $this->pdo->prepare("
                UPDATE users
                SET status = IF(status='Active','Inactive','Active'),
                    updated_at = NOW()
                WHERE id = :id AND clinic_id = :clinic_id
            ");
            $stmt->execute(['id' => $user_id, 'clinic_id' => $this->clinic_id]);
            
            $stmt = $this->pdo->prepare("
                UPDATE employees e
                JOIN users u ON e.user_id = u.id
                SET e.status = IF(u.status='Active','Active','Inactive'),
                    e.updated_at = NOW()
                WHERE u.id = :id AND u.clinic_id = :clinic_id
            ");
            $stmt->execute(['id' => $user_id, 'clinic_id' => $this->clinic_id]);
            
            // ✅ Also sync is_active in doctors table if Optometrist
            $stmt = $this->pdo->prepare("SELECT role FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $role = $stmt->fetchColumn();
            
            if ($role === 'Optometrist') {
                $is_active = ($new_status === 'Active') ? 1 : 0;
                $full_name = $this->getFullNameByUserId($user_id);
                
                $stmt = $this->pdo->prepare("
                    UPDATE doctors
                    SET is_active = ?
                    WHERE clinic_id = ? AND name = ?
                ");
                $stmt->execute([$is_active, $this->clinic_id, $full_name]);
                error_log("Doctors table is_active synced to $is_active for $full_name");
            }

                        // ✅ Also sync rider status if Rider
            if ($role === 'Rider') {
                $rider_status = ($new_status === 'Active') ? 'active' : 'suspended';
                $is_available = ($new_status === 'Active') ? 1 : 0;
                
                $stmt = $this->pdo->prepare("
                    UPDATE riders
                    SET status = ?, is_available = ?
                    WHERE user_id = ?
                ");
                $stmt->execute([$rider_status, $is_available, $user_id]);
                error_log("Rider status synced to $rider_status for user_id: $user_id");
            }
            
            $this->logAudit('UPDATE', 'users', $user_id,
                ['status' => $old_status],
                ['status' => $new_status]
            );
            
            $this->sendResponse(200, ['success' => true, 'message' => 'User status updated']);
        } catch (Exception $e) {
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    // ============= DELETE EMPLOYEE =============
    private function deleteEmployee($employee_id) {
        if (!$this->canDelete('users')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to delete employees']);
        }
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT e.*, u.id as user_id, u.first_name, u.last_name, u.email, u.role
                FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.id = ? AND u.clinic_id = ?
            ");
            $stmt->execute([$employee_id, $this->clinic_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$employee) {
                throw new Exception('Employee not found');
            }
            
            $this->pdo->beginTransaction();
            
            // Audit before deletion
            $this->logAudit('DELETE', 'employees', $employee_id, $employee, null);
            $this->logAudit('DELETE', 'users', $employee['user_id'],
                ['id' => $employee['user_id'], 'email' => $employee['email']], null
            );
            
            // Delete employee documents
            $stmt = $this->pdo->prepare("DELETE FROM employee_documents WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            
            // Delete salary history
            $stmt = $this->pdo->prepare("DELETE FROM salary_history WHERE employee_id = ?");
            $stmt->execute([$employee_id]);
            
            // ✅ Delete from doctors table if Optometrist
            if ($employee['role'] === 'Optometrist') {
                $full_name = trim($employee['first_name']) . ' ' . trim($employee['last_name']);
                
                $stmt = $this->pdo->prepare("
                    DELETE FROM doctors 
                    WHERE clinic_id = ? AND name = ?
                ");
                $stmt->execute([$this->clinic_id, $full_name]);
                error_log("Doctor record deleted for: $full_name");
            }

                        // ✅ Delete from riders table if Rider
            if ($employee['role'] === 'Rider') {
                $stmt = $this->pdo->prepare("
                    DELETE FROM riders 
                    WHERE user_id = ?
                ");
                $stmt->execute([$employee['user_id']]);
                error_log("Rider record deleted for user_id: " . $employee['user_id']);
            }
            
            // Delete employee record
            $stmt = $this->pdo->prepare("DELETE FROM employees WHERE id = ?");
            $stmt->execute([$employee_id]);
            
            // Delete user record
            $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$employee['user_id']]);
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Employee deleted successfully'
            ]);
            
        } catch (Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    // ============= DOCUMENT MANAGEMENT =============
    private function handleDocumentUpload() {
        try {
            error_log("=== HANDLE DOCUMENT UPLOAD START ===");
            error_log("POST keys: " . implode(', ', array_keys($_POST)));
            error_log("FILES keys: " . implode(', ', array_keys($_FILES)));
            
            if (!$this->canEdit('users')) {
                $this->sendResponse(403, ['error' => 'You do not have permission to upload documents']);
            }
            
            if (!isset($_POST['employee_id']) || empty($_POST['employee_id'])) {
                error_log("ERROR: employee_id missing in POST");
                throw new Exception('Employee ID is required');
            }
            
            $employee_id   = $_POST['employee_id'];
            $document_type = $_POST['document_type'] ?? 'Other';
            
            error_log("Processing for employee_id: $employee_id, type: $document_type");
            
            if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
                error_log("ERROR: No 'document' file uploaded");
                throw new Exception('Please select a file to upload');
            }
            
            $this->verifyEmployeeAccess($employee_id);
            
            $upload_dir    = $this->createUploadDirectory($employee_id);
            $uploaded_docs = $this->processUploadedFiles($employee_id, $document_type, $upload_dir);
            
            foreach ($uploaded_docs as $doc) {
                $this->logAudit('UPLOAD', 'employee_documents', $doc['document_id'], null, [
                    'employee_id'   => $employee_id,
                    'document_type' => $document_type,
                    'document_name' => $doc['document_name']
                ]);
            }
            
            error_log("Upload successful: " . count($uploaded_docs) . " files uploaded");
            
            $this->sendResponse(200, [
                'success'   => true,
                'message'   => count($uploaded_docs) . ' document(s) uploaded successfully',
                'documents' => $uploaded_docs
            ]);
            
        } catch (Exception $e) {
            error_log("UPLOAD ERROR: " . $e->getMessage());
            $this->sendResponse(400, ['error' => $e->getMessage()]);
        }
    }
    
    private function uploadSingleDocument($employee_id, $document_type, $fileData) {
        $this->verifyEmployeeAccess($employee_id);
        
        $upload_dir = __DIR__ . '/../uploads/employee_documents/employee_' . $employee_id . '/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }
        
        $allowed_types = [
            'application/pdf', 'image/jpeg', 'image/png', 'image/jpg', 'image/gif',
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $max_size = 10 * 1024 * 1024;
        
        if ($fileData['size'] > $max_size) {
            throw new Exception('File size exceeds 10MB limit: ' . $fileData['name']);
        }
        
        if (!in_array($fileData['type'], $allowed_types)) {
            throw new Exception('Invalid file type: ' . $fileData['name'] . ' (Type: ' . $fileData['type'] . ')');
        }
        
        $original_name = $fileData['name'];
        $extension     = pathinfo($original_name, PATHINFO_EXTENSION);
        $safe_name     = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
        $filename      = $safe_name . '_' . time() . '_' . uniqid() . '.' . strtolower($extension);
        $filepath      = $upload_dir . $filename;
        
        $relative_path = 'uploads/employee_documents/employee_' . $employee_id . '/' . $filename;
        
        if (!move_uploaded_file($fileData['tmp_name'], $filepath)) {
            throw new Exception('Failed to save file: ' . $original_name);
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO employee_documents 
            (employee_id, document_type, document_name, file_path, uploaded_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $employee_id,
            $document_type,
            $original_name,
            $relative_path,
            $this->user_id
        ]);
        
        return $this->pdo->lastInsertId();
    }
    
    private function deleteDocument($document_id) {
        if (!$this->canEdit('users')) {
            $this->sendResponse(403, ['error' => 'You do not have permission to delete documents']);
        }
        
        try {
            $document = $this->getDocumentDetails($document_id);
            
            if ($document['clinic_id'] != $this->clinic_id) {
                throw new Exception('Unauthorized to delete this document');
            }
            
            $doc_info = [
                'id'            => $document['id'],
                'employee_id'   => $document['employee_id'],
                'document_type' => $document['document_type'],
                'document_name' => $document['document_name']
            ];
            
            if (file_exists($document['file_path'])) {
                unlink($document['file_path']);
            }
            
            $stmt = $this->pdo->prepare("DELETE FROM employee_documents WHERE id = ?");
            $stmt->execute([$document_id]);
            
            $this->logAudit('DELETE', 'employee_documents', $document_id, $doc_info, null);
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Document deleted successfully'
            ]);
        } catch (Exception $e) {
            $this->sendResponse(400, ['error' => $e->getMessage()]);
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
    
    private function formatFileSize($bytes) {
        if ($bytes == 0) return '0 Bytes';
        $k     = 1024;
        $sizes = ['Bytes', 'KB', 'MB', 'GB'];
        $i     = floor(log($bytes) / log($k));
        return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
    }
    
    private function verifyEmployeeAccess($employee_id) {
        $stmt = $this->pdo->prepare("
            SELECT e.id 
            FROM employees e
            JOIN users u ON e.user_id = u.id
            WHERE e.id = ? AND u.clinic_id = ?
        ");
        $stmt->execute([$employee_id, $this->clinic_id]);
        
        if (!$stmt->fetch()) {
            throw new Exception('Employee not found or unauthorized');
        }
    }
    
    private function getDocumentDetails($document_id) {
        $stmt = $this->pdo->prepare("
            SELECT d.*, u.clinic_id 
            FROM employee_documents d
            JOIN employees e ON d.employee_id = e.id
            JOIN users u ON e.user_id = u.id
            WHERE d.id = ?
        ");
        $stmt->execute([$document_id]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$document) {
            throw new Exception('Document not found');
        }
        
        return $document;
    }
    
    private function createUploadDirectory($employee_id) {
        $upload_dir = __DIR__ . '/../uploads/employee_documents/employee_' . $employee_id . '/';
        if (!file_exists($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                throw new Exception('Failed to create upload directory');
            }
        }
        return $upload_dir;
    }
    
    private function processUploadedFiles($employee_id, $document_type, $upload_dir) {
        error_log("=== PROCESS UPLOADED FILES ===");
        
        $files = $_FILES['document'];
        
        $uploaded_docs = [];
        
        if (is_array($files['name'])) {
            error_log("Processing multiple files: " . count($files['name']));
            
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK || empty($files['name'][$i])) {
                    error_log("Skipping file $i - error: " . $files['error'][$i]);
                    continue;
                }
                
                $uploaded_docs[] = $this->processSingleFile(
                    $employee_id, 
                    $document_type, 
                    $upload_dir,
                    [
                        'name'     => $files['name'][$i],
                        'type'     => $files['type'][$i],
                        'tmp_name' => $files['tmp_name'][$i],
                        'error'    => $files['error'][$i],
                        'size'     => $files['size'][$i]
                    ]
                );
            }
        } else {
            error_log("Processing single file: " . $files['name']);
            
            if ($files['error'] === UPLOAD_ERR_OK && !empty($files['name'])) {
                $uploaded_docs[] = $this->processSingleFile(
                    $employee_id,
                    $document_type,
                    $upload_dir,
                    $files
                );
            }
        }
        
        if (empty($uploaded_docs)) {
            throw new Exception('No valid files were uploaded');
        }
        
        return $uploaded_docs;
    }

    private function processSingleFile($employee_id, $document_type, $upload_dir, $file) {
        error_log("Processing single file: " . $file['name']);
        
        $allowed_types = [
            'application/pdf', 
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif',
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ];
        $max_size = 10 * 1024 * 1024;
        
        if ($file['size'] > $max_size) {
            throw new Exception('File size exceeds 10MB limit: ' . $file['name']);
        }
        
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception('Invalid file type: ' . $file['name'] . ' (Type: ' . $file['type'] . ')');
        }
        
        $original_name = $file['name'];
        $extension     = pathinfo($original_name, PATHINFO_EXTENSION);
        $safe_name     = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($original_name, PATHINFO_FILENAME));
        $filename      = $safe_name . '_' . time() . '_' . uniqid() . '.' . strtolower($extension);
        $filepath      = $upload_dir . $filename;
        
        $relative_path = 'uploads/employee_documents/employee_' . $employee_id . '/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Failed to save file: ' . $original_name);
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO employee_documents 
            (employee_id, document_type, document_name, file_path, uploaded_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $employee_id,
            $document_type,
            $original_name,
            $relative_path,
            $this->user_id
        ]);
        
        $doc_id = $this->pdo->lastInsertId();
        error_log("File saved to DB with ID: $doc_id");
        
        return [
            'document_id'   => $doc_id,
            'document_name' => $original_name,
            'file_name'     => $filename,
            'file_path'     => $relative_path,
            'file_url'      => '/' . $relative_path
        ];
    }
    
    // ============= USER / EMPLOYEE MANAGEMENT HELPERS =============
    private function validateUserData($data) {
        $required = ['first_name', 'last_name', 'email', 'password', 'role', 
                     'position_id', 'date_hired', 'employment_type', 'basic_salary'];
        
        foreach ($required as $field) {
            if (empty(trim($data[$field] ?? ''))) {
                throw new Exception("Please fill all required fields. Missing: " . $field);
            }
        }
        
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }
        
        if (!is_numeric($data['basic_salary']) || $data['basic_salary'] < 0) {
            throw new Exception('Basic salary must be a valid number');
        }
    }
    
    private function checkEmailExists($email) {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            throw new Exception('Email already exists');
        }
    }
    
private function generateUserCode($role) {
    $rolePrefixes = [
        'HR'          => 'HR',
        'Finance'     => 'FIN',
        'CRM'         => 'CRM',
        'SCM'         => 'SCM',
        'Staff'       => 'STF',
        'Optometrist' => 'OPT',
        'Rider'       => 'RDR'
    ];
    
    $prefix = $rolePrefixes[$role] ?? 'EMP';
    
    // Get clinic code (you might need to add clinic_code column to clinics table)
    // For now, use clinic_id as part of the code
    $clinicCode = 'C' . str_pad($this->clinic_id, 3, '0', STR_PAD_LEFT);
    
    $stmt = $this->pdo->prepare("
        SELECT user_code
        FROM users
        WHERE clinic_id = :clinic_id
          AND user_code LIKE :prefix
        ORDER BY id DESC
        LIMIT 1
    ");
    
    // Search for codes with clinic prefix
    $searchPrefix = $clinicCode . '-' . $prefix . '%';
    
    $stmt->execute([
        'clinic_id' => $this->clinic_id,
        'prefix'    => $searchPrefix
    ]);
    
    $lastCode = $stmt->fetchColumn();
    
    if ($lastCode) {
        // Extract the number part
        $parts = explode('-', $lastCode);
        $lastNumber = isset($parts[2]) ? intval($parts[2]) : 0;
        $number = $lastNumber + 1;
    } else {
        $number = 1;
    }
    
    // Format: C001-HR-0001
    return $clinicCode . '-' . $prefix . '-' . str_pad($number, 4, '0', STR_PAD_LEFT);
}
    
    private function createUser($data, $user_code) {
        $hashed = password_hash($data['password'], PASSWORD_DEFAULT);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO users
            (clinic_id, user_code, first_name, last_name, email, password, role, status)
            VALUES
            (:clinic_id, :user_code, :first_name, :last_name, :email, :password, :role, :status)
        ");
        
        $stmt->execute([
            'clinic_id'  => $this->clinic_id,
            'user_code'  => $user_code,
            'first_name' => trim($data['first_name']),
            'last_name'  => trim($data['last_name']),
            'email'      => trim($data['email']),
            'password'   => $hashed,
            'role'       => $data['role'],
            'status'     => $data['status'] ?? 'Active'
        ]);
        
        return $this->pdo->lastInsertId();
    }

private function generateEmployeeNumber() {
    $year = date('Y');
    $prefix = 'EMP-' . $year . '-';
    $maxRetries = 3;
    $retryCount = 0;
    
    while ($retryCount < $maxRetries) {
        try {
            // Get the highest employee number for this clinic and year
            $stmt = $this->pdo->prepare("
                SELECT employee_no 
                FROM employees 
                WHERE clinic_id = ? 
                AND employee_no LIKE ?
                ORDER BY CAST(SUBSTRING_INDEX(employee_no, '-', -1) AS UNSIGNED) DESC
                LIMIT 1
            ");
            $stmt->execute([$this->clinic_id, $prefix . '%']);
            $last = $stmt->fetchColumn();
            
            if ($last && preg_match('/EMP-' . $year . '-(\d+)$/', $last, $matches)) {
                $last_num = intval($matches[1]);
                $next_num = $last_num + 1;
            } else {
                $next_num = 1001;
            }
            
            // Prevent overflow (max 9999 per year)
            if ($next_num > 9999) {
                $next_num = 1001; // Reset or handle error
                error_log("Warning: Employee number exceeded 9999 for clinic {$this->clinic_id} in year {$year}, resetting to 1001");
            }
            
            $employee_no = $prefix . $next_num;
            
            // Double-check if this number is already used (safety check)
            $checkStmt = $this->pdo->prepare("SELECT id FROM employees WHERE employee_no = ?");
            $checkStmt->execute([$employee_no]);
            if (!$checkStmt->fetch()) {
                return $employee_no;
            }
            
            $retryCount++;
            error_log("Duplicate employee number detected: $employee_no, retrying...");
            
        } catch (Exception $e) {
            error_log("Employee number generation error: " . $e->getMessage());
            $retryCount++;
            if ($retryCount >= $maxRetries) {
                // Ultimate fallback: use timestamp
                $fallback_no = $prefix . time() . rand(100, 999);
                error_log("Using fallback employee number: $fallback_no");
                return $fallback_no;
            }
            usleep(100000); // Wait 0.1 seconds
        }
    }
    
    // Ultimate fallback if all retries fail
    return $prefix . time() . rand(1000, 9999);
}

    public function createEmployeeFull($data) {
        try {
            $this->pdo->beginTransaction();
            $employee_no = $this->generateEmployeeNumber();
            $user_code   = 'U-' . uniqid();
            $user_id     = $this->createUser($data, $user_code);
            $this->createEmployee($user_id, $data, $employee_no);
            $this->pdo->commit();
            return ['user_id' => $user_id, 'employee_no' => $employee_no];
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function createEmployee($user_id, $data, $employee_no) {
        $employeeData = [
            'user_id'          => $user_id,
            'clinic_id'        => $this->clinic_id, 
            'employee_no'      => $employee_no,
            'position_id'      => $data['position_id'] ?? null,
            'date_hired'       => $data['date_hired'] ?? date('Y-m-d'),
            'date_regularized' => $data['date_regularized'] ?? null,
            'employment_type'  => $data['employment_type'] ?? 'Regular',
            'basic_salary'     => isset($data['basic_salary']) ? floatval($data['basic_salary']) : 0.00,
            'salary_frequency' => $data['salary_frequency'] ?? 'monthly',
            'salary_type'      => $data['salary_type'] ?? 'Fixed',
            'status'           => $data['status'] ?? 'Active',
            
            // Personal Info
            'birth_date'       => $data['birth_date'] ?? null,
            'gender'           => $data['gender'] ?? null,
            'marital_status'   => $data['marital_status'] ?? null,
            'address'          => $data['address'] ?? null,
            'phone_number'     => $data['phone_number'] ?? null,
            
            // Government IDs
            'sss_number'        => $data['sss_number'] ?? null,
            'philhealth_number' => $data['philhealth_number'] ?? null,
            'pagibig_number'    => $data['pagibig_number'] ?? null,
            'tin_number'        => $data['tin_number'] ?? null,
            
            // Bank Info
            'bank_name'             => $data['bank_name'] ?? null,
            'bank_account_holder'   => $data['bank_account_holder'] ?? null,
            'bank_account_number'   => $data['bank_account_number'] ?? null,
            
            // Emergency Contact
            'emergency_contact_name'         => $data['emergency_contact_name'] ?? null,
            'emergency_contact_number'       => $data['emergency_contact_number'] ?? null,
            'emergency_contact_relationship' => $data['emergency_contact_relationship'] ?? null,
            
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
        
        $filteredData = array_filter($employeeData, function($value) {
            return $value !== null;
        });
        
        $columns      = implode(', ', array_keys($filteredData));
        $placeholders = ':' . implode(', :', array_keys($filteredData));
        
        $sql  = "INSERT INTO employees ($columns) VALUES ($placeholders)";
        $stmt = $this->pdo->prepare($sql);
        
        try {
            $stmt->execute($filteredData);
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            error_log("Employee creation error: " . $e->getMessage());
            
            if (strpos($e->getMessage(), 'column not found') !== false) {
                $minimalData = [
                    'user_id'         => $user_id,
                    'clinic_id'       => $this->clinic_id,
                    'employee_no'     => $employee_no,
                    'position_id'     => $data['position_id'] ?? null,
                    'date_hired'      => $data['date_hired'] ?? date('Y-m-d'),
                    'employment_type' => $data['employment_type'] ?? 'Regular',
                    'basic_salary'    => isset($data['basic_salary']) ? floatval($data['basic_salary']) : 0.00,
                    'status'          => $data['status'] ?? 'Active',
                    'created_at'      => date('Y-m-d H:i:s'),
                    'updated_at'      => date('Y-m-d H:i:s')
                ];
                
                $optionalFields = [
                    'salary_frequency', 'salary_type', 'birth_date', 'gender', 
                    'marital_status', 'address', 'date_regularized',
                    'sss_number', 'philhealth_number', 'pagibig_number', 'tin_number',
                    'bank_name', 'bank_account_holder', 'bank_account_number',
                    'emergency_contact_name', 'emergency_contact_number', 'emergency_contact_relationship'
                ];
                
                foreach ($optionalFields as $field) {
                    if (isset($data[$field]) && $data[$field] !== '') {
                        $minimalData[$field] = $data[$field];
                    }
                }
                
                $minimalData = array_filter($minimalData, function($value) {
                    return $value !== null;
                });
                
                $minimalColumns      = implode(', ', array_keys($minimalData));
                $minimalPlaceholders = ':' . implode(', :', array_keys($minimalData));
                
                $sql  = "INSERT INTO employees ($minimalColumns) VALUES ($minimalPlaceholders)";
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute($minimalData);
                
                return $this->pdo->lastInsertId();
            } else {
                throw $e;
            }
        }
    }
    
    private function recordInitialSalary($employee_id, $salary) {
        $stmt = $this->pdo->prepare("
            SELECT u.clinic_id 
            FROM employees e
            JOIN users u ON e.user_id = u.id
            WHERE e.id = ?
        ");
        $stmt->execute([$employee_id]);
        $employee_clinic_id = $stmt->fetchColumn();
        
        if (!$employee_clinic_id) {
            $employee_clinic_id = $this->clinic_id;
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO salary_history 
            (clinic_id, employee_id, old_salary, new_salary, reason, effective_date, changed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $employee_clinic_id,
            $employee_id,
            0,
            floatval($salary),
            'Initial salary',
            date('Y-m-d'),
            $this->user_id
        ]);
        
        $this->logAudit('CREATE', 'salary_history', $this->pdo->lastInsertId(), null, [
            'employee_id' => $employee_id,
            'clinic_id'   => $employee_clinic_id,
            'old_salary'  => 0,
            'new_salary'  => floatval($salary),
            'reason'      => 'Initial salary'
        ]);
    }
    
    private function sendWelcomeEmail($data, $user_code) {
        try {
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                error_log("PHPMailer not loaded, skipping email");
                return false;
            }
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'angelloricanmendoza27@gmail.com';
            $mail->Password   = 'tkyv vypr pxvm pfse';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
            
            $mail->setFrom('no-reply@eyecore.com', 'Eyecore System');
            $mail->addAddress($data['email'], $data['first_name'] . ' ' . $data['last_name']);
            $mail->isHTML(true);
            $mail->Subject = 'Eyecore Staff Account Created';
            
            $mail->Body = "
                <h3>Welcome to Eyecore</h3>
                <p>Hello <b>{$data['first_name']}</b>,</p>
                <p>Your staff account has been successfully created.</p>
                <p>
                    <b>User Code:</b> {$user_code}<br>
                    <b>Role:</b> {$data['role']}
                </p>
                <p>Please login using your personal email:</p>
                <p><b>{$data['email']}</b></p>
                <p>For security reasons, you may change your password after login.</p>
                <br>
                <p>— Eyecore Management System</p>
            ";
            
            $mail->AltBody = "Welcome to Eyecore\n\nHello {$data['first_name']},\n\nYour staff account has been successfully created.\n\nUser Code: {$user_code}\nRole: {$data['role']}\n\nPlease login using your personal email: {$data['email']}\n\n— Eyecore Management System";
            
            if (!$mail->send()) {
                error_log("Email send failed: " . $mail->ErrorInfo);
                return false;
            }
            
            $this->logAudit('SEND', 'email', null, null, [
                'recipient' => $data['email'],
                'type'      => 'welcome_email'
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Email exception: " . $e->getMessage());
            return false;
        }
    }
    
    private function getCurrentSalary($employee_id) {
        $stmt   = $this->pdo->prepare("SELECT basic_salary FROM employees WHERE id = ?");
        $stmt->execute([$employee_id]);
        $result = $stmt->fetchColumn();
        return $result !== false ? floatval($result) : 0;
    }
    
    // ✅ Helper: get full name by user_id (used for doctors sync)
    private function getFullNameByUserId($user_id) {
        $stmt = $this->pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? trim($row['first_name']) . ' ' . trim($row['last_name']) : '';
    }
    
    private function updateUser($user_id, $data) {
        $userUpdateFields = [];
        $userUpdateValues = [':user_id' => $user_id];
        
        if (isset($data['first_name'])) {
            $userUpdateFields[]            = "first_name = :first_name";
            $userUpdateValues[':first_name'] = $data['first_name'];
        }
        
        if (isset($data['last_name'])) {
            $userUpdateFields[]           = "last_name = :last_name";
            $userUpdateValues[':last_name'] = $data['last_name'];
        }
        
        if (isset($data['email'])) {
            $userUpdateFields[]        = "email = :email";
            $userUpdateValues[':email'] = $data['email'];
        }
        
        if (isset($data['role'])) {
            $userUpdateFields[]       = "role = :role";
            $userUpdateValues[':role'] = $data['role'];
        }
        
        if (!empty($userUpdateFields)) {
            $userUpdateFields[] = "updated_at = NOW()";
            $userSql = "UPDATE users SET " . implode(', ', $userUpdateFields) . " WHERE id = :user_id";
            $stmt    = $this->pdo->prepare($userSql);
            $stmt->execute($userUpdateValues);
        }
    }
    
    private function updateEmployee($employee_id, $data) {
        $updateData = [
            'position_id'                    => $data['position_id'] ?? null,
            'employment_type'                => $data['employment_type'] ?? null,
            'date_hired'                     => $data['date_hired'] ?? null,
            'basic_salary'                   => isset($data['basic_salary']) ? floatval($data['basic_salary']) : null,
            'status'                         => $data['status'] ?? 'Active',
            'sss_number'                     => $data['sss_number'] ?? null,
            'philhealth_number'              => $data['philhealth_number'] ?? null,
            'pagibig_number'                 => $data['pagibig_number'] ?? null,
            'tin_number'                     => $data['tin_number'] ?? null,
            'bank_name'                      => $data['bank_name'] ?? null,
            'bank_account_holder'            => $data['bank_account_holder'] ?? null,
            'bank_account_number'            => $data['bank_account_number'] ?? null,
            'emergency_contact_name'         => $data['emergency_contact_name'] ?? null,
            'emergency_contact_number'       => $data['emergency_contact_number'] ?? null
        ];
        
        $fields = [];
        $values = [];
        
        foreach ($updateData as $key => $value) {
            if ($value !== null) {
                $fields[]        = "$key = :$key";
                $values[":$key"] = $value;
            }
        }
        
        if (!empty($fields)) {
            $fields[]             = "updated_at = NOW()";
            $sql                  = "UPDATE employees SET " . implode(', ', $fields) . " WHERE id = :employee_id";
            $values[':employee_id'] = $employee_id;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($values);
        }
    }
    
    private function recordSalaryChange($employee_id, $old_salary, $new_salary, $reason) {
        $stmt = $this->pdo->prepare("
            SELECT u.clinic_id 
            FROM employees e
            JOIN users u ON e.user_id = u.id
            WHERE e.id = ?
        ");
        $stmt->execute([$employee_id]);
        $employee_clinic_id = $stmt->fetchColumn();
        
        if (!$employee_clinic_id) {
            $employee_clinic_id = $this->clinic_id;
        }
        
        $stmt = $this->pdo->prepare("
            INSERT INTO salary_history 
            (clinic_id, employee_id, old_salary, new_salary, reason, effective_date, changed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $employee_clinic_id,
            $employee_id,
            floatval($old_salary),
            floatval($new_salary),
            $reason,
            date('Y-m-d'),
            $this->user_id
        ]);
        
        $this->logAudit('UPDATE', 'salary_history', $this->pdo->lastInsertId(),
            ['old_salary' => $old_salary],
            ['new_salary' => $new_salary, 'reason' => $reason, 'clinic_id' => $employee_clinic_id]
        );
    }

private function updateRiderInfo($user_id, $data) {
    $updateFields = [];
    $values = [':user_id' => $user_id];
    
    if (isset($data['vehicle_type'])) {
        $updateFields[] = "vehicle_type = :vehicle_type";
        $values[':vehicle_type'] = $data['vehicle_type'];
    }
    if (isset($data['plate_number'])) {
        $updateFields[] = "plate_number = :plate_number";
        $values[':plate_number'] = $data['plate_number'];
    }
    if (isset($data['phone_number'])) {
        $updateFields[] = "phone = :phone";
        $values[':phone'] = $data['phone_number'];
    }
    if (isset($data['driver_license_no'])) {
        $updateFields[] = "driver_license_no = :driver_license_no";
        $values[':driver_license_no'] = $data['driver_license_no'];
    }
    if (!empty($data['license_expiration_date'])) {
        $updateFields[] = "license_expiration_date = :license_expiration_date";
        $values[':license_expiration_date'] = $data['license_expiration_date'];
    }
    if (isset($data['vehicle_brand_model'])) {
        $updateFields[] = "vehicle_brand_model = :vehicle_brand_model";
        $values[':vehicle_brand_model'] = $data['vehicle_brand_model'];
    }
    if (isset($data['or_cr_number'])) {
        $updateFields[] = "or_cr_number = :or_cr_number";
        $values[':or_cr_number'] = $data['or_cr_number'];
    }
    
    if (!empty($updateFields)) {
        $updateFields[] = "updated_at = NOW()";
        $sql = "UPDATE riders SET " . implode(', ', $updateFields) . " WHERE user_id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
    }
}
}

// Main execution
try {
    if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
        $employeeBackend = new EmployeeBackend($pdo);
        $employeeBackend->handleRequest();
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