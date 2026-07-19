<?php
// api/inventory.php
// UPDATED WITH PRODUCT DETAILS (CATEGORY-SPECIFIC FIELDS + SIZE ARRAYS)

session_name('eyecore_admin');
session_start();

ob_start();

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

include_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

$clinic_id = $_SESSION['clinic_id'] ?? 1;
$user_id   = $_SESSION['user_id']   ?? 0;

if (!$user_id) {
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Please login first']);
    exit;
}

function canViewInventory()   { return RBACHelper::hasPermission('inventory_view'); }
function canCreateInventory() { return RBACHelper::hasPermission('inventory_create'); }
function canEditInventory()   { return RBACHelper::hasPermission('inventory_edit'); }
function canDeleteInventory() { return RBACHelper::hasPermission('inventory_delete'); }
function canApproveInventory(){ return RBACHelper::hasPermission('inventory_approve'); }
function canRejectInventory() { return RBACHelper::hasPermission('inventory_reject'); }

$method       = $_SERVER['REQUEST_METHOD'];
$input        = json_decode(file_get_contents('php://input'), true) ?? [];
$action       = $_GET['action'] ?? $input['action'] ?? '';
$id           = $_GET['id']     ?? $input['id']     ?? 0;
$page         = $_GET['page']   ?? 1;
$limit        = $_GET['limit']  ?? 10;
$search       = $_GET['search'] ?? '';
$category     = $_GET['category']?? 'all';
$status_filter= $_GET['status'] ?? 'all';

$response = ['success' => false, 'message' => 'Invalid request'];

// ============================================================
// HELPERS
// ============================================================
function toFloat($v) {
    if ($v === null || $v === '') return 0.00;
    return floatval(str_replace(',', '', $v));
}

function calculateSuggestedPR($stock, $reorder_level) {
    return ($stock <= $reorder_level) ? max(0, ($reorder_level * 2) - $stock) : 0;
}

function isColorProductCategory($cat) {
    return in_array($cat, ['Frames','Eyeglasses','Sunglasses','Contact Lenses','Lenses']);
}

function ensureColorsJsonColumn($pdo) {
    try {
        if ($pdo->query("SHOW COLUMNS FROM inventory LIKE 'colors_json'")->rowCount() == 0) {
            $pdo->exec("ALTER TABLE inventory ADD COLUMN colors_json TEXT NULL AFTER item_status");
        }
    } catch (Exception $e) { error_log($e->getMessage()); }
}

// ============================================================
// PRODUCT DETAILS FIELD MAP
// Maps each category to the inventory table columns it uses.
// "size_field" is the column that stores the JSON array of sizes.
// ============================================================
function getProductDetailsFieldMap($category) {
    $map = [
        'Frames' => [
            'columns' => [
                'frame_material','frame_style','frame_type','gender','weight_group',
                'collection','model_no',
                'lens_width_mm','bridge_width_mm','temple_length_mm',
            ],
            'size_field' => 'sizes_available',
        ],
        'Eyeglasses' => [
            'columns' => [
                'frame_material','frame_style','frame_type','gender',
                'lens_type','lens_material','lens_coating',
                'collection','model_no',
                'lens_width_mm','bridge_width_mm','temple_length_mm',
            ],
            'size_field' => 'sizes_available',
        ],
        'Sunglasses' => [
            'columns' => [
                'frame_material','frame_style','frame_type','gender',
                'lens_type','lens_coating',
                'collection','model_no',
                'lens_width_mm','bridge_width_mm','temple_length_mm',
            ],
            'size_field' => 'sizes_available',
        ],
        'Contact Lenses' => [
            'columns' => [
                'cl_type','cl_material','cl_color_type',
                'cl_water_content','cl_base_curve','cl_diameter','cl_pieces_per_box',
                'cl_power_range','cl_cylinder_range','cl_axis','cl_add',
            ],
            'size_field' => 'cl_power_range',
        ],
        'Lenses' => [
            'columns' => [
                'lens_type','lens_material','lens_coating',
                'lens_diameter','lens_base_curve',
                'lens_sphere_range','lens_cylinder_range','lens_add_range',
            ],
            'size_field' => 'lens_index',
        ],
        'Accessories' => [
            'columns' => [
                'accessory_type','accessory_material','solution_type',
                'solution_volume','accessory_colors','part_compatibility',
            ],
            'size_field' => 'sizes_available',
        ],
    ];
    return $map[$category] ?? null;
}

