<?php
// Disable error display to ensure JSON response
error_reporting(0);
ini_set('display_errors', 0);

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

// ✅ RBAC Permission helper functions
function canViewLogs() { return RBACHelper::hasPermission('logs_view'); }
function canExportLogs() { return RBACHelper::hasPermission('logs_export'); }
function canDeleteLogs() { return RBACHelper::hasPermission('logs_delete'); }

// Function to return JSON error
function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// Check if logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    jsonError('Unauthorized', 401);
}

$clinic_id = $_SESSION['clinic_id'];
$action = $_GET['action'] ?? '';

try {
    // Check if action is provided
    if (empty($action)) {
        jsonError('Action parameter is required');
    }
    
    switch($action) {
        case 'get_logs':
            // ✅ Check view permission
            if (!canViewLogs()) {
                jsonError('Permission denied: Cannot view logs', 403);
            }
            
            $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            $log_type = $_GET['type'] ?? 'all';
            $search = $_GET['search'] ?? '';
            $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
            $limit = 50;
            $offset = ($page - 1) * $limit;
            
            // Validate dates
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                jsonError('Invalid date format');
            }
            
            // Build query for audit_logs
            $query = "
                SELECT 
                    al.id,
                    al.user_id,
                    al.clinic_id,
                    al.action,
                    al.table_name,
                    al.record_id,
                    al.old_values,
                    al.new_values,
                    al.ip_address,
                    al.created_at,
                    u.first_name,
                    u.last_name,
                    u.role
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.clinic_id = :clinic_id
                AND DATE(al.created_at) BETWEEN :start_date AND :end_date
            ";
            
            $params = [
                ':clinic_id' => $clinic_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];
            
            // Filter by log type
            if($log_type === 'login') {
                $query .= " AND (al.action LIKE '%login%' OR al.action LIKE '%Login%')";
            } elseif($log_type === 'security') {
                $query .= " AND (al.action LIKE '%failed%' OR al.action LIKE '%unauthorized%' OR al.action LIKE '%lock%')";
            } elseif($log_type === 'crud') {
                $query .= " AND (al.action IN ('CREATE', 'UPDATE', 'DELETE', 'INSERT') OR al.action LIKE '%create%' OR al.action LIKE '%update%' OR al.action LIKE '%delete%')";
            }
            
            // Add search filter
            if(!empty($search)) {
                $query .= " AND (al.action LIKE :search OR al.table_name LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR al.ip_address LIKE :search)";
                $params[':search'] = "%$search%";
            }
            
            // Get total count
            $countQuery = str_replace(
                "SELECT al.id, al.user_id, al.clinic_id, al.action, al.table_name, al.record_id, al.old_values, al.new_values, al.ip_address, al.created_at, u.first_name, u.last_name, u.role",
                "SELECT COUNT(*) as total",
                $query
            );
            $countStmt = $pdo->prepare($countQuery);
            $countStmt->execute($params);
            $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Add pagination
            $query .= " ORDER BY al.created_at DESC LIMIT :limit OFFSET :offset";
            $stmt = $pdo->prepare($query);
            
            foreach($params as $key => $value) {
                if($key !== ':limit' && $key !== ':offset') {
                    $stmt->bindValue($key, $value);
                }
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Format logs
            $logs = [];
            foreach($auditLogs as $log) {
                $logs[] = [
                    'id' => $log['id'],
                    'user_name' => !empty($log['first_name']) ? $log['first_name'] . ' ' . $log['last_name'] : 'System',
                    'role' => $log['role'] ?? 'System',
                    'action' => $log['action'] ?? 'Unknown',
                    'table_name' => $log['table_name'] ?? '-',
                    'details' => formatAuditDetails($log),
                    'ip_address' => $log['ip_address'] ?? '-',
                    'created_at' => $log['created_at']
                ];
            }
            
            // Get statistics
            $stats = getStatistics($pdo, $clinic_id, $start_date, $end_date);
            
            echo json_encode([
                'success' => true,
                'logs' => $logs,
                'stats' => $stats,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => ceil($total / $limit),
                    'total_records' => $total,
                    'per_page' => $limit
                ]
            ]);
            break;
            
        case 'export':
            // ✅ Check export permission
            if (!canExportLogs()) {
                jsonError('Permission denied: Cannot export logs', 403);
            }
            
            $start_date = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
            $end_date = $_GET['end_date'] ?? date('Y-m-d');
            $log_type = $_GET['type'] ?? 'all';
            
            // Validate dates
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
                jsonError('Invalid date format');
            }
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="activity_logs_' . $start_date . '_to_' . $end_date . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Timestamp', 'User', 'Role', 'Action', 'Table/Module', 'Details', 'IP Address']);
            
            $query = "
                SELECT 
                    al.id,
                    al.action,
                    al.table_name,
                    al.details,
                    al.ip_address,
                    al.created_at,
                    u.first_name,
                    u.last_name,
                    u.role
                FROM audit_logs al
                LEFT JOIN users u ON al.user_id = u.id
                WHERE al.clinic_id = :clinic_id
                AND DATE(al.created_at) BETWEEN :start_date AND :end_date
                ORDER BY al.created_at DESC
            ";
            
            $params = [
                ':clinic_id' => $clinic_id,
                ':start_date' => $start_date,
                ':end_date' => $end_date
            ];
            
            if($log_type === 'login') {
                $query .= " AND al.action LIKE '%login%'";
            } elseif($log_type === 'security') {
                $query .= " AND (al.action LIKE '%failed%' OR al.action LIKE '%unauthorized%')";
            } elseif($log_type === 'crud') {
                $query .= " AND (al.action IN ('CREATE', 'UPDATE', 'DELETE') OR al.action LIKE '%create%' OR al.action LIKE '%update%' OR al.action LIKE '%delete%')";
            }
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            
            while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $userName = !empty($row['first_name']) ? $row['first_name'] . ' ' . $row['last_name'] : 'System';
                $details = formatAuditDetails($row);
                
                fputcsv($output, [
                    $row['id'],
                    $row['created_at'],
                    $userName,
                    $row['role'] ?? 'System',
                    $row['action'],
                    $row['table_name'],
                    $details,
                    $row['ip_address']
                ]);
            }
            
            fclose($output);
            break;
            
        case 'delete':
            // ✅ Check delete permission
            if (!canDeleteLogs()) {
                jsonError('Permission denied: Cannot delete logs', 403);
            }
            
            // Get parameters
            $log_ids = $_POST['ids'] ?? $_GET['ids'] ?? [];
            $delete_all = isset($_POST['delete_all']) ? (bool)$_POST['delete_all'] : false;
            $before_date = $_POST['before_date'] ?? $_GET['before_date'] ?? null;
            
            try {
                $pdo->beginTransaction();
                
                if ($delete_all) {
                    // Delete all logs before a certain date
                    if ($before_date) {
                        $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE clinic_id = ? AND DATE(created_at) <= ?");
                        $stmt->execute([$clinic_id, $before_date]);
                        $deleted_count = $stmt->rowCount();
                    } else {
                        // Delete all logs for this clinic
                        $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE clinic_id = ?");
                        $stmt->execute([$clinic_id]);
                        $deleted_count = $stmt->rowCount();
                    }
                } elseif (!empty($log_ids)) {
                    // Delete specific logs by IDs
                    if (is_array($log_ids)) {
                        $placeholders = implode(',', array_fill(0, count($log_ids), '?'));
                        $params = array_merge([$clinic_id], $log_ids);
                        $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE clinic_id = ? AND id IN ($placeholders)");
                        $stmt->execute($params);
                        $deleted_count = $stmt->rowCount();
                    } else {
                        // Single ID
                        $stmt = $pdo->prepare("DELETE FROM audit_logs WHERE clinic_id = ? AND id = ?");
                        $stmt->execute([$clinic_id, $log_ids]);
                        $deleted_count = $stmt->rowCount();
                    }
                } else {
                    jsonError('No logs selected for deletion');
                }
                
                $pdo->commit();
                
                // Log this deletion action
                $auditStmt = $pdo->prepare("
                    INSERT INTO audit_logs (user_id, clinic_id, action, table_name, new_values, ip_address, created_at)
                    VALUES (?, ?, 'DELETE', 'audit_logs', ?, ?, NOW())
                ");
                $auditStmt->execute([
                    $_SESSION['user_id'],
                    $clinic_id,
                    json_encode(['deleted_count' => $deleted_count, 'delete_all' => $delete_all, 'before_date' => $before_date]),
                    $_SERVER['REMOTE_ADDR'] ?? null
                ]);
                
                echo json_encode([
                    'success' => true,
                    'message' => "Successfully deleted {$deleted_count} log(s)",
                    'deleted_count' => $deleted_count
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Delete logs error: " . $e->getMessage());
                jsonError('Failed to delete logs: ' . $e->getMessage(), 500);
            }
            break;
            
        default:
            jsonError('Invalid action: ' . $action);
    }
    
} catch (PDOException $e) {
    error_log("Logs API Error: " . $e->getMessage());
    jsonError('Database error: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    error_log("Logs API Error: " . $e->getMessage());
    jsonError('Server error: ' . $e->getMessage(), 500);
}

function formatAuditDetails($log) {
    $details = [];
    
    if(!empty($log['details'])) {
        $details[] = $log['details'];
    }
    
    if(!empty($log['old_values']) && $log['old_values'] !== 'null' && $log['old_values'] !== null) {
        $old = json_decode($log['old_values'], true);
        if($old && is_array($old) && count($old) > 0) {
            $details[] = 'Old: ' . json_encode($old);
        }
    }
    
    if(!empty($log['new_values']) && $log['new_values'] !== 'null' && $log['new_values'] !== null) {
        $new = json_decode($log['new_values'], true);
        if($new && is_array($new) && count($new) > 0) {
            $details[] = 'New: ' . json_encode($new);
        }
    }
    
    return implode(' | ', $details) ?: 'No details';
}

function getStatistics($pdo, $clinic_id, $start_date, $end_date) {
    $stats = ['total' => 0, 'login' => 0, 'failed' => 0, 'crud' => 0];
    
    try {
        // Total events
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM audit_logs WHERE clinic_id = ? AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$clinic_id, $start_date, $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['total'] = (int)($result['total'] ?? 0);
        
        // Login events
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM audit_logs WHERE clinic_id = ? AND (action LIKE '%login%' OR action LIKE '%Login%') AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$clinic_id, $start_date, $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['login'] = (int)($result['total'] ?? 0);
        
        // Failed events
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM audit_logs WHERE clinic_id = ? AND (action LIKE '%failed%' OR action LIKE '%Failed%') AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$clinic_id, $start_date, $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['failed'] = (int)($result['total'] ?? 0);
        
        // CRUD events
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM audit_logs WHERE clinic_id = ? AND (action IN ('CREATE', 'UPDATE', 'DELETE', 'INSERT') OR action LIKE '%create%' OR action LIKE '%update%' OR action LIKE '%delete%') AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$clinic_id, $start_date, $end_date]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $stats['crud'] = (int)($result['total'] ?? 0);
        
    } catch (Exception $e) {
        error_log("Error getting statistics: " . $e->getMessage());
    }
    
    return $stats;
}
?>