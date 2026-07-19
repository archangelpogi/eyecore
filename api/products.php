<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

header('Content-Type: application/json');

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access - Please login first']);
    exit;
}

if (!isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Session expired - clinic ID missing, please login again']);
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId   = $_SESSION['user_id'];

$rawInput = file_get_contents('php://input');
$bodyData = json_decode($rawInput, true) ?? [];
$action   = $_GET['action'] ?? $bodyData['action'] ?? $_POST['action'] ?? '';

error_log("API products.php called - Action: $action, Clinic ID: $clinicId, User ID: $userId");

// ============================================================
// RBAC PERMISSION HELPER
// ============================================================
class ProductPermission {
    private static $module = 'products';
    public static function can($action) {
        $map = ['view'=>'products_view','create'=>'products_create','edit'=>'products_edit','delete'=>'products_delete','approve'=>'products_approve','reject'=>'products_reject'];
        return RBACHelper::hasPermission($map[$action] ?? 'products_' . $action);
    }
    public static function check($action) {
        if (!self::can($action)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => "Permission denied: cannot $action products"]);
            exit;
        }
    }
}

// ============================================================
// ROUTER
// ============================================================
switch ($action) {
    case 'get_products':
        if (!ProductPermission::can('view')) getProductsViewOnly($pdo, $clinicId);
        else getProducts($pdo, $clinicId);
        break;
    case 'get_inventory_items':
        ProductPermission::check('view');
        getInventoryItems($pdo, $clinicId);
        break;
    case 'post_product':
        ProductPermission::check('create');
        postProduct($pdo, $clinicId, $userId);
        break;
    case 'edit_product':
        ProductPermission::check('edit');
        editProduct($pdo, $clinicId, $bodyData);
        break;
    case 'delete_product':
        ProductPermission::check('delete');
        deleteProduct($pdo, $clinicId, $bodyData);
        break;
    case 'approve_product':
        ProductPermission::check('approve');
        approveProduct($pdo, $clinicId, $userId);
        break;
    case 'reject_product':
        ProductPermission::check('reject');
        rejectProduct($pdo, $clinicId, $userId);
        break;
    case 'upload_3d_model':
        ProductPermission::check('create');
        echo json_encode(['success' => true, 'message' => '3D model upload feature coming soon']);
        break;
    case 'get_product_3d':
        ProductPermission::check('view');
        getProduct3D($pdo);
        break;
    case 'get_3d_product':
        ProductPermission::check('view');
        get3DProduct($pdo);
        break;
    case 'get_permissions':
        echo json_encode(['success'=>true,'data'=>['can_view'=>ProductPermission::can('view'),'can_create'=>ProductPermission::can('create'),'can_edit'=>ProductPermission::can('edit'),'can_delete'=>ProductPermission::can('delete'),'can_approve'=>ProductPermission::can('approve'),'can_reject'=>ProductPermission::can('reject')]]);
        break;
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action: ' . $action]);
}