// ============================================================
// Ensure extra columns exist (for fields not in original schema)
// Run once — safe to call repeatedly.
// ============================================================
function ensureProductDetailColumns($pdo) {
    $extraColumns = [
        // Frames / Eyeglasses / Sunglasses
        'frame_style'       => 'VARCHAR(50) NULL',
        'collection'        => 'VARCHAR(100) NULL',
        'model_no'          => 'VARCHAR(50) NULL',
        'lens_width_mm'     => 'DECIMAL(5,2) NULL',
        'bridge_width_mm'   => 'DECIMAL(5,2) NULL',
        'temple_length_mm'  => 'DECIMAL(5,2) NULL',
        'weight_group'      => 'VARCHAR(30) NULL',
        // sizes_available already in schema
        // lens_index already in schema
        // lens_type, lens_material, lens_coating already in schema
        // Contact Lenses — already in schema mostly
        'cl_axis'           => 'VARCHAR(50) NULL',
        'cl_add'            => 'VARCHAR(50) NULL',
        // Accessories
        'accessory_colors'  => 'VARCHAR(255) NULL',
        'part_compatibility'=> 'VARCHAR(255) NULL',
    ];

    foreach ($extraColumns as $col => $def) {
        try {
            $check = $pdo->query("SHOW COLUMNS FROM inventory LIKE '{$col}'")->rowCount();
            if ($check == 0) {
                $pdo->exec("ALTER TABLE inventory ADD COLUMN {$col} {$def}");
                error_log("Added column: {$col}");
            }
        } catch (Exception $e) {
            error_log("Column check error ({$col}): " . $e->getMessage());
        }
    }
}

// ============================================================
// Build SET clause + params for product details
// ============================================================
function buildProductDetailsSQL($pdo, $category, $productDetails) {
    $fieldMap = getProductDetailsFieldMap($category);
    if (!$fieldMap || empty($productDetails)) return ['', []];

    ensureProductDetailColumns($pdo);

    $setClauses = [];
    $params     = [];

    // Regular columns
    foreach ($fieldMap['columns'] as $col) {
        if (array_key_exists($col, $productDetails)) {
            $setClauses[] = "`{$col}` = ?";
            $params[]     = $productDetails[$col] !== '' ? $productDetails[$col] : null;
        }
    }

    // Size array column (stored as JSON string)
    $sizeField = $fieldMap['size_field'];
    if ($sizeField && array_key_exists($sizeField, $productDetails)) {
        $sizeVal = $productDetails[$sizeField];
        if (is_array($sizeVal)) {
            $sizeVal = json_encode($sizeVal);
        }
        $setClauses[] = "`{$sizeField}` = ?";
        $params[]     = $sizeVal;
    }

    if (empty($setClauses)) return ['', []];

    // Return just the SET clause without extra comma
    return [implode(', ', $setClauses), $params];
}

// ============================================================
// Parse product details from an inventory row for the frontend
// ============================================================
function extractProductDetails($item, $category) {
    $fieldMap = getProductDetailsFieldMap($category);
    if (!$fieldMap) return null;

    $details = [];

    foreach ($fieldMap['columns'] as $col) {
        $details[$col] = $item[$col] ?? null;
    }

    // Parse JSON size field
    $sizeField = $fieldMap['size_field'];
    if ($sizeField && isset($item[$sizeField])) {
        $raw = $item[$sizeField];
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $details[$sizeField] = (json_last_error() === JSON_ERROR_NONE) ? $decoded : [$raw];
        } else {
            $details[$sizeField] = [];
        }
    }

    return $details;
}

