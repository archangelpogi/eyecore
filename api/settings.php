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

// Load permissions to session
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access']);
    exit;
}

$user_id = $_SESSION['user_id'];
$clinic_id = $_SESSION['clinic_id'];
$user_role = $_SESSION['role'];

// ✅ RBAC: Only ClinicAdmin can access settings
if ($user_role !== 'ClinicAdmin') {
    http_response_code(403);
    echo json_encode(['error' => 'You do not have permission to access settings']);
    exit;
}

// ✅ RBAC Permission helper functions
function canViewSettings() { return RBACHelper::hasPermission('settings_view'); }
function canEditSettings() { return RBACHelper::hasPermission('settings_edit'); }

/* =====================================================
   AUDIT LOG FUNCTION
===================================================== */
function logAudit($pdo, $user_id, $clinic_id, $action, $setting_key, $old_value = null, $new_value = null) {
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs 
        (user_id, clinic_id, action, table_name, record_id, old_values, new_values, ip_address, user_agent, created_at)
        VALUES (?, ?, ?, 'settings', ?, ?, ?, ?, ?, NOW())
    ");
    
    $old_json = $old_value ? json_encode([$setting_key => $old_value]) : null;
    $new_json = $new_value ? json_encode([$setting_key => $new_value]) : null;
    
    return $stmt->execute([
        $user_id,
        $clinic_id,
        $action,
        $setting_key,
        $old_json,
        $new_json,
        $ip_address,
        $user_agent
    ]);
}

