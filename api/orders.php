<?php
// api/orders.php
include __DIR__ . '/../config/db.php';

// Set JSON header
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get request method
$method = $_SERVER['REQUEST_METHOD'];

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $input = [];
}

// Get GET parameters
$action = $_GET['action'] ?? $input['action'] ?? '';
$id = $_GET['id'] ?? $input['id'] ?? 0;
$export = $_GET['export'] ?? '';

// Initialize response
$response = ['success' => false, 'message' => 'Invalid request'];

try {
    switch ($action) {
        case 'get_supplier_items':
            // Get items for a specific supplier
            $supplierId = $_GET['supplier_id'] ?? 0;
            
            $sql = "SELECT i.*, s.supplier_name 
                    FROM inventory i 
                    LEFT JOIN suppliers s ON i.supplier_id = s.id 
                    WHERE (i.supplier_id = ? OR i.supplier_id IS NULL) 
                    AND i.is_archived = 0 
                    ORDER BY i.name";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$supplierId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'data' => $items
            ];
            break;
            
        case 'get_order':
            // Get order details with items
            $orderId = $id;
            
            // Get order info
            $sql = "SELECT o.*, s.supplier_name, s.email as supplier_email, 
                    s.phone as supplier_phone, s.contact_person
                    FROM orders o 
                    LEFT JOIN suppliers s ON o.supplier_id = s.id 
                    WHERE o.id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                $response = ['success' => false, 'message' => 'Order not found'];
                break;
            }
            
            // Get order items
            $itemsSql = "SELECT oi.*, i.name as item_name, i.item_code, i.brand
                        FROM order_items oi 
                        LEFT JOIN inventory i ON oi.inventory_id = i.id 
                        WHERE oi.order_id = ?";
            
            $itemsStmt = $pdo->prepare($itemsSql);
            $itemsStmt->execute([$orderId]);
            $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'data' => $order,
                'items' => $items
            ];
            break;
            
        case 'create':
            // Create new purchase order
            if ($method === 'POST') {
                // Validate required fields
                $required = ['supplier_id', 'order_date', 'items'];
                foreach ($required as $field) {
                    if (empty($input[$field])) {
                        $response = ['success' => false, 'message' => "$field is required"];
                        break 2;
                    }
                }
                
                if (!is_array($input['items']) || count($input['items']) === 0) {
                    $response = ['success' => false, 'message' => 'At least one item is required'];
                    break 2;
                }
                
                // Generate order code
                $orderCode = 'PO-' . date('Ymd') . '-' . strtoupper(uniqid());
                
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    // Create order
                    $orderSql = "INSERT INTO orders (
                        order_code, supplier_id, order_date, expected_date, 
                        status, total_amount, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, 'Pending', ?, NOW(), NOW())";
                    
                    $orderStmt = $pdo->prepare($orderSql);
                    $orderStmt->execute([
                        $orderCode,
                        $input['supplier_id'],
                        $input['order_date'],
                        $input['expected_date'] ?? null,
                        $input['total_amount'] ?? 0
                    ]);
                    
                    $orderId = $pdo->lastInsertId();
                    
                    // Add order items
                    $itemSql = "INSERT INTO order_items (
                        order_id, inventory_id, quantity, unit_price, total_price
                    ) VALUES (?, ?, ?, ?, ?)";
                    
                    $itemStmt = $pdo->prepare($itemSql);
                    
                    foreach ($input['items'] as $item) {
                        $itemStmt->execute([
                            $orderId,
                            $item['id'],
                            $item['quantity'],
                            $item['unit_price'],
                            $item['quantity'] * $item['unit_price']
                        ]);
                    }
                    
                    // Update order total
                    $updateSql = "UPDATE orders SET total_amount = (
                        SELECT SUM(total_price) FROM order_items WHERE order_id = ?
                    ) WHERE id = ?";
                    
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([$orderId, $orderId]);
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    $response = [
                        'success' => true,
                        'message' => 'Order created successfully',
                        'order_id' => $orderId,
                        'order_code' => $orderCode
                    ];
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            break;
            
        case 'process':
            // Process order (Pending → Ordered)
            if ($method === 'POST') {
                $orderId = $input['id'] ?? 0;
                
                // Check if order exists and is Pending
                $checkSql = "SELECT * FROM orders WHERE id = ? AND status = 'Pending'";
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute([$orderId]);
                $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$order) {
                    $response = ['success' => false, 'message' => 'Order not found or cannot be processed'];
                    break;
                }
                
                // Update order status
                $updateSql = "UPDATE orders SET status = 'Ordered', updated_at = NOW() WHERE id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$orderId]);
                
                $response = [
                    'success' => true,
                    'message' => 'Order marked as Ordered'
                ];
            }
            break;
            
        case 'receive':
            // Receive order (Ordered → Received)
            if ($method === 'POST') {
                $orderId = $input['order_id'] ?? 0;
                $receiveDate = $input['receive_date'] ?? date('Y-m-d');
                
                // Check if order exists and is Ordered
                $checkSql = "SELECT o.*, s.supplier_name 
                            FROM orders o 
                            LEFT JOIN suppliers s ON o.supplier_id = s.id 
                            WHERE o.id = ? AND o.status = 'Ordered'";
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute([$orderId]);
                $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$order) {
                    $response = ['success' => false, 'message' => 'Order not found or cannot be received'];
                    break;
                }
                
                // Start transaction
                $pdo->beginTransaction();
                
                try {
                    // Get order items
                    $itemsSql = "SELECT * FROM order_items WHERE order_id = ?";
                    $itemsStmt = $pdo->prepare($itemsSql);
                    $itemsStmt->execute([$orderId]);
                    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Update inventory stock for each item
                    $updateStockSql = "UPDATE inventory SET 
                        stock = stock + ?, 
                        updated_at = NOW() 
                        WHERE id = ?";
                    
                    $updateStockStmt = $pdo->prepare($updateStockSql);
                    
                    // Record stock movements
                    $movementSql = "INSERT INTO stock_movements (
                        inventory_id, movement_type, quantity, reference, notes, created_by
                    ) VALUES (?, 'in', ?, ?, ?, ?)";
                    
                    $movementStmt = $pdo->prepare($movementSql);
                    
                    foreach ($items as $item) {
                        // Update inventory stock
                        $updateStockStmt->execute([$item['quantity'], $item['inventory_id']]);
                        
                        // Record stock movement
                        $movementStmt->execute([
                            $item['inventory_id'],
                            $item['quantity'],
                            $order['order_code'],
                            $input['receive_notes'] ?? "Order received: {$order['order_code']}",
                            1 // current user ID
                        ]);
                    }
                    
                    // Update order status
                    $updateOrderSql = "UPDATE orders SET 
                        status = 'Received', 
                        expected_date = ?, 
                        updated_at = NOW() 
                        WHERE id = ?";
                    
                    $updateOrderStmt = $pdo->prepare($updateOrderSql);
                    $updateOrderStmt->execute([$receiveDate, $orderId]);
                    
                    // Update inventory statuses
                    $updateStatusSql = "UPDATE inventory SET 
                        item_status = CASE 
                            WHEN stock <= 0 THEN 'out-of-stock'
                            WHEN stock <= reorder_level THEN 'low-stock'
                            ELSE 'in-stock'
                        END,
                        updated_at = NOW()
                        WHERE id IN (SELECT inventory_id FROM order_items WHERE order_id = ?)";
                    
                    $updateStatusStmt = $pdo->prepare($updateStatusSql);
                    $updateStatusStmt->execute([$orderId]);
                    
                    // Commit transaction
                    $pdo->commit();
                    
                    $response = [
                        'success' => true,
                        'message' => 'Order received and inventory updated'
                    ];
                    
                } catch (Exception $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            }
            break;
            
        case 'cancel':
            // Cancel order
            if ($method === 'POST') {
                $orderId = $input['id'] ?? 0;
                
                // Check if order can be cancelled (Pending or Ordered)
                $checkSql = "SELECT * FROM orders WHERE id = ? AND status IN ('Pending', 'Ordered')";
                $checkStmt = $pdo->prepare($checkSql);
                $checkStmt->execute([$orderId]);
                $order = $checkStmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$order) {
                    $response = ['success' => false, 'message' => 'Order not found or cannot be cancelled'];
                    break;
                }
                
                // Update order status
                $updateSql = "UPDATE orders SET status = 'Cancelled', updated_at = NOW() WHERE id = ?";
                $updateStmt = $pdo->prepare($updateSql);
                $updateStmt->execute([$orderId]);
                
                $response = [
                    'success' => true,
                    'message' => 'Order cancelled successfully'
                ];
            }
            break;
            
        case 'reorder_report':
            // Generate reorder report
            $sql = "SELECT i.*, s.supplier_name, s.id as supplier_id
                    FROM inventory i 
                    LEFT JOIN suppliers s ON i.supplier_id = s.id 
                    WHERE i.is_archived = 0 
                    AND i.stock <= i.reorder_level 
                    ORDER BY (i.stock/i.reorder_level) ASC, i.supplier_id";
            
            $stmt = $pdo->query($sql);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'data' => $items,
                'count' => count($items)
            ];
            break;
            
        case 'export_csv':
            // Export orders to CSV
            if ($export === 'csv') {
                // Build query based on filters
                $sql = "SELECT o.*, s.supplier_name, 
                       COUNT(oi.id) as item_count
                       FROM orders o
                       LEFT JOIN suppliers s ON o.supplier_id = s.id
                       LEFT JOIN order_items oi ON o.id = oi.order_id";
                
                $conditions = [];
                $params = [];
                
                if (!empty($_GET['status']) && $_GET['status'] !== 'all') {
                    $conditions[] = "o.status = ?";
                    $params[] = $_GET['status'];
                }
                
                if (!empty($_GET['supplier'])) {
                    $conditions[] = "o.supplier_id = ?";
                    $params[] = $_GET['supplier'];
                }
                
                if (!empty($_GET['date_from'])) {
                    $conditions[] = "o.order_date >= ?";
                    $params[] = $_GET['date_from'];
                }
                
                if (!empty($_GET['date_to'])) {
                    $conditions[] = "o.order_date <= ?";
                    $params[] = $_GET['date_to'];
                }
                
                if (!empty($_GET['search'])) {
                    $conditions[] = "(o.order_code LIKE ? OR s.supplier_name LIKE ?)";
                    $searchTerm = "%{$_GET['search']}%";
                    $params[] = $searchTerm;
                    $params[] = $searchTerm;
                }
                
                if (!empty($conditions)) {
                    $sql .= " WHERE " . implode(" AND ", $conditions);
                }
                
                $sql .= " GROUP BY o.id ORDER BY o.order_date DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Set CSV headers
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="orders_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                // Header row
                fputcsv($output, [
                    'Order Code', 'Supplier', 'Order Date', 'Expected Date',
                    'Status', 'Items', 'Total Amount', 'Created Date'
                ]);
                
                // Data rows
                foreach ($orders as $order) {
                    fputcsv($output, [
                        $order['order_code'],
                        $order['supplier_name'],
                        $order['order_date'],
                        $order['expected_date'] ?? '',
                        $order['status'],
                        $order['item_count'],
                        $order['total_amount'],
                        $order['created_at']
                    ]);
                }
                
                fclose($output);
                exit;
            }
            break;
            
        default:
            $response = ['success' => false, 'message' => 'Invalid action'];
    }
    
} catch (PDOException $e) {
    error_log("Orders API Error: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
} catch (Exception $e) {
    error_log("Orders API Error: " . $e->getMessage());
    $response = ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
}

// Send response
echo json_encode($response, JSON_PRETTY_PRINT);