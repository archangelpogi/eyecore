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

    // Include and instantiate AttendanceBackend class
    $attendanceBackend = new AttendanceBackend($pdo);
    $attendanceBackend->handleRequest();

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

class AttendanceBackend {
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
            error_log("=== Attendance Backend Debug ===");
            error_log("User ID: {$this->user_id}, Clinic: {$this->clinic_id}");
            error_log("Permissions loaded: " . count($_SESSION['permissions'] ?? []));
            error_log("Has attendance_view: " . ($this->canView() ? 'YES' : 'NO'));
            error_log("Has attendance_edit: " . ($this->canManage() ? 'YES' : 'NO'));
            error_log("Has attendance_create: " . ($this->canScan() ? 'YES' : 'NO'));
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
    private function hasPermission($permission_name) {
        return RBACHelper::hasPermission($permission_name);
    }
    
    private function canView() {
        return $this->hasPermission('attendance_view');
    }
    
    private function canManage() {
        return $this->hasPermission('attendance_edit');
    }
    
    private function canManageQR() {
        return $this->hasPermission('attendance_edit'); // Same as manage
    }
    
    private function canExport() {
        return $this->hasPermission('attendance_export');
    }
    
    private function canScan() {
        return $this->hasPermission('attendance_create');
    }
    
    private function canViewOwn() {
        return $this->hasPermission('attendance_view'); // Same as view
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
            'view' => $this->canView(),
            'manage' => $this->canManage(),
            'manage_qr' => $this->canManageQR(),
            'export' => $this->canExport(),
            'scan' => $this->canScan(),
            'view_own' => $this->canViewOwn()
        ];
        
        // Debug
        error_log("=== Attendance getCurrentUserPermissions ===");
        error_log("Role: $role");
        error_log("canView(): " . ($this->canView() ? 'true' : 'false'));
        error_log("canManage(): " . ($this->canManage() ? 'true' : 'false'));
        error_log("canScan(): " . ($this->canScan() ? 'true' : 'false'));
        
        return [
            'role' => $role,
            'permissions' => $permissions,
            'hasHR' => $hasHR,
            'isOwner' => ($role === 'ClinicAdmin' && !$hasHR),
            'user_id' => $this->user_id
        ];
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
            ");
            $stmt->execute([$user_id, $this->clinic_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($employee) {
                return $employee['id'];
            }
            
            // Try without status check
            $stmt = $this->pdo->prepare("
                SELECT e.id 
                FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.user_id = ? AND u.clinic_id = ?
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
    
    private function calculateDistance($lat1, $lon1, $lat2, $lon2) {
        $earthRadius = 6371000; // meters
        
        $lat1 = deg2rad($lat1);
        $lon1 = deg2rad($lon1);
        $lat2 = deg2rad($lat2);
        $lon2 = deg2rad($lon2);
        
        $latDelta = $lat2 - $lat1;
        $lonDelta = $lon2 - $lon1;
        
        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($lat1) * cos($lat2) * pow(sin($lonDelta / 2), 2)));
        
        return $angle * $earthRadius;
    }
    
    private function generateQRImage($data) {
        $encodedData = urlencode($data);
        return "https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=" . $encodedData . "&format=png&margin=10&ecc=H";
    }
    
    private function getDeviceInfo() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $deviceInfo = '';
        
        if (strpos($userAgent, 'Mobile') !== false) {
            $deviceInfo = 'Mobile';
        } else {
            $deviceInfo = 'Desktop';
        }
        
        if (strpos($userAgent, 'Android') !== false) {
            $deviceInfo .= ' (Android)';
        } elseif (strpos($userAgent, 'iPhone') !== false || strpos($userAgent, 'iPad') !== false) {
            $deviceInfo .= ' (iOS)';
        } elseif (strpos($userAgent, 'Windows') !== false) {
            $deviceInfo .= ' (Windows)';
        } elseif (strpos($userAgent, 'Mac') !== false) {
            $deviceInfo .= ' (Mac)';
        }
        
        return $deviceInfo;
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
        
        // Check view permission (or view own)
        if (!$this->canView() && !$this->canViewOwn()) {
            $this->sendResponse(403, ['error' => 'You do not have permission to view attendance']);
            return;
        }
        
        if (isset($_GET['attendance_id'])) {
            $this->getAttendanceDetails((int)$_GET['attendance_id']);
        } elseif (isset($_GET['stats'])) {
            $this->getStats();
        } elseif (isset($_GET['get_qr_token'])) {
            if (!$this->canManageQR()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to view QR codes']);
            }
            $this->getQRToken();
        } elseif (isset($_GET['today_scans'])) {
            $this->getTodayScans();
        } elseif (isset($_GET['check_qr_exists'])) {
            if (!$this->canManageQR()) {
                $this->sendResponse(403, ['error' => 'You do not have permission to check QR']);
            }
            $this->checkQRExists();
        } else {
            $this->getAttendanceList();
        }
    }
    
