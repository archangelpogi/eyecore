<?php
include __DIR__ . '/../config/db.php';

// --- Only for SuperAdmin ---
$currentRole = $_SESSION['role'] ?? 'SuperAdmin';
if ($currentRole != 'SuperAdmin') {
    die("Access Denied");
}

// --- Fetch initial stats ---
$totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE status='Active'")->fetchColumn();
$adminUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='ClinicAdmin'")->fetchColumn();
$optometristUsers = $pdo->query("SELECT COUNT(*) FROM users WHERE role='Optometrist'")->fetchColumn();

function e($v) { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>User Management - Super Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Bootstrap -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Lucide icons -->
<script src="https://unpkg.com/lucide@0.469.0/dist/umd/lucide.min.js"></script>

<!-- DataTables (Bootstrap 5 theme) & Buttons -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.bootstrap5.min.css">

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<style>
:root {
    --primary:#0F766E; --secondary:#0D9488; --bg:#F8FAFC; --card:#fff;
    --text:#0F172A; --muted:#64748B; --border:#E2E8F0; --radius:12px;
}
body { background:var(--bg); color:var(--text); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; font-size:.9rem; }
.text-muted { color:var(--muted) !important; }

.page-title { font-size:1.25rem; font-weight:700; margin:0; }
.page-desc { color:var(--muted); font-size:.85rem; margin:2px 0 0; }

/* Stat cards */
.kcard { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); padding:12px 14px; height:100%; display:flex; justify-content:space-between; align-items:center; }
.klabel { color:var(--muted); font-size:.74rem; font-weight:600; text-transform:uppercase; letter-spacing:.03em; }
.kvalue { font-size:1.55rem; font-weight:700; line-height:1.25; font-variant-numeric:tabular-nums; }
.kicon { width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.kicon svg { width:16px; height:16px; }

/* Single panel: toolbar + table */
.panel { background:var(--card); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.toolbar { display:flex; flex-wrap:wrap; gap:8px; align-items:center; padding:12px 14px; border-bottom:1px solid var(--border); }
.search-box { position:relative; flex:1 1 240px; max-width:340px; }
.search-box svg { position:absolute; left:10px; top:50%; transform:translateY(-50%); width:14px; height:14px; color:var(--muted); pointer-events:none; }
.search-box input { padding-left:32px; }
.toolbar .form-select { width:auto; min-width:150px; }
.toolbar .spacer { flex:1 1 auto; }
.form-control:focus, .form-select:focus { border-color:var(--secondary); box-shadow:0 0 0 .2rem rgba(13,148,136,.2); }

/* Table */
#usersTable { margin:0 !important; border-collapse:collapse !important; }
#usersTable thead th { font-size:.7rem; font-weight:700; letter-spacing:.04em; text-transform:uppercase; color:var(--muted); background:#F8FAFC; border-bottom:1px solid var(--border); padding:9px 14px; white-space:nowrap; }
#usersTable tbody td { padding:10px 14px; border-color:#EEF2F6; vertical-align:middle; }
#usersTable.table-hover tbody tr:hover { background:#F8FAFC; }
.user-avatar { width:32px; height:32px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:12px; color:#fff; flex-shrink:0; }
.user-name { font-weight:600; }

/* Badges */
.badge { font-weight:600; font-size:.72rem; padding:.35em .65em; border-radius:6px; }
.badge-superadmin { background:#FEF3C7; color:#92400E; }
.badge-clinicadmin { background:#DBEAFE; color:#1E40AF; }
.badge-optometrist { background:#DCFCE7; color:#166534; }
.badge-staff { background:#F1F5F9; color:#475569; }
.badge-secondary { background:#F1F5F9; color:#475569; }
.badge-active, .badge-inactive { display:inline-flex; align-items:center; gap:6px; }
.badge-active::before, .badge-inactive::before { content:''; width:6px; height:6px; border-radius:50%; background:currentColor; }
.badge-active { background:#DCFCE7; color:#166534; }
.badge-inactive { background:#F1F5F9; color:#64748B; }

/* DataTables footer + buttons */
.dt-footer { display:flex; flex-wrap:wrap; justify-content:space-between; align-items:center; gap:8px; padding:10px 14px; border-top:1px solid var(--border); }
.dataTables_info { padding:0 !important; color:var(--muted); font-size:.8rem; }
.dataTables_paginate { padding:0 !important; }
.page-item.active .page-link { background:var(--secondary); border-color:var(--secondary); }
.page-link { color:var(--primary); font-size:.8rem; }
div.dt-buttons .btn { font-size:.78rem; padding:.25rem .6rem; background:#fff; border:1px solid var(--border); color:var(--text); }
div.dt-buttons .btn:hover { background:#F8FAFC; }
@media (max-width:575.98px) { .search-box, .toolbar .form-select { flex:1 1 100%; max-width:none; } .toolbar .spacer { display:none; } }
</style>
</head>
<body>
<div class="container-fluid py-4 px-3 px-lg-4">

<!-- HEADER -->
<div class="mb-3">
    <h1 class="page-title">User Management</h1>
    <p class="page-desc">View all system users</p>
</div>

<!-- STATS -->
<div class="row g-2 g-md-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="kcard">
            <div><div class="klabel">Total Users</div><div id="totalUsers" class="kvalue"><?= e($totalUsers) ?></div></div>
            <div class="kicon" style="background:#F0FDFA;color:#0D9488;"><i data-lucide="users"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kcard">
            <div><div class="klabel">Active Users</div><div id="activeUsers" class="kvalue"><?= e($activeUsers) ?></div></div>
            <div class="kicon" style="background:#DCFCE7;color:#16A34A;"><i data-lucide="user-check"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kcard">
            <div><div class="klabel">Admins</div><div id="adminUsers" class="kvalue"><?= e($adminUsers) ?></div></div>
            <div class="kicon" style="background:#DBEAFE;color:#2563EB;"><i data-lucide="shield"></i></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="kcard">
            <div><div class="klabel">Optometrists</div><div id="optometristUsers" class="kvalue"><?= e($optometristUsers) ?></div></div>
            <div class="kicon" style="background:#EDE9FE;color:#7C3AED;"><i data-lucide="eye"></i></div>
        </div>
    </div>
</div>

<!-- TOOLBAR + USERS TABLE (one panel) -->
<div class="panel">
    <div class="toolbar">
        <div class="search-box">
            <i data-lucide="search"></i>
            <input type="text" id="searchInput" class="form-control form-control-sm" placeholder="Search name, email, or role…">
        </div>
        <select id="clinicFilter" class="form-select form-select-sm">
            <option value="">All Clinics</option>
            <?php
            $stmt = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name ASC");
            foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                echo "<option value='" . e($c['clinic_name']) . "'>" . e($c['clinic_name']) . "</option>";
            }
            ?>
        </select>
        <select id="roleFilter" class="form-select form-select-sm">
            <option value="">All Roles</option>
            <option value="SuperAdmin">Super Admin</option>
            <option value="ClinicAdmin">Clinic Admin</option>
            <option value="Optometrist">Optometrist</option>
            <option value="Staff">Staff</option>
        </select>
        <select id="statusFilter" class="form-select form-select-sm">
            <option value="">All Status</option>
            <option value="Active">Active</option>
            <option value="Inactive">Inactive</option>
        </select>
        <span class="spacer"></span>
        <div id="exportBtns"></div>
    </div>

    <div class="table-responsive">
        <table id="usersTable" class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>User</th>
                    <th>Contact</th>
                    <th>Role</th>
                    <th>Clinic</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->query("SELECT u.first_name, u.last_name, u.email, u.role, u.status, c.clinic_name
                                     FROM users u
                                     LEFT JOIN clinics c ON u.clinic_id = c.id
                                     ORDER BY u.first_name ASC");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

                function avatarColor($name) {
                    $colors = ['#0D9488', '#2563EB', '#7C3AED', '#DB2777', '#D97706'];
                    return $colors[crc32($name) % count($colors)];
                }

                foreach($users as $u) {
                    $fullName = $u['first_name'].' '.$u['last_name'];
                    $initials = strtoupper(substr($u['first_name'],0,1) . substr($u['last_name'],0,1));
                    $roleBadge = match($u['role']) {
                        'SuperAdmin' => 'badge-superadmin',
                        'ClinicAdmin' => 'badge-clinicadmin',
                        'Optometrist' => 'badge-optometrist',
                        'Staff' => 'badge-staff',
                        default => 'badge-secondary'
                    };
                    $statusBadge = $u['status']=='Active'?'badge-active':'badge-inactive';
                    $avatarBg = avatarColor($fullName);
                    $clinic = $u['clinic_name'] ? e($u['clinic_name']) : '—';

                    echo "<tr>
                            <td><div class='d-flex align-items-center gap-2'><div class='user-avatar' style='background: {$avatarBg}'>" . e($initials) . "</div><span class='user-name'>" . e($fullName) . "</span></div></td>
                            <td class='text-muted'>" . e($u['email']) . "</td>
                            <td><span class='badge {$roleBadge}'>" . e($u['role']) . "</span></td>
                            <td>{$clinic}</td>
                            <td><span class='badge {$statusBadge}'>" . e($u['status']) . "</span></td>
                          </tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>lucide.createIcons();</script>

<script>
$(document).ready(function () {
    // Built-in search box removed from layout (we use our own #searchInput) to avoid a duplicate search bar
    const table = $('#usersTable').DataTable({
        dom: 'Brt<"dt-footer"ip>',
        buttons: ['copy', 'excel', 'print'],
        pageLength: 10,
        language: { emptyTable: 'No users found.', zeroRecords: 'No users match your filters.' }
    });

    // Move export buttons into the toolbar
    table.buttons().container().appendTo('#exportBtns');

    // Exact-match helper so "Active" doesn't also match "Inactive"
    const exact = v => v ? '^' + $.fn.dataTable.util.escapeRegex(v) + '$' : '';

    // Filters
    $('#clinicFilter').on('change', function () { table.column(3).search(exact(this.value), true, false).draw(); });
    $('#roleFilter').on('change', function () { table.column(2).search(exact(this.value), true, false).draw(); });
    $('#statusFilter').on('change', function () { table.column(4).search(exact(this.value), true, false).draw(); });
    $('#searchInput').on('keyup', function () { table.search(this.value).draw(); });
});
</script>

</body>
</html>