<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// Check session
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$user_role       = $_SESSION['role'] ?? 'SCM';
$action          = $_GET['action'] ?? $_POST['action'] ?? '';

// ✅ RBAC Permission Helper
class SupplierPermission {
    private static $module = 'supplier';

    public static function can($action) {
        $permissionMap = [
            'view'    => self::$module . '_view',
            'create'  => self::$module . '_create',
            'edit'    => self::$module . '_edit',
            'delete'  => self::$module . '_delete',
            'approve' => self::$module . '_approve',
            'reject'  => self::$module . '_reject',
        ];
        $permission = $permissionMap[$action] ?? self::$module . '_' . $action;
        return RBACHelper::hasPermission($permission);
    }

    public static function check($action, $exitOnFail = true) {
        if (!self::can($action)) {
            if ($exitOnFail) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: Cannot ' . $action . ' suppliers']);
                exit;
            }
            return false;
        }
        return true;
    }
}

// ============================================================
// GET REQUESTS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // ✅ GET ALL SUPPLIERS — GLOBAL, no clinic_id filter
    if ($action === 'get_suppliers') {
        SupplierPermission::check('view');
        try {
            $status   = $_GET['status']   ?? 'all';
            $category = $_GET['category'] ?? 'all';
            $search   = $_GET['search']   ?? '';
            $page     = max(1, (int)($_GET['page']  ?? 1));
            $limit    = max(1, (int)($_GET['limit'] ?? 12));
            $offset   = ($page - 1) * $limit;

            $conditions  = [];
            $params      = [];
            $countParams = [];

            $query      = "SELECT s.*, (SELECT COUNT(*) FROM supplier_products WHERE supplier_id = s.id) as product_count
                           FROM suppliers s WHERE 1=1";
            $countQuery = "SELECT COUNT(*) as total FROM suppliers WHERE 1=1";

            if ($status !== 'all') {
                $conditions[]  = "s.status = ?";
                $params[]      = $status;
                $countParams[] = $status;
            }

            if ($category !== 'all') {
                $conditions[]  = "s.category = ?";
                $params[]      = $category;
                $countParams[] = $category;
            }

            if (!empty($search)) {
                $conditions[]  = "(s.supplier_name LIKE ? OR s.supplier_code LIKE ? OR s.email LIKE ? OR s.phone LIKE ? OR s.city LIKE ?)";
                $searchTerm    = "%$search%";
                array_push($params,      $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
                array_push($countParams, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            }

            if (!empty($conditions)) {
                $whereClause  = " AND " . implode(" AND ", $conditions);
                $query       .= $whereClause;
                $countQuery  .= $whereClause;
            }

            $query   .= " ORDER BY s.average_rating DESC, s.supplier_name ASC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $countStmt = $pdo->prepare($countQuery);
            $countStmt->execute($countParams);
            $total = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            echo json_encode([
                'success' => true,
                'data'    => $suppliers,
                'pagination' => [
                    'total' => $total,
                    'page'  => $page,
                    'pages' => (int)ceil($total / $limit),
                    'limit' => $limit,
                ]
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ GET SINGLE SUPPLIER — GLOBAL, no clinic_id filter
    if ($action === 'get') {
        SupplierPermission::check('view');
        try {
            $id = (int)($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Supplier ID is required']);
                exit;
            }

            $stmt = $pdo->prepare("
                SELECT s.*,
                       (SELECT COUNT(*) FROM supplier_products WHERE supplier_id = s.id) as product_count,
                       (SELECT AVG(rating) FROM supplier_reviews WHERE supplier_id = s.id) as calculated_rating,
                       (SELECT COUNT(*)    FROM supplier_reviews WHERE supplier_id = s.id) as total_reviews
                FROM suppliers s
                WHERE s.id = ?
            ");
            $stmt->execute([$id]);
            $supplier = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($supplier) {
                echo json_encode(['success' => true, 'data' => $supplier]);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Supplier not found']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ STATS — GLOBAL, no clinic_id filter
    if ($action === 'stats') {
        SupplierPermission::check('view');
        try {
            $stmt = $pdo->query("
                SELECT
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'Active'   THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN status = 'Inactive' THEN 1 ELSE 0 END) as inactive,
                    SUM(CASE WHEN average_rating >= 4 THEN 1 ELSE 0 END) as top_rated,
                    AVG(average_rating) as avg_rating
                FROM suppliers
            ");
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'stats' => $stats]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ GET CATEGORIES — GLOBAL, no clinic_id filter
    if ($action === 'get_categories') {
        SupplierPermission::check('view');
        try {
            $catStmt = $pdo->query("
                SELECT DISTINCT category FROM supplier_products
                WHERE category IS NOT NULL AND category != ''
                UNION
                SELECT 'Frames' UNION SELECT 'Lenses' UNION SELECT 'Contact Lenses'
                UNION SELECT 'Accessories' UNION SELECT 'Medical Supplies'
                UNION SELECT 'Equipment'   UNION SELECT 'Others'
                ORDER BY category
            ");
            $categories = $catStmt->fetchAll(PDO::FETCH_COLUMN);

            echo json_encode(['success' => true, 'data' => $categories]);

        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ GET PERMISSIONS
    if ($action === 'get_permissions') {
        echo json_encode([
            'success' => true,
            'data'    => [
                'role'        => $user_role,
                'permissions' => [
                    'can_view'    => SupplierPermission::can('view'),
                    'can_create'  => SupplierPermission::can('create'),
                    'can_edit'    => SupplierPermission::can('edit'),
                    'can_delete'  => SupplierPermission::can('delete'),
                    'can_approve' => SupplierPermission::can('approve'),
                    'can_reject'  => SupplierPermission::can('reject'),
                ]
            ]
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

// ============================================================
// POST REQUESTS
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ✅ CREATE SUPPLIER — GLOBAL
    if ($action === 'create') {
        SupplierPermission::check('create');
        try {
            $required = ['supplier_code', 'supplier_name'];
            foreach ($required as $field) {
                if (empty($_POST[$field])) {
                    http_response_code(400);
                    echo json_encode(['error' => "$field is required"]);
                    exit;
                }
            }

            $checkStmt = $pdo->prepare("SELECT id FROM suppliers WHERE supplier_code = ?");
            $checkStmt->execute([$_POST['supplier_code']]);
            if ($checkStmt->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Supplier code already exists']);
                exit;
            }

            $fields = [
                'supplier_code', 'supplier_name', 'contact_person', 'email', 'phone',
                'mobile', 'fax', 'address', 'city', 'state', 'country', 'postal_code',
                'tax_id', 'website', 'category', 'status', 'payment_terms',
                'credit_limit', 'current_balance', 'rating', 'notes'
            ];

            $columns      = array_merge($fields, ['created_by']);
            $placeholders = array_fill(0, count($columns), '?');
            $values       = [];

            foreach ($fields as $field) {
                $values[] = $_POST[$field] ?? null;
            }
            $values[] = $current_user_id;

            $sql  = "INSERT INTO suppliers (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($values);

            echo json_encode([
                'success' => true,
                'message' => 'Supplier created successfully',
                'id'      => $pdo->lastInsertId()
            ]);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ UPDATE SUPPLIER — GLOBAL
    if ($action === 'update') {
        SupplierPermission::check('edit');
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Supplier ID is required']);
                exit;
            }

            $checkStmt = $pdo->prepare("SELECT id FROM suppliers WHERE id = ?");
            $checkStmt->execute([$id]);
            if (!$checkStmt->fetch()) {
                http_response_code(404);
                echo json_encode(['error' => 'Supplier not found']);
                exit;
            }

            $fields  = [
                'supplier_code', 'supplier_name', 'contact_person', 'email', 'phone',
                'mobile', 'fax', 'address', 'city', 'state', 'country', 'postal_code',
                'tax_id', 'website', 'category', 'status', 'payment_terms',
                'credit_limit', 'current_balance', 'rating', 'notes'
            ];
            $updates = [];
            $values  = [];

            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    $updates[] = "$field = ?";
                    $values[]  = $_POST[$field];
                }
            }

            if (empty($updates)) {
                http_response_code(400);
                echo json_encode(['error' => 'No fields to update']);
                exit;
            }

            $values[] = $id;
            $sql      = "UPDATE suppliers SET " . implode(', ', $updates) . " WHERE id = ?";
            $stmt     = $pdo->prepare($sql);
            $stmt->execute($values);

            echo json_encode(['success' => true, 'message' => 'Supplier updated successfully']);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ DELETE SUPPLIER — GLOBAL
    if ($action === 'delete') {
        SupplierPermission::check('delete');
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Supplier ID is required']);
                exit;
            }

            $checkPO = $pdo->prepare("SELECT id FROM purchase_orders WHERE supplier_id = ? LIMIT 1");
            $checkPO->execute([$id]);
            if ($checkPO->fetch()) {
                http_response_code(400);
                echo json_encode(['error' => 'Cannot delete supplier with existing purchase orders']);
                exit;
            }

            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
            $stmt->execute([$id]);

            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Supplier deleted successfully']);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Supplier not found']);
            }

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ APPROVE — GLOBAL
    if ($action === 'approve') {
        SupplierPermission::check('approve');
        try {
            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Supplier ID is required']);
                exit;
            }

            $stmt = $pdo->prepare("UPDATE suppliers SET status = 'Active', updated_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);

            echo json_encode(['success' => true, 'message' => 'Supplier approved successfully']);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    // ✅ REJECT — GLOBAL
    if ($action === 'reject') {
        SupplierPermission::check('reject');
        try {
            $id     = (int)($_POST['id']     ?? 0);
            $reason = $_POST['reason'] ?? '';

            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Supplier ID is required']);
                exit;
            }

            $stmt = $pdo->prepare("
                UPDATE suppliers
                SET status = 'Inactive',
                    notes  = CONCAT(IFNULL(notes, ''), ?)
                WHERE id = ?
            ");
            $stmt->execute(["\n\nRejected: " . date('Y-m-d') . " - " . $reason, $id]);

            echo json_encode(['success' => true, 'message' => 'Supplier rejected successfully']);

        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Invalid action']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
exit;