    private function getAttendanceDetails($attendance_id) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    a.*,
                    e.employee_no,
                    u.first_name,
                    u.last_name,
                    u.email,
                    c.clinic_name,
                    al.location_name as attendance_location_name,
                    al.latitude as location_lat,
                    al.longitude as location_lng
                FROM attendance a
                LEFT JOIN employees e ON a.employee_id = e.id
                LEFT JOIN users u ON e.user_id = u.id
                LEFT JOIN clinics c ON u.clinic_id = c.id
                LEFT JOIN attendance_locations al ON a.location_id = al.id
                WHERE a.id = ? AND u.clinic_id = ?
            ");
            $stmt->execute([$attendance_id, $this->clinic_id]);
            $record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$record) {
                $this->sendResponse(404, ['error' => 'Record not found']);
                return;
            }
            
            // Get all photos for this attendance
            $photoStmt = $this->pdo->prepare("
                SELECT 
                    action_type,
                    photo_path,
                    scan_time,
                    device_info,
                    ip_address,
                    created_at
                FROM attendance_logs 
                WHERE attendance_id = ?
                ORDER BY 
                    CASE 
                        WHEN action_type = 'time_in' THEN 1
                        WHEN action_type = 'break_start' THEN 2
                        WHEN action_type = 'break_end' THEN 3
                        WHEN action_type = 'time_out' THEN 4
                        ELSE 5
                    END,
                    scan_time
            ");
            $photoStmt->execute([$attendance_id]);
            $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // If no logs found, create default entries
            if (empty($photos)) {
                $photos = [
                    ['action_type' => 'time_in', 'photo_path' => null],
                    ['action_type' => 'break_start', 'photo_path' => null],
                    ['action_type' => 'break_end', 'photo_path' => null],
                    ['action_type' => 'time_out', 'photo_path' => null]
                ];
            }
            
            $record['photos'] = $photos;
            
            // Log view action
            $this->logAudit('VIEW', 'attendance', $attendance_id, null, null);
            
            $this->sendResponse(200, $record);
            
        } catch (Exception $e) {
            error_log("Error in getAttendanceDetails: " . $e->getMessage());
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function getStats() {
        $today = date('Y-m-d');
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    COUNT(CASE WHEN a.status = 'Present' THEN 1 END) as present,
                    COUNT(CASE WHEN a.status = 'Late' THEN 1 END) as late,
                    COUNT(CASE WHEN a.status = 'Absent' THEN 1 END) as absent,
                    COUNT(CASE WHEN a.status = 'On-Leave' THEN 1 END) as on_leave,
                    (SELECT COUNT(*) FROM attendance_logs al
                     JOIN employees e ON al.employee_id = e.id
                     JOIN users u ON e.user_id = u.id
                     WHERE DATE(al.scan_time) = ? AND al.status = 'failed' AND u.clinic_id = ?) as failed_scans,
                    (SELECT COUNT(*) FROM attendance_logs al
                     JOIN employees e ON al.employee_id = e.id
                     JOIN users u ON e.user_id = u.id
                     WHERE DATE(al.scan_time) = ? AND al.status = 'success' AND u.clinic_id = ?) as today_scans
                FROM attendance a
                JOIN employees e ON a.employee_id = e.id
                JOIN users u ON e.user_id = u.id
                WHERE a.date = ? AND u.clinic_id = ?
            ");
            $stmt->execute([$today, $this->clinic_id, $today, $this->clinic_id, $today, $this->clinic_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $this->logAudit('VIEW_STATS', 'attendance', null, null, ['date' => $today]);
            
            $this->sendResponse(200, $result ?: ['present' => 0, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'failed_scans' => 0, 'today_scans' => 0]);
        } catch(Exception $e) {
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function getQRToken() {
        $today = date('Y-m-d');
        
        try {
            $stmt = $this->pdo->prepare("
                SELECT qr_token, qr_image_url, qr_data 
                FROM attendance_qr_codes 
                WHERE clinic_id = ? AND DATE(created_at) = ? AND status = 'active' 
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$this->clinic_id, $today]);
            $qr = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if($qr) {
                if(!empty($qr['qr_image_url'])) {
                    $this->sendResponse(200, [
                        'token' => $qr['qr_token'],
                        'qr_image' => $qr['qr_image_url'],
                        'qr_data' => $qr['qr_data']
                    ]);
                } else {
                    $qrData = "ATTENDANCE|" . $qr['qr_token'] . "|" . time();
                    $qrImage = $this->generateQRImage($qrData);
                    
                    $updateStmt = $this->pdo->prepare("
                        UPDATE attendance_qr_codes 
                        SET qr_image_url = ?, qr_data = ?
                        WHERE qr_token = ? AND clinic_id = ?
                    ");
                    $updateStmt->execute([$qrImage, $qrData, $qr['qr_token'], $this->clinic_id]);
                    
                    $this->sendResponse(200, [
                        'token' => $qr['qr_token'],
                        'qr_image' => $qrImage,
                        'qr_data' => $qrData
                    ]);
                }
            } else {
                $this->sendResponse(200, ['token' => null, 'qr_image' => null]);
            }
        } catch(Exception $e) {
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function getTodayScans() {
        $today = date('Y-m-d');
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as scan_count 
                FROM attendance_logs al
                JOIN employees e ON al.employee_id = e.id
                JOIN users u ON e.user_id = u.id
                WHERE DATE(al.scan_time) = ? AND al.status = 'success' AND u.clinic_id = ?
            ");
            $stmt->execute([$today, $this->clinic_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->sendResponse(200, ['scan_count' => $result['scan_count'] ?? 0]);
        } catch(Exception $e) {
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
    private function checkQRExists() {
        $today = date('Y-m-d');
        try {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) as count 
                FROM attendance_qr_codes 
                WHERE clinic_id = ? AND DATE(created_at) = ? AND status = 'active'
            ");
            $stmt->execute([$this->clinic_id, $today]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->sendResponse(200, ['exists' => ($result['count'] ?? 0) > 0]);
        } catch(Exception $e) {
            $this->sendResponse(500, ['error' => $e->getMessage()]);
        }
    }
    
private function getAttendanceList() {
    $date_range = $_GET['date_range'] ?? 'all';
    $employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : null;
    $status = $_GET['status'] ?? null;
    $search = $_GET['search'] ?? null;
    $approval_status = $_GET['approval_status'] ?? null; // ✅ ADD THIS
    
    // If user can only view own attendance, force employee_id
    if (!$this->canView() && $this->canViewOwn()) {
        $employee_id = $this->getEmployeeIdFromUserId($this->user_id);
        if (!$employee_id) {
            $this->sendResponse(200, []);
            return;
        }
    }
    
    // Date range
    switch($date_range) {
        case 'today':
            $date_where = "a.date = CURDATE()";
            break;
        case 'yesterday':
            $date_where = "a.date = DATE_SUB(CURDATE(), INTERVAL 1 DAY)";
            break;
        case 'this_week':
            $date_where = "YEARWEEK(a.date, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'this_month':
            $date_where = "MONTH(a.date) = MONTH(CURDATE()) AND YEAR(a.date) = YEAR(CURDATE())";
            break;
        case 'all':
        default:
            $date_where = "1=1";
            break;
    }
    
    $sql = "
        SELECT 
            a.id,
            a.employee_id,
            a.date,
            a.time_in,
            a.time_out,
            a.break_start,
            a.break_end,
            a.total_hours,
            a.status,
            a.remarks,
            a.created_at,
            a.updated_at,
            a.attendance_method,
            a.scan_location_lat,
            a.scan_location_lng,
            a.approval_status,
            a.approved_at,
            CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
            e.employee_no
        FROM attendance a
        LEFT JOIN employees e ON a.employee_id = e.id
        LEFT JOIN users u ON e.user_id = u.id
        WHERE a.clinic_id = ? AND $date_where
    ";
    
    $params = [$this->clinic_id];
    
    if($employee_id) {
        $sql .= " AND a.employee_id = ?";
        $params[] = $employee_id;
    }
    
    if($status) {
        $sql .= " AND a.status = ?";
        $params[] = $status;
    }
    
if ($approval_status && $approval_status !== 'all') {
    $sql .= " AND a.approval_status = ?";
    $params[] = $approval_status;
}
    
    if($search) {
        $sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR e.employee_no LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY a.date DESC, a.created_at DESC";
    
    try {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $this->logAudit('VIEW_LIST', 'attendance', null, null, ['count' => count($results)]);
        
        $this->sendResponse(200, $results ?: []);
    } catch(Exception $e) {
        error_log("Query error: " . $e->getMessage());
        $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
    }
}
    
private function handlePost() {
    $rawData = file_get_contents('php://input');
    $data = json_decode($rawData, true);
    
    if (!$data) {
        $this->sendResponse(400, ['success' => false, 'message' => 'Invalid JSON data']);
        return;
    }
    
    if (isset($data['generate_auto_qr'])) {
        if (!$this->canManageQR()) {
            $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to generate QR codes']);
        }
        $this->generateAutoQR($data);
    } elseif (isset($data['generate_qr'])) {
        if (!$this->canManageQR()) {
            $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to generate QR codes']);
        }
        $this->generateQR($data);
    } elseif (isset($data['revoke_qr'])) {
        if (!$this->canManageQR()) {
            $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to revoke QR codes']);
        }
        $this->revokeQR();
    } elseif (isset($data['scan_qr'])) {
        if (!$this->canScan()) {
            $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to scan QR codes']);
        }
        $this->scanQR($data);
    } elseif (isset($data['auto_absent'])) {
        if (!$this->canManage()) {
            $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to mark absent']);
        }
        $this->autoAbsent();
    } elseif (isset($data['approve_attendance'])) {
        // ✅ ADD THIS: Manual approve attendance
        if (!$this->canManage()) {
            $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to approve attendance']);
        }
        $result = $this->approveAttendance($data['attendance_id'], $data['remarks'] ?? null);
        $this->sendResponse($result['success'] ? 200 : 400, $result);
    } elseif (isset($data['reject_attendance'])) {
        // ✅ ADD THIS: Manual reject attendance
        if (!$this->canManage()) {
            $this->sendResponse(403, ['success' => false, 'message' => 'You do not have permission to reject attendance']);
        }
        $result = $this->rejectAttendance($data['attendance_id'], $data['reason'] ?? 'No reason provided');
        $this->sendResponse($result['success'] ? 200 : 400, $result);
    } else {
        $this->sendResponse(400, ['success' => false, 'message' => 'Invalid action']);
    }
}
    
    private function generateAutoQR($data) {
        $today = date('Y-m-d');
        
        try {
            $check = $this->pdo->prepare("
                SELECT * FROM attendance_qr_codes 
                WHERE clinic_id = ? AND DATE(created_at) = ? AND status = 'active'
            ");
            $check->execute([$this->clinic_id, $today]);
            
            if($check->rowCount() > 0) {
                $this->sendResponse(400, ['success' => false, 'message' => 'QR already generated for today']);
                return;
            }
            
            // Get clinic location
            $clinic_stmt = $this->pdo->prepare("SELECT latitude, longitude FROM clinics WHERE id = ?");
            $clinic_stmt->execute([$this->clinic_id]);
            $clinic = $clinic_stmt->fetch();
            
            $location_lat = $clinic['latitude'] ?? 14.599512;
            $location_lng = $clinic['longitude'] ?? 120.984222;
            $geo_radius = 100;
            
            $token = bin2hex(random_bytes(16));
            $expires = date('Y-m-d 23:59:59');
            $created_at = date('Y-m-d H:i:s');
            
            $qrData = "ATTENDANCE|$token|" . time();
            $qrImage = $this->generateQRImage($qrData);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO attendance_qr_codes 
                (clinic_id, qr_token, generated_by, expires_at, location_lat, location_lng, geo_radius, qr_image_url, qr_data, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->clinic_id, $token, $this->user_id, $expires, $location_lat, $location_lng, $geo_radius,
                $qrImage, $qrData, $created_at
            ]);
            
            $qr_id = $this->pdo->lastInsertId();
            
            $new_values = [
                'qr_token' => $token,
                'location_lat' => $location_lat,
                'location_lng' => $location_lng,
                'geo_radius' => $geo_radius
            ];
            $this->logAudit('CREATE', 'attendance_qr_codes', $qr_id, null, $new_values);
            
            $this->sendResponse(200, [
                'success' => true,
                'qr_image' => $qrImage,
                'expires_at' => date('h:i A', strtotime($expires)),
                'token' => $token,
                'location_lat' => $location_lat,
                'location_lng' => $location_lng,
                'geo_radius' => $geo_radius,
                'qr_id' => $qr_id,
                'message' => 'QR code generated successfully'
            ]);
            
        } catch(Exception $e) {
            error_log("QR Generation Error: " . $e->getMessage());
            $this->sendResponse(500, ['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
        }
    }
    
    private function generateQR($data) {
        try {
            $token = bin2hex(random_bytes(16));
            $expires = date('Y-m-d 23:59:59');
            $created_at = date('Y-m-d H:i:s');
            
            $location_lat = $data['location_lat'] ?? null;
            $location_lng = $data['location_lng'] ?? null;
            $geo_radius = $data['geo_radius'] ?? 100;
            
            if(!$location_lat || !$location_lng) {
                $clinic_stmt = $this->pdo->prepare("SELECT latitude, longitude FROM clinics WHERE id = ?");
                $clinic_stmt->execute([$this->clinic_id]);
                $clinic = $clinic_stmt->fetch();
                $location_lat = $clinic['latitude'] ?? 14.599512;
                $location_lng = $clinic['longitude'] ?? 120.984222;
            }
            
            $qrData = "ATTENDANCE|$token|" . time();
            $qrImage = $this->generateQRImage($qrData);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO attendance_qr_codes 
                (clinic_id, qr_token, generated_by, expires_at, location_lat, location_lng, geo_radius, qr_image_url, qr_data, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $this->clinic_id, $token, $this->user_id, $expires, $location_lat, $location_lng, $geo_radius,
                $qrImage, $qrData, $created_at
            ]);
            
            $qr_id = $this->pdo->lastInsertId();
            
            $new_values = [
                'qr_token' => $token,
                'location_lat' => $location_lat,
                'location_lng' => $location_lng,
                'geo_radius' => $geo_radius
            ];
            $this->logAudit('CREATE', 'attendance_qr_codes', $qr_id, null, $new_values);
            
            $this->sendResponse(200, [
                'success' => true,
                'qr_image' => $qrImage,
                'expires_at' => date('h:i A', strtotime($expires)),
                'token' => $token,
                'location_lat' => $location_lat,
                'location_lng' => $location_lng,
                'geo_radius' => $geo_radius
            ]);
            
        } catch(Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    private function revokeQR() {
        $today = date('Y-m-d');
        try {
            $oldStmt = $this->pdo->prepare("
                SELECT * FROM attendance_qr_codes 
                WHERE clinic_id = ? AND DATE(created_at) = ? AND status = 'active'
            ");
            $oldStmt->execute([$this->clinic_id, $today]);
            $old_qrs = $oldStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $stmt = $this->pdo->prepare("
                UPDATE attendance_qr_codes 
                SET status = 'revoked' 
                WHERE clinic_id = ? AND DATE(created_at) = ?
            ");
            $stmt->execute([$this->clinic_id, $today]);
            
            $affected = $stmt->rowCount();
            
            if($affected > 0) {
                foreach ($old_qrs as $old_qr) {
                    $this->logAudit('UPDATE', 'attendance_qr_codes', $old_qr['id'], $old_qr, ['status' => 'revoked']);
                }
                $this->sendResponse(200, ['success' => true, 'message' => 'QR revoked successfully']);
            } else {
                $this->sendResponse(200, ['success' => false, 'message' => 'No active QR found to revoke']);
            }
        } catch(Exception $e) {
            $this->sendResponse(500, ['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    private function scanQR($data) {
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        
        $employee_id = $this->getEmployeeIdFromUserId($this->user_id);
        
        if(!$employee_id) {
            error_log("No employee found for user_id: {$this->user_id} in clinic: {$this->clinic_id}");
            $this->sendResponse(400, ['success' => false, 'message' => 'Employee record not found. Please contact HR.']);
            return;
        }
        
        $user_lat = $data['latitude'] ?? null;
        $user_lng = $data['longitude'] ?? null;
        
        if(!$user_lat || !$user_lng) {
            $this->sendResponse(400, ['success' => false, 'message' => 'Location required']);
            return;
        }
        
        $qr_token = $data['qr_token'] ?? '';
        $parts = explode('|', $qr_token);
        
        if(count($parts) < 2 || $parts[0] !== 'ATTENDANCE') {
            if(strlen($qr_token) === 32) {
                $qr_stmt = $this->pdo->prepare("
                    SELECT qr.* 
                    FROM attendance_qr_codes qr
                    WHERE qr.clinic_id = ? AND qr.qr_token = ? AND qr.expires_at > NOW() AND qr.status = 'active'
                ");
                $qr_stmt->execute([$this->clinic_id, $qr_token]);
                $qr_data = $qr_stmt->fetch();
            } else {
                $this->sendResponse(400, ['success' => false, 'message' => 'Invalid QR format']);
                return;
            }
        } else {
            $token = $parts[1];
            $qr_stmt = $this->pdo->prepare("
                SELECT qr.* 
                FROM attendance_qr_codes qr
                WHERE qr.clinic_id = ? AND qr.qr_token = ? AND qr.expires_at > NOW() AND qr.status = 'active'
            ");
            $qr_stmt->execute([$this->clinic_id, $token]);
            $qr_data = $qr_stmt->fetch();
        }
        
        if(!$qr_data) {
            $this->sendResponse(400, ['success' => false, 'message' => 'QR expired or invalid']);
            return;
        }
        
        $qr_lat = $qr_data['location_lat'] ?? 14.599512;
        $qr_lng = $qr_data['location_lng'] ?? 120.984222;
        $qr_radius = $qr_data['geo_radius'] ?? 100;
        
        $distance = $this->calculateDistance($user_lat, $user_lng, $qr_lat, $qr_lng);
        $is_within_geo = ($distance <= $qr_radius);
        
        if (!$is_within_geo) {
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $device_info = $this->getDeviceInfo();
            $log_remarks = 'Location mismatch: ' . round($distance) . 'm from required location (Max: ' . $qr_radius . 'm)';
            
            try {
                $log_stmt = $this->pdo->prepare("
                    INSERT INTO attendance_logs 
                    (employee_id, action_type, qr_token_used, scan_time, 
                     latitude, longitude, is_within_geo, status, remarks,
                     ip_address, device_info) 
                    VALUES (?, 'failed_scan', ?, ?, ?, ?, ?, 'failed', ?, ?, ?)
                ");
                
                $log_stmt->execute([
                    $employee_id, $qr_token, $now,
                    $user_lat, $user_lng, 0, $log_remarks,
                    $ip_address, $device_info
                ]);
                
                $this->sendResponse(400, [
                    'success' => false,
                    'message' => 'Location mismatch. You are ' . round($distance) . 'm from required location (Max allowed: ' . $qr_radius . 'm)',
                    'within_geo' => false,
                    'distance' => round($distance),
                    'max_distance' => $qr_radius,
                    'qr_location' => ['lat' => $qr_lat, 'lng' => $qr_lng],
                    'your_location' => ['lat' => $user_lat, 'lng' => $user_lng]
                ]);
            } catch(Exception $e) {
                error_log("Failed scan log error: " . $e->getMessage());
                $this->sendResponse(400, [
                    'success' => false,
                    'message' => 'Location mismatch. You are ' . round($distance) . 'm from required location'
                ]);
            }
            return;
        }
        
        // Check existing attendance for today
        $check = $this->pdo->prepare("
            SELECT * FROM attendance 
            WHERE employee_id = ? AND date = ?
        ");
        $check->execute([$employee_id, $today]);
        $record = $check->fetch();
        
        $this->pdo->beginTransaction();
        
        try {
            $action = '';
            $attendance_id = 0;
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $device_info = $this->getDeviceInfo();
            
            if($record) {
                if(!$record['time_in']) {
                    $current_time = strtotime($now);
                    $eight_thirty_am = strtotime(date('Y-m-d 08:30:00'));
                    $status = ($current_time > $eight_thirty_am) ? 'Late' : 'Present';
                    
                    $stmt = $this->pdo->prepare("
                        UPDATE attendance 
                        SET time_in = ?,
                            status = ?,
                            scan_location_lat = ?,
                            scan_location_lng = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$now, $status, $user_lat, $user_lng, $record['id']]);
                    
                    $action = 'time_in';
                    $attendance_id = $record['id'];
                    
                    $new_values = ['time_in' => $now, 'status' => $status];
                    $this->logAudit('UPDATE', 'attendance', $record['id'], null, $new_values);
                    
                } elseif($record['time_in'] && !$record['time_out']) {
                    $stmt = $this->pdo->prepare("
                        UPDATE attendance 
                        SET time_out = ?,
                            total_hours = ROUND(TIMESTAMPDIFF(MINUTE, time_in, ?) / 60.0, 2),
                            scan_location_lat = ?,
                            scan_location_lng = ?
                        WHERE id = ?
                    ");
                    $stmt->execute([$now, $now, $user_lat, $user_lng, $record['id']]);
                    
                    $action = 'time_out';
                    $attendance_id = $record['id'];
                    
                    $new_values = ['time_out' => $now, 'total_hours' => round((strtotime($now) - strtotime($record['time_in'])) / 3600, 2)];
                    $this->logAudit('UPDATE', 'attendance', $record['id'], null, $new_values);
                    
                } else {
                    $this->sendResponse(400, ['success' => false, 'message' => 'Attendance already completed for today']);
                    $this->pdo->rollBack();
                    return;
                }
            } else {
                $current_time = strtotime($now);
                $eight_thirty_am = strtotime(date('Y-m-d 08:30:00'));
                $status = ($current_time > $eight_thirty_am) ? 'Late' : 'Present';
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO attendance 
                    (clinic_id, employee_id, date, time_in, status, remarks, attendance_method, scan_location_lat, scan_location_lng) 
                    VALUES (?, ?, ?, ?, ?, ?, 'qr_scan', ?, ?)
                ");
                
                $stmt->execute([$this->clinic_id, $employee_id, $today, $now, $status, 'QR Scan', $user_lat, $user_lng]);
                
                $action = 'time_in';
                $attendance_id = $this->pdo->lastInsertId();
                
                $new_values = [
                    'employee_id' => $employee_id,
                    'date' => $today,
                    'time_in' => $now,
                    'status' => $status,
                    'attendance_method' => 'qr_scan'
                ];
                $this->logAudit('CREATE', 'attendance', $attendance_id, null, $new_values);
            }
            
            $log_remarks = 'Location verified (Distance: ' . round($distance) . 'm)';
            $qr_token_used = $qr_token;
            
            $log_stmt = $this->pdo->prepare("
                INSERT INTO attendance_logs 
                (clinic_id, attendance_id, employee_id, action_type, qr_token_used, scan_time, 
                latitude, longitude, is_within_geo, status, remarks,
                ip_address, device_info) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $log_stmt->execute([
                $this->clinic_id, $attendance_id, $employee_id, $action, $qr_token_used, $now,
                $user_lat, $user_lng, 1, 'success', $log_remarks,
                $ip_address, $device_info
            ]);
            
            $this->pdo->commit();
            
            $message = ucfirst(str_replace('_', ' ', $action)) . ' recorded successfully';
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => $message,
                'within_geo' => true,
                'distance' => round($distance),
                'max_distance' => $qr_radius,
                'action' => $action
            ]);
            
        } catch(Exception $e) {
            $this->pdo->rollBack();
            error_log("Scan Error: " . $e->getMessage());
            $this->sendResponse(500, ['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
    
    private function autoAbsent() {
        $today = date('Y-m-d');
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                SELECT e.id 
                FROM employees e
                JOIN users u ON e.user_id = u.id
                WHERE e.status = 'Active' AND u.clinic_id = ?
                AND NOT EXISTS (
                    SELECT 1 FROM attendance a 
                    WHERE a.employee_id = e.id AND a.date = ?
                )
            ");
            $stmt->execute([$this->clinic_id, $today]);
            $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $inserted = 0;
            $errors = [];
            
            foreach ($employees as $employee) {
                try {
                    $insert_stmt = $this->pdo->prepare("
                        INSERT INTO attendance (employee_id, date, status, remarks, attendance_method)
                        VALUES (?, ?, 'Absent', 'Auto-marked (No QR scan)', 'auto')
                    ");
                    
                    if ($insert_stmt->execute([$employee['id'], $today])) {
                        $attendance_id = $this->pdo->lastInsertId();
                        
                        $new_values = [
                            'employee_id' => $employee['id'],
                            'date' => $today,
                            'status' => 'Absent',
                            'remarks' => 'Auto-marked (No QR scan)'
                        ];
                        $this->logAudit('CREATE', 'attendance', $attendance_id, null, $new_values);
                        $inserted++;
                    } else {
                        $errorInfo = $insert_stmt->errorInfo();
                        $errors[] = "Employee ID {$employee['id']}: " . ($errorInfo[2] ?? 'Unknown error');
                    }
                } catch (Exception $e) {
                    $errors[] = "Employee ID {$employee['id']}: " . $e->getMessage();
                    continue;
                }
            }
            
            $this->pdo->commit();
            
            $this->sendResponse(200, [
                'success' => true,
                'message' => 'Auto-absent completed: ' . $inserted . ' employees marked absent',
                'inserted' => $inserted,
                'errors' => $errors
            ]);
        } catch(Exception $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->sendResponse(500, ['error' => 'Database error: ' . $e->getMessage()]);
        }
    }

        // ============= MANUAL APPROVE ATTENDANCE METHOD =============
    private function approveAttendance($attendance_id, $remarks = null) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE attendance 
                SET approval_status = 'approved',
                    approved_by = ?,
                    approved_at = NOW(),
                    remarks = CONCAT(IFNULL(remarks, ''), ' | Manually approved by: ', ?)
                WHERE id = ? AND approval_status = 'pending'
            ");
            $stmt->execute([$this->user_id, $this->user_id, $attendance_id]);
            
            if ($stmt->rowCount() > 0) {
                $this->logAudit('MANUAL_APPROVE', 'attendance', $attendance_id, null, ['approval_status' => 'approved']);
                return ['success' => true, 'message' => 'Attendance approved successfully'];
            }
            
            return ['success' => false, 'message' => 'Attendance not found or already approved'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    // ============= MANUAL REJECT ATTENDANCE METHOD =============
    private function rejectAttendance($attendance_id, $reason) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE attendance 
                SET approval_status = 'rejected',
                    approved_by = ?,
                    approved_at = NOW(),
                    remarks = CONCAT(IFNULL(remarks, ''), ' | REJECTED: ', ?)
                WHERE id = ? AND approval_status = 'pending'
            ");
            $stmt->execute([$this->user_id, $reason, $attendance_id]);
            
            if ($stmt->rowCount() > 0) {
                $this->logAudit('MANUAL_REJECT', 'attendance', $attendance_id, null, ['approval_status' => 'rejected', 'reason' => $reason]);
                return ['success' => true, 'message' => 'Attendance rejected'];
            }
            
            return ['success' => false, 'message' => 'Attendance not found or already processed'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
?>