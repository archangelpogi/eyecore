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
<script src="https://unpkg.com/lucide@latest"></script>

<!-- DataTables CSS & Buttons -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">

<!-- jQuery & DataTables JS -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>

<style>
body { background:#f8fafc; font-family: 'Inter', sans-serif; }
.card-soft { background:#fff; border:1px solid #e5e7eb; border-radius:12px; }
.icon-box { width:48px; height:48px; border-radius:10px; display:flex; align-items:center; justify-content:center; }
.table-hover tbody tr:hover { background:#f9fafb; }
.badge-superadmin { background:#fef3c7; color:#92400e; }
.badge-clinicadmin { background:#dbeafe; color:#1e40af; }
.badge-optometrist { background:#dcfce7; color:#166534; }
.badge-staff { background:#e5e7eb; color:#374151; }
.badge-active { background:#dcfce7; color:#166534; }
.badge-inactive { background:#f3f4f6; color:#374151; }
.user-avatar { width:40px; height:40px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-weight:600; font-size:14px; color:white; }
.table td, .table th { vertical-align: middle; }
.form-control:focus, .form-select:focus { border-color:#0d9488; box-shadow:0 0 0 0.25rem rgba(13, 148, 136, 0.25); }
</style>
</head>
<body>
<div class="container-fluid p-4">

<!-- HEADER -->
<div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
    <div>
        <h2 class="fw-bold">User Management</h2>
        <p class="text-muted">View all system users</p>
    </div>
</div>

<!-- STATS -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card-soft p-4 d-flex justify-content-between">
            <div><small class="text-muted">Total Users</small><h4 id="totalUsers" class="fw-bold mt-1"><?= $totalUsers ?></h4></div>
            <div class="icon-box bg-teal-100"><i data-lucide="users" class="text-success"></i></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-soft p-4 d-flex justify-content-between">
            <div><small class="text-muted">Active Users</small><h4 id="activeUsers" class="fw-bold mt-1"><?= $activeUsers ?></h4></div>
            <div class="icon-box bg-green-100"><i data-lucide="user-check" class="text-success"></i></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-soft p-4 d-flex justify-content-between">
            <div><small class="text-muted">Admins</small><h4 id="adminUsers" class="fw-bold mt-1"><?= $adminUsers ?></h4></div>
            <div class="icon-box bg-blue-100"><i data-lucide="shield" class="text-primary"></i></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-soft p-4 d-flex justify-content-between">
            <div><small class="text-muted">Optometrists</small><h4 id="optometristUsers" class="fw-bold mt-1"><?= $optometristUsers ?></h4></div>
            <div class="icon-box bg-purple-100"><i data-lucide="eye" class="text-purple"></i></div>
        </div>
    </div>
</div>

<!-- FILTERS -->
<div class="card-soft p-3 mb-4">
    <div class="row g-3">
        <div class="col-md-4"><input type="text" id="searchInput" class="form-control" placeholder="Search by name, email, or role..."></div>
        <div class="col-md-4">
            <select id="clinicFilter" class="form-select">
                <option value="">All Clinics</option>
                <?php
                $stmt = $pdo->query("SELECT id, clinic_name FROM clinics ORDER BY clinic_name ASC");
                foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    echo "<option value='{$c['clinic_name']}'>{$c['clinic_name']}</option>";
                }
                ?>
            </select>
        </div>
        <div class="col-md-4">
            <select id="roleFilter" class="form-select">
                <option value="">All Roles</option>
                <option value="SuperAdmin">Super Admin</option>
                <option value="ClinicAdmin">Clinic Admin</option>
                <option value="Optometrist">Optometrist</option>
                <option value="Staff">Staff</option>
            </select>
        </div>
    </div>
</div>

<!-- USERS TABLE -->
<div class="card-soft overflow-hidden">
    <div class="table-responsive">
        <table id="usersTable" class="table table-hover mb-0">
            <thead class="bg-light">
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
                    $colors = [
                        'linear-gradient(135deg,#0d9488,#3b82f6)',
                        'linear-gradient(135deg,#f97316,#facc15)',
                        'linear-gradient(135deg,#9333ea,#8b5cf6)',
                        'linear-gradient(135deg,#ec4899,#f43f5e)',
                        'linear-gradient(135deg,#10b981,#14b8a6)'
                    ];
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

                    echo "<tr>
                            <td><div class='d-flex align-items-center gap-2'><div class='user-avatar' style='background: {$avatarBg}'>{$initials}</div><span>{$fullName}</span></div></td>
                            <td>{$u['email']}</td>
                            <td><span class='badge {$roleBadge}'>{$u['role']}</span></td>
                            <td>{$u['clinic_name']}</td>
                            <td><span class='badge {$statusBadge}'>{$u['status']}</span></td>
                          </tr>";
                }
                ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>lucide.createIcons();</script>

<script>
$(document).ready(function () {
    // Initialize DataTable only once
    const table = $('#usersTable').DataTable({
        dom: 'Bfrtip',
        buttons: ['copy', 'excel', 'print'],
        pageLength: 10
    });

    // Filters
    $('#clinicFilter').on('change', function () { table.column(3).search(this.value).draw(); });
    $('#roleFilter').on('change', function () { table.column(2).search(this.value).draw(); });
    $('#searchInput').on('keyup', function () { table.search(this.value).draw(); });
});
</script>

</body>
</html>
