<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
include __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'get_po_items':
            getPOItems($pdo);
            break;
            
        case 'create_return':
            createReturn($pdo);
            break;
            
        case 'get':
            getReturn($pdo);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function getPOItems($pdo) {
    $po_id = $_GET['po_id'] ?? 0;
    $clinic_id = $_SESSION['clinic_id'];
    
    // Get PO details with pr_id
    $stmt = $pdo->prepare("
        SELECT po.*, s.supplier_name, po.pr_id
        FROM purchase_orders po
        JOIN suppliers s ON po.supplier_id = s.id
        WHERE po.id = ? AND po.clinic_id = ?
    ");
    $stmt->execute([$po_id, $clinic_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$po) {
        throw new Exception('PO not found');
    }
    
    // Get items from pr_items (NOT po_items)
    $stmt = $pdo->prepare("
        SELECT 
            pi.id as po_item_id,
            pi.item_name,
            pi.quantity,
            pi.unit_price,
            pi.total_price as total,
            pi.supplier_id,
            pi.supplier_name,
            pi.supplier_product_id,
            COALESCE((
                SELECT SUM(ri.quantity) 
                FROM return_items ri 
                WHERE ri.po_item_id = pi.id AND ri.status != 'Cancelled'
            ), 0) as returned_quantity
        FROM pr_items pi
        WHERE pi.pr_id = ?
    ");
    $stmt->execute([$po['pr_id']]); // Use pr_id from PO
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $po['items'] = $items;
    
    echo json_encode(['success' => true, 'data' => $po]);
}

function createReturn($pdo) {
    $po_id = $_POST['po_id'] ?? 0;
    $pr_id = $_POST['pr_id'] ?? 0;
    $clinic_id = $_SESSION['clinic_id'];
    $user_id = $_SESSION['user_id'];
    
    // Validate PO
    $stmt = $pdo->prepare("SELECT * FROM purchase_orders WHERE id = ? AND clinic_id = ?");
    $stmt->execute([$po_id, $clinic_id]);
    $po = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$po) {
        throw new Exception('Invalid purchase order');
    }
    
    // Get selected items
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (empty($items)) {
        throw new Exception('No items selected');
    }
    
    // Calculate total refund
    $refund_amount = 0;
    foreach ($items as $item) {
        $refund_amount += $item['unit_price'] * $item['return_quantity'];
    }
    
    // Generate return number
    $year = date('Y');
    $month = date('m');
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM returns WHERE return_number LIKE 'RTR-{$year}{$month}%'");
    $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'] + 1;
    $return_number = 'RTR-' . $year . $month . str_pad($count, 4, '0', STR_PAD_LEFT);
    
    $pdo->beginTransaction();
    
    // Create return record
    $stmt = $pdo->prepare("
        INSERT INTO returns (
            return_number, po_id, clinic_id, requested_by,
            reason, description, refund_method, refund_amount,
            contact_person, contact_number, email, status,
            created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', NOW())
    ");
    $stmt->execute([
        $return_number,
        $po_id,
        $clinic_id,
        $user_id,
        $_POST['reason'],
        $_POST['description'] ?? null,
        $_POST['refund_method'],
        $refund_amount,
        $_POST['contact_person'],
        $_POST['contact_number'],
        $_POST['email'] ?? null
    ]);
    
    $return_id = $pdo->lastInsertId();
    
    // Create return items
    $itemStmt = $pdo->prepare("
        INSERT INTO return_items (return_id, po_item_id, item_id, quantity, unit_price, total)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    
    foreach ($items as $item) {
        $po_item_id = $item['po_item_id'];
        $total = $item['unit_price'] * $item['return_quantity'];
        $item_id = $item['supplier_product_id'] ?? 0;
        
        $itemStmt->execute([
            $return_id,
            $po_item_id,
            $item_id,
            $item['return_quantity'],
            $item['unit_price'],
            $total
        ]);
    }
    
    // Upload photos if any
    if (!empty($_FILES['photos'])) {
        $uploadDir = '../uploads/returns/';
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $photoStmt = $pdo->prepare("
            INSERT INTO return_photos (return_id, photo_path) VALUES (?, ?)
        ");
        
        foreach ($_FILES['photos']['tmp_name'] as $key => $tmp_name) {
            if ($_FILES['photos']['error'][$key] === 0) {
                $extension = pathinfo($_FILES['photos']['name'][$key], PATHINFO_EXTENSION);
                $filename = $return_number . '_' . ($key + 1) . '.' . $extension;
                $filepath = $uploadDir . $filename;
                
                if (move_uploaded_file($tmp_name, $filepath)) {
                    $photoStmt->execute([$return_id, 'uploads/returns/' . $filename]);
                }
            }
        }
    }
    
    // ============================================
    // ✅ BAGONG CODE: UPDATE EXPENSES & STATUSES
    // ============================================
    
    // 1. Update purchase_orders status to 'Return Requested'
    $updatePO = $pdo->prepare("
        UPDATE purchase_orders 
        SET status = 'Return Requested',
            updated_at = NOW()
        WHERE id = ?
    ");
    $updatePO->execute([$po_id]);
    
    // 2. Update purchase_requests status to 'Return Requested'
    $updatePR = $pdo->prepare("
        UPDATE purchase_requests 
        SET status = 'Return Requested',
            updated_at = NOW()
        WHERE id = ?
    ");
    $updatePR->execute([$pr_id]);
    
    // 3. ✅ UPDATE EXPENSES TABLE - from 'Waiting For Delivery' to 'Return Requested'
    try {
        $updateExpense = $pdo->prepare("
            UPDATE expenses 
            SET status = 'Return Requested',
                notes = CONCAT(IFNULL(notes, ''), '\n[RETURN] Return requested - Ref #: ', ?)
            WHERE pr_id = ? AND status IN ('Waiting For Delivery', 'Delivered')
        ");
        $updateExpense->execute([$return_number, $pr_id]);
    } catch (Exception $e) {
        // Log error but don't fail the return creation
        error_log("Failed to update expense status: " . $e->getMessage());
    }
    
    // 4. Create timeline entry
    $timelineStmt = $pdo->prepare("
        INSERT INTO return_timeline (return_id, status, notes, created_by, created_at)
        VALUES (?, 'Return Requested', 'Return request submitted', ?, NOW())
    ");
    $timelineStmt->execute([$return_id, $user_id]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'return_number' => $return_number,
        'refund_amount' => $refund_amount
    ]);
}

function getReturn($pdo) {
    $return_id = $_GET['id'] ?? 0;
    $clinic_id = $_SESSION['clinic_id'];
    
    // Get return details
    $stmt = $pdo->prepare("
        SELECT r.*, 
               po.po_number,
               s.supplier_name,
               CONCAT(u.first_name, ' ', u.last_name) as requested_by_name
        FROM returns r
        JOIN purchase_orders po ON r.po_id = po.id
        JOIN suppliers s ON po.supplier_id = s.id
        LEFT JOIN users u ON r.requested_by = u.id
        WHERE r.id = ? AND r.clinic_id = ?
    ");
    $stmt->execute([$return_id, $clinic_id]);
    $return = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$return) {
        throw new Exception('Return not found');
    }
    
    // Get return items
    $stmt = $pdo->prepare("
        SELECT ri.*, 
               pi.item_name
        FROM return_items ri
        LEFT JOIN pr_items pi ON ri.po_item_id = pi.id
        WHERE ri.return_id = ?
    ");
    $stmt->execute([$return_id]);
    $return['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get photos
    $stmt = $pdo->prepare("SELECT * FROM return_photos WHERE return_id = ?");
    $stmt->execute([$return_id]);
    $return['photos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get timeline
    $stmt = $pdo->prepare("
        SELECT rt.*, CONCAT(u.first_name, ' ', u.last_name) as created_by_name
        FROM return_timeline rt
        LEFT JOIN users u ON rt.created_by = u.id
        WHERE rt.return_id = ?
        ORDER BY rt.created_at DESC
    ");
    $stmt->execute([$return_id]);
    $return['timeline'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'data' => $return]);
}
?>