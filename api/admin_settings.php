<?php
// api/settings.php
require_once __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

// Set JSON header
header('Content-Type: application/json');

// Default settings
$default_settings = [
    'site_name' => 'Eyecore SuperAdmin Platform',
    'timezone' => 'Asia/Manila',
    'currency' => 'PHP',
    'language' => 'en',
    'email_notifications' => 1,
    'sms_notifications' => 0,
    'auto_backup' => 1,
    'backup_frequency' => 'daily',
    'maintenance_mode' => 0,
    'session_timeout' => 30,
    'password_expiry' => 90,
    'theme' => 'light',
];

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? '';
    
    switch ($action) {
        case 'get_settings':
            getSettings();
            break;
        case 'get_backups':
            getBackups();
            break;
        case 'download_backup':
            downloadBackup();
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    exit();
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
        exit();
    }
    
    $action = $data['action'] ?? '';
    
    switch ($action) {
        case 'save_settings':
            saveSettings($data['settings'] ?? []);
            break;
        case 'create_backup':
            createBackup();
            break;
        case 'delete_backup':
            deleteBackup($data['filename'] ?? '');
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
            break;
    }
    exit();
}

function getSettings() {
    global $default_settings, $conn;
    
    try {
        ensureSettingsTable();
        
        $settings = $default_settings;
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            $stmt->close();
        }
        
        echo json_encode(['success' => true, 'settings' => $settings]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage(), 'settings' => $default_settings]);
    }
}

function ensureSettingsTable() {
    global $conn, $default_settings;
    
    $result = $conn->query("SHOW TABLES LIKE 'system_settings'");
    if ($result->num_rows == 0) {
        $sql = "CREATE TABLE system_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        if ($conn->query($sql)) {
            foreach ($default_settings as $key => $value) {
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

function saveSettings($settings) {
    global $conn;
    
    try {
        $conn->begin_transaction();
        
        foreach ($settings as $key => $value) {
            // Try update first
            $stmt = $conn->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = ?");
            $stmt->bind_param("ss", $value, $key);
            $stmt->execute();
            
            if ($stmt->affected_rows === 0) {
                $stmt->close();
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
                $stmt->bind_param("ss", $key, $value);
                $stmt->execute();
            }
            
            $stmt->close();
        }
        
        $conn->commit();
        echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
        
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function getBackups() {
    $backups = [];
    $backupDir = __DIR__ . '/../backups/';
    
    if (!file_exists($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    $files = scandir($backupDir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;
        
        $filePath = $backupDir . $file;
        if (is_file($filePath) && pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
            $backups[] = [
                'name' => $file,
                'size' => filesize($filePath),
                'modified' => filemtime($filePath)
            ];
        }
    }
    
    usort($backups, function($a, $b) {
        return $b['modified'] - $a['modified'];
    });
    
    echo json_encode(['success' => true, 'backups' => $backups]);
}

function createBackup() {
    global $conn;
    
    try {
        $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
        $backupDir = __DIR__ . '/../backups/';
        $filePath = $backupDir . $filename;
        
        if (!file_exists($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $tables = [];
        $result = $conn->query("SHOW TABLES");
        while ($row = $result->fetch_row()) {
            $tables[] = $row[0];
        }
        
        $handle = fopen($filePath, 'w');
        fwrite($handle, "-- Database Backup\n-- Generated: " . date('Y-m-d H:i:s') . "\n\n");
        
        foreach ($tables as $table) {
            $createResult = $conn->query("SHOW CREATE TABLE `$table`");
            if ($createRow = $createResult->fetch_row()) {
                fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");
                fwrite($handle, $createRow[1] . ";\n\n");
            }
            $createResult->free();
        }
        
        fclose($handle);
        
        echo json_encode([
            'success' => true, 
            'message' => 'Backup created successfully',
            'filename' => $filename
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
}

function downloadBackup() {
    $filename = $_GET['filename'] ?? '';
    
    if (empty($filename)) {
        http_response_code(400);
        echo 'Filename is required';
        exit();
    }
    
    $backupDir = __DIR__ . '/../backups/';
    $filePath = $backupDir . $filename;
    
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo 'Backup file not found';
        exit();
    }
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit();
}

function deleteBackup($filename) {
    if (empty($filename)) {
        echo json_encode(['success' => false, 'message' => 'Filename is required']);
        return;
    }
    
    $backupDir = __DIR__ . '/../backups/';
    $filePath = $backupDir . $filename;
    
    if (!file_exists($filePath)) {
        echo json_encode(['success' => false, 'message' => 'Backup file not found']);
        return;
    }
    
    if (unlink($filePath)) {
        echo json_encode(['success' => true, 'message' => 'Backup deleted successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to delete backup']);
    }
}
?>