// ============================================================
// GET PRODUCTS — VIEW ONLY (with warranty data)
// ============================================================
function getProductsViewOnly($pdo, $clinicId) {
    try {
        $stmt = $pdo->prepare("
            SELECT p.id, p.name, p.description, p.price, p.category,
                   p.image, p.images_json, p.approval_status,
                   p.warranty_period, p.warranty_premium_price,
                   i.brand, i.stock
            FROM products p
            LEFT JOIN inventory i ON p.inventory_id = i.id
            WHERE p.clinic_id = ? AND p.approval_status = 'approved'
            ORDER BY p.id DESC
        ");
        $stmt->execute([$clinicId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($products as &$p) {
            $p['has_3d']          = 0;
            $p['formatted_price'] = '₱' . number_format($p['price'] ?? 0, 2);
            $p['images']          = !empty($p['images_json']) ? json_decode($p['images_json'], true) : [];
            $p['colors']          = [];
        }
        echo json_encode(['success' => true, 'data' => $products]);
    } catch (Exception $e) {
        error_log("getProductsViewOnly error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// GET PRODUCTS — FULL (with warranty data)
// ============================================================
function getProducts($pdo, $clinicId) {
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.*,
                i.brand, i.stock,
                p.images_json, p.colors_json,
                p.is_on_sale, p.sale_price, p.sale_start, p.sale_end, p.sale_label,
                p.approval_status,
                p.warranty_period, p.warranty_coverage, p.warranty_terms, p.warranty_premium_price,
                p3d.model_file, p3d.model_type,
                p3d.colors_json AS model_colors_json,
                CASE WHEN p3d.id IS NOT NULL THEN 1 ELSE 0 END AS has_3d
            FROM products p
            LEFT JOIN inventory i ON p.inventory_id = i.id
            LEFT JOIN product_3d_models p3d
                   ON p.id = p3d.product_id OR p.inventory_id = p3d.inventory_id
            WHERE p.clinic_id = ?
            GROUP BY p.id
            ORDER BY p.id DESC
        ");
        $stmt->execute([$clinicId]);
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($products as &$product) {
            $product['formatted_price'] = '₱' . number_format($product['price'] ?? 0, 2);
            if (!empty($product['sale_price'])) {
                $product['formatted_sale_price'] = '₱' . number_format($product['sale_price'], 2);
            }
            $product['images'] = !empty($product['images_json']) ? json_decode($product['images_json'], true) : [];

            // Decode warranty coverage if exists
            if (!empty($product['warranty_coverage'])) {
                $product['warranty_coverage'] = json_decode($product['warranty_coverage'], true);
            }

            // Colors: DB first, then JSON columns, then defaults
            $cs = $pdo->prepare("SELECT color_code, color_name, quantity, is_available FROM product_color_inventory WHERE product_id = ? AND clinic_id = ? ORDER BY color_name");
            $cs->execute([$product['id'], $clinicId]);
            $dbColors = $cs->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($dbColors)) {
                $product['colors'] = array_map(fn($c) => ['name'=>$c['color_name'],'code'=>$c['color_code'],'quantity'=>$c['quantity'],'is_available'=>$c['is_available']], $dbColors);
            } elseif (!empty($product['colors_json'])) {
                $product['colors'] = json_decode($product['colors_json'], true);
            } elseif (!empty($product['model_colors_json'])) {
                $product['colors'] = json_decode($product['model_colors_json'], true);
            } else {
                $product['colors'] = _defaultColors();
            }

            // Extra fields
            if (!empty($product['extra_fields_json'])) {
                $product['extra_fields'] = json_decode($product['extra_fields_json'], true);
            }
        }

        echo json_encode(['success' => true, 'data' => $products]);
    } catch (Exception $e) {
        error_log("getProducts error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Error loading products: ' . $e->getMessage()]);
    }
}

// ============================================================
// ✅ UPDATED: GET INVENTORY ITEMS (with product_details from inventory)
// ============================================================
function getInventoryItems($pdo, $clinicId) {
    try {
        // Check if colors_json column exists
        $hasColorsJson = false;
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'colors_json'");
            $hasColorsJson = $checkCol->rowCount() > 0;
        } catch (Exception $e) {
            $hasColorsJson = false;
        }
        
        // Get all inventory items
        $stmt = $pdo->prepare("
            SELECT 
                id, 
                name, 
                category, 
                brand, 
                type,
                selling_price AS price, 
                stock,
                colors_json,
                'inventory' AS source,
                -- Product details fields (category-specific)
                frame_material, frame_style, frame_type, gender, weight_group,
                collection, model_no,
                lens_width_mm, bridge_width_mm, temple_length_mm,
                lens_type, lens_material, lens_coating, lens_index,
                lens_diameter, lens_base_curve,
                lens_sphere_range, lens_cylinder_range, lens_add_range,
                cl_type, cl_material, cl_color_type, cl_water_content,
                cl_base_curve, cl_diameter, cl_pieces_per_box,
                cl_power_range, cl_cylinder_range, cl_axis, cl_add,
                accessory_type, accessory_material, solution_type,
                solution_volume, accessory_colors, part_compatibility,
                sizes_available
            FROM inventory
            WHERE clinic_id = ? AND (is_archived = 0 OR is_archived IS NULL)
            ORDER BY name
        ");
        $stmt->execute([$clinicId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($items as &$item) {
            // Decode colors
            if (!empty($item['colors_json'])) {
                $colors = json_decode($item['colors_json'], true);
                if (is_array($colors)) {
                    $normalizedColors = [];
                    foreach ($colors as $color) {
                        $normalizedColors[] = [
                            'name' => $color['name'] ?? $color['color_name'] ?? '',
                            'code' => $color['code'] ?? $color['color_code'] ?? '#000000',
                            'is_available' => $color['is_available'] ?? true,
                            'quantity' => $color['quantity'] ?? 0
                        ];
                    }
                    $item['colors'] = $normalizedColors;
                } else {
                    $item['colors'] = [];
                }
            } else {
                $item['colors'] = [];
            }
            unset($item['colors_json']);
            
            // ✅ Build product_details object for frontend
            $productDetails = [];
            
            // Category-specific fields mapping
            $category = $item['category'];
            
            switch ($category) {
                case 'Frames':
                    $productDetails = [
                        'frame_material' => $item['frame_material'] ?? null,
                        'frame_style' => $item['frame_style'] ?? null,
                        'frame_type' => $item['frame_type'] ?? null,
                        'gender' => $item['gender'] ?? null,
                        'weight_group' => $item['weight_group'] ?? null,
                        'collection' => $item['collection'] ?? null,
                        'model_no' => $item['model_no'] ?? null,
                        'lens_width_mm' => $item['lens_width_mm'] ?? null,
                        'bridge_width_mm' => $item['bridge_width_mm'] ?? null,
                        'temple_length_mm' => $item['temple_length_mm'] ?? null,
                        'sizes_available' => !empty($item['sizes_available']) ? json_decode($item['sizes_available'], true) : []
                    ];
                    break;
                    
                case 'Eyeglasses':
                case 'Sunglasses':
                    $productDetails = [
                        'frame_material' => $item['frame_material'] ?? null,
                        'frame_style' => $item['frame_style'] ?? null,
                        'frame_type' => $item['frame_type'] ?? null,
                        'gender' => $item['gender'] ?? null,
                        'lens_type' => $item['lens_type'] ?? null,
                        'lens_material' => $item['lens_material'] ?? null,
                        'lens_coating' => $item['lens_coating'] ?? null,
                        'collection' => $item['collection'] ?? null,
                        'model_no' => $item['model_no'] ?? null,
                        'lens_width_mm' => $item['lens_width_mm'] ?? null,
                        'bridge_width_mm' => $item['bridge_width_mm'] ?? null,
                        'temple_length_mm' => $item['temple_length_mm'] ?? null,
                        'sizes_available' => !empty($item['sizes_available']) ? json_decode($item['sizes_available'], true) : []
                    ];
                    break;
                    
                case 'Contact Lenses':
                    $productDetails = [
                        'cl_type' => $item['cl_type'] ?? null,
                        'cl_material' => $item['cl_material'] ?? null,
                        'cl_color_type' => $item['cl_color_type'] ?? null,
                        'cl_water_content' => $item['cl_water_content'] ?? null,
                        'cl_base_curve' => $item['cl_base_curve'] ?? null,
                        'cl_diameter' => $item['cl_diameter'] ?? null,
                        'cl_pieces_per_box' => $item['cl_pieces_per_box'] ?? null,
                        'cl_power_range' => $item['cl_power_range'] ?? null,
                        'cl_cylinder_range' => $item['cl_cylinder_range'] ?? null,
                        'cl_axis' => $item['cl_axis'] ?? null,
                        'cl_add' => $item['cl_add'] ?? null
                    ];
                    break;
                    
                case 'Lenses':
                    $productDetails = [
                        'lens_type' => $item['lens_type'] ?? null,
                        'lens_material' => $item['lens_material'] ?? null,
                        'lens_coating' => $item['lens_coating'] ?? null,
                        'lens_diameter' => $item['lens_diameter'] ?? null,
                        'lens_base_curve' => $item['lens_base_curve'] ?? null,
                        'lens_sphere_range' => $item['lens_sphere_range'] ?? null,
                        'lens_cylinder_range' => $item['lens_cylinder_range'] ?? null,
                        'lens_add_range' => $item['lens_add_range'] ?? null,
                        'lens_index' => $item['lens_index'] ?? null
                    ];
                    break;
                    
                case 'Accessories':
                    $productDetails = [
                        'accessory_type' => $item['accessory_type'] ?? null,
                        'accessory_material' => $item['accessory_material'] ?? null,
                        'solution_type' => $item['solution_type'] ?? null,
                        'solution_volume' => $item['solution_volume'] ?? null,
                        'accessory_colors' => $item['accessory_colors'] ?? null,
                        'part_compatibility' => $item['part_compatibility'] ?? null,
                        'sizes_available' => !empty($item['sizes_available']) ? json_decode($item['sizes_available'], true) : []
                    ];
                    break;
                    
                default:
                    $productDetails = [];
                    break;
            }
            
            $item['product_details'] = $productDetails;
        }
        
        echo json_encode(['success' => true, 'data' => $items]);
        
    } catch (Exception $e) {
        error_log("getInventoryItems error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// ✅ COMPLETE: POST PRODUCT — Insert into products & product_color_inventory
// ============================================================
function postProduct($pdo, $clinicId, $userId) {
    try {
        $inventory_id = $_POST['item_id'] ?? null;
        $description  = $_POST['description'] ?? '';

        // Sale data
        $is_on_sale = $_POST['is_on_sale'] ?? '0';
        $sale_price = (isset($_POST['sale_price']) && $_POST['sale_price'] !== '') ? $_POST['sale_price'] : null;
        $sale_start = (isset($_POST['sale_start']) && $_POST['sale_start'] !== '') ? $_POST['sale_start'] : null;
        $sale_end   = (isset($_POST['sale_end'])   && $_POST['sale_end']   !== '') ? $_POST['sale_end']   : null;
        $sale_label = $_POST['sale_label'] ?? null;

        // Colors & Extra fields
        $colors_json       = $_POST['colors_json']       ?? null;
        $extra_fields_json = $_POST['extra_fields_json'] ?? null;

        // 3D model options
        $model_option         = $_POST['model_option']         ?? 'none';
        $use_default_model    = $_POST['use_default_model']    ?? '0';
        $default_model_id     = $_POST['default_model_id']     ?? null;
        $use_completed_model  = $_POST['use_completed_model']  ?? '0';
        $completed_model_file = $_POST['completed_model_file'] ?? null;
        $completed_model_id   = $_POST['completed_model_id']   ?? null;

        if (!$inventory_id) throw new Exception('No item selected');

        // Verify inventory item belongs to this clinic and get all details including product_details fields
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                -- Frame specific fields
                i.frame_material, i.frame_style, i.frame_type, i.gender, i.weight_group,
                i.collection, i.model_no,
                i.lens_width_mm, i.bridge_width_mm, i.temple_length_mm,
                -- Lens specific fields
                i.lens_type, i.lens_material, i.lens_coating, i.lens_index,
                i.lens_diameter, i.lens_base_curve,
                i.lens_sphere_range, i.lens_cylinder_range, i.lens_add_range,
                -- Contact Lens specific fields
                i.cl_type, i.cl_material, i.cl_color_type, i.cl_water_content,
                i.cl_base_curve, i.cl_diameter, i.cl_pieces_per_box,
                i.cl_power_range, i.cl_cylinder_range, i.cl_axis, i.cl_add,
                -- Accessories specific fields
                i.accessory_type, i.accessory_material, i.solution_type,
                i.solution_volume, i.accessory_colors, i.part_compatibility,
                i.sizes_available
            FROM inventory i
            WHERE i.id = ? AND i.clinic_id = ?
        ");
        $stmt->execute([$inventory_id, $clinicId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) throw new Exception('Item not found in inventory');

        // Check if already posted
        $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE inventory_id = ?");
        $check->execute([$inventory_id]);
        if ($check->fetchColumn() > 0) throw new Exception('This item is already posted as a product');

        $pdo->beginTransaction();

        // ── Handle image uploads ─────────────────────────────────────────────
        $uploaded_images = [];
        $primary_image   = '';
        $upload_dir      = __DIR__ . '/../uploads/products/';
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

        if (!empty($_FILES['images'])) {
            $files = $_FILES['images'];
            $count = is_array($files['name']) ? count($files['name']) : 1;
            for ($i = 0; $i < min($count, 5); $i++) {
                $err  = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                $tmp  = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $name = is_array($files['name'])     ? $files['name'][$i]     : $files['name'];
                if ($err == 0 && is_uploaded_file($tmp)) {
                    $ext      = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                    $allowed  = ['jpg','jpeg','png','gif','webp'];
                    if (!in_array($ext, $allowed)) continue;
                    $filename = 'prod_temp_' . time() . '_' . uniqid() . '_' . $i . '.' . $ext;
                    if (move_uploaded_file($tmp, $upload_dir . $filename)) {
                        $uploaded_images[] = 'uploads/products/' . $filename;
                        if ($i == 0) $primary_image = 'uploads/products/' . $filename;
                    }
                }
            }
        }

        $default_image = match($item['category']) {
            'Frames'         => 'sunglass.jpg',
            'Contact Lenses' => 'contactlens.jpg',
            default          => 'product-default.jpg'
        };
        $product_image = !empty($primary_image) ? $primary_image : $default_image;
        $images_json   = !empty($uploaded_images) ? json_encode($uploaded_images) : null;

if (empty($extra_fields_json)) {
    $productDetails = [];
    $category = $item['category'];
    
    switch ($category) {
        case 'Frames':
            // Handle sizes_available as array
            $sizesAvailable = null;
            if (!empty($item['sizes_available'])) {
                // Check if it's already an array or JSON string
                if (is_string($item['sizes_available'])) {
                    $sizesAvailable = json_decode($item['sizes_available'], true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $sizesAvailable = [$item['sizes_available']];
                    }
                } elseif (is_array($item['sizes_available'])) {
                    $sizesAvailable = $item['sizes_available'];
                }
            }
            
            $productDetails = [
                'frame_material' => $item['frame_material'] ?? null,
                'frame_style' => $item['frame_style'] ?? null,
                'frame_type' => $item['frame_type'] ?? null,
                'gender' => $item['gender'] ?? null,
                'weight_group' => $item['weight_group'] ?? null,
                'collection' => $item['collection'] ?? null,
                'model_no' => $item['model_no'] ?? null,
                'lens_width_mm' => $item['lens_width_mm'] ?? null,
                'bridge_width_mm' => $item['bridge_width_mm'] ?? null,
                'temple_length_mm' => $item['temple_length_mm'] ?? null,
                'sizes_available' => $sizesAvailable  // ✅ This should be an array
            ];
            break;
                    
                case 'Eyeglasses':
                case 'Sunglasses':
                    $productDetails = [
                        'frame_material' => $item['frame_material'] ?? null,
                        'frame_style' => $item['frame_style'] ?? null,
                        'frame_type' => $item['frame_type'] ?? null,
                        'gender' => $item['gender'] ?? null,
                        'lens_type' => $item['lens_type'] ?? null,
                        'lens_material' => $item['lens_material'] ?? null,
                        'lens_coating' => $item['lens_coating'] ?? null,
                        'collection' => $item['collection'] ?? null,
                        'model_no' => $item['model_no'] ?? null,
                        'lens_width_mm' => $item['lens_width_mm'] ?? null,
                        'bridge_width_mm' => $item['bridge_width_mm'] ?? null,
                        'temple_length_mm' => $item['temple_length_mm'] ?? null,
                        'sizes_available' => !empty($item['sizes_available']) ? json_decode($item['sizes_available'], true) : []
                    ];
                    break;
                    
                case 'Contact Lenses':
                    $productDetails = [
                        'cl_type' => $item['cl_type'] ?? null,
                        'cl_material' => $item['cl_material'] ?? null,
                        'cl_color_type' => $item['cl_color_type'] ?? null,
                        'cl_water_content' => $item['cl_water_content'] ?? null,
                        'cl_base_curve' => $item['cl_base_curve'] ?? null,
                        'cl_diameter' => $item['cl_diameter'] ?? null,
                        'cl_pieces_per_box' => $item['cl_pieces_per_box'] ?? null,
                        'cl_power_range' => $item['cl_power_range'] ?? null,
                        'cl_cylinder_range' => $item['cl_cylinder_range'] ?? null,
                        'cl_axis' => $item['cl_axis'] ?? null,
                        'cl_add' => $item['cl_add'] ?? null
                    ];
                    break;
                    
                case 'Lenses':
                    $productDetails = [
                        'lens_type' => $item['lens_type'] ?? null,
                        'lens_material' => $item['lens_material'] ?? null,
                        'lens_coating' => $item['lens_coating'] ?? null,
                        'lens_diameter' => $item['lens_diameter'] ?? null,
                        'lens_base_curve' => $item['lens_base_curve'] ?? null,
                        'lens_sphere_range' => $item['lens_sphere_range'] ?? null,
                        'lens_cylinder_range' => $item['lens_cylinder_range'] ?? null,
                        'lens_add_range' => $item['lens_add_range'] ?? null,
                        'lens_index' => $item['lens_index'] ?? null
                    ];
                    break;
                    
                case 'Accessories':
                    $productDetails = [
                        'accessory_type' => $item['accessory_type'] ?? null,
                        'accessory_material' => $item['accessory_material'] ?? null,
                        'solution_type' => $item['solution_type'] ?? null,
                        'solution_volume' => $item['solution_volume'] ?? null,
                        'accessory_colors' => $item['accessory_colors'] ?? null,
                        'part_compatibility' => $item['part_compatibility'] ?? null,
                        'sizes_available' => !empty($item['sizes_available']) ? json_decode($item['sizes_available'], true) : []
                    ];
                    break;
                    
                default:
                    $productDetails = [];
                    break;
            }
            
            $extra_fields_json = !empty($productDetails) ? json_encode($productDetails) : null;
        }

// ── Insert product ───────────────────────────────────────────────────
$insert = $pdo->prepare("
    INSERT INTO products (
        clinic_id, inventory_id, name, description,
        image, images, images_json,
        price, category,
        is_on_sale, sale_price, sale_start, sale_end, sale_label,
        extra_fields_json,
        warranty_period, warranty_coverage, warranty_terms, warranty_premium_price,
        approval_status, created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'approved', NOW())
");

// Kunin ang warranty data mula sa POST
$warranty_period = $_POST['warranty_period'] ?? null;
$warranty_coverage = $_POST['warranty_coverage'] ?? null;
$warranty_terms = $_POST['warranty_terms'] ?? null;
$warranty_premium_price = $_POST['warranty_premium_price'] ?? 0;

$insert->execute([
    $clinicId, $inventory_id,
    $item['name'], $description,
    $product_image, $primary_image, $images_json,
    $item['selling_price'], $item['category'],
    $is_on_sale, $sale_price, $sale_start, $sale_end, $sale_label,
    $extra_fields_json,
    $warranty_period, $warranty_coverage, $warranty_terms, $warranty_premium_price
]);
        $product_id = $pdo->lastInsertId();

        // ── Rename images to use real product ID ────────────────────────────
        if (!empty($uploaded_images)) {
            $final_images  = [];
            $current_time  = time();
            foreach ($uploaded_images as $index => $old_path) {
                $old_fn  = basename($old_path);
                $ext     = pathinfo($old_fn, PATHINFO_EXTENSION);
                $new_fn  = 'prod_' . $product_id . '_' . $current_time . '_' . $index . '.' . $ext;
                $new_path = 'uploads/products/' . $new_fn;
                if (file_exists($upload_dir . $old_fn) && rename($upload_dir . $old_fn, $upload_dir . $new_fn)) {
                    $final_images[] = $new_path;
                    if ($index == 0) $primary_image = $new_path;
                }
            }
            if (!empty($final_images)) {
                $images_json_final = json_encode($final_images);
                $pdo->prepare("UPDATE products SET image=?, images=?, images_json=? WHERE id=?")->execute([$primary_image, $primary_image, $images_json_final, $product_id]);
            }
        }

        // ✅ INSERT COLORS INTO product_color_inventory WITH QUANTITY
        $colors = null;
        
        // First, try to get colors from POST data (from frontend)
        if (!empty($colors_json)) {
            $colors = json_decode($colors_json, true);
            error_log("Colors from POST: " . print_r($colors, true));
        } 
        // If no colors in POST, try to get from inventory colors_json
        elseif (!empty($item['colors_json'])) {
            $colors = json_decode($item['colors_json'], true);
            error_log("Colors from inventory: " . print_r($colors, true));
        }
        
        // Also check if there's a separate colors_available field in inventory
        if (empty($colors) && !empty($item['colors_available'])) {
            $colors = json_decode($item['colors_available'], true);
            error_log("Colors from colors_available: " . print_r($colors, true));
        }
        
        if (!empty($colors) && is_array($colors)) {
            // Delete any existing colors for this product (to avoid duplicates)
            $deleteExisting = $pdo->prepare("DELETE FROM product_color_inventory WHERE product_id = ? AND clinic_id = ?");
            $deleteExisting->execute([$product_id, $clinicId]);
            
            $colorStmt = $pdo->prepare("
                INSERT INTO product_color_inventory 
                (product_id, clinic_id, color_name, color_code, quantity, is_available, created_at, updated_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            
            $colorsInserted = 0;
            foreach ($colors as $color) {
                // Get color name (handle different naming conventions)
                $cname = '';
                if (isset($color['name'])) {
                    $cname = trim($color['name']);
                } elseif (isset($color['color_name'])) {
                    $cname = trim($color['color_name']);
                }
                
                // Get color code
                $ccode = '#000000';
                if (isset($color['code'])) {
                    $ccode = trim($color['code']);
                } elseif (isset($color['color_code'])) {
                    $ccode = trim($color['color_code']);
                }
                
                // Get quantity
                $qty = 0;
                if (isset($color['quantity'])) {
                    $qty = (int)$color['quantity'];
                } elseif (isset($color['qty'])) {
                    $qty = (int)$color['qty'];
                } elseif (isset($color['stock'])) {
                    $qty = (int)$color['stock'];
                }
                
                // Get availability
                $cavail = 1;
                if (isset($color['is_available'])) {
                    $cavail = (int)$color['is_available'];
                } elseif (isset($color['available'])) {
                    $cavail = (int)$color['available'];
                }
                
                // Only insert if color name is not empty
                if (!empty($cname)) {
                    $colorStmt->execute([$product_id, $clinicId, $cname, $ccode, $qty, $cavail]);
                    $colorsInserted++;
                    error_log("Inserted color: $cname, code: $ccode, quantity: $qty, available: $cavail");
                }
            }
            error_log("Saved $colorsInserted colors to product_color_inventory for product ID: $product_id");
        } else {
            error_log("No colors found for product ID: $product_id");
        }

        // ===== HANDLE 3D MODEL =====
        if ($use_default_model == '1') {
            $default_model_id = $_POST['default_model_id'] ?? null;
            if ($default_model_id) {
                $model_stmt = $pdo->prepare("SELECT * FROM default_3d_models WHERE id = ?");
                $model_stmt->execute([$default_model_id]);
                $default_model = $model_stmt->fetch();
                
                if ($default_model) {
                    $colors_json_3d = null;
                    if (!empty($colors)) {
                        $colors_json_3d = json_encode($colors);
                    }
                    
                    try {
                        $insert_model = $pdo->prepare("
                            INSERT INTO product_3d_models 
                            (inventory_id, product_id, model_file, model_type, colors_json, has_3d)
                            VALUES (?, ?, ?, ?, ?, 1)
                        ");
                        $insert_model->execute([
                            $inventory_id,
                            $product_id,
                            $default_model['model_file'],
                            $item['category'] == 'Frames' ? 'frame' : 'contact_lens',
                            $colors_json_3d
                        ]);
                    } catch (Exception $e) {
                        error_log("3D model insert error: " . $e->getMessage());
                    }
                }
            }
        } elseif ($use_completed_model == '1') {
            $completed_model_file = $_POST['completed_model_file'] ?? null;
            if ($completed_model_file) {
                $colors_json_3d = null;
                if (!empty($colors)) {
                    $colors_json_3d = json_encode($colors);
                }
                
                try {
                    $insert_model = $pdo->prepare("
                        INSERT INTO product_3d_models 
                        (inventory_id, product_id, model_file, model_type, colors_json, has_3d)
                        VALUES (?, ?, ?, ?, ?, 1)
                    ");
                    $insert_model->execute([
                        $inventory_id,
                        $product_id,
                        $completed_model_file,
                        $item['category'] == 'Frames' ? 'frame' : 'contact_lens',
                        $colors_json_3d
                    ]);
                } catch (Exception $e) {
                    error_log("3D model insert error: " . $e->getMessage());
                }
            }
        }
        
        // ── Update inventory with product_id ────────────────────────────────
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM inventory LIKE 'product_id'");
            if ($checkCol->rowCount() > 0) {
                $pdo->prepare("UPDATE inventory SET product_id = ? WHERE id = ?")->execute([$product_id, $inventory_id]);
            }
        } catch (Exception $e) {
            error_log("Inventory product_id update skipped: " . $e->getMessage());
        }

        $pdo->commit();

        error_log("Product posted successfully - ID: $product_id, Category: {$item['category']}, Colors saved: " . (!empty($colors) ? count($colors) : 0));

        echo json_encode([
            'success'    => true,
            'product_id' => $product_id,
            'message'    => 'Product posted successfully'
        ]);

    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("postProduct error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// EDIT PRODUCT (with warranty)
// ============================================================
function editProduct($pdo, $clinicId, $data) {
    try {
        if (empty($data)) { http_response_code(400); echo json_encode(['success'=>false,'message'=>'Invalid request data']); return; }
        $id          = (int)($data['id'] ?? 0);
        $name        = trim($data['name'] ?? '');
        $description = trim($data['description'] ?? '');
        $price       = (float)str_replace(',', '', $data['price'] ?? 0);
        $category    = trim($data['category'] ?? '');
        
        // Warranty data
        $warranty_period = $data['warranty_period'] ?? null;
        $warranty_coverage = isset($data['warranty_coverage']) ? json_encode($data['warranty_coverage']) : null;
        $warranty_terms = $data['warranty_terms'] ?? null;
        $warranty_premium_price = $data['warranty_premium_price'] ?? 0;
        
        if (!$id)           { echo json_encode(['success'=>false,'message'=>'Product ID required']); return; }
        if (empty($name))   { echo json_encode(['success'=>false,'message'=>'Product name required']); return; }
        if ($price <= 0)    { echo json_encode(['success'=>false,'message'=>'Valid price required']); return; }
        if (empty($category)){ echo json_encode(['success'=>false,'message'=>'Category required']); return; }

        $check = $pdo->prepare("SELECT id FROM products WHERE id = ? AND clinic_id = ?");
        $check->execute([$id, $clinicId]);
        if (!$check->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Product not found']); return; }

        $pdo->prepare("
            UPDATE products SET 
                name=?, description=?, price=?, category=?, 
                warranty_period=?, warranty_coverage=?, warranty_terms=?, warranty_premium_price=?,
                updated_at=NOW() 
            WHERE id=? AND clinic_id=?
        ")->execute([
            $name, $description, $price, $category,
            $warranty_period, $warranty_coverage, $warranty_terms, $warranty_premium_price,
            $id, $clinicId
        ]);

        echo json_encode(['success'=>true,'message'=>'Product updated successfully','data'=>compact('id','name','description','price','category')]);
    } catch (Exception $e) {
        error_log("editProduct error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Error updating product: ' . $e->getMessage()]);
    }
}

function deleteProduct($pdo, $clinicId, $data) {
    try {
        $id = (int)($data['id'] ?? 0);
        if (!$id) { echo json_encode(['success'=>false,'message'=>'Product ID required']); return; }

        $check = $pdo->prepare("SELECT id FROM products WHERE id = ? AND clinic_id = ?");
        $check->execute([$id, $clinicId]);
        if (!$check->fetch()) { http_response_code(404); echo json_encode(['success'=>false,'message'=>'Product not found']); return; }

        // ① Null out FK in inventory first
        $pdo->prepare("UPDATE inventory SET product_id = NULL WHERE product_id = ?")->execute([$id]);

        // ② Delete ALL child/related records BEFORE deleting the parent product
        foreach (['inventory_transactions', 'product_3d_models', 'product_color_inventory'] as $table) {
            try {
                $pdo->prepare("DELETE FROM $table WHERE product_id = ?")->execute([$id]);
            } catch (Exception $e) {
                error_log("Cleanup $table warning: " . $e->getMessage());
            }
        }

        // ③ Now safe to delete the parent
        $pdo->prepare("DELETE FROM products WHERE id = ? AND clinic_id = ?")->execute([$id, $clinicId]);

        echo json_encode(['success'=>true,'message'=>'Product deleted successfully']);
    } catch (Exception $e) {
        error_log("deleteProduct error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
}
// ============================================================
// APPROVE / REJECT
// ============================================================
function approveProduct($pdo, $clinicId, $userId) {
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $id   = $data['id'] ?? 0;
        if (!$id) throw new Exception('Product ID required');
        $pdo->prepare("UPDATE products SET approval_status='approved', approved_by=?, approved_at=NOW() WHERE id=? AND clinic_id=?")->execute([$userId, $id, $clinicId]);
        echo json_encode(['success'=>true,'message'=>'Product approved successfully']);
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
}

function rejectProduct($pdo, $clinicId, $userId) {
    try {
        $data   = json_decode(file_get_contents('php://input'), true);
        $id     = $data['id'] ?? 0;
        $reason = $data['reason'] ?? '';
        if (!$id) throw new Exception('Product ID required');
        $pdo->prepare("UPDATE products SET approval_status='rejected', rejected_by=?, rejected_at=NOW(), rejection_reason=? WHERE id=? AND clinic_id=?")->execute([$userId, $reason, $id, $clinicId]);
        echo json_encode(['success'=>true,'message'=>'Product rejected']);
    } catch (Exception $e) { echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
}

function getProduct3D($pdo) {
    try {
        $product_id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT p.*, i.selling_price AS price,
                   p.warranty_period, p.warranty_premium_price,
                   p3d.model_file, p3d.model_type, p3d.colors_json, p3d.has_3d
            FROM products p
            LEFT JOIN inventory i ON p.inventory_id = i.id
            LEFT JOIN product_3d_models p3d ON p.id = p3d.product_id
            WHERE p.id = ?
        ");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($product && !empty($product['model_file'])) {
            // Check product_color_inventory table for colors
            $colorStmt = $pdo->prepare("SELECT color_name, color_code, is_available FROM product_color_inventory WHERE product_id = ? ORDER BY color_name");
            $colorStmt->execute([$product_id]);
            $dbColors = $colorStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($dbColors)) {
                $product['colors'] = array_map(fn($c) => [
                    'name' => $c['color_name'],
                    'code' => $c['color_code'],
                    'is_available' => $c['is_available']
                ], $dbColors);
            } elseif (!empty($product['colors_json'])) {
                $product['colors'] = json_decode($product['colors_json'], true);
            } else {
                $product['colors'] = _defaultColors();
            }
            
            echo json_encode(['success'=>true,'data'=>$product]);
        } else {
            echo json_encode(['success'=>false,'message'=>'No 3D model available for this product']);
        }
    } catch (Exception $e) {
        error_log("getProduct3D error: " . $e->getMessage());
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
}

// ============================================================
// GET 3D PRODUCT (by inventory_id)
// ============================================================
function get3DProduct($pdo) {
    try {
        $inventory_id = $_GET['id'] ?? 0;
        $stmt = $pdo->prepare("
            SELECT i.*, i.selling_price AS price,
                   p3d.model_file, p3d.model_type, p3d.colors_json, p3d.has_3d
            FROM inventory i
            LEFT JOIN product_3d_models p3d ON i.id = p3d.inventory_id
            WHERE i.id = ?
        ");
        $stmt->execute([$inventory_id]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($product) {
            $product['colors'] = !empty($product['colors_json']) ? json_decode($product['colors_json'], true) : _defaultColors();
        }
        echo json_encode(['success'=>true,'data'=>$product]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
}

// ============================================================
// HELPER — Default colors
// ============================================================
function _defaultColors() {
    return [
        ['name'=>'Black',  'code'=>'#000000', 'is_available'=> true],
        ['name'=>'Brown',  'code'=>'#8B4513', 'is_available'=> true],
        ['name'=>'Gold',   'code'=>'#FFD700', 'is_available'=> true],
        ['name'=>'Silver', 'code'=>'#C0C0C0', 'is_available'=> true],
        ['name'=>'Blue',   'code'=>'#0000FF', 'is_available'=> true],
        ['name'=>'Red',    'code'=>'#FF0000', 'is_available'=> true],
    ];
}