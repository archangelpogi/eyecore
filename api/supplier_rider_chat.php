<?php
// api/supplier_rider_chat.php
session_name('eyecore_admin');
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

$supplier_id = (int)($_REQUEST['supplier_id'] ?? $_SESSION['supplier_id'] ?? 0);
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if (!$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'Missing supplier_id']);
    exit;
}

$IMG_URL_BASE = '/assets/images/chat-images/';
$IMG_PHYSICAL_DIR = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . $IMG_URL_BASE;

// ============================================
// GET: List of riders
// ============================================
if ($action === 'get_riders') {
    try {
        $stmt = $pdo->prepare("
            SELECT id, full_name, phone, photo_path, status
            FROM supplier_riders
            WHERE supplier_id = ? AND status = 'Active'
            ORDER BY full_name ASC
        ");
        $stmt->execute([$supplier_id]);
        $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($riders as $r) {
            // Kunin ang unread (galing sa rider)
            $stmt2 = $pdo->prepare("
                SELECT COUNT(*) FROM chats
                WHERE user_id = ?
                  AND sender_type = 'supplier_rider'
                  AND sender_id = ?
                  AND is_read = 0
            ");
            $stmt2->execute([$supplier_id, $r['id']]);
            $unread = (int)$stmt2->fetchColumn();

            // Kunin ang last message
            $stmt3 = $pdo->prepare("
                SELECT message, created_at, sender_type
                FROM chats
                WHERE user_id = ?
                  AND ((sender_type = 'supplier' AND receiver_type = 'supplier_rider' AND receiver_id = ?)
                       OR (sender_type = 'supplier_rider' AND sender_id = ? AND receiver_type = 'supplier'))
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt3->execute([$supplier_id, $r['id'], $r['id']]);
            $last = $stmt3->fetch(PDO::FETCH_ASSOC);

            $result[] = [
                'id' => (int)$r['id'],
                'name' => $r['full_name'],
                'phone' => $r['phone'],
                'status' => $r['status'],
                'unread' => $unread,
                'last_message' => $last ? htmlspecialchars(substr($last['message'] ?? '', 0, 50)) : '',
                'last_time' => $last ? date('g:i A', strtotime($last['created_at'])) : ''
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET: Messages with a rider
// ============================================
if ($action === 'get_messages') {
    $rider_id = (int)($_GET['rider_id'] ?? 0);
    if (!$rider_id) { echo json_encode([]); exit; }

    try {
        // Mark as read
        $pdo->prepare("
            UPDATE chats SET is_read = 1
            WHERE user_id = ? 
              AND sender_type = 'supplier_rider' 
              AND sender_id = ? 
              AND is_read = 0
        ")->execute([$supplier_id, $rider_id]);

        // Kunin ang messages
        $stmt = $pdo->prepare("
            SELECT c.*, sr.full_name as rider_name
            FROM chats c
            LEFT JOIN supplier_riders sr ON c.sender_id = sr.id AND c.sender_type = 'supplier_rider'
            WHERE c.user_id = ?
              AND ((c.sender_type = 'supplier' AND c.receiver_type = 'supplier_rider' AND c.receiver_id = ?)
                   OR (c.sender_type = 'supplier_rider' AND c.sender_id = ? AND c.receiver_type = 'supplier'))
            ORDER BY c.created_at ASC
        ");
        $stmt->execute([$supplier_id, $rider_id, $rider_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($messages as $m) {
            $is_mine = ($m['sender_type'] === 'supplier');
            $result[] = [
                'id' => (int)$m['id'],
                'sender_name' => $is_mine ? 'You' : ($m['rider_name'] ?? 'Rider'),
                'message' => htmlspecialchars($m['message'] ?? ''),
                'image' => !empty($m['image']) ? $IMG_URL_BASE . $m['image'] : null,
                'time' => date('g:i A', strtotime($m['created_at'])),
                'date' => date('M j, Y', strtotime($m['created_at'])),
                'is_mine' => $is_mine
            ];
        }
        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// POST: Send Message
// ============================================
if ($action === 'send_message') {
    $rider_id = (int)($_POST['rider_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    // Image upload logic
    $img_filename = null;
    if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed) && $_FILES['image']['size'] <= 5 * 1024 * 1024) {
            if (!file_exists($IMG_PHYSICAL_DIR)) mkdir($IMG_PHYSICAL_DIR, 0777, true);
            $img_filename = 'supplier_rider_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], $IMG_PHYSICAL_DIR . $img_filename);
        }
    }

    if (!$rider_id || (!$message && !$img_filename)) {
        echo json_encode(['success' => false, 'message' => 'Missing fields']);
        exit;
    }

    try {
        // I-verify na ang rider ay kay supplier
        $stmt = $pdo->prepare("SELECT id FROM supplier_riders WHERE id = ? AND supplier_id = ?");
        $stmt->execute([$rider_id, $supplier_id]);
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Rider not found']);
            exit;
        }

        // Insert message: Supplier to Rider
        $stmt = $pdo->prepare("
            INSERT INTO chats (
                clinic_id, user_id, sender_type, sender_id,
                receiver_id, receiver_type, message, image, is_read, created_at
            ) VALUES (
                0, ?, 'supplier', ?, ?, 'supplier_rider', ?, ?, 0, NOW()
            )
        ");
        $stmt->execute([
            $supplier_id,   // user_id (scope)
            $supplier_id,   // sender_id (supplier)
            $rider_id,      // receiver_id (rider)
            $message,
            $img_filename
        ]);

        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
    }
    exit;
}

// Default fallback
echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);