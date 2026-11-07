<?php
// api/rider_chat.php — Rider ↔ Clinic ↔ Supplier Chat API
// Supports: clinic rider, supplier rider (with supplier + clinics)
// EXISTING LOGIC UNTOUCHED — only additions for supplier rider

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// ============================================
// AUTH & CONFIG
// ============================================
$rider_id   = (int)($_REQUEST['rider_id'] ?? $_POST['rider_id'] ?? $_GET['rider_id'] ?? $_SESSION['rider_id'] ?? 0);
$rider_type = $_REQUEST['rider_type'] ?? $_POST['rider_type'] ?? $_GET['rider_type'] ?? $_SESSION['rider_type'] ?? 'clinic';
$action     = $_GET['action'] ?? $_POST['action'] ?? '';

if (!$rider_id) {
    echo json_encode(['success' => false, 'message' => 'Missing rider_id']);
    exit;
}

// Determine which table to query
if ($rider_type === 'supplier') {
    $stmt = $pdo->prepare("
        SELECT r.id as rider_id, r.supplier_id, r.full_name as rider_name
        FROM supplier_riders r
        WHERE r.id = ?
    ");
    $stmt->execute([$rider_id]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) { echo json_encode(['success' => false, 'message' => 'Supplier rider not found']); exit; }

    $contact_entity_id   = (int)$rider['supplier_id'];
    $contact_entity_type = 'supplier';
    $rider_supplier_id   = (int)$rider['supplier_id'];   // ✅ DAGDAG
    $rider_name_full     = $rider['rider_name'];          // ✅ DAGDAG
} else {
    // Default: clinic rider
    $stmt = $pdo->prepare("
        SELECT r.id as rider_id, r.user_id, r.clinic_id, r.name as rider_name
        FROM riders r
        WHERE r.id = ?
    ");
    $stmt->execute([$rider_id]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rider) { echo json_encode(['success' => false, 'message' => 'Clinic rider not found']); exit; }

    $contact_entity_id   = (int)$rider['clinic_id'];
    $contact_entity_type = 'clinic';
}

$IMG_URL_BASE     = '/assets/images/chat-images/';
$IMG_PHYSICAL_DIR = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . $IMG_URL_BASE;

// ============================================
// HELPER: Image Upload
// ============================================
function handleImageUpload($physical_dir) {
    if (!isset($_FILES['image']) || $_FILES['image']['error'] != 0) return [true, null, null];
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed)) return [false, null, 'Invalid type.'];
    if ($_FILES['image']['size'] > 5 * 1024 * 1024) return [false, null, 'Max 5MB.'];
    if (!file_exists($physical_dir)) mkdir($physical_dir, 0777, true);
    $new_name = 'rider_chat_' . time() . '_' . mt_rand(1000, 9999) . '.' . $ext;
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $physical_dir . $new_name)) return [false, null, 'Upload failed.'];
    return [true, $new_name, null];
}

