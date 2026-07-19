<?php
// api/supplier_products.php
session_start();
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get supplier_id from session or request
$supplier_id = $_SESSION['supplier_id'] ?? $_GET['supplier_id'] ?? null;

$method = $_SERVER['REQUEST_METHOD'];

// Get request data
$input = [];
if ($method === 'POST' && !empty($_FILES)) {
    $input = $_POST;
    $files = $_FILES;
} else {
    $json_input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() === JSON_ERROR_NONE) {
        $input = $json_input;
    }
    $files = [];
}

$action = $_GET['action'] ?? $input['action'] ?? '';
$id = $_GET['id'] ?? $input['id'] ?? 0;
$page = $_GET['page'] ?? 1;
$limit = $_GET['limit'] ?? 10;
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? 'all';

// Only require supplier_id for non-public actions
$public_actions = ['search', 'get_by_category'];
if (!in_array($action, $public_actions) && !$supplier_id) {
    echo json_encode(['success' => false, 'message' => 'Supplier ID required']);
    exit;
}

function toFloat($value) {
    if ($value === null || $value === '') return 0.00;
    return floatval(str_replace(',', '', $value));
}

function uploadPhoto($file, $supplier_id) {
    $target_dir = __DIR__ . "/../uploads/supplier_products/";
    if (!file_exists($target_dir)) {
        mkdir($target_dir, 0777, true);
    }
    
    $ext = strtolower(pathinfo($file["name"], PATHINFO_EXTENSION));
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    
    if (!in_array($ext, $allowed)) {
        return ['success' => false, 'message' => 'Invalid file type'];
    }
    
    if ($file["size"] > 5 * 1024 * 1024) {
        return ['success' => false, 'message' => 'File too large (max 5MB)'];
    }
    
    $new_filename = 'supplier_' . $supplier_id . '_' . uniqid() . '.' . $ext;
    $target_file = $target_dir . $new_filename;
    
    if (move_uploaded_file($file["tmp_name"], $target_file)) {
        return [
            'success' => true,
            'path' => 'uploads/supplier_products/' . $new_filename,
            'filename' => $new_filename
        ];
    }
    
    return ['success' => false, 'message' => 'Upload failed'];
}

try {
    switch ($action) {
        
        case 'get_products':
    $offset = ($page - 1) * $limit;
    
    // Modified SQL - removed the subquery that was causing the error
    $sql = "SELECT sp.*, sp.stock, sp.stock_status, sp.low_stock_threshold
            FROM supplier_products sp
            WHERE sp.supplier_id = ? AND sp.is_active = 1";
    $params = [$supplier_id];
    
    if (!empty($search)) {
        $sql .= " AND (sp.product_name LIKE ? OR sp.product_code LIKE ? OR sp.brand LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
    }
    
    if ($category !== 'all') {
        $sql .= " AND sp.category = ?";
        $params[] = $category;
    }
    
    $sql .= " ORDER BY sp.product_name ASC LIMIT ? OFFSET ?";
    $params[] = (int)$limit;
    $params[] = (int)$offset;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($products as &$p) {
        $p['cost_price'] = floatval($p['cost_price']);
        $p['selling_price'] = floatval($p['selling_price']);
        $p['wholesale_price'] = $p['wholesale_price'] ? floatval($p['wholesale_price']) : null;
        $p['has_photo'] = (bool)$p['has_photo'];
        
        // Add a default value for linked_count since we removed it from the query
        $p['linked_count'] = 0;
    }
    
    // Fix the total count query
    $count_sql = "SELECT COUNT(*) FROM supplier_products sp WHERE sp.supplier_id = ? AND sp.is_active = 1";
    $count_params = [$supplier_id];
    
    if (!empty($search)) {
        $count_sql .= " AND (sp.product_name LIKE ? OR sp.product_code LIKE ? OR sp.brand LIKE ?)";
        $count_params[] = $searchTerm; $count_params[] = $searchTerm; $count_params[] = $searchTerm;
    }
    
    if ($category !== 'all') {
        $count_sql .= " AND sp.category = ?";
        $count_params[] = $category;
    }
    
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($count_params);
    $total = $count_stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'data' => $products,
        'pagination' => [
            'total' => $total,
            'page' => (int)$page,
            'limit' => (int)$limit,
            'total_pages' => ceil($total / $limit)
        ]
    ]);
    break;
            
