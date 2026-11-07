<?php
// api/crm_messages.php — COMPLETE FIX
// Supports: patients, staff, clinic riders, supplier riders

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

session_name('eyecore_admin');
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// RBAC helpers
function canViewMessages() { return RBACHelper::hasPermission('crm_messages_view'); }
function canCreateMessages() { return RBACHelper::hasPermission('crm_messages_create'); }
function canEditMessages() { return RBACHelper::hasPermission('crm_messages_edit'); }
function canDeleteMessages() { return RBACHelper::hasPermission('crm_messages_delete'); }

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

$CHAT_IMG_URL_BASE = '/assets/images/chat-images/';
$CHAT_IMG_PHYSICAL_DIR = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . $CHAT_IMG_URL_BASE;

// ============================================
// Parse conversation ID (supports supplier_rider_X, rider_X, supplier_X, user_X, plain int)
// ============================================
function parseConversationId($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return [null, 0];

    // Check supplier_rider_ first (longest prefix)
    if (strpos($raw, 'supplier_rider_') === 0) {
        return ['supplier_rider', (int)substr($raw, 15)];
    }
    if (strpos($raw, 'rider_') === 0) {
        return ['rider', (int)substr($raw, 6)];
    }
    if (strpos($raw, 'supplier_') === 0) {
        return ['supplier', (int)substr($raw, 9)];
    }
    if (strpos($raw, 'user_') === 0) {
        return ['user', (int)substr($raw, 5)];
    }
    return ['user', (int)$raw];
}

// ============================================
// Image upload helper
// ============================================
function handleChatImageUpload($physical_dir, $url_base) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] != 0) {
        return [true, null, null];
    }
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 5 * 1024 * 1024;
    $filename = $_FILES['image']['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $filesize = $_FILES['image']['size'];
    if (!in_array($ext, $allowed)) return [false, null, 'Invalid image type.'];
    if ($filesize > $max_size) return [false, null, 'Image too large. Max 5MB.'];
    if (!file_exists($physical_dir)) mkdir($physical_dir, 0777, true);
    $new_filename = 'chat_clinic_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $physical_dir . $new_filename)) {
        return [false, null, 'Upload failed.'];
    }
    return [true, $new_filename, null];
}

// ============================================
// GET: Permissions
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['get_permissions'])) {
    $hrStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE clinic_id = ? AND role = 'HR' AND status = 'Active'");
    $hrStmt->execute([$clinic_id]);
    $hasHR = $hrStmt->fetchColumn() > 0;
    echo json_encode([
        'success' => true,
        'data' => [
            'role' => $user_role,
            'permissions' => ['view'=>canViewMessages(),'create'=>canCreateMessages(),'edit'=>canEditMessages(),'delete'=>canDeleteMessages()],
            'hasHR' => $hasHR,
            'isOwner' => ($user_role === 'ClinicAdmin' && !$hasHR),
            'user_id' => $user_id
        ]
    ]);
    exit();
}

