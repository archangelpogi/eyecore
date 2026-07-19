<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Role Management</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
            --teal-soft: #f0fdfa;
            --teal-mid: #14b8a6;
        }
        body { background: #f8fafc; font-family: 'Inter', system-ui, -apple-system, sans-serif; }

        /* ── Roles list card ── */
        .role-card {
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            transition: all 0.2s ease;
            cursor: pointer;
            background: #fff;
        }
        .role-card:hover, .role-card.active {
            border-color: var(--teal);
            box-shadow: 0 0 0 3px rgba(13,148,136,.12);
            transform: translateY(-2px);
        }
        .role-card.active { background: var(--teal-soft); }
        .role-badge { font-size: 11px; font-weight: 600; }

        /* ── Permission matrix ── */
        .perm-module-header {
            background: #f1f5f9;
            font-weight: 700;
            font-size: 12px;
            letter-spacing: .6px;
            text-transform: uppercase;
            padding: 8px 14px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #1e293b;
        }
        .perm-row {
            display: flex;
            align-items: center;
            padding: 8px 14px;
            border-radius: 8px;
            transition: background .15s;
        }
        .perm-row:hover { background: #f8fafc; }
        .perm-page-name {
            flex: 1;
            font-size: 13px;
            color: #334155;
            font-weight: 500;
        }
        .perm-page-name small { color: #94a3b8; font-weight: 400; font-size: 11px; }
        .perm-actions {
            display: flex;
            gap: 12px;
        }
        .perm-check-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 2px;
            min-width: 56px;
        }
        .perm-check-wrap label {
            font-size: 10px;
            color: #64748b;
            margin: 0;
            font-weight: 500;
        }
        .perm-check-wrap input[type=checkbox] {
            width: 16px; height: 16px;
            cursor: pointer;
        }
        .perm-check-wrap input[type=checkbox]:checked {
            accent-color: var(--teal);
        }

        /* Action color hints - Teal theme */
        .perm-view label { color: var(--teal); }
        .perm-create label { color: #10b981; }
        .perm-edit label { color: #f59e0b; }
        .perm-delete label { color: #ef4444; }
        .perm-archive label { color: #6c757d; }
        .perm-approve label { color: #10b981; }
        .perm-reject label { color: #ef4444; }

        /* Icons for actions */
        .perm-check-wrap label::before {
            font-family: 'bootstrap-icons';
            margin-right: 3px;
            font-size: 9px;
        }
        .perm-view label::before { content: "\f35d"; }
        .perm-create label::before { content: "\f2f5"; }
        .perm-edit label::before { content: "\f4cb"; }
        .perm-archive label::before { content: "\f487"; }
        .perm-approve label::before { content: "\f26b"; }
        .perm-reject label::before { content: "\f6b8"; }

        /* ── Search highlight ── */
        .highlight { background: #fef9c3; border-radius: 3px; }

        /* ── Sticky save bar ── */
        .save-bar {
            position: sticky;
            bottom: 0;
            background: #fff;
            border-top: 1px solid #e2e8f0;
            padding: 12px 24px;
            z-index: 10;
            border-radius: 0 0 16px 16px;
        }

        /* Card styling */
        .card-custom {
            border: none;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .card-header-custom {
            background: white;
            border-bottom: 1px solid #e2e8f0;
            padding: 1rem 1.5rem;
        }

        /* Buttons */
        .btn-teal {
            background: var(--teal);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 8px 20px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-teal:hover {
            background: var(--teal-dark);
            transform: translateY(-1px);
        }
        .btn-outline-teal {
            background: transparent;
            border: 1px solid var(--teal);
            color: var(--teal);
            border-radius: 10px;
            padding: 6px 16px;
            font-weight: 500;
        }
        .btn-outline-teal:hover {
            background: var(--teal-soft);
            color: var(--teal-dark);
        }
        .btn-sm-success {
            background: #10b981;
            color: white;
            border: none;
            border-radius: 8px;
            padding: 6px 14px;
            font-weight: 500;
        }
        .btn-sm-success:hover {
            background: #059669;
        }

        /* Access Denied */
        .access-denied {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Role name input group */
        .role-input-group {
            position: relative;
        }
        .role-input-group .dropdown-menu {
            max-height: 250px;
            overflow-y: auto;
            border-radius: 12px;
            border: 1px solid #e2e8f0;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .role-suggestion-item {
            padding: 8px 16px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .role-suggestion-item:hover {
            background: var(--teal-soft);
        }
        .role-suggestion-item i {
            color: var(--teal);
            margin-right: 8px;
        }

        /* Role name input container */
.input-group {
    position: relative;
    z-index: 1;
}

#roleSuggestionsDropdown {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    width: 100%;
    z-index: 1050;
    background: white;
    border-radius: 12px;
    margin-top: 5px;
}
        
    </style>
</head>
<body>

<div id="roleManagementApp">
    <?php
    // Start session and check RBAC permissions
    if (session_status() === PHP_SESSION_NONE) {
        session_name('eyecore_admin');
        session_start();
    }
    
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../include/RBACHelper.php';
    
    // Initialize RBACHelper
    RBACHelper::init($pdo);
    
    // Load permissions to session
    if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
        RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
    }
    
    // Check if user is logged in and is ClinicAdmin
    $isClinicAdmin = isset($_SESSION['role']) && $_SESSION['role'] === 'ClinicAdmin';
    
    if (!$isClinicAdmin) {
        ?>
        <div class="container-fluid p-5 text-center access-denied">
            <div class="alert alert-danger" style="max-width: 500px; margin: 0 auto;">
                <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                <h3>Access Denied</h3>
                <p>Only Clinic Administrators can access Role Management.</p>
                <p class="text-muted small mb-0">This module is restricted to maintain system security.</p>
            </div>
        </div>
        <?php
        exit;
    }
    
    $clinicId = $_SESSION['clinic_id'];
    $userId = $_SESSION['user_id'];
    $userRole = $_SESSION['role'] ?? '';
    ?>
</div>

<div class="container-fluid p-3 p-md-4">

    <!-- Header with Teal Theme -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h3 class="fw-bold mb-0" style="color: var(--teal);">
                <i class="bi bi-shield-lock me-2" style="color: var(--teal);"></i>Role Management
            </h3>
            <p class="text-muted mb-0 small">Create custom roles, assign permissions, and manage employee access</p>
        </div>
        <button class="btn btn-teal" onclick="openRoleModal()">
            <i class="bi bi-plus-circle me-1"></i> Create New Role
        </button>
    </div>

    <div class="row g-3">

        <!-- ── LEFT: Roles List ── -->
        <div class="col-md-4">
            <div class="card card-custom h-100">
                <div class="card-header-custom d-flex justify-content-between align-items-center">
                    <span class="fw-bold" style="color: #1e293b;">All Roles</span>
                    <span class="badge" style="background: var(--teal-soft); color: var(--teal);" id="roleCount">0</span>
                </div>
                <div class="card-body p-2" id="rolesList">
                    <div class="text-center py-5 text-muted">
                        <div class="spinner-border spinner-border-sm me-2" style="color: var(--teal);"></div> Loading...
                    </div>
                </div>
            </div>
        </div>

        <!-- ── RIGHT: Permission Matrix ── -->
        <div class="col-md-8">
            <div class="card card-custom" id="permissionPanel">
                <div class="card-header-custom">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                        <span class="fw-bold" id="permPanelTitle" style="color: #1e293b;">
                            <i class="bi bi-info-circle me-1" style="color: var(--teal);"></i>
                            Select a role to edit its permissions
                        </span>
                        <div class="d-flex gap-2" id="permPanelActions" style="display:none!important;">
                            <input type="text" class="form-control form-control-sm" style="width:180px; border-radius: 8px;"
                                   id="permSearch" placeholder="🔍 Search permissions..." oninput="filterPermissions(this.value)">
                            <button class="btn btn-sm btn-outline-teal" onclick="checkAll(true)">All</button>
                            <button class="btn btn-sm btn-outline-teal" onclick="checkAll(false)">None</button>
                        </div>
                    </div>
                </div>

                <div class="card-body p-3" id="permissionMatrix" style="max-height:65vh;overflow-y:auto;">
                    <div class="text-center py-5 text-muted">
                        <i class="bi bi-arrow-left-circle display-4 d-block mb-2 opacity-25" style="color: var(--teal);"></i>
                        Pick a role from the left panel
                    </div>
                </div>

                <div class="save-bar d-none" id="saveBar">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted" id="saveBarInfo">0 permissions selected</small>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-teal" onclick="cancelEdit()">Cancel</button>
                            <button class="btn btn-sm btn-teal" onclick="savePermissions()">
                                <i class="bi bi-check-circle me-1"></i>Save Permissions
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Create / Edit Role with Role Suggestions ── -->
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal) 0%, var(--teal-dark) 100%); color: white;">
                <h5 class="modal-title fw-bold" id="roleModalTitle">
                    <i class="bi bi-shield-plus me-2"></i>Create New Role
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="modal_role_id">
<div class="mb-3">
    <label class="form-label fw-semibold">Role Name <span class="text-danger">*</span></label>
    <div style="position: relative;">
        <div class="input-group">
            <span class="input-group-text bg-light border-end-0" style="border-radius: 10px 0 0 10px;">
                <i class="bi bi-tag" style="color: var(--teal);"></i>
            </span>
            <input type="text" class="form-control border-start-0" id="modal_role_name" 
                   placeholder="Type role name or select from suggestions" 
                   maxlength="100" style="border-radius: 0 10px 10px 0;"
                   autocomplete="off">
            <button class="btn btn-outline-teal" type="button" id="showRoleSuggestionsBtn" 
                    style="border-radius: 10px; margin-left: 5px;">
                <i class="bi bi-chevron-down"></i>
            </button>
        </div>
        <!-- Dropdown Suggestions -->
        <div id="roleSuggestionsDropdown" style="display: none; position: absolute; z-index: 1050; 
             background: white; border: 1px solid #e2e8f0; border-radius: 12px; 
             box-shadow: 0 4px 12px rgba(0,0,0,0.1); width: 100%; margin-top: 5px;">
            <div class="px-3 py-2 text-muted small border-bottom" style="background: var(--teal-soft); border-radius: 12px 12px 0 0;">
                <i class="bi bi-star-fill me-1" style="color: #f59e0b;"></i> Suggested Roles for Optical Clinic
            </div>
            <div id="roleSuggestionsList" style="max-height: 250px; overflow-y: auto;"></div>
            <div class="dropdown-divider"></div>
            <div class="px-3 py-2 text-muted small" style="background: #f8fafc; border-radius: 0 0 12px 12px;">
                <i class="bi bi-pencil-square me-1"></i> Or type your own custom role
            </div>
        </div>
    </div>
    <small class="text-muted">Choose from suggested roles or create your own custom role.</small>
</div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Description</label>
                    <textarea class="form-control" id="modal_role_desc" rows="2" 
                              placeholder="Brief description of this role..." style="border-radius: 12px;"></textarea>
                </div>
                <div class="alert alert-info py-2 small" style="background: var(--teal-soft); border-color: var(--teal-light); color: var(--teal-dark); border-radius: 12px;">
                    <i class="bi bi-info-circle me-1"></i>
                    After creating, click the role to assign permissions to it.
                </div>
            </div>
            <div class="modal-footer border-0 pb-4">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 10px;">Cancel</button>
                <button type="button" class="btn btn-teal" onclick="saveRole()">
                    <i class="bi bi-check-circle me-1"></i> Save Role
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Modal: Assign roles to Employee ── -->
<div class="modal fade" id="assignRolesModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden;">
            <div class="modal-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white;">
                <h5 class="modal-title fw-bold">
                    <i class="bi bi-person-check me-2"></i>Assign Roles to Employee
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="assign_user_id">
                <p class="mb-3">Assigning roles to: <strong id="assign_user_name" class="text-teal"></strong></p>
                <div id="assignRolesList" class="d-flex flex-column gap-2" style="max-height: 400px; overflow-y: auto;">
                    <!-- Checkboxes rendered here -->
                </div>
            </div>
            <div class="modal-footer border-0 pb-4">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal" style="border-radius: 10px;">Cancel</button>
                <button type="button" class="btn btn-teal" onclick="saveUserRoles()">
                    <i class="bi bi-check-circle me-1"></i> Apply Roles
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* ════════════════════════════════════════════════════════════
   RBAC Role Manager — Role Management Module
   ════════════════════════════════════════════════════════════ */

const API = 'api/roles.php';
let allRoles       = [];
let allPermissions = {};
let activeRoleId   = null;

// Predefined role suggestions for optical clinic
const roleSuggestions = [
    { name: 'Optometrist', icon: 'bi-eye', description: 'Eye examination and prescription' },
    { name: 'Optical Assistant', icon: 'bi-person-standing', description: 'Assist patients with frame selection' },
    { name: 'Receptionist', icon: 'bi-telephone', description: 'Front desk and appointment scheduling' },
    { name: 'Cashier', icon: 'bi-cash', description: 'Payment processing and billing' },
    { name: 'Sales Associate', icon: 'bi-cart', description: 'Product sales and recommendations' },
    { name: 'Lab Technician', icon: 'bi-gear', description: 'Lens edging and frame fitting' },
    { name: 'Inventory Manager', icon: 'bi-box-seam', description: 'Stock management and ordering' },
    { name: 'Payroll Manager', icon: 'bi-calculator', description: 'Employee payroll and benefits' },
    { name: 'HR Manager', icon: 'bi-people', description: 'Human resources and recruitment' },
    { name: 'Marketing Manager', icon: 'bi-megaphone', description: 'Clinic marketing and promotions' },
    { name: 'Clinic Manager', icon: 'bi-building', description: 'Overall clinic operations' },
    { name: 'Billing Specialist', icon: 'bi-receipt', description: 'Insurance claims and billing' },
    { name: 'Customer Service', icon: 'bi-chat-dots', description: 'Patient inquiries and support' },
    { name: 'Optical Consultant', icon: 'bi-star', description: 'Expert frame and lens recommendations' }
];

/* ── Init ────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    loadRoles();
    loadPermissions();
    initRoleSuggestions();
    
});

/* ── Role Suggestions Dropdown Functions ────────────────── */
function initRoleSuggestions() {
    const input = document.getElementById('modal_role_name');
    const dropdown = document.getElementById('roleSuggestionsDropdown');
    const showBtn = document.getElementById('showRoleSuggestionsBtn');
    
    if (!input || !dropdown || !showBtn) return;
    
    // Populate suggestions list
    const suggestionsList = document.getElementById('roleSuggestionsList');
    if (suggestionsList) {
        suggestionsList.innerHTML = roleSuggestions.map(role => `
            <div class="role-suggestion-item" onclick="selectRoleSuggestion('${role.name.replace(/'/g, "\\'")}')" 
                 style="padding: 8px 16px; cursor: pointer; transition: background 0.2s; border-bottom: 1px solid #f1f5f9;">
                <i class="${role.icon} me-2" style="color: var(--teal);"></i>
                <strong>${role.name}</strong>
                <small class="text-muted d-block ms-4">${role.description}</small>
            </div>
        `).join('');
        
        // Add hover effect
        document.querySelectorAll('.role-suggestion-item').forEach(item => {
            item.addEventListener('mouseenter', () => {
                item.style.background = 'var(--teal-soft)';
            });
            item.addEventListener('mouseleave', () => {
                item.style.background = 'transparent';
            });
        });
    }
    
    // Show dropdown on button click
    showBtn.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        
        if (dropdown.style.display === 'block') {
            dropdown.style.display = 'none';
        } else {
            dropdown.style.display = 'block';
            // Make sure dropdown appears below the input
            dropdown.style.top = '100%';
            dropdown.style.left = '0';
            dropdown.style.width = '100%';
        }
    });
    
    // Hide dropdown when clicking outside
    document.addEventListener('click', (e) => {
        if (!input.contains(e.target) && !showBtn.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
    
    // Show dropdown on input focus
    input.addEventListener('focus', () => {
        dropdown.style.display = 'block';
        dropdown.style.top = '100%';
        dropdown.style.left = '0';
        dropdown.style.width = '100%';
    });
    
    // Prevent dropdown from closing when clicking inside
    dropdown.addEventListener('click', (e) => {
        e.stopPropagation();
    });
}

function selectRoleSuggestion(roleName) {
    document.getElementById('modal_role_name').value = roleName;
    document.getElementById('roleSuggestionsDropdown').style.display = 'none';
}

function loadRoles() {
    fetch(`${API}?action=get_roles`)
        .then(r => r.json())
        .then(d => {
            if (!d.success) throw new Error(d.error);
            allRoles = d.roles;
            renderRoles();
        })
        .catch(e => showError(e.message));
}

function renderRoles() {
    const el = document.getElementById('rolesList');
    document.getElementById('roleCount').textContent = allRoles.length;

    if (!allRoles.length) {
        el.innerHTML = `<div class="text-center py-4 text-muted small">
            <i class="bi bi-shield-slash fs-1 d-block mb-2 opacity-25"></i>
            No roles yet. Click <b class="text-teal">Create New Role</b> to start.
        </div>`;
        return;
    }

    el.innerHTML = allRoles.map(r => `
        <div class="role-card p-3 mb-2 ${r.id == activeRoleId ? 'active' : ''}"
             onclick="selectRole(${r.id})" id="roleCard_${r.id}">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-bold" style="color: #1e293b;">${esc(r.role_name)}</div>
                    <small class="text-muted">${esc(r.description || 'No description')}</small>
                </div>
                <div class="d-flex gap-1 flex-column align-items-end">
                    <span class="badge" style="background: var(--teal-soft); color: var(--teal);">
                        <i class="bi bi-key me-1"></i>${r.permission_count} perms
                    </span>
                    <span class="badge bg-light text-dark border">
                        <i class="bi bi-people me-1"></i>${r.user_count} users
                    </span>
                </div>
            </div>
            <div class="mt-2 d-flex gap-1">
                ${!r.is_system ? `
                    <button class="btn btn-xs btn-outline-teal py-0 px-2"
                            style="font-size:11px; border-radius: 6px;"
                            onclick="event.stopPropagation(); openEditRoleModal(${r.id})">
                        <i class="bi bi-pencil"></i> Edit
                    </button>
                    <button class="btn btn-xs btn-outline-danger py-0 px-2"
                            style="font-size:11px; border-radius: 6px;"
                            onclick="event.stopPropagation(); deleteRole(${r.id}, '${esc(r.role_name)}')">
                        <i class="bi bi-trash"></i> Delete
                    </button>
                ` : `<span class="badge bg-warning text-dark" style="font-size:10px">System Role</span>`}
            </div>
        </div>
    `).join('');
}

/* ── Load all permissions ────────────────────────────────── */
function loadPermissions() {
    fetch(`${API}?action=get_permissions`)
        .then(r => r.json())
        .then(d => { if (d.success) allPermissions = d.permissions; })
        .catch(e => console.error('Load permissions error:', e));
}

/* ── Select role → show its permissions ─────────────────── */
function selectRole(roleId) {
    activeRoleId = roleId;
    renderRoles();

    const matrix = document.getElementById('permissionMatrix');
    matrix.innerHTML = `<div class="text-center py-4"><div class="spinner-border" style="color: var(--teal);"></div></div>`;

    fetch(`${API}?action=get_role_detail`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ role_id: roleId })
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error);
        const role = d.role;
        const assignedIds = new Set(d.permission_ids.map(Number));

        document.getElementById('permPanelTitle').innerHTML =
            `<i class="bi bi-shield-check me-1" style="color: var(--teal);"></i>
             Permissions for <b style="color: var(--teal);">${esc(role.role_name)}</b>`;

        document.getElementById('permPanelActions').style.removeProperty('display');
        document.getElementById('saveBar').classList.remove('d-none');

        renderPermissionMatrix(assignedIds);
    })
    .catch(e => {
        console.error('Error:', e);
        matrix.innerHTML = `<div class="alert alert-danger">${e.message}</div>`;
    });
}

function renderPermissionMatrix(assignedIds = new Set()) {
    const matrix = document.getElementById('permissionMatrix');
    let html = '';

    for (const [module, pages] of Object.entries(allPermissions)) {
        let rowsHtml = '';

        for (const [pageName, perms] of Object.entries(pages)) {
            const menuName = perms[0]?.menu_name || pageName;
            const icon     = perms[0]?.icon || 'bi-file';

            const actions = ['view','create','edit','delete','approve','reject'];
            let checks = '';

            actions.forEach(action => {
                const perm = perms.find(p => p.permission_name.endsWith('_' + action));
                if (perm) {
                    const checked = assignedIds.has(Number(perm.id)) ? 'checked' : '';
                    
                    let displayLabel = action;
                    if (action === 'delete') displayLabel = 'archive';
                    if (action === 'approve') displayLabel = 'approve';
                    if (action === 'reject') displayLabel = 'reject';
                    
                    checks += `
                        <div class="perm-check-wrap perm-${action}">
                            <input type="checkbox" class="perm-cb"
                                   id="perm_${perm.id}"
                                   data-id="${perm.id}"
                                   ${checked}
                                   onchange="updateSaveBar()">
                            <label for="perm_${perm.id}">${displayLabel}</label>
                        </div>`;
                } else {
                    checks += `<div class="perm-check-wrap" style="min-width:56px"></div>`;
                }
            });

            const allCheckedInRow = perms.every(p => assignedIds.has(Number(p.id)));
            rowsHtml += `
                <div class="perm-row" data-page="${pageName}">
                    <div class="perm-page-name">
                        <i class="bi ${icon} me-1 text-secondary"></i>
                        ${esc(menuName)}
                        <small class="ms-1">${esc(pageName)}</small>
                    </div>
                    <div class="perm-actions">${checks}</div>
                    <div class="ms-3">
                        <input type="checkbox" title="Toggle all for this page"
                               ${allCheckedInRow ? 'checked' : ''}
                               onchange="togglePagePerms('${pageName}', this.checked)"
                               style="width:15px;height:15px;cursor:pointer;accent-color:#6c757d">
                    </div>
                </div>`;
        }

        html += `
            <div class="mb-3" data-module="${module}">
                <div class="perm-module-header mb-2">
                    <span><i class="bi bi-folder2-open me-2"></i>${esc(module)}</span>
                    <div class="d-flex gap-1">
                        <button class="btn btn-xs py-0 px-2 btn-outline-teal"
                                style="font-size:10px"
                                onclick="toggleModulePerms('${module}', true)">All</button>
                        <button class="btn btn-xs py-0 px-2 btn-outline-teal"
                                style="font-size:10px"
                                onclick="toggleModulePerms('${module}', false)">None</button>
                    </div>
                </div>
                ${rowsHtml}
            </div>`;
    }

    matrix.innerHTML = html;
    updateSaveBar();
}

/* ── Toggle helpers ──────────────────────────────────────── */
function togglePagePerms(pageName, checked) {
    document.querySelectorAll(`.perm-row[data-page="${pageName}"] .perm-cb`)
        .forEach(cb => { cb.checked = checked; });
    updateSaveBar();
}

function toggleModulePerms(module, checked) {
    document.querySelectorAll(`[data-module="${module}"] .perm-cb`)
        .forEach(cb => { cb.checked = checked; });
    updateSaveBar();
}

function checkAll(checked) {
    document.querySelectorAll('.perm-cb').forEach(cb => { cb.checked = checked; });
    updateSaveBar();
}

function updateSaveBar() {
    const count = document.querySelectorAll('.perm-cb:checked').length;
    document.getElementById('saveBarInfo').textContent = `${count} permissions selected`;
}

function cancelEdit() {
    activeRoleId = null;
    renderRoles();
    document.getElementById('permissionMatrix').innerHTML = `
        <div class="text-center py-5 text-muted">
            <i class="bi bi-arrow-left-circle display-4 d-block mb-2 opacity-25" style="color: var(--teal);"></i>
            Pick a role from the left panel
        </div>`;
    document.getElementById('permPanelTitle').innerHTML =
        `<i class="bi bi-info-circle me-1" style="color: var(--teal);"></i> Select a role to edit its permissions`;
    document.getElementById('permPanelActions').style.setProperty('display','none','important');
    document.getElementById('saveBar').classList.add('d-none');
}

/* ── Search filter ───────────────────────────────────────── */
function filterPermissions(term) {
    term = term.toLowerCase().trim();
    document.querySelectorAll('.perm-row').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = !term || text.includes(term) ? '' : 'none';
    });
}

function savePermissions() {
    if (!activeRoleId) {
        Swal.fire('Error', 'No role selected', 'error');
        return;
    }

    const permIds = [...document.querySelectorAll('.perm-cb:checked')]
                    .map(cb => parseInt(cb.dataset.id));

    Swal.fire({ 
        title: 'Saving permissions...', 
        allowOutsideClick: false, 
        showConfirmButton: false,
        didOpen: () => Swal.showLoading() 
    });

    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ 
            action: 'save_role', 
            role_id: activeRoleId, 
            permission_ids: permIds 
        })
    })
    .then(r => r.json())
    .then(d => {
        Swal.close();
        if (!d.success) throw new Error(d.error);
        Swal.fire({ 
            icon: 'success', 
            title: 'Saved!', 
            text: d.message, 
            timer: 1500, 
            showConfirmButton: false 
        });
        loadRoles();
    })
    .catch(e => { 
        Swal.close(); 
        Swal.fire('Error', e.message, 'error'); 
    });
}

