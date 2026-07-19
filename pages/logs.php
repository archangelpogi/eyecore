<?php

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Get filter values from GET parameters
$searchTerm = $_GET['search'] ?? '';
$filterModule = $_GET['module'] ?? 'all';
$filterStatus = $_GET['status'] ?? 'all';
$filterClinic = $_GET['clinic'] ?? 'all';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 10;
$offset = ($page - 1) * $limit;

// Prepare base query
$whereConditions = [];
$params = [];
$paramTypes = '';

// Build WHERE conditions
if (!empty($searchTerm)) {
    $whereConditions[] = "(u.full_name LIKE ? OR al.action LIKE ? OR al.ip_address LIKE ?)";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $params[] = "%$searchTerm%";
    $paramTypes .= 'sss';
}

if ($filterModule !== 'all') {
    $whereConditions[] = "al.module = ?";
    $params[] = $filterModule;
    $paramTypes .= 's';
}

if ($filterStatus !== 'all') {
    $whereConditions[] = "al.status = ?";
    $params[] = $filterStatus;
    $paramTypes .= 's';
}

if ($filterClinic !== 'all') {
    if ($filterClinic === 'All Clinics') {
        $whereConditions[] = "al.clinic_id IS NULL";
    } else {
        $whereConditions[] = "c.clinic_name = ?";
        $params[] = $filterClinic;
        $paramTypes .= 's';
    }
}

// Build WHERE clause
$whereClause = '';
if (!empty($whereConditions)) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

