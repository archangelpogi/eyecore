<?php
session_name('eyecore_admin');
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper with PDO
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

// Auth — check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ✅ Check view permission
if (!canViewMessages()) {
    echo json_encode(['error' => 'Permission denied: You cannot view messages']);
    exit;
}

$clinic_id = (int)$_SESSION['clinic_id'];
$user_id   = (int)$_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$action    = $_GET['action'] ?? '';

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
// GET: Staff list (for new message)
// ============================================
if ($action === 'get_staff') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, CONCAT(first_name, ' ', last_name) as name, role
            FROM users
            WHERE clinic_id = ? AND id != ? AND status = 'Active'
              AND role NOT IN ('Patient', 'Supplier')
            ORDER BY first_name ASC
        ");
        $stmt->execute([$clinic_id, $user_id]);
        $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($staff as $s) {
            $result[] = [
                'id' => (int)$s['id'],
                'name' => $s['name'],
                'role' => $s['role']
            ];
        }
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("get_staff error: " . $e->getMessage());
        echo json_encode([]);
    }
    exit;
}

// ============================================
// GET: Conversations list
// ============================================
if ($action === 'get_conversations') {
    try {
        // Check if messages table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'messages'");
        if ($tableCheck->rowCount() == 0) {
            echo json_encode([]);
            exit;
        }
        
        // Get distinct conversations
        $stmt = $pdo->prepare("
            SELECT 
                conversation_id,
                MAX(created_at) as last_at,
                (SELECT message FROM messages m2 
                 WHERE m2.conversation_id = m.conversation_id 
                 ORDER BY m2.created_at DESC LIMIT 1) as last_message,
                (SELECT sender_id FROM messages m2 
                 WHERE m2.conversation_id = m.conversation_id 
                 ORDER BY m2.created_at DESC LIMIT 1) as last_sender_id,
                (SELECT sender_type FROM messages m2 
                 WHERE m2.conversation_id = m.conversation_id 
                 ORDER BY m2.created_at DESC LIMIT 1) as last_sender_type,
                (SELECT receiver_id FROM messages m2 
                 WHERE m2.conversation_id = m.conversation_id 
                 ORDER BY m2.created_at DESC LIMIT 1) as last_receiver_id,
                (SELECT receiver_type FROM messages m2 
                 WHERE m2.conversation_id = m.conversation_id 
                 ORDER BY m2.created_at DESC LIMIT 1) as last_receiver_type,
                (SELECT COUNT(*) FROM messages m3 
                 WHERE m3.conversation_id = m.conversation_id 
                   AND m3.receiver_id = ? 
                   AND m3.receiver_type = 'user' 
                   AND m3.is_read = 0) as unread
            FROM messages m
            WHERE clinic_id = ?
              AND (sender_id = ? OR (receiver_id = ? AND receiver_type = 'user'))
            GROUP BY conversation_id
            ORDER BY last_at DESC
        ");
        $stmt->execute([$user_id, $clinic_id, $user_id, $user_id]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($conversations as $conv) {
            // Determine other party
            $last_sender_id = (int)$conv['last_sender_id'];
            $last_sender_type = $conv['last_sender_type'];
            $last_receiver_id = (int)$conv['last_receiver_id'];
            $last_receiver_type = $conv['last_receiver_type'];
            
            $other_id = null;
            $other_type = null;
            
            if ($last_sender_id == $user_id && $last_sender_type == 'user') {
                $other_id = $last_receiver_id;
                $other_type = $last_receiver_type;
            } else {
                $other_id = $last_sender_id;
                $other_type = $last_sender_type;
            }
            
            // Get other party name
            $other_name = 'Unknown';
            if ($other_type === 'user') {
                $stmt2 = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
                $stmt2->execute([$other_id]);
                $user = $stmt2->fetch(PDO::FETCH_ASSOC);
                $other_name = $user['name'] ?? 'Unknown Staff';
            } else {
                $stmt2 = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM patients WHERE id = ?");
                $stmt2->execute([$other_id]);
                $patient = $stmt2->fetch(PDO::FETCH_ASSOC);
                $other_name = $patient['name'] ?? 'Unknown Patient';
            }
            
            $preview = $conv['last_message'] ?? '';
            if (mb_strlen($preview) > 35) {
                $preview = mb_substr($preview, 0, 35) . '…';
            }
            
            $result[] = [
                'conversation_id' => $conv['conversation_id'],
                'other_id' => $other_id,
                'other_type' => $other_type,
                'other_name' => $other_name,
                'last_message' => $preview,
                'last_time' => $conv['last_at'] ? date('g:i A', strtotime($conv['last_at'])) : '',
                'unread' => (int)$conv['unread']
            ];
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("get_conversations error: " . $e->getMessage());
        echo json_encode([]);
    }
    exit;
}

// ============================================
// GET: Messages for a conversation
// ============================================
if ($action === 'get_messages') {
    $conv_id = $_GET['conversation_id'] ?? '';
    if (!$conv_id) {
        echo json_encode([]);
        exit;
    }
    
    try {
        // Mark messages as read
        $stmt = $pdo->prepare("
            UPDATE messages 
            SET is_read = 1 
            WHERE clinic_id = ? 
              AND conversation_id = ? 
              AND receiver_id = ? 
              AND receiver_type = 'user' 
              AND is_read = 0
        ");
        $stmt->execute([$clinic_id, $conv_id, $user_id]);
        
        // Get messages
        $stmt = $pdo->prepare("
            SELECT m.id, m.sender_id, m.sender_type, m.message, m.created_at,
                   CASE 
                       WHEN m.sender_type = 'user' THEN CONCAT(u.first_name, ' ', u.last_name)
                       WHEN m.sender_type = 'patient' THEN CONCAT(p.first_name, ' ', p.last_name)
                       ELSE 'System'
                   END as sender_name
            FROM messages m
            LEFT JOIN users u ON m.sender_id = u.id AND m.sender_type = 'user'
            LEFT JOIN patients p ON m.sender_id = p.id AND m.sender_type = 'patient'
            WHERE m.clinic_id = ? AND m.conversation_id = ?
            ORDER BY m.created_at ASC
        ");
        $stmt->execute([$clinic_id, $conv_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($messages as $msg) {
            $result[] = [
                'id' => (int)$msg['id'],
                'sender_id' => (int)$msg['sender_id'],
                'sender_type' => $msg['sender_type'],
                'sender_name' => $msg['sender_name'],
                'message' => htmlspecialchars($msg['message']),
                'time' => date('g:i A', strtotime($msg['created_at']))
            ];
        }
        
        echo json_encode($result);
        
    } catch (Exception $e) {
        error_log("get_messages error: " . $e->getMessage());
        echo json_encode([]);
    }
    exit;
}

// ============================================
// POST: Send a message (reply)
// ============================================
if ($action === 'send') {
    if (!canCreateMessages()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot send messages']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $conv_id = $data['conversation_id'] ?? '';
    $message = trim($data['message'] ?? '');
    
    if (!$conv_id || !$message) {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }
    
    try {
        // Get last message to determine receiver
        $stmt = $pdo->prepare("
            SELECT sender_id, sender_type, receiver_id, receiver_type 
            FROM messages 
            WHERE clinic_id = ? AND conversation_id = ? 
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$clinic_id, $conv_id]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$last) {
            echo json_encode(['success' => false, 'message' => 'Conversation not found']);
            exit;
        }
        
        // Determine receiver
        if ($last['sender_id'] == $user_id && $last['sender_type'] == 'user') {
            $receiver_id = $last['receiver_id'];
            $receiver_type = $last['receiver_type'];
        } else {
            $receiver_id = $last['sender_id'];
            $receiver_type = $last['sender_type'];
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO messages (clinic_id, conversation_id, sender_id, sender_type, receiver_id, receiver_type, message, created_at)
            VALUES (?, ?, ?, 'user', ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $clinic_id,
            $conv_id,
            $user_id,
            $receiver_id,
            $receiver_type,
            $message
        ]);
        
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
        
    } catch (Exception $e) {
        error_log("send message error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ============================================
// POST: New conversation
// ============================================
if ($action === 'new_conversation') {
    if (!canCreateMessages()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot send messages']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $receiver_id = (int)($data['receiver_id'] ?? 0);
    $message = trim($data['message'] ?? '');
    
    if (!$receiver_id || !$message) {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }
    
    try {
        // Create deterministic conversation_id (sort IDs to be consistent)
        $ids = [$user_id, $receiver_id];
        sort($ids);
        $conv_id = $ids[0] . '_' . $ids[1] . '_user';
        
        // Get receiver name
        $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
        $stmt->execute([$receiver_id]);
        $receiver = $stmt->fetch(PDO::FETCH_ASSOC);
        $receiver_name = $receiver['name'] ?? 'Unknown Staff';
        
        $stmt = $pdo->prepare("
            INSERT INTO messages (clinic_id, conversation_id, sender_id, sender_type, receiver_id, receiver_type, message, created_at)
            VALUES (?, ?, ?, 'user', ?, 'user', ?, NOW())
        ");
        $stmt->execute([
            $clinic_id,
            $conv_id,
            $user_id,
            $receiver_id,
            $message
        ]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'success' => true,
                'conversation_id' => $conv_id,
                'receiver_name' => $receiver_name,
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
// DELETE: Delete a message
// ============================================
if ($action === 'delete_message') {
    if (!canDeleteMessages()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot delete messages']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $message_id = (int)($data['message_id'] ?? 0);
    
    if (!$message_id) {
        echo json_encode(['success' => false, 'message' => 'Message ID required']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            DELETE FROM messages 
            WHERE id = ? AND clinic_id = ? AND sender_id = ? AND sender_type = 'user'
        ");
        $stmt->execute([$message_id, $clinic_id, $user_id]);
        
        echo json_encode(['success' => true, 'message' => 'Message deleted']);
        
    } catch (Exception $e) {
        error_log("delete_message error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>