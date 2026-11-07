<?php
// ✅ I-OFF ANG DISPLAY ERRORS PARA HINDI MAG-CRASH ANG JSON
error_reporting(E_ALL);
ini_set('display_errors', 0); 
ini_set('log_errors', 1);

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// ✅ Custom Error Handler para maging JSON lahat ng errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    echo json_encode(['success' => false, 'message' => "PHP Error: $errstr on line $errline"]);
    exit;
});

$supplier_id = (int)($_REQUEST['supplier_id'] ?? 0);
$action      = $_GET['action'] ?? '';

if (!$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'Missing supplier ID']);
    exit;
}

// Kunin ang user_id ng supplier
$uStmt = $pdo->prepare("SELECT created_by, supplier_name FROM suppliers WHERE id = ?");
$uStmt->execute([$supplier_id]);
$supplier = $uStmt->fetch(PDO::FETCH_ASSOC);

if (!$supplier) {
    echo json_encode(['success' => false, 'message' => 'Supplier not found']);
    exit;
}

$user_id = (int)$supplier['created_by'];
$user_role = 'Supplier';

$IMG_URL_BASE    = '/assets/images/chat-images/';
$IMG_PHYSICAL_DIR = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . $IMG_URL_BASE;

function handleImageUpload($physical_dir) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] != 0) return [true, null, null];
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return [false, null, 'Invalid type.'];
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) return [false, null, 'Max 5MB.'];
    if (!file_exists($physical_dir)) mkdir($physical_dir, 0777, true);
    $new_name = 'support_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $physical_dir . $new_name)) return [false, null, 'Upload failed.'];
    return [true, $new_name, null];
}

// ============================================
// ✅ DEVELOPER SUPPORT (EXISTING - WALANG BINAGO)
// ============================================

