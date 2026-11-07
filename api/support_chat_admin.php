<?php
error_reporting(E_ALL);
ini_set('display_errors', 0); 

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// ✅ Kukunin na lang natin sa request imbes na sa session
$admin_id = (int)($_REQUEST['admin_id'] ?? 0);
$action   = $_GET['action'] ?? '';

if (!$admin_id) {
    echo json_encode(['success' => false, 'message' => 'Missing admin ID']);
    exit;
}

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

// GET: Threads (Lahat ng threads)
if ($action === 'get_threads') {
    try {
        $stmt = $pdo->query("
            SELECT t.*,
                (SELECT message FROM support_messages sm WHERE sm.thread_id = t.id ORDER BY sm.id DESC LIMIT 1) as last_message,
                (SELECT COUNT(*) FROM support_messages sm WHERE sm.thread_id = t.id AND sm.is_read_by_support = 0 AND sm.sender_role != 'SuperAdmin') as unread
            FROM support_threads t
            ORDER BY COALESCE(t.last_message_at, t.created_at) DESC
        ");
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $result = [];
        foreach ($threads as $t) {
            $creator_name = 'Unknown User';
            $clinic_name = 'No Clinic';

            $uStmt = $pdo->prepare("SELECT first_name, last_name, clinic_id FROM users WHERE id = ?");
            $uStmt->execute([$t['created_by']]);
            $userDetails = $uStmt->fetch(PDO::FETCH_ASSOC);

            if ($userDetails) {
                $fullName = trim(($userDetails['first_name'] ?? '') . ' ' . ($userDetails['last_name'] ?? ''));
                if ($fullName) $creator_name = $fullName;

                if (!empty($userDetails['clinic_id'])) {
                    $cStmt = $pdo->prepare("SELECT name FROM clinics WHERE id = ?");
                    $cStmt->execute([$userDetails['clinic_id']]);
                    $clinicData = $cStmt->fetch(PDO::FETCH_ASSOC);
                    if ($clinicData && !empty($clinicData['name'])) $clinic_name = $clinicData['name'];
                }
            }

            $result[] = [
                'id'           => (int)$t['id'],
                'subject'      => $t['subject'],
                'status'       => $t['status'],
                'creator_name' => $creator_name,
                'clinic_name'  => $clinic_name,
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
        $pdo->prepare("UPDATE support_messages SET is_read_by_support = 1 WHERE thread_id = ?")->execute([$thread_id]);

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

// POST: Send Message
if ($action === 'send_message') {
    $thread_id = (int)($_POST['thread_id'] ?? 0);
    $message   = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleImageUpload($IMG_PHYSICAL_DIR);
    if (!$img_ok) { echo json_encode(['success' => false, 'message' => $img_error]); exit; }
    if (!$thread_id || (!$message && !$img_filename)) { echo json_encode(['success' => false, 'message' => 'Missing fields']); exit; }

    try {
        $stmt = $pdo->prepare("INSERT INTO support_messages (thread_id, sender_id, sender_role, message, image, is_read_by_user, is_read_by_support) VALUES (?, ?, 'SuperAdmin', ?, ?, 0, 1)");
        $stmt->execute([$thread_id, $admin_id, $message, $img_filename]);

        $pdo->prepare("UPDATE support_threads SET last_message_at = NOW(), status = IF(status='resolved','open',status) WHERE id = ?")->execute([$thread_id]);
        echo json_encode(['success' => true]);
    } catch (Exception $e) { echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]); }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);