try {
    // Get total count for pagination
    $countSql = "SELECT COUNT(*) as total 
                 FROM activity_logs al
                 LEFT JOIN users u ON al.user_id = u.id
                 LEFT JOIN clinics c ON al.clinic_id = c.id
                 $whereClause";
    
    $countStmt = $pdo->prepare($countSql);
    if (!empty($params)) {
        $countStmt->execute($params);
    } else {
        $countStmt->execute();
    }
    $totalCount = $countStmt->fetchColumn();
    $totalPages = ceil($totalCount / $limit);

    // Fetch activity logs with pagination
    $logsSql = "SELECT 
                    al.id,
                    al.timestamp,
                    u.full_name as user_name,
                    u.role,
                    al.action,
                    al.module,
                    c.clinic_name,
                    al.ip_address,
                    al.status,
                    al.details
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                LEFT JOIN clinics c ON al.clinic_id = c.id
                $whereClause
                ORDER BY al.timestamp DESC
                LIMIT ? OFFSET ?";
    
    $logsParams = $params;
    $logsParams[] = $limit;
    $logsParams[] = $offset;
    $logsParamTypes = $paramTypes . 'ii';
    
    $logsStmt = $pdo->prepare($logsSql);
    
    if (!empty($params)) {
        // Bind parameters with types
        for ($i = 0; $i < count($logsParams); $i++) {
            $paramType = PDO::PARAM_STR;
            if ($i === count($params)) {
                $paramType = PDO::PARAM_INT; // limit
            } elseif ($i === count($params) + 1) {
                $paramType = PDO::PARAM_INT; // offset
            }
            $logsStmt->bindValue($i + 1, $logsParams[$i], $paramType);
        }
        $logsStmt->execute();
    } else {
        $logsStmt->execute([$limit, $offset]);
    }
    
    $logs = $logsStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get stats
    $statsSql = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as success_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN status = 'warning' THEN 1 ELSE 0 END) as warning_count
                FROM activity_logs";
    
    $statsStmt = $pdo->query($statsSql);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Get distinct modules for filter dropdown
    $modulesStmt = $pdo->query("SELECT DISTINCT module FROM activity_logs WHERE module IS NOT NULL ORDER BY module");
    $modules = $modulesStmt->fetchAll(PDO::FETCH_COLUMN);

    // Get distinct clinics for filter dropdown
    $clinicsSql = "SELECT DISTINCT c.clinic_name 
                   FROM activity_logs al
                   LEFT JOIN clinics c ON al.clinic_id = c.id
                   WHERE c.clinic_name IS NOT NULL
                   UNION
                   SELECT 'All Clinics' as clinic_name
                   WHERE EXISTS (SELECT 1 FROM activity_logs WHERE clinic_id IS NULL)
                   ORDER BY clinic_name";
    $clinicsStmt = $pdo->query($clinicsSql);
    $clinics = $clinicsStmt->fetchAll(PDO::FETCH_COLUMN);

} catch (Exception $e) {
    error_log("Error fetching activity logs: " . $e->getMessage());
    $logs = [];
    $stats = ['total' => 0, 'success_count' => 0, 'failed_count' => 0, 'warning_count' => 0];
    $modules = [];
    $clinics = [];
    $totalCount = 0;
    $totalPages = 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Activity Logs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
    body{background:#f8fafc;font-family:'Segoe UI',sans-serif}
    .card-soft{background:#fff;border:1px solid #e5e7eb;border-radius:12px}
    .icon-box{width:48px;height:48px;border-radius:10px;display:flex;align-items:center;justify-content:center}
    .gradient-btn{background:linear-gradient(to right,#0d9488,#3b82f6);color:#fff;border:none;border-radius:8px;padding:10px 24px}
    .gradient-btn:hover{transform:translateY(-2px);box-shadow:0 10px 20px rgba(13,148,136,0.2)}
    .search-input{padding-left:40px;border-radius:8px}
    .badge-status{padding:4px 12px;border-radius:20px;font-size:12px;font-weight:500}
    .badge-success{background:#d1fae5;color:#065f46}
    .badge-failed{background:#fee2e2;color:#991b1b}
    .badge-warning{background:#fef3c7;color:#92400e}
    .user-avatar{width:32px;height:32px;background:linear-gradient(to bottom right,#0d9488,#3b82f6);border-radius:50%;display:flex;align-items:center;justify-content:center}
    .table-hover tbody tr:hover{background-color:#f9fafb}
    .pagination .page-link{color:#0d9488}
    .pagination .page-item.active .page-link{background-color:#0d9488;border-color:#0d9488}
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start gap-4 mb-4">
        <div>
            <h2 class="fw-bold">Activity Logs</h2>
            <p class="text-muted mt-1">Complete audit trail of all system activities</p>
        </div>
        <button class="btn gradient-btn d-flex align-items-center gap-2" onclick="exportLogs()">
            <i data-lucide="download" style="width:20px;height:20px"></i>
            Export Logs
        </button>
    </div>

    <!-- STATS CARDS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Total Activities</div>
                    <div class="h4 fw-bold mt-1"><?= number_format($stats['total'] ?? 0) ?></div>
                </div>
                <div class="icon-box bg-teal-100">
                    <i data-lucide="activity" class="text-teal-600"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Successful</div>
                    <div class="h4 fw-bold mt-1"><?= number_format($stats['success_count'] ?? 0) ?></div>
                </div>
                <div class="icon-box bg-green-100">
                    <i data-lucide="file-text" class="text-green-600"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Failed</div>
                    <div class="h4 fw-bold mt-1"><?= number_format($stats['failed_count'] ?? 0) ?></div>
                </div>
                <div class="icon-box bg-red-100">
                    <i data-lucide="file-text" class="text-red-600"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div>
                    <div class="text-sm text-muted">Warnings</div>
                    <div class="h4 fw-bold mt-1"><?= number_format($stats['warning_count'] ?? 0) ?></div>
                </div>
                <div class="icon-box bg-yellow-100">
                    <i data-lucide="file-text" class="text-yellow-600"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- SEARCH & FILTERS -->
    <div class="card-soft p-4 mb-4">
        <form id="filterForm" method="GET" class="row g-3">
            <input type="hidden" name="page" value="1">
            <div class="col-lg-6 col-xl-4">
                <div class="position-relative">
                    <i data-lucide="search" class="position-absolute top-50 start-0 translate-middle-y ms-3 text-muted" style="width:20px;height:20px"></i>
                    <input type="text" name="search" class="form-control ps-5" placeholder="Search by user, action, or IP address..." value="<?= htmlspecialchars($searchTerm) ?>" oninput="applyFilters()">
                </div>
            </div>
            <div class="col-lg-6 col-xl-2">
                <select name="module" class="form-select" onchange="applyFilters()">
                    <option value="all">All Modules</option>
                    <?php foreach($modules as $module): ?>
                        <option value="<?= htmlspecialchars($module) ?>" <?= $filterModule==$module?'selected':'' ?>>
                            <?= htmlspecialchars($module) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-6 col-xl-2">
                <select name="clinic" class="form-select" onchange="applyFilters()">
                    <option value="all">All Clinics</option>
                    <?php foreach($clinics as $clinic): ?>
                        <option value="<?= htmlspecialchars($clinic) ?>" <?= $filterClinic==$clinic?'selected':'' ?>>
                            <?= htmlspecialchars($clinic) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-6 col-xl-2">
                <select name="status" class="form-select" onchange="applyFilters()">
                    <option value="all">All Status</option>
                    <option value="success" <?= $filterStatus=='success'?'selected':'' ?>>Success</option>
                    <option value="failed" <?= $filterStatus=='failed'?'selected':'' ?>>Failed</option>
                    <option value="warning" <?= $filterStatus=='warning'?'selected':'' ?>>Warning</option>
                </select>
            </div>
            <div class="col-lg-6 col-xl-2">
                <button type="button" class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                    <i data-lucide="rotate-ccw" style="width:16px;height:16px"></i>
                    Reset
                </button>
            </div>
        </form>
    </div>

    <!-- ACTIVITY LOGS TABLE -->
    <div class="card-soft overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="px-4 py-3 text-muted fw-medium">Timestamp</th>
                        <th class="px-4 py-3 text-muted fw-medium">User</th>
                        <th class="px-4 py-3 text-muted fw-medium d-none d-md-table-cell">Action</th>
                        <th class="px-4 py-3 text-muted fw-medium d-none d-lg-table-cell">Module</th>
                        <th class="px-4 py-3 text-muted fw-medium d-none d-lg-table-cell">Clinic</th>
                        <th class="px-4 py-3 text-muted fw-medium d-none d-xl-table-cell">IP Address</th>
                        <th class="px-4 py-3 text-muted fw-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($logs) > 0): ?>
                        <?php foreach($logs as $log): ?>
                            <tr>
                                <td class="px-4 py-3">
                                    <div class="d-flex align-items-center gap-2">
                                        <i data-lucide="calendar" style="width:16px;height:16px" class="text-muted"></i>
                                        <span class="font-monospace text-sm"><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['timestamp']))) ?></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="user-avatar">
                                            <i data-lucide="user" style="width:16px;height:16px" class="text-white"></i>
                                        </div>
                                        <div>
                                            <div class="fw-medium"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></div>
                                            <div class="text-xs text-muted"><?= htmlspecialchars($log['role'] ?? 'System') ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 d-none d-md-table-cell">
                                    <?= htmlspecialchars($log['action']) ?>
                                </td>
                                <td class="px-4 py-3 d-none d-lg-table-cell">
                                    <?= htmlspecialchars($log['module'] ?? 'System') ?>
                                </td>
                                <td class="px-4 py-3 d-none d-lg-table-cell">
                                    <div class="d-flex align-items-center gap-2">
                                        <i data-lucide="building-2" style="width:16px;height:16px" class="text-muted"></i>
                                        <?= htmlspecialchars($log['clinic_name'] ?? 'All Clinics') ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3 d-none d-xl-table-cell">
                                    <span class="font-monospace text-sm"><?= htmlspecialchars($log['ip_address'] ?? 'N/A') ?></span>
                                </td>
                                <td class="px-4 py-3">
                                    <?php
                                    $badgeClass = '';
                                    $status = $log['status'] ?? 'unknown';
                                    switch($status) {
                                        case 'success': $badgeClass = 'badge-success'; break;
                                        case 'failed': $badgeClass = 'badge-failed'; break;
                                        case 'warning': $badgeClass = 'badge-warning'; break;
                                        default: $badgeClass = 'badge-secondary'; break;
                                    }
                                    ?>
                                    <span class="badge-status <?= $badgeClass ?>">
                                        <?= ucfirst(htmlspecialchars($status)) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="px-4 py-5 text-center text-muted">
                                <i data-lucide="search-x" style="width:48px;height:48px" class="mb-3 opacity-50"></i>
                                <p class="mb-0">No activity logs found matching your criteria</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- PAGINATION -->
        <?php if($totalPages > 1): ?>
        <div class="border-top px-4 py-3">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
                <div class="text-muted">
                    Showing <span class="fw-medium"><?= min($limit, count($logs)) ?></span> of 
                    <span class="fw-medium"><?= number_format($totalCount) ?></span> log entries
                </div>
                <nav>
                    <ul class="pagination mb-0">
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" aria-label="Previous">
                                <span aria-hidden="true">&laquo;</span>
                            </a>
                        </li>
                        
                        <?php for($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                            <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                    <?= $i ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" aria-label="Next">
                                <span aria-hidden="true">&raquo;</span>
                            </a>
                        </li>
                    </ul>
                </nav>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Initialize Lucide icons
lucide.createIcons();

function showToast(msg, icon='success') {
    Swal.fire({
        toast: true,
        position: 'top-end',
        icon: icon,
        title: msg,
        showConfirmButton: false,
        timer: 3000
    });
}

function applyFilters() {
    const form = document.getElementById('filterForm');
    const search = form.search.value;
    const module = form.module.value;
    const clinic = form.clinic.value;
    const status = form.status.value;
    
    let url = 'activity_logs.php?';
    if(search) url += `search=${encodeURIComponent(search)}&`;
    if(module !== 'all') url += `module=${encodeURIComponent(module)}&`;
    if(clinic !== 'all') url += `clinic=${encodeURIComponent(clinic)}&`;
    if(status !== 'all') url += `status=${encodeURIComponent(status)}&`;
    
    window.location.href = url;
}

function resetFilters() {
    window.location.href = 'activity_logs.php';
}

function exportLogs() {
    // Build export URL with current filters
    const form = document.getElementById('filterForm');
    const search = form.search.value;
    const module = form.module.value;
    const clinic = form.clinic.value;
    const status = form.status.value;
    
    let exportUrl = 'export_logs.php?';
    if(search) exportUrl += `search=${encodeURIComponent(search)}&`;
    if(module !== 'all') exportUrl += `module=${encodeURIComponent(module)}&`;
    if(clinic !== 'all') exportUrl += `clinic=${encodeURIComponent(clinic)}&`;
    if(status !== 'all') exportUrl += `status=${encodeURIComponent(status)}&`;
    
    // Show loading
    Swal.fire({
        title: 'Exporting Logs...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading();
        }
    });
    
    // Trigger download
    const link = document.createElement('a');
    link.href = exportUrl;
    link.download = 'activity_logs_export.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    setTimeout(() => {
        Swal.close();
        showToast('Logs exported successfully!', 'success');
    }, 1000);
}
</script>
</body>
</html>