case 'get_product':
    $stmt = $pdo->prepare("SELECT sp.* FROM supplier_products sp WHERE sp.id = ? AND sp.supplier_id = ?");
    $stmt->execute([$id, $supplier_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        $product['cost_price'] = floatval($product['cost_price']);
        $product['selling_price'] = floatval($product['selling_price']);
        $product['wholesale_price'] = $product['wholesale_price'] ? floatval($product['wholesale_price']) : null;
        $product['has_photo'] = (bool)$product['has_photo'];
        
        // Get linked inventory - DISABLED due to database error
        // $inv_stmt = $pdo->prepare("SELECT id, item_code, name, stock FROM inventory WHERE supplier_product_id = ?");
        // $inv_stmt->execute([$id]);
        // $product['linked_inventory'] = $inv_stmt->fetchAll(PDO::FETCH_ASSOC);
        $product['linked_inventory'] = []; // Return empty array as placeholder
        
        echo json_encode(['success' => true, 'data' => $product]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
    }
    break;
            
        case 'add_product':
    $required = ['product_code', 'product_name', 'category', 'cost_price', 'selling_price'];
    foreach ($required as $field) {
        if (empty($input[$field]) && empty($_POST[$field])) {
            echo json_encode(['success' => false, 'message' => "$field is required"]);
            exit;
        }
    }
    
    // Check duplicate code
    $check = $pdo->prepare("SELECT id FROM supplier_products WHERE product_code = ? AND supplier_id = ?");
    $check->execute([$_POST['product_code'] ?? $input['product_code'], $supplier_id]);
    if ($check->rowCount() > 0) {
        echo json_encode(['success' => false, 'message' => 'Product code already exists']);
        exit;
    }
    
    // Handle photo
    $has_photo = 0;
    $photo_path = null;
    $photo_filename = null;
    
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $upload = uploadPhoto($_FILES['photo'], $supplier_id);
        if ($upload['success']) {
            $has_photo = 1;
            $photo_path = $upload['path'];
            $photo_filename = $upload['filename'];
        }
    }
    
    // ✅ GET STOCK VALUES
    $stock = isset($_POST['initial_stock']) ? (int)$_POST['initial_stock'] : 0;
    $low_stock_threshold = isset($_POST['low_stock_threshold']) ? (int)$_POST['low_stock_threshold'] : 5;
    
    // ✅ CALCULATE STOCK STATUS
    if ($stock <= 0) {
        $stock_status = 'out-of-stock';
    } elseif ($stock <= $low_stock_threshold) {
        $stock_status = 'low-stock';
    } else {
        $stock_status = 'in-stock';
    }
    
    $sql = "INSERT INTO supplier_products (
                supplier_id, product_code, product_name, category, brand,
                description, cost_price, selling_price, wholesale_price,
                unit, min_order_qty, stock, stock_status, low_stock_threshold,
                lead_time_days, is_active, has_photo, photo_path, photo_filename, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?, ?)";
    
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([
        $supplier_id,
        $_POST['product_code'] ?? $input['product_code'],
        $_POST['product_name'] ?? $input['product_name'],
        $_POST['category'] ?? $input['category'],
        $_POST['brand'] ?? $input['brand'] ?? null,
        $_POST['description'] ?? $input['description'] ?? null,
        toFloat($_POST['cost_price'] ?? $input['cost_price']),
        toFloat($_POST['selling_price'] ?? $input['selling_price']),
        !empty($_POST['wholesale_price'] ?? $input['wholesale_price']) ? toFloat($_POST['wholesale_price'] ?? $input['wholesale_price']) : null,
        $_POST['unit'] ?? $input['unit'] ?? 'pcs',
        $_POST['min_order_qty'] ?? $input['min_order_qty'] ?? 1,
        $stock,
        $stock_status,
        $low_stock_threshold,
        $_POST['lead_time_days'] ?? $input['lead_time_days'] ?? null,
        $has_photo,
        $photo_path,
        $photo_filename,
        $_POST['notes'] ?? $input['notes'] ?? null
    ]);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Product added successfully',
            'id' => $pdo->lastInsertId()
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add product']);
    }
    break;

        case 'update_product':
    // Kunin ang ID
    if (isset($_POST['product_id']) && !empty($_POST['product_id'])) {
        $id = $_POST['product_id'];
    } elseif (isset($input['product_id']) && !empty($input['product_id'])) {
        $id = $input['product_id'];
    }
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'Product ID required']);
        exit;
    }
    
    // Kunin ang supplier_id
    $supplier_id = $_SESSION['supplier_id'] ?? $_POST['supplier_id'] ?? $input['supplier_id'] ?? null;
    
    // Handle photo upload
    $photo_updated = false;
    $has_photo = null;
    $photo_path = null;
    $photo_filename = null;
    
    if (!empty($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $upload = uploadPhoto($_FILES['photo'], $supplier_id);
        if ($upload['success']) {
            $has_photo = 1;
            $photo_path = $upload['path'];
            $photo_filename = $upload['filename'];
            $photo_updated = true;
        }
    }
    
    if (isset($_POST['remove_photo']) && $_POST['remove_photo'] == '1') {
        // Get current photo to delete
        $get = $pdo->prepare("SELECT photo_path FROM supplier_products WHERE id = ?");
        $get->execute([$id]);
        $current = $get->fetch();
        if ($current && $current['photo_path']) {
            $file = __DIR__ . "/../" . $current['photo_path'];
            if (file_exists($file)) unlink($file);
        }
        $has_photo = 0;
        $photo_path = null;
        $photo_filename = null;
        $photo_updated = true;
    }
    
    // Build update query
    $fields = [];
    $params = [];
    
    $updatable = ['product_code', 'product_name', 'category', 'brand', 'description', 'unit', 'min_order_qty', 'lead_time_days', 'notes'];
    foreach ($updatable as $field) {
        if (isset($_POST[$field])) {
            $fields[] = "$field = ?";
            $params[] = $_POST[$field];
        }
    }
    
    // ✅ FIX: Isama ang stock at low_stock_threshold
    if (isset($_POST['stock'])) {
        $fields[] = "stock = ?";
        $params[] = (int)$_POST['stock'];
    }
    
    if (isset($_POST['low_stock_threshold'])) {
        $fields[] = "low_stock_threshold = ?";
        $params[] = (int)$_POST['low_stock_threshold'];
    }
    
    // ✅ Recalculate stock_status based on stock
    if (isset($_POST['stock'])) {
        $stock = (int)$_POST['stock'];
        $threshold = isset($_POST['low_stock_threshold']) ? (int)$_POST['low_stock_threshold'] : 5;
        
        if ($stock <= 0) {
            $stock_status = 'out-of-stock';
        } elseif ($stock <= $threshold) {
            $stock_status = 'low-stock';
        } else {
            $stock_status = 'in-stock';
        }
        
        $fields[] = "stock_status = ?";
        $params[] = $stock_status;
    }
    
    if (isset($_POST['cost_price'])) {
        $fields[] = "cost_price = ?";
        $params[] = toFloat($_POST['cost_price']);
    }
    
    if (isset($_POST['selling_price'])) {
        $fields[] = "selling_price = ?";
        $params[] = toFloat($_POST['selling_price']);
    }
    
    if (isset($_POST['wholesale_price'])) {
        $fields[] = "wholesale_price = ?";
        $params[] = !empty($_POST['wholesale_price']) ? toFloat($_POST['wholesale_price']) : null;
    }
    
    if ($photo_updated) {
        $fields[] = "has_photo = ?";
        $params[] = $has_photo;
        $fields[] = "photo_path = ?";
        $params[] = $photo_path;
        $fields[] = "photo_filename = ?";
        $params[] = $photo_filename;
    }
    
    $fields[] = "updated_at = NOW()";
    
    if (empty($fields)) {
        echo json_encode(['success' => false, 'message' => 'No fields to update']);
        exit;
    }
    
    $params[] = $id;
    $params[] = $supplier_id;
    
    $sql = "UPDATE supplier_products SET " . implode(", ", $fields) . " WHERE id = ? AND supplier_id = ?";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute($params);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Product updated successfully'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Failed to update product'
        ]);
    }
    break;
            
        case 'delete_product':
            // Soft delete
            $stmt = $pdo->prepare("UPDATE supplier_products SET is_active = 0 WHERE id = ? AND supplier_id = ?");
            $stmt->execute([$id, $supplier_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Product deactivated'
            ]);
            break;
            
        case 'get_price_list':
    $sql = "SELECT sp.* FROM supplier_products sp WHERE sp.supplier_id = ? AND sp.is_active = 1";
    $params = [$supplier_id];
    
    if (!empty($search)) {
        $sql .= " AND (sp.product_name LIKE ? OR sp.product_code LIKE ? OR sp.brand LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY sp.product_name ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($products as &$p) {
        $p['cost_price'] = floatval($p['cost_price']);
        $p['selling_price'] = floatval($p['selling_price']);
        $p['wholesale_price'] = $p['wholesale_price'] ? floatval($p['wholesale_price']) : null;
        $p['margin'] = $p['selling_price'] > 0 ? round(($p['selling_price'] - $p['cost_price']) / $p['selling_price'] * 100, 2) : 0;
        
        // Check if in inventory - DISABLED due to database error
        // $inv = $pdo->prepare("SELECT COUNT(*) as cnt FROM inventory WHERE supplier_product_id = ?");
        // $inv->execute([$p['id']]);
        // $p['in_inventory'] = $inv->fetch()['cnt'];
        $p['in_inventory'] = 0; // Default value
    }
    
    echo json_encode(['success' => true, 'data' => $products]);
    break;

            
        case 'get_stock_levels':
    $sql = "SELECT 
                sp.id, sp.product_code, sp.product_name, sp.brand, sp.cost_price,
                sp.selling_price, sp.stock as current_stock,
                sp.low_stock_threshold as reorder_level,
                sp.stock_status,
                CASE 
                    WHEN sp.stock <= sp.low_stock_threshold THEN (sp.low_stock_threshold * 2 - sp.stock)
                    ELSE 0
                END as suggested_order
            FROM supplier_products sp
            WHERE sp.supplier_id = ? AND sp.is_active = 1";
    
    $params = [$supplier_id];
    
    if (!empty($search)) {
        $sql .= " AND (sp.product_name LIKE ? OR sp.product_code LIKE ? OR sp.brand LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm; $params[] = $searchTerm; $params[] = $searchTerm;
    }
    
    $sql .= " ORDER BY 
                CASE sp.stock_status
                    WHEN 'out-of-stock' THEN 1
                    WHEN 'low-stock' THEN 2
                    ELSE 3
                END,
                sp.stock ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = [
        'in_stock' => 0,
        'low_stock' => 0,
        'out_of_stock' => 0
    ];
    
    foreach ($stocks as &$s) {
        $s['cost_price'] = floatval($s['cost_price']);
        $s['current_stock'] = intval($s['current_stock']);
        $s['reorder_level'] = intval($s['reorder_level']);
        $s['suggested_order'] = intval($s['suggested_order']);
        $s['estimated_cost'] = $s['suggested_order'] * $s['cost_price'];
        
        $summary[$s['stock_status'] == 'in-stock' ? 'in_stock' : 
                ($s['stock_status'] == 'low-stock' ? 'low_stock' : 'out_of_stock')]++;
    }
    
    echo json_encode([
        'success' => true,
        'data' => $stocks,
        'summary' => $summary
    ]);
    break;

    case 'search':
    $q = $_GET['q'] ?? '';
    $category = $_GET['category'] ?? '';
    
    try {
        $sql = "SELECT sp.*, s.supplier_name, s.id as supplier_id
                FROM supplier_products sp
                JOIN suppliers s ON sp.supplier_id = s.id
                WHERE sp.is_active = 1 
                AND s.status = 'Active'";
        
        $params = [];
        
        // Add search condition
        if (!empty($q) && strlen($q) >= 2) {
            $sql .= " AND (sp.product_name LIKE ? OR sp.brand LIKE ? OR sp.description LIKE ?)";
            $searchTerm = "%$q%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        // Add category filter
        if (!empty($category)) {
            $sql .= " AND sp.category = ?";
            $params[] = $category;
        }
        
        $sql .= " ORDER BY sp.product_name, sp.cost_price";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convert numeric values
        foreach ($products as &$p) {
            $p['cost_price'] = floatval($p['cost_price']);
            $p['selling_price'] = floatval($p['selling_price']);
            $p['stock'] = intval($p['stock']);
            $p['min_order_qty'] = intval($p['min_order_qty'] ?? 1);
            $p['lead_time_days'] = intval($p['lead_time_days'] ?? 3);
            $p['has_photo'] = (bool)$p['has_photo'];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $products,
            'count' => count($products)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    break;

    case 'get_categories':
    try {
        $sql = "SELECT DISTINCT category 
                FROM supplier_products 
                WHERE is_active = 1 
                ORDER BY category";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo json_encode([
            'success' => true,
            'data' => $categories
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}