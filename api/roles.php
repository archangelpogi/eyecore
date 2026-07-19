<?php
/**
 * api/roles.php  — RBAC Role Management API
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ IDAGDAG ITO!

// ✅ SUBSCRIPTION CHECK - HR module (ENTERPRISE plan required)
$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('hr')) {
    header('Location: ../views/subscription.php');
    exit;
}
if (!isset($_SESSION['user_id'], $_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$clinicId = (int)$_SESSION['clinic_id'];
$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';
$isAdmin  = in_array($userRole, ['SuperAdmin', 'ClinicAdmin'], true);

RBACHelper::init($pdo);

// ─── Request parsing ─────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($method === 'POST' && empty($action)) {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
} else {
    $body = $_POST;
}

// ─── Router ──────────────────────────────────────────────────
try {
    switch ($action) {

        // ── List all roles (exclude ClinicAdmin) ────────────────
        case 'get_roles':
            // Get all roles for this clinic, EXCLUDE ClinicAdmin (system role)
            $stmt = $pdo->prepare("SELECT * FROM roles WHERE clinic_id = ? AND id > 0 AND role_name != 'ClinicAdmin' ORDER BY role_name");
            $stmt->execute([$clinicId]);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Attach assigned user count per role and permission count
            foreach ($roles as &$role) {
                // Get user count
                $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM user_roles WHERE role_id = ?");
                $stmt2->execute([$role['id']]);
                $role['user_count'] = (int)$stmt2->fetchColumn();
                
                // Get permission count
                $stmt3 = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_id = ?");
                $stmt3->execute([$role['id']]);
                $role['permission_count'] = (int)$stmt3->fetchColumn();
            }
            
            send(200, ['success' => true, 'roles' => $roles]);
            break;

        // ── Get all permissions grouped by module ─────────────
        case 'get_permissions':
            // Get all permissions grouped by module and page_name
            $stmt = $pdo->query("
                SELECT 
                    p.id,
                    p.permission_name,
                    p.module,
                    p.page_name,
                    p.description,
                    mi.menu_name,
                    mi.icon
                FROM permissions p
                LEFT JOIN menu_items mi ON mi.page_name = p.page_name
                WHERE p.module IS NOT NULL AND p.module != ''
                ORDER BY p.module, p.page_name, p.permission_name
            ");
            
            $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Group by module, then by page_name
            $grouped = [];
            foreach ($permissions as $perm) {
                $module = $perm['module'];
                $pageName = $perm['page_name'];
                $action = str_replace($pageName . '_', '', $perm['permission_name']);
                
                if (!isset($grouped[$module])) {
                    $grouped[$module] = [];
                }
                if (!isset($grouped[$module][$pageName])) {
                    $grouped[$module][$pageName] = [];
                }
                
                $grouped[$module][$pageName][] = [
                    'id' => $perm['id'],
                    'permission_name' => $perm['permission_name'],
                    'action' => $action,
                    'menu_name' => $perm['menu_name'],
                    'icon' => $perm['icon']
                ];
            }
            
            send(200, ['success' => true, 'permissions' => $grouped]);
            break;

        // ── Get single role + its permission IDs ─────────────
case 'get_role_detail':
    // Get role_id from POST body or GET parameter
    $input = json_decode(file_get_contents('php://input'), true);
    $roleId = (int)($input['role_id'] ?? $_GET['role_id'] ?? 0);
    
    if (!$roleId) {
        send(400, ['error' => 'role_id required', 'received' => $roleId]);
        break;
    }

    $stmt = $pdo->prepare("SELECT * FROM roles WHERE id = ? AND clinic_id = ?");
    $stmt->execute([$roleId, $clinicId]);
    $role = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$role) {
        send(404, ['error' => 'Role not found', 'role_id' => $roleId, 'clinic_id' => $clinicId]);
        break;
    }

    // Get permission IDs for this role
    $stmt2 = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
    $stmt2->execute([$roleId]);
    $permIds = $stmt2->fetchAll(PDO::FETCH_COLUMN);
    
    send(200, ['success' => true, 'role' => $role, 'permission_ids' => $permIds]);
    break;
        // ── Create or update role ─────────────────────────────
case 'save_role':
    error_log("=== SAVE_ROLE DEBUG ===");
    error_log("Role ID: " . ($body['role_id'] ?? 'null'));
    error_log("Role Name: " . ($body['role_name'] ?? 'null'));
    error_log("Clinic ID: $clinicId");
    
    if (!$isAdmin) {
        send(403, ['error' => 'Admin only']);
        break;
    }

    $roleId      = (int)($body['role_id'] ?? 0);
    $roleName    = trim($body['role_name'] ?? '');
    $description = trim($body['description'] ?? '');
    $permIds     = array_map('intval', $body['permission_ids'] ?? []);

    // Validate role name for new role
    if ($roleId == 0 && empty($roleName)) {
        send(400, ['error' => 'role_name is required for new role']);
        break;
    }

    try {
        $pdo->beginTransaction();

        // For UPDATE existing role
        if ($roleId > 0) {
            // Check if role exists and belongs to this clinic
            $stmt = $pdo->prepare("SELECT id, is_system FROM roles WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$roleId, $clinicId]);
            $existingRole = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$existingRole) {
                throw new Exception('Role not found or does not belong to this clinic');
            }
            
            if ($existingRole['is_system'] == 1) {
                throw new Exception('Cannot modify system role');
            }
            
            // Update role name and description if provided
            if (!empty($roleName)) {
                // Check for duplicate name (excluding current role)
                $stmt = $pdo->prepare("
                    SELECT id FROM roles 
                    WHERE clinic_id = ? 
                    AND role_name = ? 
                    AND id != ?
                ");
                $stmt->execute([$clinicId, $roleName, $roleId]);
                if ($stmt->fetch()) {
                    throw new Exception('Role name already exists in this clinic');
                }
                
                $stmt = $pdo->prepare("
                    UPDATE roles 
                    SET role_name = ?, description = ?, updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $stmt->execute([$roleName, $description, $roleId, $clinicId]);
            } else {
                // Update description only
                $stmt = $pdo->prepare("
                    UPDATE roles 
                    SET description = ?, updated_at = NOW()
                    WHERE id = ? AND clinic_id = ?
                ");
                $stmt->execute([$description, $roleId, $clinicId]);
            }
            
            // Update permissions
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);
            
            if (!empty($permIds)) {
                $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, clinic_id) VALUES (?, ?, ?)");
                foreach ($permIds as $permId) {
                    $stmt->execute([$roleId, $permId, $clinicId]);
                }
            }
            
            $pdo->commit();
            send(200, [
                'success' => true, 
                'message' => 'Role updated successfully with ' . count($permIds) . ' permission(s)'
            ]);
            break;
        }
        
        // For CREATE new role
        // Check if role name already exists in this clinic
        $stmt = $pdo->prepare("
            SELECT id FROM roles 
            WHERE clinic_id = ? AND role_name = ?
        ");
        $stmt->execute([$clinicId, $roleName]);
        if ($stmt->fetch()) {
            throw new Exception('Role name already exists in this clinic');
        }

        // Create new role
        $stmt = $pdo->prepare("
            INSERT INTO roles (clinic_id, role_name, description, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$clinicId, $roleName, $description, $userId]);
        $newRoleId = $pdo->lastInsertId();

        // Add permissions to new role
        if (!empty($permIds)) {
            $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, clinic_id) VALUES (?, ?, ?)");
            foreach ($permIds as $permId) {
                $stmt->execute([$newRoleId, $permId, $clinicId]);
            }
        }

        $pdo->commit();
        send(200, [
            'success' => true,
            'message' => 'Role created successfully with ' . count($permIds) . ' permissions',
            'role_id' => $newRoleId
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        send(400, ['error' => $e->getMessage()]);
    }
    break;
    
    // ✅ Kung wala pang role_id, kailangan ng role_name (for new role)
    if (empty($roleName)) {
        send(400, ['error' => 'role_name is required']);
        break;
    }

    if ($roleId) {
        // Update existing role
        $stmt = $pdo->prepare("
            UPDATE roles SET role_name=?, description=?, updated_at=NOW()
            WHERE id=? AND clinic_id=? AND is_system=0
        ");
        $stmt->execute([$roleName, $description, $roleId, $clinicId]);
        if (!$stmt->rowCount()) {
            // If role not found or is system, but we might still want to update permissions
            // So proceed to update permissions
        }
    } else {
        // Create new role
        $stmt = $pdo->prepare("
            INSERT INTO roles (clinic_id, role_name, description, created_by, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$clinicId, $roleName, $description, $userId]);
        $roleId = $pdo->lastInsertId();
    }

    // Update permissions
    $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
    $stmt->execute([$roleId]);
    
    foreach ($permIds as $permId) {
        $stmt = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id, clinic_id) VALUES (?, ?, ?)");
        $stmt->execute([$roleId, $permId, $clinicId]);
    }

    send(200, [
        'success' => true,
        'message' => $roleId ? 'Role and permissions saved successfully' : 'Role created successfully',
        'role_id' => $roleId
    ]);
    break;

        // ── Delete role ───────────────────────────────────────
        case 'delete_role':
            if (!$isAdmin) send(403, ['error' => 'Admin only']);

            $roleId = (int)($body['role_id'] ?? 0);
            if (!$roleId) send(400, ['error' => 'role_id required']);

            // Check if system role
            $stmt = $pdo->prepare("SELECT is_system FROM roles WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$roleId, $clinicId]);
            $role = $stmt->fetch();
            if ($role && $role['is_system']) {
                send(400, ['error' => 'Cannot delete system role']);
            }
            
            // Delete role permissions first
            $stmt = $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?");
            $stmt->execute([$roleId]);
            
            // Delete user_roles associations
            $stmt = $pdo->prepare("DELETE FROM user_roles WHERE role_id = ?");
            $stmt->execute([$roleId]);
            
            // Delete the role
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id = ? AND clinic_id = ? AND is_system = 0");
            $stmt->execute([$roleId, $clinicId]);
            
            send(200, ['success' => true, 'message' => 'Role deleted successfully']);
            break;

case 'assign_user_roles':
    $targetUserId = (int)($body['user_id'] ?? 0);
    $roleIds      = array_map('intval', $body['role_ids'] ?? []);
    
    // Get the user's clinic_id
    $stmt = $pdo->prepare("SELECT clinic_id FROM users WHERE id = ?");
    $stmt->execute([$targetUserId]);
    $userClinicId = $stmt->fetchColumn();
    
    if (!$userClinicId) {
        send(400, ['error' => 'User not found']);
        break;
    }
    
    // Delete old roles for this user AND clinic
    $stmt = $pdo->prepare("DELETE FROM user_roles WHERE user_id = ? AND clinic_id = ?");
    $stmt->execute([$targetUserId, $userClinicId]);
    
    // Insert new roles with correct clinic_id
    if (!empty($roleIds)) {
        $stmt = $pdo->prepare("
            INSERT INTO user_roles (user_id, role_id, clinic_id, assigned_by, assigned_at) 
            VALUES (?, ?, ?, ?, NOW())
        ");
        foreach ($roleIds as $roleId) {
            $stmt->execute([$targetUserId, $roleId, $userClinicId, $userId]);
        }
    }
    
    send(200, ['success' => true, 'message' => count($roleIds) . ' role(s) assigned']);
    break;

        // ── Get roles assigned to a user ──────────────────────
        case 'get_user_roles':
            $targetUserId = (int)($body['user_id'] ?? $_GET['user_id'] ?? 0);
            if (!$targetUserId) send(400, ['error' => 'user_id required']);

            $stmt = $pdo->prepare("
                SELECT r.id, r.role_name, r.description
                FROM user_roles ur
                JOIN roles r ON ur.role_id = r.id
                WHERE ur.user_id = ? AND r.clinic_id = ?
            ");
            $stmt->execute([$targetUserId, $clinicId]);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            send(200, ['success' => true, 'roles' => $roles]);
            break;

        default:
            send(400, ['error' => "Unknown action: $action"]);
    }
} catch (Exception $e) {
    send(500, ['success' => false, 'error' => $e->getMessage()]);
}

function send(int $code, array $data): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}