function openRoleModal() {
    document.getElementById('modal_role_id').value   = '';
    document.getElementById('modal_role_name').value = '';
    document.getElementById('modal_role_desc').value = '';
    document.getElementById('roleModalTitle').innerHTML =
        '<i class="bi bi-shield-plus me-2"></i>Create New Role';
    new bootstrap.Modal(document.getElementById('roleModal')).show();
}

function openEditRoleModal(roleId) {
    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'get_role_detail', role_id: roleId })
    })
    .then(r => r.json())
    .then(d => {
        if (!d.success) throw new Error(d.error);
        document.getElementById('modal_role_id').value   = d.role.id;
        document.getElementById('modal_role_name').value = d.role.role_name;
        document.getElementById('modal_role_desc').value = d.role.description || '';
        document.getElementById('roleModalTitle').innerHTML =
            '<i class="bi bi-pencil me-2"></i>Edit Role';
        new bootstrap.Modal(document.getElementById('roleModal')).show();
    })
    .catch(e => showError(e.message));
}

function saveRole() {
    const roleId   = document.getElementById('modal_role_id').value;
    const roleName = document.getElementById('modal_role_name').value.trim();
    const desc     = document.getElementById('modal_role_desc').value.trim();

    if (!roleName) { 
        Swal.fire('Warning', 'Role name is required', 'warning'); 
        return; 
    }

    Swal.fire({ 
        title: 'Saving...', 
        allowOutsideClick: false, 
        showConfirmButton: false,
        didOpen: () => Swal.showLoading() 
    });

    const payload = {
        action: 'save_role',
        role_id: roleId ? parseInt(roleId) : 0,
        role_name: roleName,
        description: desc,
        permission_ids: []
    };

    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(d => {
        Swal.close();
        if (!d.success) throw new Error(d.error);
        bootstrap.Modal.getInstance(document.getElementById('roleModal')).hide();
        Swal.fire({ 
            icon: 'success', 
            title: 'Saved!', 
            text: d.message, 
            timer: 1500, 
            showConfirmButton: false 
        });
        loadRoles();
    })
    .catch(e => { 
        Swal.close(); 
        Swal.fire('Error', e.message, 'error'); 
    });
}

