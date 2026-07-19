<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$clinic_id = $_SESSION['clinic_id'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'Unknown User';

// ✅ RBAC Permission Helper Class for Payment Configuration
class PaymentConfigPermission {
    private static $module = 'payment-configuration';
    
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
                echo json_encode(['error' => 'Permission denied: Cannot ' . $action . ' payment configuration']);
                exit;
            }
            return false;
        }
        return true;
    }
}

// ============= AUDIT LOG FUNCTION =============
function logAudit($pdo, $user_id, $clinic_id, $action, $table_name, $record_id = null, $old_values = null, $new_values = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    $old_json = $old_values ? json_encode($old_values) : null;
    $new_json = $new_values ? json_encode($new_values) : null;
    
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$user_id, $clinic_id, $action, $table_name, $record_id, $old_json, $new_json, $ip_address, $user_agent]);
}

// ============= GET PERMISSIONS ENDPOINT =============
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_permissions'])) {
    // ✅ RBAC Check
    PaymentConfigPermission::check('view');
    
    $hasHR = false;
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND role = 'HR' AND status = 'Active'");
    $stmt->execute([$user_id]);
    $hasHR = $stmt->fetchColumn() > 0;
    
    $permissions = [
        'view' => PaymentConfigPermission::can('view'),
        'create' => PaymentConfigPermission::can('create'),
        'edit' => PaymentConfigPermission::can('edit'),
        'delete' => PaymentConfigPermission::can('delete'),
        'approve' => PaymentConfigPermission::can('approve'),
        'reject' => PaymentConfigPermission::can('reject')
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'role' => $user_role,
            'permissions' => $permissions,
            'hasHR' => $hasHR,
            'isOwner' => ($user_role === 'ClinicAdmin' && !$hasHR),
            'user_id' => $user_id,
            'user_name' => $user_name
        ]
    ]);
    exit;
}

