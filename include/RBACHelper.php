<?php
/**
 * RBACHelper.php
 * Place in: include/RBACHelper.php
 */
class RBACHelper
{
    private static ?PDO $pdo = null;

    // ─── Bootstrap ───────────────────────────────────────────
    public static function init(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    // ─── Core check ──────────────────────────────────────────

    /**
     * Check if current session user has a specific permission.
     * SuperAdmin & ClinicAdmin bypass all checks.
     */
    public static function hasPermission(string $permission): bool
    {
        if (!isset($_SESSION['user_id'])) return false;

        $role = $_SESSION['role'] ?? '';
        if (in_array($role, ['SuperAdmin', 'ClinicAdmin'], true)) return true;

        return in_array($permission, self::getSessionPermissions(), true);
    }

    /**
     * Redirect if user lacks permission.
     */
    public static function requirePermission(string $permission, string $redirect = 'main.php?view=dashboard'): void
    {
        if (!self::hasPermission($permission)) {
            header("Location: {$redirect}");
            exit;
        }
    }

    public static function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (self::hasPermission($p)) return true;
        }
        return false;
    }

    public static function hasAllPermissions(array $permissions): bool
    {
        foreach ($permissions as $p) {
            if (!self::hasPermission($p)) return false;
        }
        return true;
    }

    // ─── Session cache ────────────────────────────────────────

    /**
     * Call this right after login to cache permissions in session.
     */
    public static function loadPermissionsToSession(int $userId, int $clinicId): void
    {
        $_SESSION['permissions'] = self::getUserPermissions($userId, $clinicId);
    }

    /**
     * Clear permission cache (call on logout or role change).
     */
    public static function clearPermissionsFromSession(): void
    {
        unset($_SESSION['permissions']);
    }

    /**
     * Get permissions from session cache
     */
    public static function getSessionPermissions(): array
    {
        if (!isset($_SESSION['permissions'])) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
            if ($userId > 0) {
                self::loadPermissionsToSession($userId, $clinicId);
            } else {
                return [];
            }
        }
        return $_SESSION['permissions'] ?? [];
    }

    // ─── DB Queries ───────────────────────────────────────────

    /**
     * Get all permission_names for a user (merged from all assigned roles).
     */
    public static function getUserPermissions(int $userId, int $clinicId): array
    {
        if (!self::$pdo) {
            error_log("RBACHelper: PDO not initialized");
            return [];
        }
        
        try {
            $sql = "
                SELECT DISTINCT p.permission_name
                FROM user_roles ur
                INNER JOIN role_permissions rp ON rp.role_id = ur.role_id
                INNER JOIN permissions p ON p.id = rp.permission_id
                WHERE ur.user_id = :user_id AND ur.clinic_id = :clinic_id
            ";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':clinic_id' => $clinicId
            ]);
            $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
            return $result ?: [];
        } catch (PDOException $e) {
            error_log("Error getting user permissions: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all roles assigned to a user.
     */
    public static function getUserRoles(int $userId, int $clinicId): array
    {
        if (!self::$pdo) {
            return [];
        }
        
        try {
            $sql = "
                SELECT r.id, r.role_name, r.description
                FROM user_roles ur
                INNER JOIN roles r ON r.id = ur.role_id
                WHERE ur.user_id = :user_id AND ur.clinic_id = :clinic_id
            ";
            $stmt = self::$pdo->prepare($sql);
            $stmt->execute([
                ':user_id' => $userId,
                ':clinic_id' => $clinicId
            ]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log("Error getting user roles: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Build sidebar menu array filtered by user permissions.
     */
    public static function getSidebarMenu(int $userId, int $clinicId): array
    {
        if (!self::$pdo) {
            return [];
        }
        
        $role         = $_SESSION['role'] ?? '';
        $isSuperAdmin = in_array($role, ['SuperAdmin', 'ClinicAdmin'], true);
        $permissions  = self::getSessionPermissions();

        $sql = "
            SELECT id, menu_name, icon, group_icon, url, page_name,
                   permission_required, module, group_name, sort_order, enabled_for_admin
            FROM menu_items
            WHERE is_active = 1
            ORDER BY module, sort_order
        ";
        $stmt = self::$pdo->query($sql);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $menu = [];
        foreach ($items as $item) {
            // Admin-only items
            if ($item['enabled_for_admin'] && !$isSuperAdmin) continue;

            if ($isSuperAdmin) {
                $menu[$item['module']][] = $item;
                continue;
            }

            // Determine required permission
            $required = $item['permission_required']
                ?? ($item['page_name'] ? $item['page_name'] . '_view' : null);

            if (!$required || in_array($required, $permissions, true)) {
                $menu[$item['module']][] = $item;
            }
        }

        return $menu;
    }

    // ─── Role / Permission Management ─────────────────────────

    /**
     * Create a new role.
     */
    public static function createRole(int $clinicId, string $roleName, string $description = '', int $createdBy = 0): int
    {
        if (!self::$pdo) return 0;
        
        $stmt = self::$pdo->prepare("
            INSERT INTO roles (clinic_id, role_name, description, created_by, created_at)
            VALUES (:clinic_id, :role_name, :description, :created_by, NOW())
        ");
        $stmt->execute([
            ':clinic_id'   => $clinicId,
            ':role_name'   => $roleName,
            ':description' => $description,
            ':created_by'  => $createdBy,
        ]);
        return (int)self::$pdo->lastInsertId();
    }

    /**
     * Assign permissions to a role (replaces existing).
     */
    public static function setRolePermissions(int $roleId, int $clinicId, array $permissionIds): void
    {
        if (!self::$pdo) return;
        
        // Remove old
        $del = self::$pdo->prepare("DELETE FROM role_permissions WHERE role_id = :role_id AND clinic_id = :clinic_id");
        $del->execute([':role_id' => $roleId, ':clinic_id' => $clinicId]);

        if (empty($permissionIds)) return;

        // Insert new
        $ins = self::$pdo->prepare("
            INSERT INTO role_permissions (role_id, permission_id, clinic_id)
            VALUES (:role_id, :permission_id, :clinic_id)
        ");
        foreach ($permissionIds as $pid) {
            $ins->execute([
                ':role_id' => $roleId,
                ':permission_id' => (int)$pid,
                ':clinic_id' => $clinicId
            ]);
        }
    }

    /**
     * Assign roles to a user (replaces existing).
     */
    public static function setUserRoles(int $userId, int $clinicId, array $roleIds, int $assignedBy = 0): void
    {
        if (!self::$pdo) return;
        
        $del = self::$pdo->prepare("DELETE FROM user_roles WHERE user_id = :user_id AND clinic_id = :clinic_id");
        $del->execute([':user_id' => $userId, ':clinic_id' => $clinicId]);

        if (empty($roleIds)) return;

        $ins = self::$pdo->prepare("
            INSERT INTO user_roles (user_id, role_id, clinic_id, assigned_by, assigned_at)
            VALUES (:user_id, :role_id, :clinic_id, :assigned_by, NOW())
        ");
        foreach ($roleIds as $rid) {
            $ins->execute([
                ':user_id'     => $userId,
                ':role_id'     => (int)$rid,
                ':clinic_id'   => $clinicId,
                ':assigned_by' => $assignedBy,
            ]);
        }

        // Refresh session cache if editing self
        if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
            self::loadPermissionsToSession($userId, $clinicId);
        }
    }

    /**
     * Get all roles for a clinic (with permission count).
     */
    public static function getAllRoles(int $clinicId): array
    {
        if (!self::$pdo) return [];
        
        $sql = "
            SELECT r.*,
                   COUNT(rp.id) AS permission_count
            FROM roles r
            LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.clinic_id = r.clinic_id
            WHERE r.clinic_id = :clinic_id
            GROUP BY r.id
            ORDER BY r.role_name
        ";
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute([':clinic_id' => $clinicId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Get all permissions grouped by module.
     */
    public static function getAllPermissionsGrouped(): array
    {
        if (!self::$pdo) return [];
        
        $sql = "
            SELECT p.*, mi.menu_name, mi.icon
            FROM permissions p
            LEFT JOIN menu_items mi ON mi.page_name = p.page_name
            ORDER BY p.module, p.page_name,
                FIELD(SUBSTRING_INDEX(p.permission_name,'_',-1),'view','create','edit','delete')
        ";
        $stmt = self::$pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['module']][$row['page_name']][] = $row;
        }
        return $grouped;
    }

    /**
     * Get permission IDs already assigned to a role.
     */
    public static function getRolePermissionIds(int $roleId, int $clinicId): array
    {
        if (!self::$pdo) return [];
        
        $stmt = self::$pdo->prepare("
            SELECT permission_id FROM role_permissions
            WHERE role_id = :role_id AND clinic_id = :clinic_id
        ");
        $stmt->execute([':role_id' => $roleId, ':clinic_id' => $clinicId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    /**
     * Delete a role (and all its permission + user assignments).
     */
    public static function deleteRole(int $roleId, int $clinicId): bool
    {
        if (!self::$pdo) return false;
        
        self::$pdo->prepare("DELETE FROM role_permissions WHERE role_id = ? AND clinic_id = ?")
                  ->execute([$roleId, $clinicId]);
        self::$pdo->prepare("DELETE FROM user_roles WHERE role_id = ? AND clinic_id = ?")
                  ->execute([$roleId, $clinicId]);
        $stmt = self::$pdo->prepare("DELETE FROM roles WHERE id = ? AND clinic_id = ? AND is_system = 0");
        $stmt->execute([$roleId, $clinicId]);
        return $stmt->rowCount() > 0;
    }
}