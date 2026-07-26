<?php
// ✅ Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'C:/xampp/php/logs/php_error.log');

session_name('eyecore_admin');
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Check if pdo is connected
if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ============================================
// RBAC PERMISSION HELPER FUNCTIONS
// ============================================
function canViewMessages() { return RBACHelper::hasPermission('crm_messages_view'); }
function canCreateMessages() { return RBACHelper::hasPermission('crm_messages_create'); }
function canEditMessages() { return RBACHelper::hasPermission('crm_messages_edit'); }
function canDeleteMessages() { return RBACHelper::hasPermission('crm_messages_delete'); }

// Auth — check if user has view permission
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!canViewMessages()) {
    echo json_encode(['error' => 'Permission denied: You cannot view messages']);
    exit;
}

$clinic_id = (int)$_SESSION['clinic_id'];
$user_id = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$action = $_GET['action'] ?? '';

// ============================================
// CHAT IMAGE UPLOAD CONFIG
// PAKI-VERIFY: dapat itong '/assets/images/chat-images/' ay
// yung EXACT SAME folder na ginagamit ng user-side chat_send.php.
// Kung mismatch ang folder structure niyo, i-adjust nalang itong dalawang variable.
// ============================================
$CHAT_IMG_URL_BASE = '/assets/images/chat-images/'; // absolute path mula sa domain root, ginagamit sa <img src>
$CHAT_IMG_PHYSICAL_DIR = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . $CHAT_IMG_URL_BASE; // actual disk location kung saan isesave

// Helper function to get patient ID from user ID
function getPatientIdFromUserId($pdo, $user_id, $clinic_id) {
    // First, try to find patient record linked to this user
    $stmt = $pdo->prepare("SELECT id FROM patients WHERE user_id = ? AND clinic_id = ?");
    $stmt->execute([$user_id, $clinic_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($patient) {
        return $patient['id'];
    }
    
    // If no patient record, check if this user is a patient in users table
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && $user['role'] == 'Patient') {
        // Create patient record for this user
        $stmt = $pdo->prepare("INSERT INTO patients (user_id, clinic_id, first_name, last_name, email) 
                               SELECT ?, ?, first_name, last_name, email FROM users WHERE id = ?");
        $stmt->execute([$user_id, $clinic_id, $user_id]);
        return $pdo->lastInsertId();
    }
    
    return $user_id; // Fallback: use user_id as patient_id
}

// ============================================
// IMAGE UPLOAD HELPER — ginagamit ng 'send' at 'new_conversation'
// Returns: [success(bool), filename(string|null), error_message(string|null)]
// ============================================
function handleChatImageUpload($physical_dir, $url_base) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] != 0) {
        return [true, null, null]; // walang image, ok lang, hindi error
    }

    $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 5 * 1024 * 1024; // 5MB

    $filename = $_FILES['image']['name'];
    $ext      = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $filesize = $_FILES['image']['size'];

    if (!in_array($ext, $allowed)) {
        return [false, null, 'Invalid image type. Only JPG, PNG, GIF, WEBP allowed.'];
    }

    if ($filesize > $max_size) {
        return [false, null, 'Image is too large. Max size is 5MB.'];
    }

    if (!file_exists($physical_dir)) {
        mkdir($physical_dir, 0777, true);
    }

    $new_filename = 'chat_clinic_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    $upload_path  = $physical_dir . $new_filename;

    if (!move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
        return [false, null, 'Failed to upload image.'];
    }

    return [true, $new_filename, null];
}

// ============================================
// GET: Permissions endpoint
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_permissions'])) {
    $hasHR = false;
    $hrStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE clinic_id = ? AND role = 'HR' AND status = 'Active'");
    $hrStmt->execute([$clinic_id]);
    $hasHR = $hrStmt->fetchColumn() > 0;
    
    $permissions = [
        'view' => canViewMessages(),
        'create' => canCreateMessages(),
        'edit' => canEditMessages(),
        'delete' => canDeleteMessages()
    ];
    
    echo json_encode([
        'success' => true,
        'data' => [
            'role' => $user_role,
            'permissions' => $permissions,
            'hasHR' => $hasHR,
            'isOwner' => ($user_role === 'ClinicAdmin' && !$hasHR),
            'user_id' => $user_id
        ]
    ]);
    exit();
}