function deleteRole(roleId, roleName) {
    Swal.fire({
        title: `Delete "${roleName}"?`,
        text: 'This will remove all permission and user assignments for this role.',
        icon: 'warning', showCancelButton: true,
        confirmButtonColor: '#d33', confirmButtonText: 'Yes, delete'
    }).then(r => {
        if (!r.isConfirmed) return;
        fetch(API, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ action: 'delete_role', role_id: roleId })
        })
        .then(r => r.json())
        .then(d => {
            if (!d.success) throw new Error(d.error);
            if (activeRoleId === roleId) cancelEdit();
            Swal.fire({ icon: 'success', title: 'Deleted!', timer: 1200, showConfirmButton: false });
            loadRoles();
        })
        .catch(e => showError(e.message));
    });
}

function openAssignRolesModal(userId, userName) {
    document.getElementById('assign_user_id').value   = userId;
    document.getElementById('assign_user_name').textContent = userName;

    Promise.all([
        fetch(`${API}?action=get_user_roles&user_id=${userId}`).then(r => r.json()),
        allRoles.length ? Promise.resolve({ success: true, roles: allRoles })
                        : fetch(`${API}?action=get_roles`).then(r => r.json())
    ])
    .then(([userRolesRes, rolesRes]) => {
        if (!rolesRes.success) throw new Error(rolesRes.error);
        allRoles = rolesRes.roles;

        const currentRoleIds = new Set((userRolesRes.roles || []).map(r => r.id));
        const container = document.getElementById('assignRolesList');

        container.innerHTML = allRoles.map(r => `
            <div class="form-check p-3 border rounded" style="cursor:pointer; border-radius: 12px; transition: all 0.2s;"
                 onclick="this.querySelector('input').click()">
                <input class="form-check-input assign-role-cb"
                       type="checkbox" value="${r.id}"
                       id="assign_role_${r.id}"
                       ${currentRoleIds.has(r.id) ? 'checked' : ''}
                       onclick="event.stopPropagation()"
                       style="accent-color: var(--teal);">
                <label class="form-check-label w-100" for="assign_role_${r.id}">
                    <div class="fw-semibold" style="color: #1e293b;">${esc(r.role_name)}</div>
                    <small class="text-muted">${r.permission_count} permissions · ${esc(r.description || 'No description')}</small>
                </label>
            </div>
        `).join('');

        new bootstrap.Modal(document.getElementById('assignRolesModal')).show();
    })
    .catch(e => showError(e.message));
}

function saveUserRoles() {
    const userId  = parseInt(document.getElementById('assign_user_id').value);
    const roleIds = [...document.querySelectorAll('.assign-role-cb:checked')].map(cb => parseInt(cb.value));

    Swal.fire({ title: 'Assigning...', allowOutsideClick: false, showConfirmButton: false,
                didOpen: () => Swal.showLoading() });

    fetch(API, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'assign_user_roles', user_id: userId, role_ids: roleIds })
    })
    .then(r => r.json())
    .then(d => {
        Swal.close();
        if (!d.success) throw new Error(d.error);
        bootstrap.Modal.getInstance(document.getElementById('assignRolesModal')).hide();
        Swal.fire({ icon: 'success', title: 'Assigned!', text: d.message, timer: 1500, showConfirmButton: false });
    })
    .catch(e => { Swal.close(); showError(e.message); });
}

/* ── Utilities ───────────────────────────────────────────── */
function showError(msg) {
    Swal.fire({ icon: 'error', title: 'Error', text: msg });
}

function esc(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>
</body>
</html>