/* =====================================================
   GET SETTINGS
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // ✅ Check view permission
    if (!canViewSettings()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied: Cannot view settings']);
        exit;
    }
    
    try {
        $action = $_GET['action'] ?? '';
        
        // ===== GET CLINIC DETAILS FOR EDITING =====
        if ($action === 'get_clinic_details') {
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    clinic_name,
                    description,
                    contact,
                    clinic_email,
                    address,
                    city,
                    province,
                    postal_code,
                    hours,
                    days,
                    latitude,
                    longitude,
                    status,
                    logo,
                    cover_photo,
                    radius
                FROM clinics 
                WHERE id = ?
            ");
            $stmt->execute([$clinic_id]);
            $clinic = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($clinic) {
                echo json_encode(['success' => true, 'data' => $clinic]);
            } else {
                echo json_encode(['error' => 'Clinic not found']);
            }
            exit;
        }
        
        // ===== GET WORKING HOURS =====
        if ($action === 'get_working_hours') {
            $stmt = $pdo->prepare("
                SELECT setting_value FROM settings 
                WHERE clinic_id = ? AND setting_key = 'working_hours'
            ");
            $stmt->execute([$clinic_id]);
            $working_hours = $stmt->fetchColumn();
            
            $working_hours_data = $working_hours ? json_decode($working_hours, true) : [];
            
            echo json_encode(['success' => true, 'working_hours' => $working_hours_data]);
            exit;
        }
        
        // ===== GET MODULE VISIBILITY SETTINGS =====
        if ($action === 'get_module_visibility') {
            $stmt = $pdo->prepare("
                SELECT 
                    m.group_name,
                    COALESCE(v.enabled, 1) as enabled
                FROM (
                    SELECT DISTINCT group_name 
                    FROM menu_items 
                    WHERE is_active = 1
                ) m
                LEFT JOIN clinic_menu_visibility v 
                    ON v.group_name = m.group_name 
                    AND v.clinic_id = ?
                ORDER BY m.group_name
            ");
            $stmt->execute([$clinic_id]);
            $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'modules' => $modules]);
            exit;
        }
        
        // Default: Return all settings
        $stmt = $pdo->prepare("
            SELECT setting_key, setting_value 
            FROM settings 
            WHERE clinic_id = ?
        ");
        $stmt->execute([$clinic_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        // Get clinic data
        $clinicStmt = $pdo->prepare("
            SELECT 
                clinic_name,
                description,
                contact,
                clinic_email,
                address,
                city,
                province,
                postal_code,
                hours,
                days,
                latitude,
                longitude,
                status,
                logo,
                cover_photo,
                radius
            FROM clinics 
            WHERE id = ?
        ");
        $clinicStmt->execute([$clinic_id]);
        $clinic = $clinicStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($clinic) {
            $settings['clinic_name'] = $clinic['clinic_name'];
            $settings['description'] = $clinic['description'];
            $settings['contact'] = $clinic['contact'];
            $settings['clinic_email'] = $clinic['clinic_email'];
            $settings['address'] = $clinic['address'];
            $settings['city'] = $clinic['city'];
            $settings['province'] = $clinic['province'];
            $settings['postal_code'] = $clinic['postal_code'];
            $settings['hours'] = $clinic['hours'];
            $settings['days'] = $clinic['days'];
            $settings['latitude'] = $clinic['latitude'];
            $settings['longitude'] = $clinic['longitude'];
            $settings['status'] = $clinic['status'];
            $settings['logo'] = $clinic['logo'];
            $settings['cover_photo'] = $clinic['cover_photo'];
            $settings['radius'] = $clinic['radius'] ?? 100;
        }
        
        logAudit($pdo, $user_id, $clinic_id, 'VIEW', 'settings_all', null, null);
        
        echo json_encode($settings);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

/* =====================================================
   POST SETTINGS - SAVE TO DATABASE
===================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ✅ Check edit permission
    if (!canEditSettings()) {
        http_response_code(403);
        echo json_encode(['error' => 'Permission denied: Cannot edit settings']);
        exit;
    }
    
    try {
        // ===== HANDLE CLINIC LOGO UPLOAD =====
        if (isset($_FILES['clinic_logo'])) {
            $file = $_FILES['clinic_logo'];
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 2 * 1024 * 1024;
            
            if (!in_array($file['type'], $allowed_types)) {
                echo json_encode(['error' => 'Only JPG, PNG, GIF, and WEBP images are allowed']);
                exit;
            }
            
            if ($file['size'] > $max_size) {
                echo json_encode(['error' => 'File size exceeds 2MB limit']);
                exit;
            }
            
            $upload_dir = __DIR__ . "/../assets/images/clinic-logos/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "clinic_{$clinic_id}_logo_" . time() . "." . $extension;
            $filepath = $upload_dir . $filename;
            
            $oldStmt = $pdo->prepare("SELECT logo FROM clinics WHERE id = ?");
            $oldStmt->execute([$clinic_id]);
            $old_logo = $oldStmt->fetchColumn();
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $stmt = $pdo->prepare("UPDATE clinics SET logo = ? WHERE id = ?");
                $stmt->execute([$filename, $clinic_id]);
                
                if ($old_logo && file_exists($upload_dir . $old_logo)) {
                    unlink($upload_dir . $old_logo);
                }
                
                logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 'logo', $old_logo, $filename);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Logo uploaded successfully',
                    'filename' => $filename
                ]);
            } else {
                echo json_encode(['error' => 'Failed to upload file']);
            }
            exit;
        }
        
        // ===== HANDLE CLINIC COVER UPLOAD =====
        if (isset($_FILES['clinic_cover'])) {
            $file = $_FILES['clinic_cover'];
            
            $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $max_size = 2 * 1024 * 1024;
            
            if (!in_array($file['type'], $allowed_types)) {
                echo json_encode(['error' => 'Only JPG, PNG, GIF, and WEBP images are allowed']);
                exit;
            }
            
            if ($file['size'] > $max_size) {
                echo json_encode(['error' => 'File size exceeds 2MB limit']);
                exit;
            }
            
            $upload_dir = __DIR__ . "/../assets/images/clinic-covers/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = "clinic_{$clinic_id}_cover_" . time() . "." . $extension;
            $filepath = $upload_dir . $filename;
            
            $oldStmt = $pdo->prepare("SELECT cover_photo FROM clinics WHERE id = ?");
            $oldStmt->execute([$clinic_id]);
            $old_cover = $oldStmt->fetchColumn();
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $stmt = $pdo->prepare("UPDATE clinics SET cover_photo = ? WHERE id = ?");
                $stmt->execute([$filename, $clinic_id]);
                
                if ($old_cover && file_exists($upload_dir . $old_cover)) {
                    unlink($upload_dir . $old_cover);
                }
                
                logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 'cover_photo', $old_cover, $filename);
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Cover photo uploaded successfully',
                    'filename' => $filename
                ]);
            } else {
                echo json_encode(['error' => 'Failed to upload file']);
            }
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            echo json_encode(['error' => 'Invalid input']);
            exit;
        }
        
        $type = $input['type'] ?? '';
        $data = $input['data'] ?? [];
        
        // ===== HANDLE CLINIC DETAILS UPDATE =====
        if ($type === 'clinic_details') {
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM clinics WHERE id = ?");
                $stmt->execute([$clinic_id]);
                $old_data = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $updates = [];
                $params = [];
                
                $fieldMap = [
                    'name' => 'clinic_name',
                    'description' => 'description',
                    'contact' => 'contact',
                    'email' => 'clinic_email',
                    'address' => 'address',
                    'city' => 'city',
                    'province' => 'province',
                    'postal' => 'postal_code',
                    'hours' => 'hours',
                    'days' => 'days',
                    'latitude' => 'latitude',
                    'longitude' => 'longitude',
                    'radius' => 'radius',
                    'status' => 'status'
                ];
                
                foreach ($fieldMap as $inputField => $dbField) {
                    if (isset($data[$inputField])) {
                        $updates[] = "$dbField = ?";
                        $params[] = $data[$inputField];
                    }
                }
                
                if (!empty($updates)) {
                    $params[] = $clinic_id;
                    $sql = "UPDATE clinics SET " . implode(', ', $updates) . " WHERE id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    
                    foreach ($fieldMap as $inputField => $dbField) {
                        if (isset($data[$inputField]) && isset($old_data[$dbField]) && $old_data[$dbField] != $data[$inputField]) {
                            logAudit($pdo, $user_id, $clinic_id, 'UPDATE', $dbField, 
                                    $old_data[$dbField], $data[$inputField]);
                        }
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Clinic details updated successfully']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }
        
        // ===== HANDLE WORKING HOURS UPDATE =====
        if ($type === 'working_hours') {
            $setting_key = 'working_hours';
            $jsonData = json_encode($data);
            
            $stmt = $pdo->prepare("
                SELECT setting_value FROM settings 
                WHERE clinic_id = ? AND setting_key = ?
            ");
            $stmt->execute([$clinic_id, $setting_key]);
            $old = $stmt->fetchColumn();
            
            $stmt = $pdo->prepare("
                INSERT INTO settings (clinic_id, setting_key, setting_value) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
            ");
            $stmt->execute([$clinic_id, $setting_key, $jsonData]);
            
            if ($old != $jsonData) {
                logAudit($pdo, $user_id, $clinic_id, 'UPDATE', $setting_key, $old, $jsonData);
            }
            
            echo json_encode(['success' => true]);
            exit;
        }
        
        // ===== HANDLE MODULE VISIBILITY UPDATE =====
        if ($type === 'update_module_visibility') {
            $modules_data = $data['modules'] ?? [];
            
            $pdo->beginTransaction();
            try {
                foreach ($modules_data as $module) {
                    $stmt = $pdo->prepare("
                        INSERT INTO clinic_menu_visibility (clinic_id, group_name, enabled)
                        VALUES (?, ?, ?)
                        ON DUPLICATE KEY UPDATE 
                            enabled = VALUES(enabled),
                            updated_at = NOW()
                    ");
                    $stmt->execute([$clinic_id, $module['group_name'], $module['enabled']]);
                }
                $pdo->commit();
                
                logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 'module_visibility', null, 
                         json_encode($modules_data));
                
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }
        
        // Default error for unsupported types
        echo json_encode(['error' => 'Invalid settings type']);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>