// ============================================
// GET: List of recipients (for new message)
// ============================================
if ($action === 'get_recipients') {
    $type = $_GET['type'] ?? 'user';
    
    try {
        if ($type === 'user') {
            // Get staff members (excluding current user)
            $stmt = $pdo->prepare("
                SELECT id, CONCAT(first_name, ' ', last_name) as name 
                FROM users 
                WHERE clinic_id = ? AND id != ? AND role != 'Patient'
                ORDER BY first_name
            ");
            $stmt->execute([$clinic_id, $user_id]);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Get patients - get the actual user IDs from patients table
            $stmt = $pdo->prepare("
                SELECT p.id as patient_id, p.user_id, CONCAT(p.first_name, ' ', p.last_name) as name
                FROM patients p
                WHERE p.clinic_id = ?
                ORDER BY p.first_name
            ");
            $stmt->execute([$clinic_id]);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $recipients = [];
            foreach ($patients as $p) {
                $recipients[] = [
                    'id' => (int)$p['user_id'], // Use user_id for consistency with user side
                    'name' => $p['name']
                ];
            }
        }
        
        echo json_encode($recipients);
        
    } catch (Exception $e) {
        error_log("get_recipients error: " . $e->getMessage());
        echo json_encode([]);
    }
    exit;
}

// ============================================
// GET: Conversations list (MATCHING USER SIDE STRUCTURE)
// ============================================
if ($action === 'get_conversations') {
    try {
        // ✅ Check if chats table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'chats'");
        if ($tableCheck->rowCount() == 0) {
            echo json_encode([]);
            exit;
        }
        
        // Get all unique users (patients) that this clinic has chatted with
        // Using the SAME structure as user side: user_id is the user ID from users table
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                c.user_id as other_user_id,
                MAX(c.created_at) as last_time,
                (
                    SELECT message FROM chats c2 
                    WHERE c2.clinic_id = c.clinic_id 
                      AND c2.user_id = c.user_id 
                    ORDER BY c2.created_at DESC LIMIT 1
                ) as last_message,
                (
                    SELECT image FROM chats c4
                    WHERE c4.clinic_id = c.clinic_id
                      AND c4.user_id = c.user_id
                    ORDER BY c4.created_at DESC LIMIT 1
                ) as last_image,
                (
                    SELECT COUNT(*) FROM chats c3 
                    WHERE c3.clinic_id = c.clinic_id 
                      AND c3.user_id = c.user_id 
                      AND c3.sender_type = 'user'
                      AND c3.is_read = 0
                ) as unread
            FROM chats c
            WHERE c.clinic_id = ?
              AND c.user_id IS NOT NULL
              AND c.user_id > 0
            GROUP BY c.user_id
            ORDER BY last_time DESC
        ");
        $stmt->execute([$clinic_id]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($conversations as $conv) {
            // Get user details from users table (since user_id is from users table)
            $stmt2 = $pdo->prepare("
                SELECT CONCAT(first_name, ' ', last_name) as name, role
                FROM users 
                WHERE id = ?
            ");
            $stmt2->execute([$conv['other_user_id']]);
            $user = $stmt2->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $preview = $conv['last_message'] ?? '';
                if ($preview === '' && !empty($conv['last_image'])) {
                    $preview = '📷 Photo';
                } elseif (mb_strlen($preview) > 40) {
                    $preview = mb_substr($preview, 0, 40) . '…';
                }
                
                $result[] = [
                    'conversation_id' => (string)$conv['other_user_id'], // Use user_id as conversation_id
                    'other_id' => (int)$conv['other_user_id'],
                    'other_type' => ($user['role'] == 'Patient' ? 'patient' : 'user'),
                    'other_name' => $user['name'],
                    'last_message' => htmlspecialchars($preview),
                    'last_time' => $conv['last_time'] ? date('g:i A', strtotime($conv['last_time'])) : '',
                    'unread' => (int)$conv['unread']
                ];
            }
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("get_conversations error: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET: Messages for a conversation (MATCHING USER SIDE STRUCTURE)
// ============================================
if ($action === 'get_messages') {
    $other_user_id = (int)($_GET['conversation_id'] ?? 0);
    if (!$other_user_id) {
        echo json_encode([]);
        exit;
    }
    
    try {
        // Mark messages as read (from user to clinic)
        $stmt = $pdo->prepare("
            UPDATE chats 
            SET is_read = 1 
            WHERE clinic_id = ? 
              AND user_id = ? 
              AND sender_type = 'user'
              AND is_read = 0
        ");
        $stmt->execute([$clinic_id, $other_user_id]);
        
        // Get messages between clinic and this user
        // Using the SAME structure: user_id is the user ID from users table
        $stmt = $pdo->prepare("
            SELECT c.*,
                   c.created_at as message_time
            FROM chats c
            WHERE c.clinic_id = ? 
              AND c.user_id = ?
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$clinic_id, $other_user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($messages as $msg) {
            // Determine sender display name and type
            if ($msg['sender_type'] == 'clinic') {
                // Message from clinic
                $sender_name = 'Clinic Staff';
                $display_sender_type = 'clinic';
                $sender_id = (int)$msg['sender_id'];
            } else {
                // Message from user (patient)
                // Get user name from users table
                $stmt2 = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
                $stmt2->execute([$msg['user_id']]);
                $user = $stmt2->fetch(PDO::FETCH_ASSOC);
                $sender_name = $user['name'] ?? 'User';
                $display_sender_type = 'user';
                $sender_id = (int)$msg['user_id'];
            }
            
            $result[] = [
                'id' => (int)$msg['id'],
                'sender_id' => $sender_id,
                'sender_type' => $display_sender_type,
                'sender_name' => $sender_name,
                'message' => htmlspecialchars($msg['message']),
                'image' => !empty($msg['image']) ? $GLOBALS['CHAT_IMG_URL_BASE'] . $msg['image'] : null,
                'time' => date('g:i A', strtotime($msg['created_at'])),
                'is_read' => (bool)$msg['is_read']
            ];
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("get_messages error: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// POST: Send a message (reply to user) - MATCHING USER SIDE STRUCTURE
// NOTE: FormData na ang ginagamit dito (hindi na JSON), para pwede
// magsama ng image file. Nasa $_POST na ang mga fields, at $_FILES['image']
// ang optional na larawan.
// ============================================
if ($action === 'send') {
    if (!canCreateMessages()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot send messages']);
        exit;
    }
    
    $other_user_id = (int)($_POST['conversation_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleChatImageUpload($CHAT_IMG_PHYSICAL_DIR, $CHAT_IMG_URL_BASE);
    if (!$img_ok) {
        echo json_encode(['success' => false, 'message' => $img_error]);
        exit;
    }

    if (!$other_user_id || (!$message && !$img_filename)) {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }
    
    try {
        // Verify user exists
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$other_user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        
        // Insert message from clinic to user
        // MATCHING USER SIDE STRUCTURE: user_id is the user ID from users table
        $stmt = $pdo->prepare("
            INSERT INTO chats (
                clinic_id, 
                user_id, 
                sender_type,
                sender_id,
                message, 
                image,
                is_read, 
                created_at
            ) VALUES (
                ?, ?, 'clinic', ?, ?, ?, 0, NOW()
            )
        ");
        $stmt->execute([
            $clinic_id,
            $other_user_id,   // user_id = user ID from users table (matches user side)
            $user_id,         // sender_id = ang clinic staff na nag-send
            $message,
            $img_filename
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true, 
                'id' => $pdo->lastInsertId(),
                'message' => 'Message sent successfully'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send message']);
        }
        
    } catch (Exception $e) {
        error_log("send message error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// POST: Start a new conversation with user
// (FormData rin dito ngayon para pwede rin sumama ng image sa first message,
//  pero optional lang — text pa rin ang required dito tulad ng dati.)
// ============================================
if ($action === 'new_conversation') {
    if (!canCreateMessages()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot send messages']);
        exit;
    }
    
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleChatImageUpload($CHAT_IMG_PHYSICAL_DIR, $CHAT_IMG_URL_BASE);
    if (!$img_ok) {
        echo json_encode(['success' => false, 'message' => $img_error]);
        exit;
    }
    
    if (!$receiver_id || (!$message && !$img_filename)) {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }
    
    try {
        // Get user name and verify
        $stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
        $stmt->execute([$receiver_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'User not found']);
            exit;
        }
        
        // Insert first message - MATCHING USER SIDE STRUCTURE
        $stmt = $pdo->prepare("
            INSERT INTO chats (
                clinic_id, 
                user_id, 
                sender_type,
                sender_id,
                message,
                image,
                is_read, 
                created_at
            ) VALUES (
                ?, ?, 'clinic', ?, ?, ?, 0, NOW()
            )
        ");
        $stmt->execute([
            $clinic_id,
            $receiver_id,
            $user_id,
            $message,
            $img_filename
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'conversation_id' => (string)$receiver_id,
                'receiver_name' => $user['name'],
                'message' => 'Message sent successfully'
            ]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to send message']);
        }
        
    } catch (Exception $e) {
        error_log("new_conversation error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// POST: Mark messages as read
// ============================================
if ($action === 'mark_read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id_to_mark = (int)($data['conversation_id'] ?? 0);
    
    if (!$user_id_to_mark) {
        echo json_encode(['success' => false, 'message' => 'User ID required']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE chats 
            SET is_read = 1 
            WHERE clinic_id = ? 
              AND user_id = ? 
              AND sender_type = 'user'
              AND is_read = 0
        ");
        $stmt->execute([$clinic_id, $user_id_to_mark]);
        
        echo json_encode(['success' => true, 'affected' => $stmt->rowCount()]);
        
    } catch (Exception $e) {
        error_log("mark_read error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET: Get unread count
// ============================================
if ($action === 'get_unread_count') {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as unread 
            FROM chats 
            WHERE clinic_id = ? 
              AND sender_type = 'user' 
              AND is_read = 0
        ");
        $stmt->execute([$clinic_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'unread' => (int)$result['unread']]);
        
    } catch (Exception $e) {
        error_log("get_unread_count error: " . $e->getMessage());
        echo json_encode(['success' => false, 'unread' => 0]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);