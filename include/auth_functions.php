<?php
/**
 * auth_functions.php
 * Place in: include/auth_functions.php
 *
 * Bridges the old hasPermission('patients','view') call style
 * to the new RBACHelper::hasPermission('patients_view') system.
 */

require_once __DIR__ . '/RBACHelper.php';

// ── Bootstrap RBACHelper once ──────────────────────────────
// Call this ONCE after you create your PDO connection, e.g.:
//   RBACHelper::init($pdo);
// If you already call it in db.php or index.php, remove the block below.
if (!function_exists('_rbac_boot')) {
    function _rbac_boot(): void {
        global $pdo;
        if (isset($pdo)) {
            RBACHelper::init($pdo);
        }
    }
    _rbac_boot();
}

/**
 * hasPermission('patients', 'view')
 *  → checks 'patients_view' in RBACHelper
 *
 * Also accepts single-arg form: hasPermission('patients_view')
 */
function hasPermission(string $module, string $action = ''): bool
{
    if ($action === '') {
        // Already in new format: hasPermission('patients_view')
        $permissionKey = $module;
    } else {
        // Old format: hasPermission('patients', 'view')
        $permissionKey = $module . '_' . $action;
    }

    return RBACHelper::hasPermission($permissionKey);
}

/**
 * requirePermission('patients', 'edit')
 * Redirects if user lacks permission.
 */
function requirePermission(string $module, string $action = '', string $redirect = 'main.php?view=dashboard'): void
{
    if (!hasPermission($module, $action)) {
        header("Location: {$redirect}");
        exit;
    }
}