// ============================================================
// saveItemColors — unchanged from original
// ============================================================
function saveItemColors($pdo, $inventory_id, $colors, $clinic_id) {
    ensureColorsJsonColumn($pdo);

    $getItem = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND clinic_id = ?");
    $getItem->execute([$inventory_id, $clinic_id]);
    $item = $getItem->fetch();
    if (!$item) return false;

    $colorsJson = json_encode($colors);
    $pdo->prepare("UPDATE inventory SET colors_json = ?, updated_at = NOW() WHERE id = ? AND clinic_id = ?")
        ->execute([$colorsJson, $inventory_id, $clinic_id]);

    $totalStock = 0;
    foreach ($colors as $color) {
        $totalStock += (int)($color['quantity'] ?? 0);
    }
    $newStatus = $totalStock <= 0 ? 'out-of-stock' : ($totalStock <= $item['reorder_level'] ? 'low-stock' : 'in-stock');

    $pdo->prepare("UPDATE inventory SET stock = ?, item_status = ?, updated_at = NOW() WHERE id = ? AND clinic_id = ?")
        ->execute([$totalStock, $newStatus, $inventory_id, $clinic_id]);

    return true;
}

function updateInventoryStockFromColors($pdo, $inventory_id, $clinic_id) {
    $getItem = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND clinic_id = ?");
    $getItem->execute([$inventory_id, $clinic_id]);
    $item = $getItem->fetch();
    if (!$item) return false;
    if (!isColorProductCategory($item['category'])) return false;

    $totalStock = 0;
    if (!empty($item['colors_json'])) {
        $colors = json_decode($item['colors_json'], true);
        if (is_array($colors)) {
            foreach ($colors as $color) $totalStock += (int)($color['quantity'] ?? 0);
        }
    }
    $newStatus = $totalStock <= 0 ? 'out-of-stock' : ($totalStock <= $item['reorder_level'] ? 'low-stock' : 'in-stock');
    $pdo->prepare("UPDATE inventory SET stock = ?, item_status = ?, updated_at = NOW() WHERE id = ? AND clinic_id = ?")
        ->execute([$totalStock, $newStatus, $inventory_id, $clinic_id]);
    return $totalStock;
}