// GET: Threads
if ($action === 'get_threads') {
    try {
        $stmt = $pdo->prepare("
            SELECT t.*,
                (SELECT message FROM support_messages sm WHERE sm.thread_id = t.id ORDER BY sm.id DESC LIMIT 1) as last_message,
                (SELECT COUNT(*) FROM support_messages sm WHERE sm.thread_id = t.id AND sm.is_read_by_user = 0 AND sm.sender_role = 'SuperAdmin') as unread
            FROM support_threads t
            WHERE t.created_by = ?
            ORDER BY COALESCE(t.last_message_at, t.created_at) DESC
        ");
        $stmt->execute([$user_id]);
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($threads as $t) {
            $result[] = [
                'id'           => (int)$t['id'],
                'subject'      => $t['subject'],
                'status'       => $t['status'],
                'last_message' => $t['last_message'] ?? 'No messages',
                'last_time'    => date('M j, g:i A', strtotime($t['last_message_at'] ?? $t['created_at'])),
                'unread'       => (int)$t['unread']
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// GET: Messages
if ($action === 'get_messages') {
    $thread_id = (int)($_GET['thread_id'] ?? 0);
    if (!$thread_id) { echo json_encode([]); exit; }

    try {
        $pdo->prepare("UPDATE support_messages SET is_read_by_user = 1 WHERE thread_id = ?")->execute([$thread_id]);

        $stmt = $pdo->prepare("
            SELECT sm.*, 
                   TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))) as sender_name
            FROM support_messages sm
            LEFT JOIN users u ON u.id = sm.sender_id
            WHERE sm.thread_id = ?
            ORDER BY sm.created_at ASC
        ");
        $stmt->execute([$thread_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($messages as $m) {
            $name = trim($m['sender_name'] ?? '');
            if (empty($name)) $name = ($m['sender_role'] === 'SuperAdmin') ? 'Developer' : 'User';

            $result[] = [
                'id'          => (int)$m['id'],
                'sender_id'   => (int)$m['sender_id'],
                'sender_name' => $name,
                'sender_role' => $m['sender_role'],
                'message'     => htmlspecialchars($m['message'] ?? ''),
                'image'       => !empty($m['image']) ? $GLOBALS['IMG_URL_BASE'] . $m['image'] : null,
                'time'        => date('g:i A', strtotime($m['created_at'])),
                'date'        => date('M j, Y', strtotime($m['created_at']))
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// POST: Create Thread
if ($action === 'create_thread') {
    $subject  = trim($_POST['subject'] ?? 'Supplier Support Request');
    $message  = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleImageUpload($IMG_PHYSICAL_DIR);
    if (!$img_ok) { echo json_encode(['success' => false, 'message' => $img_error]); exit; }
    if (!$message && !$img_filename) { echo json_encode(['success' => false, 'message' => 'Missing fields']); exit; }

    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT id FROM support_threads WHERE created_by = ? AND status != 'closed' ORDER BY id DESC LIMIT 1");
        $stmt->execute([$user_id]);
        $existing_id = $stmt->fetchColumn();

        if ($existing_id) {
            $thread_id = $existing_id;
            $pdo->prepare("UPDATE support_threads SET last_message_at = NOW() WHERE id = ?")->execute([$thread_id]);
        } else {
            $thread_code = 'SUP-' . date('Ymd') . '-' . mt_rand(1000, 9999);
            $stmt = $pdo->prepare("INSERT INTO support_threads (thread_code, subject, category, priority, status, created_by, created_by_role, last_message_at) VALUES (?, ?, 'general', 'normal', 'open', ?, ?, NOW())");
            $stmt->execute([$thread_code, $subject, $user_id, $user_role]);
            $thread_id = $pdo->lastInsertId();
        }

        $stmt = $pdo->prepare("INSERT INTO support_messages (thread_id, sender_id, sender_role, message, image, is_read_by_user, is_read_by_support) VALUES (?, ?, ?, ?, ?, 1, 0)");
        $stmt->execute([$thread_id, $user_id, $user_role, $message, $img_filename]);

        $pdo->commit();
        echo json_encode(['success' => true, 'thread_id' => (int)$thread_id]);
    } catch (Exception $e) { 
        $pdo->rollBack(); 
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]); 
    }
    exit;
}

// POST: Send Message (Developer Support)
if ($action === 'send_message') {
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $message   = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleImageUpload($IMG_PHYSICAL_DIR);
    if (!$img_ok) { echo json_encode(['success' => false, 'message' => $img_error]); exit; }
    if (!$thread_id || (!$message && !$img_filename)) { echo json_encode(['success' => false, 'message' => 'Missing fields']); exit; }

    try {
        $stmt = $pdo->prepare("INSERT INTO support_messages (thread_id, sender_id, sender_role, message, image, is_read_by_user, is_read_by_support) VALUES (?, ?, ?, ?, ?, 1, 0)");
        $stmt->execute([$thread_id, $user_id, $user_role, $message, $img_filename]);

        $pdo->prepare("UPDATE support_threads SET last_message_at = NOW(), status = IF(status='resolved','open',status) WHERE id = ?")->execute([$thread_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]); }
    exit;
}

// GET: Unread Count
if ($action === 'get_unread_count') {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT sm.thread_id) FROM support_messages sm JOIN support_threads st ON st.id = sm.thread_id WHERE st.created_by = ? AND sm.is_read_by_user = 0 AND sm.sender_role = 'SuperAdmin'");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true, 'unread' => (int)$stmt->fetchColumn()]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'unread' => 0]); }
    exit;
}

// ============================================
// ✅ BAGONG DAGDAG: CLINIC CHAT (SUPPLIER ↔ CLINIC)
// ============================================

// GET: List of Active Clinics
if ($action === 'get_clinics') {
    try {
        $stmt = $pdo->query("SELECT id, name, logo FROM clinics WHERE status = 'Active' ORDER BY name ASC");
        $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($clinics as $c) {
            $result[] = [
                'id' => (int)$c['id'],
                'name' => $c['name'],
                'logo' => !empty($c['logo']) ? '/assets/images/clinic_logos/' . $c['logo'] : null
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// GET: Clinic Messages (between Supplier and Clinic)
if ($action === 'get_clinic_messages') {
    $clinic_id = (int)($_GET['clinic_id'] ?? 0);
    if (!$clinic_id) { echo json_encode([]); exit; }

    try {
        // Mark messages as read (those sent by clinic to this supplier)
        $pdo->prepare("UPDATE chats SET is_read = 1 WHERE clinic_id = ? AND user_id = ? AND sender_type = 'clinic'")->execute([$clinic_id, $user_id]);

        // Get messages between clinic and this supplier
        $stmt = $pdo->prepare("
            SELECT c.*, 
                   u.first_name, u.last_name, u.avatar
            FROM chats c
            LEFT JOIN users u ON c.sender_id = u.id
            WHERE c.clinic_id = ? 
              AND (c.user_id = ? OR c.sender_id = ?)
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$clinic_id, $user_id, $user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($messages as $m) {
            $is_mine = ((int)$m['sender_id'] === $user_id);
            
            $sender_name = $is_mine ? 'You' : 'Clinic';
            if (!$is_mine && !empty($m['first_name'])) {
                $sender_name = trim($m['first_name'] . ' ' . ($m['last_name'] ?? ''));
            }

            $result[] = [
                'id'          => (int)$m['id'],
                'sender_id'   => (int)$m['sender_id'],
                'sender_name' => $sender_name,
                'sender_type' => $m['sender_type'],
                'message'     => htmlspecialchars($m['message'] ?? ''),
                'image'       => !empty($m['image']) ? $GLOBALS['IMG_URL_BASE'] . $m['image'] : null,
                'time'        => date('g:i A', strtotime($m['created_at'])),
                'date'        => date('M j, Y', strtotime($m['created_at'])),
                'is_mine'     => $is_mine
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) { echo json_encode(['error' => $e->getMessage()]); }
    exit;
}

// POST: Send Message to Clinic
if ($action === 'send_clinic_message') {
    $clinic_id = (int)($_POST['clinic_id'] ?? 0);
    $message   = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleImageUpload($IMG_PHYSICAL_DIR);
    if (!$img_ok) { echo json_encode(['success' => false, 'message' => $img_error]); exit; }
    if (!$clinic_id || (!$message && !$img_filename)) { echo json_encode(['success' => false, 'message' => 'Missing fields']); exit; }

    try {
        // Insert message from supplier to clinic
        $stmt = $pdo->prepare("
            INSERT INTO chats (clinic_id, user_id, sender_type, sender_id, receiver_id, receiver_type, message, image, is_read, created_at) 
            VALUES (?, ?, 'user', ?, 0, 'clinic', ?, ?, 0, NOW())
        ");
        $stmt->execute([$clinic_id, $user_id, $user_id, $message, $img_filename]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]); }
    exit;
}

// GET: Unread Clinic Messages Count
if ($action === 'get_clinic_unread_count') {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM chats 
            WHERE user_id = ? 
              AND sender_type = 'clinic' 
              AND is_read = 0
        ");
        $stmt->execute([$user_id]);
        echo json_encode(['success' => true, 'unread' => (int)$stmt->fetchColumn()]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'unread' => 0]); }
    exit;
}

// ============================================
// INVALID ACTION FALLBACK
// ============================================
echo json_encode(['success' => false, 'message' => 'Invalid action']);