// ============= GET PAYMENT SETTINGS =============
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ✅ RBAC Check
    PaymentConfigPermission::check('view');
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                payment_policy,
                downpayment_percentage,
                payment_method_online,
                payment_method_onsite,
                booking_flow,
                created_at,
                updated_at
            FROM clinics 
            WHERE id = ?
        ");
        $stmt->execute([$clinic_id]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$settings) {
            // Return default settings if none found
            $settings = [
                'payment_policy' => 'full_payment',
                'downpayment_percentage' => 30,
                'payment_method_online' => 1,
                'payment_method_onsite' => 1,
                'booking_flow' => 'approve_first',
                'created_at' => null,
                'updated_at' => null
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $settings
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============= UPDATE PAYMENT SETTINGS =============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? '';
        $data = $input['data'] ?? [];
        
        if ($type === 'save_payment_settings') {
            // ✅ CHECK EDIT PERMISSION
            PaymentConfigPermission::check('edit');
            
            // Validate payment policy
            $validPolicies = ['full_payment', 'downpayment_30', 'downpayment_custom', 'no_payment', 'pay_on_site'];
            if (!in_array($data['payment_policy'], $validPolicies)) {
                echo json_encode(['error' => 'Invalid payment policy']);
                exit;
            }
            
            // Validate booking flow
            $validFlows = ['approve_first', 'pay_first'];
            if (!in_array($data['booking_flow'], $validFlows)) {
                echo json_encode(['error' => 'Invalid booking flow']);
                exit;
            }
            
            // Validate downpayment percentage
            $downpayment = isset($data['downpayment_percentage']) ? intval($data['downpayment_percentage']) : 30;
            if ($downpayment < 10 || $downpayment > 90) {
                echo json_encode(['error' => 'Downpayment percentage must be between 10% and 90%']);
                exit;
            }
            
            // ✅ GET OLD VALUES FOR AUDIT
            $oldStmt = $pdo->prepare("
                SELECT payment_policy, downpayment_percentage, payment_method_online, payment_method_onsite, booking_flow
                FROM clinics WHERE id = ?
            ");
            $oldStmt->execute([$clinic_id]);
            $old_values = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            // Update clinic with booking_flow included
            $stmt = $pdo->prepare("
                UPDATE clinics SET 
                    payment_policy = ?,
                    downpayment_percentage = ?,
                    payment_method_online = ?,
                    payment_method_onsite = ?,
                    booking_flow = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $data['payment_policy'],
                $downpayment,
                $data['payment_method_online'] ? 1 : 0,
                $data['payment_method_onsite'] ? 1 : 0,
                $data['booking_flow'],
                $clinic_id
            ]);
            
            if ($result) {
                // ✅ GET NEW VALUES FOR AUDIT
                $newStmt = $pdo->prepare("
                    SELECT payment_policy, downpayment_percentage, payment_method_online, payment_method_onsite, booking_flow
                    FROM clinics WHERE id = ?
                ");
                $newStmt->execute([$clinic_id]);
                $new_values = $newStmt->fetch(PDO::FETCH_ASSOC);
                
                // Log audit
                logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 'clinics', $clinic_id, $old_values, $new_values);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Payment settings updated successfully',
                    'data' => $new_values
                ]);
            } else {
                echo json_encode(['error' => 'Failed to save payment settings']);
            }
            exit;
        }
        
        // Handle other POST actions (create, delete, etc.)
        if ($type === 'create_payment_method') {
            // ✅ CHECK CREATE PERMISSION
            PaymentConfigPermission::check('create');
            
            // Implementation for creating new payment methods
            // ... (add your logic here)
            
            echo json_encode(['success' => true, 'message' => 'Payment method created']);
            exit;
        }
        
        if ($type === 'delete_payment_method') {
            // ✅ CHECK DELETE PERMISSION
            PaymentConfigPermission::check('delete');
            
            // Implementation for deleting payment methods
            // ... (add your logic here)
            
            echo json_encode(['success' => true, 'message' => 'Payment method deleted']);
            exit;
        }
        
        echo json_encode(['error' => 'Invalid action type']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============= APPROVE PAYMENT CONFIGURATION =============
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['action']) && $_GET['action'] === 'approve') {
    // ✅ CHECK APPROVE PERMISSION
    PaymentConfigPermission::check('approve');
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $config_id = $data['id'] ?? 0;
        
        if (!$config_id) {
            echo json_encode(['error' => 'Configuration ID is required']);
            exit;
        }
        
        // Get old values for audit
        $oldStmt = $pdo->prepare("SELECT * FROM payment_config_approvals WHERE id = ? AND clinic_id = ?");
        $oldStmt->execute([$config_id, $clinic_id]);
        $old_config = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_config) {
            echo json_encode(['error' => 'Configuration not found']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        // Update approval status
        $updateStmt = $pdo->prepare("
            UPDATE payment_config_approvals 
            SET status = 'approved', 
                approved_by = ?, 
                approved_at = NOW(),
                updated_at = NOW()
            WHERE id = ? AND clinic_id = ?
        ");
        $updateStmt->execute([$user_id, $config_id, $clinic_id]);
        
        // Apply the approved settings to clinic
        $applyStmt = $pdo->prepare("
            UPDATE clinics 
            SET payment_policy = ?,
                downpayment_percentage = ?,
                payment_method_online = ?,
                payment_method_onsite = ?,
                booking_flow = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $applyStmt->execute([
            $old_config['payment_policy'],
            $old_config['downpayment_percentage'],
            $old_config['payment_method_online'],
            $old_config['payment_method_onsite'],
            $old_config['booking_flow'],
            $clinic_id
        ]);
        
        // Log audit
        logAudit($pdo, $user_id, $clinic_id, 'APPROVE', 'payment_config_approvals', $config_id, $old_config, ['status' => 'approved']);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Payment configuration approved']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============= REJECT PAYMENT CONFIGURATION =============
if ($_SERVER['REQUEST_METHOD'] === 'PUT' && isset($_GET['action']) && $_GET['action'] === 'reject') {
    // ✅ CHECK REJECT PERMISSION
    PaymentConfigPermission::check('reject');
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $config_id = $data['id'] ?? 0;
        $reason = $data['reason'] ?? '';
        
        if (!$config_id) {
            echo json_encode(['error' => 'Configuration ID is required']);
            exit;
        }
        
        if (empty($reason)) {
            echo json_encode(['error' => 'Rejection reason is required']);
            exit;
        }
        
        // Get old values for audit
        $oldStmt = $pdo->prepare("SELECT * FROM payment_config_approvals WHERE id = ? AND clinic_id = ?");
        $oldStmt->execute([$config_id, $clinic_id]);
        $old_config = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_config) {
            echo json_encode(['error' => 'Configuration not found']);
            exit;
        }
        
        // Update rejection status
        $updateStmt = $pdo->prepare("
            UPDATE payment_config_approvals 
            SET status = 'rejected', 
                rejected_by = ?, 
                rejected_at = NOW(),
                rejection_reason = ?,
                updated_at = NOW()
            WHERE id = ? AND clinic_id = ?
        ");
        $updateStmt->execute([$user_id, $reason, $config_id, $clinic_id]);
        
        // Log audit
        logAudit($pdo, $user_id, $clinic_id, 'REJECT', 'payment_config_approvals', $config_id, $old_config, ['status' => 'rejected', 'reason' => $reason]);
        
        echo json_encode(['success' => true, 'message' => 'Payment configuration rejected']);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============= GET PAYMENT CONFIGURATION HISTORY =============
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'history') {
    // ✅ CHECK VIEW PERMISSION
    PaymentConfigPermission::check('view');
    
    try {
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 20;
        
        $stmt = $pdo->prepare("
            SELECT ca.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
                   CONCAT(a.first_name, ' ', a.last_name) as approved_by_name,
                   CONCAT(r.first_name, ' ', r.last_name) as rejected_by_name
            FROM payment_config_approvals ca
            LEFT JOIN users u ON ca.requested_by = u.id
            LEFT JOIN users a ON ca.approved_by = a.id
            LEFT JOIN users r ON ca.rejected_by = r.id
            WHERE ca.clinic_id = ?
            ORDER BY ca.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$clinic_id, $limit]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $history
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Default response for invalid actions
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>