// ============================================================
// MAIN SWITCH
// ============================================================
try {
    switch ($action) {

        // ==================== GET ITEMS ====================
        case 'get_items':
            if (!canViewInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }

            $offset = ($page - 1) * $limit;
            $sql    = "SELECT SQL_CALC_FOUND_ROWS * FROM inventory WHERE clinic_id = ? AND (is_archived = 0 OR is_archived IS NULL)";
            $params = [$clinic_id];

            if (!empty($search)) {
                $sql .= " AND (name LIKE ? OR item_id LIKE ? OR brand LIKE ?)";
                $s = "%$search%";
                array_push($params, $s, $s, $s);
            }
            if ($category !== 'all') { $sql .= " AND category = ?"; $params[] = $category; }
            if ($status_filter !== 'all') { $sql .= " AND item_status = ?"; $params[] = $status_filter; }

            $sql .= " ORDER BY updated_at DESC LIMIT ? OFFSET ?";
            array_push($params, (int)$limit, (int)$offset);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($items as &$item) {
                $item['selling_price']        = floatval($item['selling_price'] ?? 0);
                $item['cost']                 = floatval($item['cost'] ?? 0);
                $item['supplier_product_price']= floatval($item['supplier_product_price'] ?? 0);
                $item['stock']                = intval($item['stock'] ?? 0);
                $item['reorder_level']        = intval($item['reorder_level'] ?? 5);
                $item['suggested_pr']         = max(0, ($item['reorder_level'] * 2) - $item['stock']);
                $item['has_colors']           = isColorProductCategory($item['category']);
                $item['colors']               = ($item['has_colors'] && !empty($item['colors_json']))
                                                    ? json_decode($item['colors_json'], true) : [];
                // ✅ Include product details
                $item['product_details']      = extractProductDetails($item, $item['category']);
            }

            $total = $pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
            $response = [
                'success'    => true,
                'data'       => $items,
                'pagination' => [
                    'total'       => $total,
                    'page'        => (int)$page,
                    'limit'       => (int)$limit,
                    'total_pages' => ceil($total / $limit)
                ]
            ];
            break;

        // ==================== GET SINGLE ITEM ====================
        case 'get_item':
            if (!canViewInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }

            $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$id, $clinic_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($item) {
                $item['selling_price']         = floatval($item['selling_price'] ?? 0);
                $item['cost']                  = floatval($item['cost'] ?? 0);
                $item['supplier_product_price']= floatval($item['supplier_product_price'] ?? 0);
                $item['stock']                 = intval($item['stock'] ?? 0);
                $item['reorder_level']         = intval($item['reorder_level'] ?? 5);
                $item['suggested_pr']          = calculateSuggestedPR($item['stock'], $item['reorder_level']);
                $item['has_colors']            = isColorProductCategory($item['category']);
                $item['colors']                = ($item['has_colors'] && !empty($item['colors_json']))
                                                     ? json_decode($item['colors_json'], true) : [];
                // ✅ Include product details for the edit modal
                $item['product_details']       = extractProductDetails($item, $item['category']);

                $response = ['success' => true, 'data' => $item];
            } else {
                $response = ['success' => false, 'message' => 'Item not found'];
            }
            break;

        // ==================== ADD ITEM ====================
        case 'add':
        case 'create':
            if (!canCreateInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }

            if ($method === 'POST') {
                foreach (['category','name','stock','selling_price'] as $f) {
                    if (empty($input[$f])) { $response = ['success'=>false,'message'=>"$f is required"]; break 2; }
                }

                ensureColorsJsonColumn($pdo);
                ensureProductDetailColumns($pdo);

                // Generate item_id
                $catPrefix = str_pad(substr(strtoupper(preg_replace('/[^a-zA-Z0-9]/','', $input['category'])),0,3),3,'X');
                $dateStr   = date('Ymd');
                $item_id   = '';
                for ($i = 0; $i < 100; $i++) {
                    $try = $catPrefix . '-' . $dateStr . '-' . rand(100,999);
                    $chk = $pdo->prepare("SELECT id FROM inventory WHERE item_id = ? AND clinic_id = ?");
                    $chk->execute([$try, $clinic_id]);
                    if ($chk->rowCount() == 0) { $item_id = $try; break; }
                }

                // Generate item_code
                $namePrefix = str_pad(substr(strtoupper(preg_replace('/[^a-zA-Z0-9]/','', $input['name'])),0,3),3,'X');
                $item_code  = '';
                for ($i = 0; $i < 100; $i++) {
                    $try = $namePrefix . '-' . rand(10000,99999);
                    $chk = $pdo->prepare("SELECT id FROM inventory WHERE item_code = ? AND clinic_id = ?");
                    $chk->execute([$try, $clinic_id]);
                    if ($chk->rowCount() == 0) { $item_code = $try; break; }
                }

                $stock        = (int)$input['stock'];
                $reorder      = (int)($input['reorder_level'] ?? 5);
                $item_status  = $stock <= 0 ? 'out-of-stock' : ($stock <= $reorder ? 'low-stock' : 'in-stock');
                $selling_price= toFloat($input['selling_price']);
                $cost         = toFloat($input['cost'] ?? 0);
                $supp_price   = toFloat($input['supplier_product_price'] ?? 0);
                $supplier_id  = !empty($input['supplier_id']) ? $input['supplier_id'] : null;
                $supplier_name= !empty($input['supplier_name']) ? $input['supplier_name'] : null;

                if (empty($supplier_name) && !empty($supplier_id)) {
                    $s = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
                    $s->execute([$supplier_id]);
                    $supplier_name = ($s->fetch())['supplier_name'] ?? null;
                }

                // ✅ Product details SET clause
                $productDetails = $input['product_details'] ?? [];
                [$pdSQL, $pdParams] = buildProductDetailsSQL($pdo, $input['category'], $productDetails);

                $pdo->beginTransaction();
                try {
                    $sql = "INSERT INTO inventory (
                        item_id, item_code, clinic_id, category, name, brand, type,
                        stock, reorder_level, selling_price, cost, supplier_product_price,
                        item_status, supplier_id, supplier, created_at, updated_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())";

                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $item_id, $item_code, $clinic_id,
                        $input['category'], $input['name'],
                        $input['brand'] ?? null, $input['type'] ?? null,
                        $stock, $reorder, $selling_price, $cost, $supp_price,
                        $item_status, $supplier_id, $supplier_name
                    ]);

                    $inventory_id = $pdo->lastInsertId();

                    // ✅ Save product details if any
                    if (!empty($pdSQL) && !empty($pdParams)) {
                        $pdUpdate = $pdo->prepare("UPDATE inventory SET {$pdSQL} WHERE id = ?");
                        // strip leading ", "
                        $cleanSQL = ltrim($pdSQL, ', ');
                        $pdUpdate = $pdo->prepare("UPDATE inventory SET {$cleanSQL} WHERE id = ?");
                        $pdUpdate->execute(array_merge($pdParams, [$inventory_id]));
                    }

                    // Save colors
                    if (!empty($input['colors']) && is_array($input['colors']) && isColorProductCategory($input['category'])) {
                        saveItemColors($pdo, $inventory_id, $input['colors'], $clinic_id);
                    }

                    $pdo->commit();

                    $response = [
                        'success'      => true,
                        'message'      => 'Item added successfully',
                        'id'           => $inventory_id,
                        'item_id'      => $item_id,
                        'item_code'    => $item_code,
                        'stock'        => $stock,
                        'suggested_pr' => calculateSuggestedPR($stock, $reorder),
                        'needs_reorder'=> calculateSuggestedPR($stock, $reorder) > 0
                    ];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Add item error: " . $e->getMessage());
                    $response = ['success'=>false,'message'=>'Database error: ' . $e->getMessage()];
                }
            }
            break;

        // ==================== UPDATE ITEM ====================
        case 'update':
            if (!canEditInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }

            if ($method === 'POST') {
                foreach (['id','name','category','stock','selling_price'] as $f) {
                    if (empty($input[$f])) { $response = ['success'=>false,'message'=>"$f is required"]; break 2; }
                }

                ensureProductDetailColumns($pdo);

                $stock       = (int)$input['stock'];
                $reorder     = (int)($input['reorder_level'] ?? 5);
                $item_status = $stock <= 0 ? 'out-of-stock' : ($stock <= $reorder ? 'low-stock' : 'in-stock');
                $selling_price= toFloat($input['selling_price']);
                $cost        = toFloat($input['cost'] ?? 0);
                $supp_price  = toFloat($input['supplier_product_price'] ?? 0);

                // ✅ Product details
                $productDetails = $input['product_details'] ?? [];
                [$pdSQL, $pdParams] = buildProductDetailsSQL($pdo, $input['category'], $productDetails);

                $pdo->beginTransaction();
                try {
                    // Base update
                    $sql = "UPDATE inventory SET
                        name = ?, category = ?, brand = ?, type = ?,
                        stock = ?, reorder_level = ?, selling_price = ?,
                        cost = ?, supplier_product_price = ?, item_status = ?,
                        updated_at = NOW()
                        WHERE id = ? AND clinic_id = ?";

                    $pdo->prepare($sql)->execute([
                        $input['name'], $input['category'],
                        $input['brand'] ?? null, $input['type'] ?? null,
                        $stock, $reorder, $selling_price, $cost, $supp_price,
                        $item_status, $input['id'], $clinic_id
                    ]);

                    // ✅ Update product details columns
                    if (!empty($pdSQL) && !empty($pdParams)) {
                        $cleanSQL = ltrim($pdSQL, ', ');
                        $pdUpdate = $pdo->prepare("UPDATE inventory SET {$cleanSQL} WHERE id = ? AND clinic_id = ?");
                        $pdUpdate->execute(array_merge($pdParams, [$input['id'], $clinic_id]));
                    }

                    // Save colors
                    if (isset($input['colors']) && is_array($input['colors']) && isColorProductCategory($input['category'])) {
                        saveItemColors($pdo, $input['id'], $input['colors'], $clinic_id);
                    }

                    $pdo->commit();

                    $response = [
                        'success'      => true,
                        'message'      => 'Item updated successfully',
                        'stock'        => $stock,
                        'suggested_pr' => calculateSuggestedPR($stock, $reorder),
                        'needs_reorder'=> calculateSuggestedPR($stock, $reorder) > 0
                    ];
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Update item error: " . $e->getMessage());
                    $response = ['success'=>false,'message'=>'Database error: ' . $e->getMessage()];
                }
            }
            break;

        // ==================== QUICK SALE ====================
        case 'quick_sale':
            if (!canEditInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }
            if ($method === 'POST') {
                if (empty($input['item_id']) || empty($input['quantity'])) {
                    $response = ['success'=>false,'message'=>'Item ID and quantity are required']; break;
                }
                $quantity   = (int)$input['quantity'];
                $color_name = $input['color_name'] ?? null;

                $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND clinic_id = ?");
                $stmt->execute([$input['item_id'], $clinic_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) { $response = ['success'=>false,'message'=>'Item not found']; break; }

                if ($color_name && isColorProductCategory($item['category']) && !empty($item['colors_json'])) {
                    $colors = json_decode($item['colors_json'], true);
                    $cidx = -1;
                    foreach ($colors as $i => $c) {
                        if (($c['name'] ?? '') == $color_name || ($c['color_name'] ?? '') == $color_name) { $cidx = $i; break; }
                    }
                    if ($cidx === -1) { $response = ['success'=>false,'message'=>"Color '$color_name' not found"]; break; }
                    $cur = (int)($colors[$cidx]['quantity'] ?? 0);
                    if ($cur < $quantity) { $response = ['success'=>false,'message'=>"Insufficient stock. Available: $cur"]; break; }
                    $colors[$cidx]['quantity'] = $cur - $quantity;
                    $pdo->prepare("UPDATE inventory SET colors_json = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([json_encode($colors), $input['item_id']]);
                    $newStock = updateInventoryStockFromColors($pdo, $input['item_id'], $clinic_id);
                    $response = ['success'=>true,'message'=>'Sale completed','new_stock'=>$newStock,
                                 'color_updated'=>true,'color_name'=>$color_name,
                                 'new_color_qty'=>$colors[$cidx]['quantity']];
                } else {
                    if ($item['stock'] < $quantity) { $response = ['success'=>false,'message'=>"Insufficient stock. Available: {$item['stock']}"]; break; }
                    $newStock  = $item['stock'] - $quantity;
                    $newStatus = $newStock <= 0 ? 'out-of-stock' : ($newStock <= $item['reorder_level'] ? 'low-stock' : 'in-stock');
                    $pdo->prepare("UPDATE inventory SET stock = ?, item_status = ?, updated_at = NOW() WHERE id = ? AND clinic_id = ?")
                        ->execute([$newStock, $newStatus, $input['item_id'], $clinic_id]);
                    $response = ['success'=>true,'message'=>'Sale completed','new_stock'=>$newStock,
                                 'suggested_pr'=>calculateSuggestedPR($newStock,$item['reorder_level'])];
                }
            }
            break;

        // ==================== RESTOCK ====================
        case 'restock':
            if (!canEditInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }
            if ($method === 'POST') {
                $rid        = $input['id'] ?? 0;
                $quantity   = (int)($input['quantity'] ?? 0);
                $color_name = $input['color_name'] ?? null;
                if (!$rid || !$quantity) { $response = ['success'=>false,'message'=>'ID and quantity required']; break; }

                $stmt = $pdo->prepare("SELECT * FROM inventory WHERE id = ? AND clinic_id = ?");
                $stmt->execute([$rid, $clinic_id]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$item) { $response = ['success'=>false,'message'=>'Item not found']; break; }

                if ($color_name && isColorProductCategory($item['category']) && !empty($item['colors_json'])) {
                    $colors = json_decode($item['colors_json'], true);
                    $cidx = -1;
                    foreach ($colors as $i => $c) {
                        if (($c['name'] ?? '') == $color_name || ($c['color_name'] ?? '') == $color_name) { $cidx = $i; break; }
                    }
                    if ($cidx !== -1) {
                        $colors[$cidx]['quantity'] = (int)($colors[$cidx]['quantity'] ?? 0) + $quantity;
                    } else {
                        $colors[] = ['name'=>$color_name,'color_name'=>$color_name,'code'=>'#000000','color_code'=>'#000000','quantity'=>$quantity,'is_available'=>1];
                    }
                    $pdo->prepare("UPDATE inventory SET colors_json = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([json_encode($colors), $rid]);
                    $newStock = updateInventoryStockFromColors($pdo, $rid, $clinic_id);
                    $response = ['success'=>true,'message'=>'Restocked successfully','new_stock'=>$newStock,'color_updated'=>true];
                } else {
                    $newStock  = $item['stock'] + $quantity;
                    $newStatus = $newStock <= $item['reorder_level'] ? 'low-stock' : 'in-stock';
                    $pdo->prepare("UPDATE inventory SET stock = ?, item_status = ?, updated_at = NOW() WHERE id = ? AND clinic_id = ?")
                        ->execute([$newStock, $newStatus, $rid, $clinic_id]);
                    $response = ['success'=>true,'message'=>'Restocked successfully','new_stock'=>$newStock,
                                 'suggested_pr'=>calculateSuggestedPR($newStock,$item['reorder_level'])];
                }
            }
            break;

case 'delete':
    if (!canDeleteInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }
    // Allow both POST and DELETE methods
    if ($method === 'POST' || $method === 'DELETE') {
        // Get ID from either input or the $id variable
        $deleteId = $input['id'] ?? $id ?? 0;
        if ($deleteId) {
            $stmt = $pdo->prepare("UPDATE inventory SET is_archived = 1, updated_at = NOW() WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$deleteId, $clinic_id]);
            if ($stmt->rowCount() > 0) {
                $response = ['success' => true, 'message' => 'Item archived successfully'];
            } else {
                $response = ['success' => false, 'message' => 'Item not found or already archived'];
            }
        } else {
            $response = ['success' => false, 'message' => 'Item ID is required'];
        }
    } else {
        $response = ['success' => false, 'message' => 'Method not allowed. Use POST or DELETE'];
    }
    break;

        // ==================== GET STATS ====================
        case 'get_stats':
            if (!canViewInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }
            $base = "FROM inventory WHERE clinic_id = ? AND (is_archived = 0 OR is_archived IS NULL)";
            $s1 = $pdo->prepare("SELECT COUNT(*) $base"); $s1->execute([$clinic_id]); $total = $s1->fetchColumn();
            $s2 = $pdo->prepare("SELECT COUNT(*) $base AND stock <= reorder_level AND stock > 0"); $s2->execute([$clinic_id]); $low = $s2->fetchColumn();
            $s3 = $pdo->prepare("SELECT COUNT(*) $base AND stock <= 0"); $s3->execute([$clinic_id]); $oos = $s3->fetchColumn();
            $s4 = $pdo->prepare("SELECT SUM(CASE WHEN cost IS NOT NULL AND cost > 0 THEN cost * stock ELSE selling_price * stock END) $base"); $s4->execute([$clinic_id]); $val = $s4->fetchColumn() ?? 0;
            $s5 = $pdo->prepare("SELECT SUM(selling_price * stock) $base"); $s5->execute([$clinic_id]); $rev = $s5->fetchColumn() ?? 0;
            $s6 = $pdo->prepare("SELECT COUNT(*) $base AND stock <= reorder_level"); $s6->execute([$clinic_id]); $nr = $s6->fetchColumn();
            $response = ['success'=>true,'total_items'=>(int)$total,'low_stock'=>(int)$low,'out_of_stock'=>(int)$oos,
                         'total_value'=>floatval($val),'potential_revenue'=>floatval($rev),
                         'needs_reorder'=>(int)$nr,'healthy_stock'=>(int)($total-$low-$oos)];
            break;

        // ==================== LOW STOCK REPORT ====================
        case 'low_stock_report':
            if (!canViewInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }
            $stmt = $pdo->prepare("SELECT *, (reorder_level * 2 - stock) as suggested_pr
                FROM inventory WHERE clinic_id = ? AND stock <= reorder_level AND stock > 0
                AND (is_archived = 0 OR is_archived IS NULL) ORDER BY stock ASC");
            $stmt->execute([$clinic_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$item) {
                $item['selling_price'] = floatval($item['selling_price']);
                $item['cost']          = floatval($item['cost'] ?? 0);
                $item['suggested_pr']  = intval($item['suggested_pr']);
                if (!empty($item['colors_json'])) $item['colors'] = json_decode($item['colors_json'], true);
                $item['product_details'] = extractProductDetails($item, $item['category']);
            }
            $response = ['success'=>true,'data'=>$items,'count'=>count($items)];
            break;

        // ==================== REORDER SUGGESTIONS ====================
        case 'get_reorder_suggestions':
            if (!canViewInventory()) { $response = ['success'=>false,'message'=>'Permission denied']; break; }
            $stmt = $pdo->prepare("SELECT id, item_id, name, brand, category, stock, reorder_level,
                supplier, supplier_id, selling_price, cost, colors_json,
                (reorder_level * 2 - stock) as suggested_pr
                FROM inventory WHERE clinic_id = ? AND stock <= reorder_level
                AND (is_archived = 0 OR is_archived IS NULL)
                ORDER BY (reorder_level - stock) DESC");
            $stmt->execute([$clinic_id]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($items as &$item) {
                $item['selling_price']  = floatval($item['selling_price']);
                $item['cost']           = floatval($item['cost'] ?? 0);
                $item['suggested_pr']   = max(0, intval($item['suggested_pr']));
                $item['estimated_cost'] = $item['suggested_pr'] * $item['cost'];
                if (!empty($item['colors_json'])) $item['colors'] = json_decode($item['colors_json'], true);
            }
            $response = ['success'=>true,'data'=>$items,'total_items'=>count($items),
                         'total_estimated_cost'=>array_sum(array_column($items,'estimated_cost'))];
            break;

        default:
            $response = ['success'=>false,'message'=>'Invalid action'];
    }
} catch (PDOException $e) {
    $response = ['success'=>false,'message'=>'Database error: ' . $e->getMessage()];
} catch (Exception $e) {
    $response = ['success'=>false,'message'=>'Error: ' . $e->getMessage()];
}

ob_end_clean();
echo json_encode($response);
exit;