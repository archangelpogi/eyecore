<?php
/**
 * api/get_3d_model.php
 * 
 * Returns the 3D model path for a specific supplier product.
 * Used by select_products.php for the 3D preview feature.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$clinicId = $_SESSION['clinic_id'] ?? 0;
if (!$clinicId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Clinic ID missing']);
    exit;
}

$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$productId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Product ID required']);
    exit;
}

try {
    // Fetch the 3D model path from supplier_products
    $stmt = $pdo->prepare("
        SELECT 
            sp.id,
            sp.product_name,
            sp.model_3d_path,
            sp.model_3d_filename,
            sp.has_3d_model,
            s.supplier_name
        FROM supplier_products sp
        INNER JOIN suppliers s ON s.id = sp.supplier_id
        WHERE sp.id = ? 
          AND sp.has_3d_model = 1 
          AND sp.is_active = 1
          AND s.status = 'Active'
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product && !empty($product['model_3d_path'])) {
        echo json_encode([
            'success' => true,
            'model_path' => $product['model_3d_path'],
            'product_name' => $product['product_name'],
            'supplier_name' => $product['supplier_name']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => '3D model not available for this product'
        ]);
    }
    
} catch (Exception $e) {
    error_log("get_3d_model error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}