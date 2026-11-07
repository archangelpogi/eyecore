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
        
        // ===== ✅ GET DELIVERY SETTINGS =====
        if ($action === 'get_delivery_settings') {
            $stmt = $pdo->prepare("
                SELECT offers_delivery, delivery_fee, delivery_radius_km, free_delivery_minimum
                FROM clinics 
                WHERE id = ?
            ");
            $stmt->execute([$clinic_id]);
            $delivery = $stmt->fetch(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $delivery ?: [
                    'offers_delivery' => 0,
                    'delivery_fee' => 0,
                    'delivery_radius_km' => 0,
                    'free_delivery_minimum' => 0
                ]
            ]);
            exit;
        }
        
        // ═══════════════════════════════════════════════════
        // ✅ NEW: GET DELIVERY FEES (per-city list)
        // ═══════════════════════════════════════════════════
        if ($action === 'get_delivery_fees') {
            $stmt = $pdo->prepare("
                SELECT id, region, city, barangay, fee_amount, estimated_days, is_active
                FROM delivery_fees 
                WHERE clinic_id = ?
                ORDER BY city ASC
            ");
            $stmt->execute([$clinic_id]);
            $fees = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'data' => $fees ?: []
            ]);
            exit;
        }
        
if ($action === 'get_documents') {
    // Auto-update expired docs to "Awaiting Re-upload"
    $pdo->prepare("
        UPDATE clinic_documents 
        SET status = 'Awaiting Re-upload'
        WHERE clinic_id = ? 
          AND expires_at IS NOT NULL 
          AND expires_at < CURDATE()
          AND status = 'Approved'
    ")->execute([$clinic_id]);
    
    // Required documents list
    $required_docs = [
        'Business Permit',
        'DTI',
        'SEC',
        'BIR',
        'Optometrist License',
        'Barangay Clearance',
        'Health Clearance',
        'Insurance',
        'Other Documents'
    ];
    
    // Get existing docs
    $stmt = $pdo->prepare("
        SELECT id, document_type, owner_name, released_at, expires_at,
               file_path, status, remarks, rejection_reason,
               uploaded_at, reviewed_at, submission_attempts, 
               is_resubmitted, is_latest
        FROM clinic_documents
        WHERE clinic_id = ? AND is_latest = 1
        ORDER BY document_type ASC
    ");
    $stmt->execute([$clinic_id]);
    $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Index by document_type
    $existing_by_type = [];
    foreach ($existing as $doc) {
        $existing_by_type[$doc['document_type']] = $doc;
    }
    
    // Build complete list with status
    $documents = [];
    $missing_count = 0;
    $action_count = 0;
    $complete_count = 0;
    
    foreach ($required_docs as $doc_type) {
        if (isset($existing_by_type[$doc_type])) {
            $doc = $existing_by_type[$doc_type];
            
            // Compute flags
            $expires = $doc['expires_at'];
            $status = $doc['status'];
            
            $is_expired = false;
            $is_expiring_soon = false;
            $days_left = null;
            
            if ($expires) {
                $days_left = (int)((strtotime($expires) - time()) / 86400);
                if ($days_left < 0) {
                    $is_expired = true;
                } elseif ($days_left <= 30) {
                    $is_expiring_soon = true;
                }
            }
            
            // Can upload? (kung expired, expiring, rejected, awaiting)
            $can_upload = in_array($status, ['Awaiting Re-upload', 'Rejected']);
            $needs_action = $is_expired || $is_expiring_soon || $can_upload;
            
            if ($needs_action) $action_count++;
            if ($status === 'Approved' && !$is_expired && !$is_expiring_soon) $complete_count++;
            
            $documents[] = array_merge($doc, [
                'is_required' => true,
                'is_missing' => false,
                'is_expired' => $is_expired,
                'is_expiring_soon' => $is_expiring_soon,
                'days_left' => $days_left,
                'can_upload' => $can_upload,
                'needs_action' => $needs_action
            ]);
        } else {
            // Missing document
            $missing_count++;
            $action_count++;
            
            $documents[] = [
                'id' => null,
                'document_type' => $doc_type,
                'owner_name' => null,
                'released_at' => null,
                'expires_at' => null,
                'file_path' => null,
                'status' => 'Missing',
                'remarks' => null,
                'rejection_reason' => null,
                'uploaded_at' => null,
                'reviewed_at' => null,
                'submission_attempts' => 0,
                'is_resubmitted' => 0,
                'is_latest' => 1,
                'is_required' => true,
                'is_missing' => true,
                'is_expired' => false,
                'is_expiring_soon' => false,
                'days_left' => null,
                'can_upload' => true,
                'needs_action' => true
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $documents,
        'summary' => [
            'total_required' => count($required_docs),
            'complete' => $complete_count,
            'missing' => $missing_count,
            'action_required' => $action_count,
            'all_valid' => ($action_count === 0)
        ]
    ]);
    exit;
}

        // ═══════════════════════════════════════════════════
        // ✅ SECURE DOWNLOAD DOCUMENT
        // ═══════════════════════════════════════════════════
        if ($action === 'download_document') {
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                http_response_code(400);
                exit('Invalid document ID');
            }
            
            // Verify ownership — clinic_id check para hindi ma-access ng ibang clinic
            $stmt = $pdo->prepare("
                SELECT file_path, document_type 
                FROM clinic_documents 
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$id, $clinic_id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$doc) {
                http_response_code(404);
                exit('Document not found');
            }
            
            // Resolve path — support both absolute and relative
            $path = $doc['file_path'];
            if (!file_exists($path)) {
                $path = __DIR__ . '/../' . ltrim($doc['file_path'], '/');
            }
            
            if (!file_exists($path)) {
                http_response_code(404);
                exit('File not found on server');
            }
            
            // Serve file
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=0, must-revalidate');
            
            readfile($path);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ GET CONTRACT
        // ═══════════════════════════════════════════════════
        if ($action === 'get_contract') {
            $stmt = $pdo->prepare("
                SELECT id, clinic_id, contract_no, contract_pdf, 
                       start_date, end_date, status, notes, 
                       uploaded_by, created_at, updated_at
                FROM clinic_contracts 
                WHERE clinic_id = ?
                ORDER BY id DESC 
                LIMIT 1
            ");
            $stmt->execute([$clinic_id]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Auto-update status based sa end_date
            if ($contract) {
                $new_status = 'active';
                if (!empty($contract['end_date'])) {
                    $days_left = (strtotime($contract['end_date']) - time()) / 86400;
                    if ($days_left < 0) {
                        $new_status = 'expired';
                    } elseif ($days_left <= 30) {
                        $new_status = 'expiring';
                    }
                }
                
                // Update DB kung nagbago
                if ($new_status !== $contract['status']) {
                    $pdo->prepare("UPDATE clinic_contracts SET status = ? WHERE id = ?")
                        ->execute([$new_status, $contract['id']]);
                    $contract['status'] = $new_status;
                }
            }
            
            echo json_encode([
                'success' => true,
                'data' => $contract ?: null
            ]);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ SECURE DOWNLOAD CONTRACT
        // ═══════════════════════════════════════════════════
        if ($action === 'download_contract') {
            $id = (int)($_GET['id'] ?? 0);
            
            if ($id <= 0) {
                http_response_code(400);
                exit('Invalid contract ID');
            }
            
            // Verify ownership
            $stmt = $pdo->prepare("
                SELECT contract_pdf 
                FROM clinic_contracts 
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$id, $clinic_id]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$contract) {
                http_response_code(404);
                exit('Contract not found');
            }
            
            $path = $contract['contract_pdf'];
            if (!file_exists($path)) {
                $path = __DIR__ . '/../' . ltrim($contract['contract_pdf'], '/');
            }
            
            if (!file_exists($path)) {
                http_response_code(404);
                exit('File not found on server');
            }
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="' . basename($path) . '"');
            header('Content-Length: ' . filesize($path));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=0, must-revalidate');
            
            readfile($path);
            exit;
        }

if ($action === 'download_contract_template') {
    $template_path = __DIR__ . '/../assets/documents/templates/eyecore-contract-template.pdf';
    
    // ✅ DEBUG
    if (isset($_GET['debug'])) {
        echo json_encode([
            'template_path' => $template_path,
            'file_exists' => file_exists($template_path),
            'is_readable' => is_readable($template_path),
            'filesize' => file_exists($template_path) ? filesize($template_path) : null,
            'dir_path' => dirname($template_path),
            'dir_exists' => is_dir(dirname($template_path)),
            'dir_contents' => is_dir(dirname($template_path)) ? scandir(dirname($template_path)) : null,
        ], JSON_PRETTY_PRINT);
        exit;
    }
    
    if (!file_exists($template_path)) {
        http_response_code(404);
        exit('Template not found. Please contact EyeCore admin.');
    }
            
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="EyeCore_Contract_Template.pdf"');
            header('Content-Length: ' . filesize($template_path));
            header('Cache-Control: private, max-age=0, must-revalidate');
            
            readfile($template_path);
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
                radius,
                offers_delivery,
                delivery_fee,
                delivery_radius_km,
                free_delivery_minimum
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
            $settings['offers_delivery'] = $clinic['offers_delivery'] ?? 0;
            $settings['delivery_fee'] = $clinic['delivery_fee'] ?? 0;
            $settings['delivery_radius_km'] = $clinic['delivery_radius_km'] ?? 0;
            $settings['free_delivery_minimum'] = $clinic['free_delivery_minimum'] ?? 0;
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

        // ═══════════════════════════════════════════════════
        // ✅ UPLOAD CLINIC DOCUMENT (multipart/form-data)
        // ═══════════════════════════════════════════════════
        if (isset($_POST['type']) && $_POST['type'] === 'upload_document') {
            $document_type = trim($_POST['document_type'] ?? '');
            $owner_name    = trim($_POST['owner_name'] ?? '');
            $released_at   = !empty($_POST['released_at']) ? $_POST['released_at'] : null;
            $expires_at    = !empty($_POST['expires_at'])  ? $_POST['expires_at']  : null;
            $remarks       = trim($_POST['remarks'] ?? '');
            
            // Validate document type
            $valid_types = [
                'Business Permit', 'DTI', 'SEC', 'BIR', 'Optometrist License',
                'Barangay Clearance', 'Health Clearance', 'Insurance', 'Other Documents'
            ];
            
            if (!in_array($document_type, $valid_types)) {
                echo json_encode(['error' => 'Invalid document type']);
                exit;
            }
            
            // Validate file
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => 'No file uploaded or upload error']);
                exit;
            }
            
            $file = $_FILES['file'];
            
            // Max 5MB
            if ($file['size'] > 5 * 1024 * 1024) {
                echo json_encode(['error' => 'File exceeds 5MB limit']);
                exit;
            }
            
            // Allowed extensions
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                echo json_encode(['error' => 'Only PDF, JPG, JPEG, PNG allowed']);
                exit;
            }
            
            // ✅ Use the SAME folder as the existing clinic-side upload
            $dir = __DIR__ . "/../uploads/clinic_{$clinic_id}/";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Safe filename (match existing naming style from upload_documents.php)
            $safe_type = preg_replace('/[^A-Za-z0-9]/', '_', $document_type);
            $microtime = str_replace('.', '', microtime(true));
            $random = rand(10000, 99999);
            $filename = "{$safe_type}_{$clinic_id}_{$microtime}_{$random}.{$ext}";
            $dest = $dir . $filename;
            $db_path = "uploads/clinic_{$clinic_id}/{$filename}";
            
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                echo json_encode(['error' => 'Failed to move uploaded file']);
                exit;
            }
            
            // Check existing latest doc of same type
            $stmt = $pdo->prepare("
                SELECT id, submission_attempts 
                FROM clinic_documents 
                WHERE clinic_id = ? AND document_type = ? AND is_latest = 1
            ");
            $stmt->execute([$clinic_id, $document_type]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $attempts = ($existing['submission_attempts'] ?? 0) + 1;
            
            $pdo->beginTransaction();
            try {
                // Mark old as not latest (history)
                if ($existing) {
                    $pdo->prepare("UPDATE clinic_documents SET is_latest = 0 WHERE id = ?")
                        ->execute([$existing['id']]);
                }
                
                // Insert new record as Pending
                $stmt = $pdo->prepare("
                    INSERT INTO clinic_documents 
                    (clinic_id, document_type, owner_name, released_at, expires_at, 
                     file_path, status, remarks, is_latest, uploaded_at, 
                     is_resubmitted, submission_attempts)
                    VALUES (?, ?, ?, ?, ?, ?, 'Pending', ?, 1, NOW(), ?, ?)
                ");
                $stmt->execute([
                    $clinic_id,
                    $document_type,
                    $owner_name ?: null,
                    $released_at,
                    $expires_at,
                    $db_path,
                    $remarks ?: null,
                    $existing ? 1 : 0,
                    $attempts
                ]);
                
                $new_id = $pdo->lastInsertId();
                
                $pdo->commit();
                
                logAudit($pdo, $user_id, $clinic_id, 'CREATE', 
                         'document_' . $new_id, null, json_encode([
                             'document_type' => $document_type,
                             'file' => $db_path
                         ]));
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Document uploaded successfully',
                    'id' => $new_id
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                // Clean up uploaded file
                if (file_exists($dest)) @unlink($dest);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
            }
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ UPLOAD CONTRACT (multipart/form-data)
        // ═══════════════════════════════════════════════════
        if (isset($_POST['type']) && $_POST['type'] === 'upload_contract') {
            $contract_no = trim($_POST['contract_no'] ?? '');
            $start_date  = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
            $end_date    = !empty($_POST['end_date'])   ? $_POST['end_date']   : null;
            $notes       = trim($_POST['notes'] ?? '');
            
            // Validate file
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['error' => 'No file uploaded or upload error']);
                exit;
            }
            
            $file = $_FILES['file'];
            
            // Max 10MB
            if ($file['size'] > 10 * 1024 * 1024) {
                echo json_encode(['error' => 'File exceeds 10MB limit']);
                exit;
            }
            
            // PDF only
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                echo json_encode(['error' => 'Only PDF files allowed']);
                exit;
            }
            
            // ✅ Same folder
            $dir = __DIR__ . "/../uploads/clinic_{$clinic_id}/";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Safe filename
            $safe_no = $contract_no 
                ? preg_replace('/[^A-Za-z0-9\-]+/', '_', $contract_no) 
                : 'contract';
            $microtime = str_replace('.', '', microtime(true));
            $filename = "{$safe_no}_{$clinic_id}_{$microtime}.pdf";
            $dest = $dir . $filename;
            $db_path = "uploads/clinic_{$clinic_id}/{$filename}";
            
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                echo json_encode(['error' => 'Failed to move uploaded file']);
                exit;
            }
            
            // Auto-compute status
            $status = 'active';
            if ($end_date) {
                $days_left = (strtotime($end_date) - time()) / 86400;
                if ($days_left < 0) {
                    $status = 'expired';
                } elseif ($days_left <= 30) {
                    $status = 'expiring';
                }
            }
            
            // Check existing contract
            $stmt = $pdo->prepare("SELECT id, contract_pdf FROM clinic_contracts WHERE clinic_id = ?");
            $stmt->execute([$clinic_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $pdo->beginTransaction();
            try {
                if ($existing) {
                    // Update existing
                    $stmt = $pdo->prepare("
                        UPDATE clinic_contracts 
                        SET contract_no = ?, contract_pdf = ?, start_date = ?, 
                            end_date = ?, status = ?, notes = ?, uploaded_by = ?
                        WHERE clinic_id = ?
                    ");
                    $stmt->execute([
                        $contract_no ?: null,
                        $db_path,
                        $start_date,
                        $end_date,
                        $status,
                        $notes ?: null,
                        $user_id,
                        $clinic_id
                    ]);
                    
                    // Delete old file
                    $old_path = $existing['contract_pdf'];
                    if (!file_exists($old_path)) {
                        $old_path = __DIR__ . '/../' . ltrim($existing['contract_pdf'], '/');
                    }
                    if (file_exists($old_path) && $old_path !== $dest) {
                        @unlink($old_path);
                    }
                    
                    $contract_id = $existing['id'];
                    $action_log = 'UPDATE';
                    
                } else {
                    // Insert new
                    $stmt = $pdo->prepare("
                        INSERT INTO clinic_contracts 
                        (clinic_id, contract_no, contract_pdf, start_date, end_date, 
                         status, notes, uploaded_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $clinic_id,
                        $contract_no ?: null,
                        $db_path,
                        $start_date,
                        $end_date,
                        $status,
                        $notes ?: null,
                        $user_id
                    ]);
                    
                    $contract_id = $pdo->lastInsertId();
                    $action_log = 'CREATE';
                }
                
                $pdo->commit();
                
                logAudit($pdo, $user_id, $clinic_id, $action_log, 
                         'contract_' . $contract_id, null, json_encode([
                             'contract_no' => $contract_no,
                             'file' => $db_path,
                             'status' => $status
                         ]));
                
                echo json_encode([
                    'success' => true,
                    'message' => $existing ? 'Contract updated' : 'Contract uploaded',
                    'id' => $contract_id
                ]);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                if (file_exists($dest)) @unlink($dest);
                echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
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

    // ✅ SYNC TO clinics.hours AND clinics.days
    $enabled_days = [];
    $time_ranges = [];
    $day_abbr_map = [
        'monday' => 'Mon', 'tuesday' => 'Tue', 'wednesday' => 'Wed',
        'thursday' => 'Thu', 'friday' => 'Fri', 'saturday' => 'Sat', 'sunday' => 'Sun'
    ];

    foreach ($data as $day => $info) {
        if (!empty($info['enabled']) && !empty($info['open']) && !empty($info['close'])) {
            $enabled_days[] = $day_abbr_map[$day] ?? ucfirst($day);
            $time_ranges[] = $info['open'] . '-' . $info['close'];
        }
    }

    // Kunin unique time ranges
    $unique_times = array_unique($time_ranges);

    // Format: "Mon-Sat" or "Mon, Wed, Fri"
    $days_text = '';
    if (count($enabled_days) === 7) {
        $days_text = 'Daily';
    } elseif (count($enabled_days) > 0) {
        // Check kung consecutive
        $first = $enabled_days[0];
        $last  = end($enabled_days);
        $days_text = (count($enabled_days) === 1) ? $first : "$first-$last";
    } else {
        $days_text = 'Closed';
    }

    // Format time: "09:00-18:00" or "09:00-12:00, 13:00-18:00"
    $hours_text = count($unique_times) > 0 ? implode(', ', $unique_times) : 'Closed';

    // I-update ang clinics table
    $pdo->prepare("
        UPDATE clinics 
        SET hours = ?, days = ?, updated_at = NOW()
        WHERE id = ?
    ")->execute([$hours_text, $days_text, $clinic_id]);

    echo json_encode([
        'success' => true,
        'synced' => [
            'hours' => $hours_text,
            'days'  => $days_text
        ]
    ]);
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
        
        // ===== HANDLE DELIVERY SETTINGS UPDATE =====
        if ($type === 'delivery_settings') {
            $offers_delivery = isset($data['offers_delivery']) && $data['offers_delivery'] == 1 ? 1 : 0;
            $delivery_fee = floatval($data['delivery_fee'] ?? 0);
            $delivery_radius_km = intval($data['delivery_radius_km'] ?? 0);
            $free_delivery_minimum = floatval($data['free_delivery_minimum'] ?? 0);
            
            $pdo->beginTransaction();
            try {
                $oldStmt = $pdo->prepare("
                    SELECT offers_delivery, delivery_fee, delivery_radius_km, free_delivery_minimum 
                    FROM clinics WHERE id = ?
                ");
                $oldStmt->execute([$clinic_id]);
                $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("
                    UPDATE clinics SET
                        offers_delivery = ?,
                        delivery_fee = ?,
                        delivery_radius_km = ?,
                        free_delivery_minimum = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $offers_delivery,
                    $delivery_fee,
                    $delivery_radius_km,
                    $free_delivery_minimum,
                    $clinic_id
                ]);
                
                $fields = ['offers_delivery', 'delivery_fee', 'delivery_radius_km', 'free_delivery_minimum'];
                foreach ($fields as $field) {
                    if ($old[$field] != $$field) {
                        logAudit($pdo, $user_id, $clinic_id, 'UPDATE', $field, $old[$field], $$field);
                    }
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Delivery settings updated']);
                
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['error' => $e->getMessage()]);
            }
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ NEW: ADD DELIVERY FEE (per city)
        // ═══════════════════════════════════════════════════
        if ($type === 'add_delivery_fee') {
            $city = trim($data['city'] ?? '');
            $region = trim($data['region'] ?? '');
            $barangay = trim($data['barangay'] ?? '');
            $fee_amount = floatval($data['fee_amount'] ?? 0);
            $estimated_days = intval($data['estimated_days'] ?? 1);
            
            if (empty($city) || $fee_amount <= 0) {
                echo json_encode(['error' => 'City and valid fee amount are required']);
                exit;
            }
            
            $checkStmt = $pdo->prepare("
                SELECT id FROM delivery_fees 
                WHERE clinic_id = ? AND city = ?
            ");
            $checkStmt->execute([$clinic_id, $city]);
            if ($checkStmt->fetch()) {
                echo json_encode(['error' => 'City already exists']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO delivery_fees (clinic_id, region, city, barangay, fee_amount, estimated_days, is_active)
                VALUES (?, ?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$clinic_id, $region, $city, $barangay, $fee_amount, $estimated_days]);
            
            $new_id = $pdo->lastInsertId();
            logAudit($pdo, $user_id, $clinic_id, 'CREATE', 'delivery_fee_' . $new_id, null, json_encode($data));
            
            echo json_encode(['success' => true, 'message' => 'City added successfully', 'id' => $new_id]);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ NEW: UPDATE DELIVERY FEE
        // ═══════════════════════════════════════════════════
        if ($type === 'update_delivery_fee') {
            $id = intval($data['id'] ?? 0);
            $city = trim($data['city'] ?? '');
            $region = trim($data['region'] ?? '');
            $barangay = trim($data['barangay'] ?? '');
            $fee_amount = floatval($data['fee_amount'] ?? 0);
            $estimated_days = intval($data['estimated_days'] ?? 1);
            $is_active = intval($data['is_active'] ?? 1);
            
            if ($id <= 0 || empty($city) || $fee_amount <= 0) {
                echo json_encode(['error' => 'Invalid input']);
                exit;
            }
            
            $checkStmt = $pdo->prepare("
                SELECT id FROM delivery_fees 
                WHERE id = ? AND clinic_id = ?
            ");
            $checkStmt->execute([$id, $clinic_id]);
            if (!$checkStmt->fetch()) {
                echo json_encode(['error' => 'City not found']);
                exit;
            }
            
            $oldStmt = $pdo->prepare("SELECT * FROM delivery_fees WHERE id = ?");
            $oldStmt->execute([$id]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                UPDATE delivery_fees 
                SET city = ?, region = ?, barangay = ?, fee_amount = ?, estimated_days = ?, is_active = ?
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$city, $region, $barangay, $fee_amount, $estimated_days, $is_active, $id, $clinic_id]);
            
            logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 'delivery_fee_' . $id, json_encode($old), json_encode($data));
            
            echo json_encode(['success' => true, 'message' => 'City updated successfully']);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ NEW: DELETE DELIVERY FEE
        // ═══════════════════════════════════════════════════
        if ($type === 'delete_delivery_fee') {
            $id = intval($data['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid ID']);
                exit;
            }
            
            $checkStmt = $pdo->prepare("
                SELECT id, city FROM delivery_fees 
                WHERE id = ? AND clinic_id = ?
            ");
            $checkStmt->execute([$id, $clinic_id]);
            $fee = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if (!$fee) {
                echo json_encode(['error' => 'City not found']);
                exit;
            }
            
            $stmt = $pdo->prepare("DELETE FROM delivery_fees WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$id, $clinic_id]);
            
            logAudit($pdo, $user_id, $clinic_id, 'DELETE', 'delivery_fee_' . $id, json_encode($fee), null);
            
            echo json_encode(['success' => true, 'message' => 'City deleted successfully']);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ NEW: UPDATE DOCUMENT (edit metadata)
        // ═══════════════════════════════════════════════════
        if ($type === 'update_document') {
            $id = (int)($data['id'] ?? 0);
            $owner_name  = trim($data['owner_name'] ?? '');
            $released_at = !empty($data['released_at']) ? $data['released_at'] : null;
            $expires_at  = !empty($data['expires_at'])  ? $data['expires_at']  : null;
            $remarks     = trim($data['remarks'] ?? '');
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid document ID']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT id FROM clinic_documents 
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$id, $clinic_id]);
            if (!$stmt->fetch()) {
                echo json_encode(['error' => 'Document not found']);
                exit;
            }
            
            $oldStmt = $pdo->prepare("SELECT * FROM clinic_documents WHERE id = ?");
            $oldStmt->execute([$id]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                UPDATE clinic_documents 
                SET owner_name = ?, released_at = ?, expires_at = ?, remarks = ?
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([
                $owner_name ?: null,
                $released_at,
                $expires_at,
                $remarks ?: null,
                $id,
                $clinic_id
            ]);
            
            logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 
                     'document_' . $id, json_encode($old), json_encode($data));
            
            echo json_encode(['success' => true, 'message' => 'Document updated']);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ NEW: DELETE DOCUMENT
        // ═══════════════════════════════════════════════════
        if ($type === 'delete_document') {
            $id = (int)($data['id'] ?? 0);
            
            if ($id <= 0) {
                echo json_encode(['error' => 'Invalid document ID']);
                exit;
            }
            
            $stmt = $pdo->prepare("
                SELECT file_path, document_type 
                FROM clinic_documents 
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$id, $clinic_id]);
            $doc = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$doc) {
                echo json_encode(['error' => 'Document not found']);
                exit;
            }
            
            $path = $doc['file_path'];
            if (!file_exists($path)) {
                $path = __DIR__ . '/../' . ltrim($doc['file_path'], '/');
            }
            if (file_exists($path)) {
                @unlink($path);
            }
            
            $stmt = $pdo->prepare("DELETE FROM clinic_documents WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$id, $clinic_id]);
            
            logAudit($pdo, $user_id, $clinic_id, 'DELETE', 
                     'document_' . $id, json_encode($doc), null);
            
            echo json_encode(['success' => true, 'message' => 'Document deleted']);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ NEW: UPDATE CONTRACT (edit metadata, no new file)
        // ═══════════════════════════════════════════════════
        if ($type === 'update_contract') {
            $contract_no = trim($data['contract_no'] ?? '');
            $start_date  = !empty($data['start_date']) ? $data['start_date'] : null;
            $end_date    = !empty($data['end_date'])   ? $data['end_date']   : null;
            $notes       = trim($data['notes'] ?? '');
            
            $stmt = $pdo->prepare("SELECT id FROM clinic_contracts WHERE clinic_id = ?");
            $stmt->execute([$clinic_id]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existing) {
                echo json_encode(['error' => 'No contract found']);
                exit;
            }
            
            $status = 'active';
            if ($end_date) {
                $days_left = (strtotime($end_date) - time()) / 86400;
                if ($days_left < 0) {
                    $status = 'expired';
                } elseif ($days_left <= 30) {
                    $status = 'expiring';
                }
            }
            
            $oldStmt = $pdo->prepare("SELECT * FROM clinic_contracts WHERE id = ?");
            $oldStmt->execute([$existing['id']]);
            $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
            
            $stmt = $pdo->prepare("
                UPDATE clinic_contracts 
                SET contract_no = ?, start_date = ?, end_date = ?, 
                    status = ?, notes = ?
                WHERE clinic_id = ?
            ");
            $stmt->execute([
                $contract_no ?: null,
                $start_date,
                $end_date,
                $status,
                $notes ?: null,
                $clinic_id
            ]);
            
            logAudit($pdo, $user_id, $clinic_id, 'UPDATE', 
                     'contract_' . $existing['id'], json_encode($old), json_encode($data));
            
            echo json_encode(['success' => true, 'message' => 'Contract updated']);
            exit;
        }

        // ═══════════════════════════════════════════════════
        // ✅ NEW: DELETE CONTRACT
        // ═══════════════════════════════════════════════════
        if ($type === 'delete_contract') {
            $id = (int)($data['id'] ?? 0);
            
            $stmt = $pdo->prepare("
                SELECT * FROM clinic_contracts 
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$id, $clinic_id]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$contract) {
                echo json_encode(['error' => 'Contract not found']);
                exit;
            }
            
            $path = $contract['contract_pdf'];
            if (!file_exists($path)) {
                $path = __DIR__ . '/../' . ltrim($contract['contract_pdf'], '/');
            }
            if (file_exists($path)) {
                @unlink($path);
            }
            
            $stmt = $pdo->prepare("DELETE FROM clinic_contracts WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$id, $clinic_id]);
            
            logAudit($pdo, $user_id, $clinic_id, 'DELETE', 
                     'contract_' . $id, json_encode($contract), null);
            
            echo json_encode(['success' => true, 'message' => 'Contract deleted']);
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