// ============================================
// GET: Chat list for supplier rider (supplier + clinics)
// ✅ BAGONG ACTION — para sa supplier rider chat list
// ============================================
if ($action === 'get_chat_list' && $rider_type === 'supplier') {
    try {
        $result = [];

        // 1. Supplier boss conversation
        $stmt = $pdo->prepare("
            SELECT 
                s.id, s.supplier_name AS name,
                (SELECT message FROM chats c
                 WHERE c.user_id = s.id
                   AND ((c.sender_type = 'supplier' AND c.receiver_type = 'supplier_rider' AND c.receiver_id = ?)
                        OR (c.sender_type = 'supplier_rider' AND c.sender_id = ? AND c.receiver_type = 'supplier'))
                 ORDER BY c.created_at DESC LIMIT 1) AS last_message,
                (SELECT created_at FROM chats c
                 WHERE c.user_id = s.id
                   AND ((c.sender_type = 'supplier' AND c.receiver_type = 'supplier_rider' AND c.receiver_id = ?)
                        OR (c.sender_type = 'supplier_rider' AND c.sender_id = ? AND c.receiver_type = 'supplier'))
                 ORDER BY c.created_at DESC LIMIT 1) AS last_time,
                (SELECT COUNT(*) FROM chats c
                 WHERE c.user_id = s.id
                   AND c.sender_type = 'supplier'
                   AND c.receiver_type = 'supplier_rider'
                   AND c.receiver_id = ?
                   AND c.is_read = 0) AS unread
            FROM suppliers s
            WHERE s.id = ?
        ");
        $stmt->execute([$rider_id, $rider_id, $rider_id, $rider_id, $rider_id, $rider_supplier_id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($supplier) {
            $result[] = [
                'type' => 'supplier',
                'target_id' => (int)$supplier['id'],
                'name' => 'Supplier Admin (' . $supplier['name'] . ')',
                'avatar_initial' => strtoupper(substr($supplier['name'], 0, 1)),
                'last_message' => $supplier['last_message'] ? htmlspecialchars(substr($supplier['last_message'], 0, 50)) : '',
                'last_time' => $supplier['last_time'] ? date('M j, g:i A', strtotime($supplier['last_time'])) : '',
                'unread' => (int)$supplier['unread']
            ];
        }

        // 2. Clinics — from purchase orders (active + old deliveries)
        $stmt = $pdo->prepare("
            SELECT DISTINCT 
                c.id, c.clinic_name AS name, c.address
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            JOIN clinics c ON pr.clinic_id = c.id
            WHERE po.assigned_rider_id = ?
            ORDER BY c.clinic_name
        ");
        $stmt->execute([$rider_id]);
        $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($clinics as $clinic) {
            $stmt2 = $pdo->prepare("
                SELECT message, created_at FROM chats
                WHERE clinic_id = ?
                  AND ((sender_type = 'supplier_rider' AND sender_id = ?)
                       OR (receiver_type = 'supplier_rider' AND receiver_id = ?))
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt2->execute([$clinic['id'], $rider_id, $rider_id]);
            $last = $stmt2->fetch(PDO::FETCH_ASSOC);

            $stmt3 = $pdo->prepare("
                SELECT COUNT(*) FROM chats
                WHERE clinic_id = ?
                  AND receiver_type = 'supplier_rider'
                  AND receiver_id = ?
                  AND sender_type = 'clinic'
                  AND is_read = 0
            ");
            $stmt3->execute([$clinic['id'], $rider_id]);
            $unread = (int)$stmt3->fetchColumn();

            $result[] = [
                'type' => 'clinic',
                'target_id' => (int)$clinic['id'],
                'name' => $clinic['name'],
                'avatar_initial' => strtoupper(substr($clinic['name'], 0, 1)),
                'last_message' => $last ? htmlspecialchars(substr($last['message'] ?? '📷 Photo', 0, 50)) : '',
                'last_time' => $last ? date('M j, g:i A', strtotime($last['created_at'])) : '',
                'unread' => $unread
            ];
        }

        echo json_encode($result);
    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// GET: Messages
// ============================================
if ($action === 'get_messages') {
    // ✅ ✅ ✅ DAGDAG: SUPPLIER RIDER MULTI-CHAT (supplier + clinics)
    if ($rider_type === 'supplier') {
        try {
            $chat_with = $_GET['chat_with'] ?? '';
            $target_id = (int)($_GET['target_id'] ?? 0);

            $messages = [];

            if ($chat_with === 'supplier') {
                $supplier_id = $rider_supplier_id;

                // Mark as read (messages from supplier to rider)
                $pdo->prepare("
                    UPDATE chats SET is_read = 1
                    WHERE user_id = ?
                      AND sender_type = 'supplier'
                      AND receiver_type = 'supplier_rider'
                      AND receiver_id = ?
                      AND is_read = 0
                ")->execute([$supplier_id, $rider_id]);

                // Get messages
                $stmt = $pdo->prepare("
                    SELECT c.* FROM chats c
                    WHERE c.user_id = ?
                      AND (
                          (c.sender_type = 'supplier' AND c.receiver_type = 'supplier_rider' AND c.receiver_id = ?)
                          OR (c.sender_type = 'supplier_rider' AND c.sender_id = ? AND c.receiver_type = 'supplier')
                      )
                    ORDER BY c.created_at ASC
                ");
                $stmt->execute([$supplier_id, $rider_id, $rider_id]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else if ($chat_with === 'clinic' && $target_id > 0) {
                $clinic_id = $target_id;

                // Mark as read
                $pdo->prepare("
                    UPDATE chats SET is_read = 1
                    WHERE clinic_id = ?
                      AND receiver_type = 'supplier_rider'
                      AND receiver_id = ?
                      AND is_read = 0
                ")->execute([$clinic_id, $rider_id]);

                // Get messages
                $stmt = $pdo->prepare("
                    SELECT c.* FROM chats c
                    WHERE c.clinic_id = ?
                      AND (
                          (c.sender_type = 'supplier_rider' AND c.sender_id = ?)
                          OR (c.receiver_type = 'supplier_rider' AND c.receiver_id = ?)
                      )
                    ORDER BY c.created_at ASC
                ");
                $stmt->execute([$clinic_id, $rider_id, $rider_id]);
                $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            $result = [];
            foreach ($messages as $m) {
                $is_mine = ($m['sender_type'] === 'supplier_rider' && (int)$m['sender_id'] === $rider_id);
                $otherName = ($chat_with === 'supplier') ? 'Supplier Admin' : 'Clinic Staff';

                $result[] = [
                    'id'          => (int)$m['id'],
                    'sender_name' => $is_mine ? 'You' : $otherName,
                    'sender_role' => $m['sender_type'],
                    'message'     => htmlspecialchars($m['message'] ?? ''),
                    'image'       => !empty($m['image']) ? $IMG_URL_BASE . $m['image'] : null,
                    'time'        => date('g:i A', strtotime($m['created_at'])),
                    'date'        => date('M j, Y', strtotime($m['created_at'])),
                    'is_mine'     => $is_mine
                ];
            }
            echo json_encode($result);
        } catch (Exception $e) {
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }
    // ✅ END DAGDAG

    // ═══ EXISTING LOGIC — HUWAG GALAWIN ═══
    try {
        // Mark as read — messages TO this rider FROM clinic
        $pdo->prepare("
            UPDATE chats 
            SET is_read = 1 
            WHERE clinic_id = ?
              AND receiver_type = 'rider'
              AND receiver_id = ?
              AND is_read = 0
        ")->execute([$contact_entity_id, $rider_id]);

        $otherName = $contact_entity_type === 'clinic' ? 'Clinic Staff' : 'Supplier Staff';

        if ($contact_entity_type === 'clinic') {
            $stmt = $pdo->prepare("
                SELECT c.* 
                FROM chats c
                WHERE c.clinic_id = ?
                  AND (
                      (c.sender_type = 'rider' AND c.sender_id = ?)
                      OR (c.receiver_type = 'rider' AND c.receiver_id = ?)
                  )
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$contact_entity_id, $rider_id, $rider_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT c.* 
                FROM chats c
                WHERE (
                      (c.sender_type = 'supplier' AND c.sender_id = ?)
                      OR (c.receiver_type = 'supplier' AND c.receiver_id = ?)
                  )
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([$rider_id, $rider_id]);
        }
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($messages as $m) {
            $is_mine = (
                ($m['sender_type'] === 'rider'    && (int)$m['sender_id'] === $rider_id) ||
                ($m['sender_type'] === 'supplier' && (int)$m['sender_id'] === $rider_id)
            );

            $result[] = [
                'id'          => (int)$m['id'],
                'sender_name' => $is_mine ? 'You' : $otherName,
                'sender_role' => $m['sender_type'],
                'message'     => htmlspecialchars($m['message'] ?? ''),
                'image'       => !empty($m['image']) ? $IMG_URL_BASE . $m['image'] : null,
                'time'        => date('g:i A', strtotime($m['created_at'])),
                'date'        => date('M j, Y', strtotime($m['created_at'])),
                'is_mine'     => $is_mine
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
    // ✅ ✅ ✅ DAGDAG: SUPPLIER RIDER SEND
    if ($rider_type === 'supplier') {
        $message = trim($_POST['message'] ?? '');
        [$img_ok, $img_filename, $img_error] = handleImageUpload($IMG_PHYSICAL_DIR);
        if (!$img_ok) { echo json_encode(['success' => false, 'message' => $img_error]); exit; }
        if (!$message && !$img_filename) { echo json_encode(['success' => false, 'message' => 'Missing fields']); exit; }

        try {
            $chat_with = $_POST['chat_with'] ?? '';
            $target_id = (int)($_POST['target_id'] ?? 0);

            if ($chat_with === 'supplier') {
                $stmt = $pdo->prepare("
                    INSERT INTO chats (
                        clinic_id, user_id, sender_type, sender_id,
                        receiver_id, receiver_type, message, image, is_read, created_at
                    ) VALUES (
                        0, ?, 'supplier_rider', ?, ?, 'supplier', ?, ?, 0, NOW()
                    )
                ");
                $stmt->execute([
                    $rider_supplier_id,
                    $rider_id,
                    $rider_supplier_id,
                    $message,
                    $img_filename
                ]);
            } else if ($chat_with === 'clinic' && $target_id > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO chats (
                        clinic_id, user_id, sender_type, sender_id,
                        receiver_id, receiver_type, message, image, is_read, created_at
                    ) VALUES (
                        ?, 0, 'supplier_rider', ?, 0, 'clinic', ?, ?, 0, NOW()
                    )
                ");
                $stmt->execute([
                    $target_id,
                    $rider_id,
                    $message,
                    $img_filename
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Invalid target']);
                exit;
            }

            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]);
        }
        exit;
    }
    // ✅ END DAGDAG

    // ═══ EXISTING LOGIC — HUWAG GALAWIN ═══
    $message = trim($_POST['message'] ?? '');

    [$img_ok, $img_filename, $img_error] = handleImageUpload($IMG_PHYSICAL_DIR);
    if (!$img_ok) { echo json_encode(['success' => false, 'message' => $img_error]); exit; }
    if (!$message && !$img_filename) { echo json_encode(['success' => false, 'message' => 'Missing fields']); exit; }

    try {
        if ($contact_entity_type === 'clinic') {
            $stmt = $pdo->prepare("
                INSERT INTO chats (
                    clinic_id, user_id, sender_type, sender_id, receiver_id, receiver_type,
                    message, image, is_read, created_at
                ) VALUES (
                    ?, 0, 'rider', ?, 0, 'clinic', ?, ?, 0, NOW()
                )
            ");
            $stmt->execute([$contact_entity_id, $rider_id, $message, $img_filename]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO chats (
                    clinic_id, user_id, sender_type, sender_id, receiver_id, receiver_type,
                    message, image, is_read, created_at
                ) VALUES (
                    0, ?, 'supplier', ?, 0, 'supplier', ?, ?, 0, NOW()
                )
            ");
            $stmt->execute([$contact_entity_id, $rider_id, $message, $img_filename]);
        }

        echo json_encode(['success' => true]);
    } catch (Exception $e) { 
        echo json_encode(['success' => false, 'message' => 'DB Error: ' . $e->getMessage()]); 
    }
    exit;
}

// ============================================
// GET: Unread Count
// ============================================
if ($action === 'get_unread_count') {
    // ✅ ✅ ✅ DAGDAG: SUPPLIER RIDER UNREAD
    if ($rider_type === 'supplier') {
        try {
            $total = 0;

            // Unread from supplier boss
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM chats
                WHERE user_id = ?
                  AND sender_type = 'supplier'
                  AND receiver_type = 'supplier_rider'
                  AND receiver_id = ?
                  AND is_read = 0
            ");
            $stmt->execute([$rider_supplier_id, $rider_id]);
            $total += (int)$stmt->fetchColumn();

            // Unread from clinics
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM chats
                WHERE receiver_type = 'supplier_rider'
                  AND receiver_id = ?
                  AND sender_type = 'clinic'
                  AND is_read = 0
            ");
            $stmt->execute([$rider_id]);
            $total += (int)$stmt->fetchColumn();

            echo json_encode(['success' => true, 'unread' => $total]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'unread' => 0]);
        }
        exit;
    }
    // ✅ END DAGDAG

    // ═══ EXISTING LOGIC — HUWAG GALAWIN ═══
    try {
        if ($contact_entity_type === 'clinic') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM chats 
                WHERE clinic_id = ?
                  AND receiver_type = 'rider'
                  AND receiver_id = ?
                  AND is_read = 0
            ");
            $stmt->execute([$contact_entity_id, $rider_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM chats 
                WHERE receiver_type = 'supplier'
                  AND receiver_id = ?
                  AND is_read = 0
            ");
            $stmt->execute([$rider_id]);
        }
        echo json_encode(['success' => true, 'unread' => (int)$stmt->fetchColumn()]);
    } catch (Exception $e) { 
        echo json_encode(['success' => false, 'unread' => 0]); 
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action']);