// ============================================
// GET: Recipients (staff, patients, riders, supplier_rider)
// ============================================
if ($action === 'get_recipients') {
    $type = $_GET['type'] ?? 'user';
    try {
        if ($type === 'user') {
            $stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE clinic_id = ? AND id != ? AND role != 'Patient' ORDER BY first_name");
            $stmt->execute([$clinic_id, $user_id]);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($type === 'rider') {
            $stmt = $pdo->prepare("SELECT id, name FROM riders WHERE clinic_id = ? AND status = 'active' ORDER BY name");
            $stmt->execute([$clinic_id]);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } elseif ($type === 'supplier_rider') {
            // ✅ Supplier riders na may delivery sa clinic na ito
            $stmt = $pdo->prepare("
                SELECT DISTINCT sr.id, CONCAT(sr.full_name, ' (', COALESCE(s.supplier_name, 'Supplier'), ')') as name
                FROM supplier_riders sr
                JOIN purchase_orders po ON po.assigned_rider_id = sr.id
                JOIN purchase_requests pr ON po.pr_id = pr.id
                LEFT JOIN suppliers s ON sr.supplier_id = s.id
                WHERE pr.clinic_id = ? AND sr.status = 'Active'
                ORDER BY sr.full_name
            ");
            $stmt->execute([$clinic_id]);
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        } else {
            // Patients
            $stmt = $pdo->prepare("SELECT p.id as patient_id, p.user_id, CONCAT(p.first_name, ' ', p.last_name) as name FROM patients p WHERE p.clinic_id = ? ORDER BY p.first_name");
            $stmt->execute([$clinic_id]);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $recipients = [];
            foreach ($patients as $p) {
                $recipients[] = ['id' => (int)$p['user_id'], 'name' => $p['name']];
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
// GET: Conversations list (patients + staff + clinic riders + supplier riders)
// ============================================
if ($action === 'get_conversations') {
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'chats'");
        if ($tableCheck->rowCount() == 0) { echo json_encode([]); exit; }

        $result = [];

        // ── 1. PATIENT/STAFF CONVERSATIONS ──
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                c.user_id as other_user_id,
                MAX(c.created_at) as last_time,
                (SELECT message FROM chats c2 WHERE c2.clinic_id = c.clinic_id AND c2.user_id = c.user_id ORDER BY c2.created_at DESC LIMIT 1) as last_message,
                (SELECT image FROM chats c4 WHERE c4.clinic_id = c.clinic_id AND c4.user_id = c.user_id ORDER BY c4.created_at DESC LIMIT 1) as last_image,
                (SELECT COUNT(*) FROM chats c3 WHERE c3.clinic_id = c.clinic_id AND c3.user_id = c.user_id AND c3.sender_type IN ('user','patient') AND c3.is_read = 0) as unread
            FROM chats c
            WHERE c.clinic_id = ?
              AND c.user_id IS NOT NULL
              AND c.user_id > 0
              AND c.sender_type IN ('user','patient','clinic')
              AND (c.receiver_type IS NULL OR c.receiver_type NOT IN ('rider','supplier','supplier_rider'))
            GROUP BY c.user_id
            ORDER BY last_time DESC
        ");
        $stmt->execute([$clinic_id]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($conversations as $conv) {
            $stmt2 = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name, role FROM users WHERE id = ?");
            $stmt2->execute([$conv['other_user_id']]);
            $user = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $preview = $conv['last_message'] ?? '';
                if ($preview === '' && !empty($conv['last_image'])) $preview = '📷 Photo';
                elseif (mb_strlen($preview) > 40) $preview = mb_substr($preview, 0, 40) . '…';
                $result[] = [
                    'conversation_id' => (string)$conv['other_user_id'],
                    'other_id' => (int)$conv['other_user_id'],
                    'other_type' => ($user['role'] == 'Patient' ? 'patient' : 'user'),
                    'other_name' => $user['name'],
                    'last_message' => htmlspecialchars($preview),
                    'last_time' => $conv['last_time'] ? date('g:i A', strtotime($conv['last_time'])) : '',
                    'unread' => (int)$conv['unread']
                ];
            }
        }

        // ── 2. CLINIC RIDER CONVERSATIONS ──
        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN c.sender_type = 'rider' THEN c.sender_id
                    WHEN c.receiver_type = 'rider' THEN c.receiver_id
                END as rider_id,
                MAX(c.created_at) as last_time,
                (SELECT message FROM chats c2 
                 WHERE c2.clinic_id = c.clinic_id 
                   AND ((c2.sender_type='rider' AND c2.sender_id=rider_id) OR (c2.receiver_type='rider' AND c2.receiver_id=rider_id))
                 ORDER BY c2.created_at DESC LIMIT 1) as last_message,
                (SELECT image FROM chats c4 
                 WHERE c4.clinic_id = c.clinic_id 
                   AND ((c4.sender_type='rider' AND c4.sender_id=rider_id) OR (c4.receiver_type='rider' AND c4.receiver_id=rider_id))
                 ORDER BY c4.created_at DESC LIMIT 1) as last_image,
                (SELECT COUNT(*) FROM chats c3 
                 WHERE c3.clinic_id = c.clinic_id 
                   AND c3.sender_type = 'rider' 
                   AND c3.sender_id = rider_id
                   AND c3.is_read = 0) as unread
            FROM chats c
            WHERE c.clinic_id = ?
              AND (c.sender_type = 'rider' OR c.receiver_type = 'rider')
            GROUP BY rider_id
            ORDER BY last_time DESC
        ");
        $stmt->execute([$clinic_id]);
        $riderConvs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($riderConvs as $conv) {
            if (!$conv['rider_id']) continue;
            $stmt2 = $pdo->prepare("SELECT id, name FROM riders WHERE id = ?");
            $stmt2->execute([$conv['rider_id']]);
            $rider = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($rider) {
                $preview = $conv['last_message'] ?? '';
                if ($preview === '' && !empty($conv['last_image'])) $preview = '📷 Photo';
                elseif (mb_strlen($preview) > 40) $preview = mb_substr($preview, 0, 40) . '…';
                $result[] = [
                    'conversation_id' => 'rider_' . $conv['rider_id'],
                    'other_id' => (int)$conv['rider_id'],
                    'other_type' => 'rider',
                    'other_name' => $rider['name'],
                    'last_message' => htmlspecialchars($preview),
                    'last_time' => $conv['last_time'] ? date('g:i A', strtotime($conv['last_time'])) : '',
                    'unread' => (int)$conv['unread']
                ];
            }
        }

        // ── 3. SUPPLIER RIDER CONVERSATIONS ──
        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    WHEN c.sender_type = 'supplier_rider' THEN c.sender_id
                    WHEN c.receiver_type = 'supplier_rider' THEN c.receiver_id
                END as supplier_rider_id,
                MAX(c.created_at) as last_time,
                (SELECT message FROM chats c2 
                 WHERE c2.clinic_id = c.clinic_id 
                   AND ((c2.sender_type='supplier_rider' AND c2.sender_id=supplier_rider_id) 
                        OR (c2.receiver_type='supplier_rider' AND c2.receiver_id=supplier_rider_id))
                 ORDER BY c2.created_at DESC LIMIT 1) as last_message,
                (SELECT image FROM chats c4 
                 WHERE c4.clinic_id = c.clinic_id 
                   AND ((c4.sender_type='supplier_rider' AND c4.sender_id=supplier_rider_id) 
                        OR (c4.receiver_type='supplier_rider' AND c4.receiver_id=supplier_rider_id))
                 ORDER BY c4.created_at DESC LIMIT 1) as last_image,
                (SELECT COUNT(*) FROM chats c3 
                 WHERE c3.clinic_id = c.clinic_id 
                   AND c3.sender_type = 'supplier_rider' 
                   AND c3.sender_id = supplier_rider_id
                   AND c3.is_read = 0) as unread
            FROM chats c
            WHERE c.clinic_id = ?
              AND (c.sender_type = 'supplier_rider' OR c.receiver_type = 'supplier_rider')
            GROUP BY supplier_rider_id
            ORDER BY last_time DESC
        ");
        $stmt->execute([$clinic_id]);
        $supplierRiderConvs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($supplierRiderConvs as $conv) {
            if (!$conv['supplier_rider_id']) continue;
            $stmt2 = $pdo->prepare("
                SELECT sr.id, sr.full_name, s.supplier_name 
                FROM supplier_riders sr
                LEFT JOIN suppliers s ON sr.supplier_id = s.id
                WHERE sr.id = ?
            ");
            $stmt2->execute([$conv['supplier_rider_id']]);
            $sr = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($sr) {
                $supplierName = $sr['supplier_name'] ?? 'Supplier';
                $preview = $conv['last_message'] ?? '';
                if ($preview === '' && !empty($conv['last_image'])) $preview = '📷 Photo';
                elseif (mb_strlen($preview) > 40) $preview = mb_substr($preview, 0, 40) . '…';
                $result[] = [
                    'conversation_id' => 'supplier_rider_' . $conv['supplier_rider_id'],
                    'other_id' => (int)$conv['supplier_rider_id'],
                    'other_type' => 'supplier_rider',
                    'other_name' => $sr['full_name'] . ' (' . $supplierName . ')',
                    'last_message' => htmlspecialchars($preview),
                    'last_time' => $conv['last_time'] ? date('g:i A', strtotime($conv['last_time'])) : '',
                    'unread' => (int)$conv['unread']
                ];
            }
        }

        // Sort combined by last_time DESC
        usort($result, function($a, $b) {
            return strtotime($b['last_time'] ?: '1970-01-01') - strtotime($a['last_time'] ?: '1970-01-01');
        });

        echo json_encode($result);
    } catch (Exception $e) {
        error_log("get_conversations error: " . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET: Messages for a conversation
// ============================================
if ($action === 'get_messages') {
    $raw = $_GET['conversation_id'] ?? '';
    [$conv_type, $conv_id] = parseConversationId($raw);
    if (!$conv_id) { echo json_encode([]); exit; }

    try {
        // ✅ SUPPLIER RIDER MESSAGES
        if ($conv_type === 'supplier_rider') {
            $pdo->prepare("
                UPDATE chats SET is_read = 1 
                WHERE clinic_id = ? 
                  AND sender_type = 'supplier_rider' 
                  AND sender_id = ? 
                  AND is_read = 0
            ")->execute([$clinic_id, $conv_id]);

            $stmt = $pdo->prepare("
                SELECT c.* FROM chats c
                WHERE c.clinic_id = ?
                  AND (
                      (c.sender_type = 'supplier_rider' AND c.sender_id = ?)
                      OR (c.receiver_type = 'supplier_rider' AND c.receiver_id = ?)
                  )
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$clinic_id, $conv_id, $conv_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt2 = $pdo->prepare("
                SELECT sr.full_name, s.supplier_name 
                FROM supplier_riders sr
                LEFT JOIN suppliers s ON sr.supplier_id = s.id
                WHERE sr.id = ?
            ");
            $stmt2->execute([$conv_id]);
            $riderInfo = $stmt2->fetch(PDO::FETCH_ASSOC);
            $riderName = $riderInfo['full_name'] ?? 'Supplier Rider';
            $supplierName = $riderInfo['supplier_name'] ?? 'Supplier';
            $displayName = $riderName . ' (' . $supplierName . ')';

            $result = [];
            foreach ($messages as $msg) {
                $isClinic = ($msg['sender_type'] === 'clinic');
                $result[] = [
                    'id' => (int)$msg['id'],
                    'sender_id' => (int)$msg['sender_id'],
                    'sender_type' => $isClinic ? 'clinic' : 'supplier_rider',
                    'sender_name' => $isClinic ? 'Clinic Staff' : $displayName,
                    'message' => htmlspecialchars($msg['message'] ?? ''),
                    'image' => !empty($msg['image']) ? $CHAT_IMG_URL_BASE . $msg['image'] : null,
                    'time' => date('g:i A', strtotime($msg['created_at'])),
                    'is_read' => (bool)$msg['is_read']
                ];
            }
            echo json_encode($result);
            exit;
        }

        // CLINIC RIDER MESSAGES
        if ($conv_type === 'rider') {
            $pdo->prepare("UPDATE chats SET is_read = 1 WHERE clinic_id = ? AND sender_type = 'rider' AND sender_id = ? AND is_read = 0")
                ->execute([$clinic_id, $conv_id]);

            $stmt = $pdo->prepare("
                SELECT c.* FROM chats c
                WHERE c.clinic_id = ?
                  AND ((c.sender_type = 'rider' AND c.sender_id = ?) OR (c.receiver_type = 'rider' AND c.receiver_id = ?))
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$clinic_id, $conv_id, $conv_id]);
            $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $stmt2 = $pdo->prepare("SELECT name FROM riders WHERE id = ?");
            $stmt2->execute([$conv_id]);
            $riderName = $stmt2->fetchColumn() ?: 'Rider';

            $result = [];
            foreach ($messages as $msg) {
                $isClinic = ($msg['sender_type'] === 'clinic');
                $result[] = [
                    'id' => (int)$msg['id'],
                    'sender_id' => (int)$msg['sender_id'],
                    'sender_type' => $isClinic ? 'clinic' : 'rider',
                    'sender_name' => $isClinic ? 'Clinic Staff' : $riderName,
                    'message' => htmlspecialchars($msg['message'] ?? ''),
                    'image' => !empty($msg['image']) ? $CHAT_IMG_URL_BASE . $msg['image'] : null,
                    'time' => date('g:i A', strtotime($msg['created_at'])),
                    'is_read' => (bool)$msg['is_read']
                ];
            }
            echo json_encode($result);
            exit;
        }

        // USER/PATIENT MESSAGES
        $other_user_id = $conv_id;
        $stmt = $pdo->prepare("UPDATE chats SET is_read = 1 WHERE clinic_id = ? AND user_id = ? AND sender_type IN ('user','patient') AND is_read = 0");
        $stmt->execute([$clinic_id, $other_user_id]);

        $stmt = $pdo->prepare("SELECT c.*, c.created_at as message_time FROM chats c WHERE c.clinic_id = ? AND c.user_id = ? ORDER BY c.created_at ASC");
        $stmt->execute([$clinic_id, $other_user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($messages as $msg) {
            if ($msg['sender_type'] == 'clinic') {
                $sender_name = 'Clinic Staff';
                $display_sender_type = 'clinic';
                $sender_id = (int)$msg['sender_id'];
            } else {
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
                'message' => htmlspecialchars($msg['message'] ?? ''),
                'image' => !empty($msg['image']) ? $CHAT_IMG_URL_BASE . $msg['image'] : null,
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
// POST: Send message (reply)
// ============================================
if ($action === 'send') {
    if (!canCreateMessages()) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

    $raw = $_POST['conversation_id'] ?? '';
    [$conv_type, $conv_id] = parseConversationId($raw);
    $message = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleChatImageUpload($CHAT_IMG_PHYSICAL_DIR, $CHAT_IMG_URL_BASE);
    if (!$img_ok) { echo json_encode(['success'=>false,'message'=>$img_error]); exit; }
    if (!$conv_id || (!$message && !$img_filename)) { echo json_encode(['success'=>false,'message'=>'Missing fields']); exit; }

    try {
        // ✅ SEND TO SUPPLIER RIDER
        if ($conv_type === 'supplier_rider') {
            $stmt = $pdo->prepare("SELECT id, full_name FROM supplier_riders WHERE id = ?");
            $stmt->execute([$conv_id]);
            $sr = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sr) { echo json_encode(['success'=>false,'message'=>'Supplier rider not found']); exit; }

            $stmt = $pdo->prepare("
                INSERT INTO chats (clinic_id, user_id, sender_type, sender_id, receiver_id, receiver_type, message, image, is_read, created_at)
                VALUES (?, 0, 'clinic', ?, ?, 'supplier_rider', ?, ?, 0, NOW())
            ");
            $stmt->execute([$clinic_id, $user_id, $conv_id, $message, $img_filename]);

            echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId(), 'message'=>'Message sent to supplier rider']);
            exit;
        }

        // SEND TO CLINIC RIDER
        if ($conv_type === 'rider') {
            $stmt = $pdo->prepare("SELECT id, name FROM riders WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$conv_id, $clinic_id]);
            $rider = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rider) { echo json_encode(['success'=>false,'message'=>'Rider not found']); exit; }

            $stmt = $pdo->prepare("
                INSERT INTO chats (clinic_id, user_id, sender_type, sender_id, receiver_id, receiver_type, message, image, is_read, created_at)
                VALUES (?, 0, 'clinic', ?, ?, 'rider', ?, ?, 0, NOW())
            ");
            $stmt->execute([$clinic_id, $user_id, $conv_id, $message, $img_filename]);

            echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId(), 'message'=>'Message sent to rider']);
            exit;
        }

        // SEND TO USER/PATIENT
        $stmt = $pdo->prepare("SELECT id, role FROM users WHERE id = ?");
        $stmt->execute([$conv_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) { echo json_encode(['success'=>false,'message'=>'User not found']); exit; }

        $stmt = $pdo->prepare("
            INSERT INTO chats (clinic_id, user_id, sender_type, sender_id, message, image, is_read, created_at)
            VALUES (?, ?, 'clinic', ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$clinic_id, $conv_id, $user_id, $message, $img_filename]);

        echo json_encode(['success'=>true, 'id'=>$pdo->lastInsertId(), 'message'=>'Message sent']);
    } catch (Exception $e) {
        error_log("send message error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// ============================================
// POST: New conversation
// ============================================
if ($action === 'new_conversation') {
    if (!canCreateMessages()) { echo json_encode(['success'=>false,'message'=>'Permission denied']); exit; }

    $receiver_type_raw = $_POST['receiver_type'] ?? 'user';
    $receiver_id = (int)($_POST['receiver_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleChatImageUpload($CHAT_IMG_PHYSICAL_DIR, $CHAT_IMG_URL_BASE);
    if (!$img_ok) { echo json_encode(['success'=>false,'message'=>$img_error]); exit; }
    if (!$receiver_id || (!$message && !$img_filename)) { echo json_encode(['success'=>false,'message'=>'Missing fields']); exit; }

    try {
        // NEW CONVERSATION WITH SUPPLIER RIDER
        if ($receiver_type_raw === 'supplier_rider') {
            $stmt = $pdo->prepare("SELECT id, full_name FROM supplier_riders WHERE id = ?");
            $stmt->execute([$receiver_id]);
            $sr = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$sr) { echo json_encode(['success'=>false,'message'=>'Supplier rider not found']); exit; }

            $stmt = $pdo->prepare("
                INSERT INTO chats (clinic_id, user_id, sender_type, sender_id, receiver_id, receiver_type, message, image, is_read, created_at)
                VALUES (?, 0, 'clinic', ?, ?, 'supplier_rider', ?, ?, 0, NOW())
            ");
            $stmt->execute([$clinic_id, $user_id, $receiver_id, $message, $img_filename]);

            echo json_encode([
                'success' => true,
                'conversation_id' => 'supplier_rider_' . $receiver_id,
                'receiver_name' => $sr['full_name'],
                'message' => 'Message sent to supplier rider'
            ]);
            exit;
        }

        // NEW CONVERSATION WITH CLINIC RIDER
        if ($receiver_type_raw === 'rider') {
            $stmt = $pdo->prepare("SELECT id, name FROM riders WHERE id = ? AND clinic_id = ? AND status = 'active'");
            $stmt->execute([$receiver_id, $clinic_id]);
            $rider = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rider) { echo json_encode(['success'=>false,'message'=>'Rider not found']); exit; }

            $stmt = $pdo->prepare("
                INSERT INTO chats (clinic_id, user_id, sender_type, sender_id, receiver_id, receiver_type, message, image, is_read, created_at)
                VALUES (?, 0, 'clinic', ?, ?, 'rider', ?, ?, 0, NOW())
            ");
            $stmt->execute([$clinic_id, $user_id, $receiver_id, $message, $img_filename]);

            echo json_encode([
                'success' => true,
                'conversation_id' => 'rider_' . $receiver_id,
                'receiver_name' => $rider['name'],
                'message' => 'Message sent to rider'
            ]);
            exit;
        }

        // NEW CONVERSATION WITH USER/PATIENT (existing)
        $stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as name FROM users WHERE id = ?");
        $stmt->execute([$receiver_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) { echo json_encode(['success'=>false,'message'=>'User not found']); exit; }

        $stmt = $pdo->prepare("
            INSERT INTO chats (clinic_id, user_id, sender_type, sender_id, message, image, is_read, created_at)
            VALUES (?, ?, 'clinic', ?, ?, ?, 0, NOW())
        ");
        $stmt->execute([$clinic_id, $receiver_id, $user_id, $message, $img_filename]);

        echo json_encode([
            'success' => true,
            'conversation_id' => (string)$receiver_id,
            'receiver_name' => $user['name'],
            'message' => 'Message sent'
        ]);
    } catch (Exception $e) {
        error_log("new_conversation error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>'Database error: '.$e->getMessage()]);
    }
    exit;
}

// ============================================
// POST: Mark read
// ============================================
if ($action === 'mark_read') {
    $data = json_decode(file_get_contents('php://input'), true);
    $raw = $data['conversation_id'] ?? '';
    [$conv_type, $conv_id] = parseConversationId($raw);
    if (!$conv_id) { echo json_encode(['success'=>false,'message'=>'ID required']); exit; }

    try {
        if ($conv_type === 'supplier_rider') {
            $stmt = $pdo->prepare("UPDATE chats SET is_read = 1 WHERE clinic_id = ? AND sender_type = 'supplier_rider' AND sender_id = ? AND is_read = 0");
            $stmt->execute([$clinic_id, $conv_id]);
        } elseif ($conv_type === 'rider') {
            $stmt = $pdo->prepare("UPDATE chats SET is_read = 1 WHERE clinic_id = ? AND sender_type = 'rider' AND sender_id = ? AND is_read = 0");
            $stmt->execute([$clinic_id, $conv_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE chats SET is_read = 1 WHERE clinic_id = ? AND user_id = ? AND sender_type IN ('user','patient') AND is_read = 0");
            $stmt->execute([$clinic_id, $conv_id]);
        }
        echo json_encode(['success'=>true, 'affected'=>$stmt->rowCount()]);
    } catch (Exception $e) {
        error_log("mark_read error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

// ============================================
// GET: Unread count
// ============================================
if ($action === 'get_unread_count') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM chats WHERE clinic_id = ? AND is_read = 0 AND sender_type IN ('user','patient','rider','supplier_rider')");
        $stmt->execute([$clinic_id]);
        echo json_encode(['success'=>true, 'unread'=>(int)$stmt->fetchColumn()]);
    } catch (Exception $e) {
        error_log("get_unread_count error: " . $e->getMessage());
        echo json_encode(['success'=>false, 'unread'=>0]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);