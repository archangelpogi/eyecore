<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    header('Location: ../admin/login.php');
    exit();
}

// ✅ RBAC Permission Check - MUST HAVE LOGS VIEW PERMISSION
if (!RBACHelper::hasPermission('logs_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to view Activity Logs.</p>
        </div>
    </div>
    <?php
    exit;
}

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('logs_view');
$canExport = RBACHelper::hasPermission('logs_export');
$canDelete = RBACHelper::hasPermission('logs_delete');

$clinic_id = $_SESSION['clinic_id'];
$user_role = $_SESSION['role'] ?? '';
$is_clinic_admin = ($user_role === 'ClinicAdmin');
?>

<div class="p-3">
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h5 class="fw-bold mb-0" style="color: #0d9488;">
                <i class="bi bi-journal-bookmark-fill me-2"></i>Activity Logs
            </h5>
            <small class="text-muted">System audit trail and security monitoring</small>
        </div>
    </div>

    <!-- Date Range Filter -->
    <div class="card mb-3" style="border-top: 3px solid #0d9488;">
        <div class="card-body py-2">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label small mb-0">From</label>
                    <input type="date" class="form-control form-control-sm" id="startDate" value="<?php echo date('Y-m-d', strtotime('-30 days')); ?>" style="width: 130px;">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">To</label>
                    <input type="date" class="form-control form-control-sm" id="endDate" value="<?php echo date('Y-m-d'); ?>" style="width: 130px;">
                </div>
                <div class="col-auto">
                    <label class="form-label small mb-0">Type</label>
                    <select class="form-select form-select-sm" id="logType" style="width: 140px;">
                        <option value="all">All Logs</option>
                        <option value="audit">Audit Logs</option>
                        <option value="login">Login Logs</option>
                        <option value="security">Security Events</option>
                    </select>
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-primary" onclick="loadLogs()" style="background: #0d9488; border-color: #0d9488;">
                        <i class="bi bi-search"></i> Apply
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Statistics Cards - Teal Theme -->
    <div class="row g-2 mb-3" id="statsCards">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid #0d9488;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">Total Events</small>
                            <h6 class="mb-0 fw-bold" id="statTotal">-</h6>
                        </div>
                        <i class="bi bi-journal-text fs-4" style="color: #0d9488;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid #10b981;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">Login Events</small>
                            <h6 class="mb-0 fw-bold" id="statLogin">-</h6>
                        </div>
                        <i class="bi bi-box-arrow-in-right fs-4" style="color: #10b981;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid #ef4444;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">Failed Logins</small>
                            <h6 class="mb-0 fw-bold" id="statFailed">-</h6>
                        </div>
                        <i class="bi bi-exclamation-triangle fs-4" style="color: #ef4444;"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm" style="border-left: 4px solid #f59e0b;">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <small class="text-muted">CRUD Actions</small>
                            <h6 class="mb-0 fw-bold" id="statCrud">-</h6>
                        </div>
                        <i class="bi bi-pencil-square fs-4" style="color: #f59e0b;"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Logs Table -->
    <div class="card shadow-sm">
        <div class="card-header bg-white py-2 d-flex justify-content-between align-items-center" style="border-bottom: 2px solid #0d9488;">
            <h6 class="mb-0 fw-bold" style="color: #0d9488;">
                <i class="bi bi-list-ul me-2"></i>Activity Logs
            </h6>
            <div class="d-flex gap-2">
                <div class="input-group input-group-sm" style="width: 250px;">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" class="form-control" id="searchInput" placeholder="Search logs..." onkeyup="searchLogs()">
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                <table class="table table-hover mb-0">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th width="80">ID</th>
                            <th width="150">Timestamp</th>
                            <th width="150">User</th>
                            <th width="120">Action</th>
                            <th width="150">Module/Table</th>
                            <th>Details</th>
                            <th width="130">IP Address</th>
                        </thead>
                    <tbody id="logsTable">
                         <tr>
                            <td colspan="7" class="text-center py-4 text-muted">
                                <div class="spinner-border text-teal" style="color: #0d9488; width: 1.5rem; height: 1.5rem;"></div>
                                <p class="mt-2 mb-0">Loading logs...</p>
                             </tr>
                    </tbody>
                 </table>
            </div>
        </div>
        <div class="card-footer bg-white py-2 d-flex justify-content-between align-items-center">
            <small class="text-muted" id="logCountInfo">Loading...</small>
            <div id="pagination" class="btn-group btn-group-sm">
                <!-- Pagination will be loaded -->
            </div>
        </div>
    </div>
</div>

<!-- Permission badge for view-only mode -->
<?php if (!$canExport && !$canDelete): ?>
<div class="position-fixed bottom-0 end-0 m-3">
    <div class="badge bg-secondary p-2">
        <i class="bi bi-eye me-1"></i> View Only Mode
    </div>
</div>
<?php endif; ?>

<style>
    .bg-teal { background-color: #0d9488; }
    .text-teal { color: #0d9488; }
    .btn-outline-teal {
        border-color: #0d9488;
        color: #0d9488;
    }
    .btn-outline-teal:hover {
        background-color: #0d9488;
        border-color: #0d9488;
        color: white;
    }
    .badge-action-create { background-color: #10b981; }
    .badge-action-update { background-color: #3b82f6; }
    .badge-action-delete { background-color: #ef4444; }
    .badge-action-login { background-color: #0d9488; }
    .badge-action-logout { background-color: #6b7280; }
    .badge-action-failed { background-color: #ef4444; }
    .badge-action-view { background-color: #8b5cf6; }
    .sticky-top { top: 0; z-index: 10; }
    table th { font-size: 12px; font-weight: 600; }
    table td { font-size: 13px; vertical-align: middle; }
    .card-header { background-color: white !important; }
</style>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// ✅ Pass permissions to JavaScript
const logsPermissions = {
    canView: <?= json_encode($canView) ?>,
    canExport: <?= json_encode($canExport) ?>,
    canDelete: <?= json_encode($canDelete) ?>
};

console.log('Logs Permissions:', logsPermissions);

let currentPage = 1;
let totalPages = 1;

document.addEventListener('DOMContentLoaded', function() {
    if (logsPermissions.canView) {
        loadLogs();
        
        // Auto-refresh every 30 seconds
        setInterval(() => {
            loadLogs();
        }, 30000);
    } else {
        document.getElementById('logsTable').innerHTML = `
            <tr>
                <td colspan="7" class="text-center py-4 text-danger">
                    <i class="bi bi-shield-lock fs-1 d-block mb-2"></i>
                    You don't have permission to view activity logs.
                </td>
            </tr>
        `;
        document.getElementById('statsCards').style.display = 'none';
    }
});

function loadLogs() {
    if (!logsPermissions.canView) return;
    
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const logType = document.getElementById('logType').value;
    const search = document.getElementById('searchInput').value;
    
    // Show loading state
    document.getElementById('logsTable').innerHTML = `
         <tr>
            <td colspan="7" class="text-center py-4">
                <div class="spinner-border text-teal" style="color: #0d9488; width: 1.5rem; height: 1.5rem;"></div>
                <p class="mt-2 mb-0">Loading logs...</p>
             </td>
         </tr>
    `;
    
    fetch(`api/logs.php?action=get_logs&clinic_id=<?php echo $clinic_id; ?>&start_date=${startDate}&end_date=${endDate}&type=${logType}&search=${encodeURIComponent(search)}&page=${currentPage}`)
        .then(async response => {
            const text = await response.text();
            try {
                return JSON.parse(text);
            } catch(e) {
                console.error('Invalid JSON response:', text.substring(0, 500));
                throw new Error('Server returned invalid response');
            }
        })
        .then(data => {
            if(data.success) {
                renderLogsTable(data.logs);
                updateStatistics(data.stats);
                updatePagination(data.pagination);
            } else {
                document.getElementById('logsTable').innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted">${data.message || 'No logs found'}</td></tr>`;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('logsTable').innerHTML = `<tr><td colspan="7" class="text-center py-4 text-danger"><i class="bi bi-exclamation-triangle me-2"></i>Failed to load logs. Please check if the API is working.</td></tr>`;
            // Update stats to show zeros
            updateStatistics({ total: 0, login: 0, failed: 0, crud: 0 });
        });
}

function renderLogsTable(logs) {
    const tbody = document.getElementById('logsTable');
    
    if(!logs || logs.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center py-4 text-muted"><i class="bi bi-journal-x me-2"></i>No activity logs found</td></tr>`;
        return;
    }
    
    tbody.innerHTML = logs.map(log => {
        let badgeClass = 'secondary';
        let badgeIcon = 'bi-info-circle';
        
        // Determine badge style based on action
        const actionLower = (log.action || '').toLowerCase();
        if(actionLower.includes('create') || actionLower.includes('insert')) {
            badgeClass = 'success';
            badgeIcon = 'bi-file-plus';
        } else if(actionLower.includes('update') || actionLower.includes('edit')) {
            badgeClass = 'primary';
            badgeIcon = 'bi-pencil';
        } else if(actionLower.includes('delete') || actionLower.includes('remove')) {
            badgeClass = 'danger';
            badgeIcon = 'bi-trash';
        } else if(actionLower.includes('login') && !actionLower.includes('failed')) {
            badgeClass = 'teal';
            badgeIcon = 'bi-box-arrow-in-right';
        } else if(actionLower.includes('logout')) {
            badgeClass = 'secondary';
            badgeIcon = 'bi-box-arrow-left';
        } else if(actionLower.includes('failed')) {
            badgeClass = 'danger';
            badgeIcon = 'bi-exclamation-triangle';
        } else if(actionLower.includes('view')) {
            badgeClass = 'info';
            badgeIcon = 'bi-eye';
        }
        
        // Format timestamp
        let timestamp = log.created_at || log.timestamp;
        if(timestamp) {
            const date = new Date(timestamp);
            timestamp = date.toLocaleString();
        }
        
        // Truncate details if too long
        let details = log.details || log.description || '-';
        if(details.length > 80) details = details.substring(0, 80) + '...';
        
        // Get user name
        const userName = log.user_name || log.user || 'System';
        
        return `
            <tr>
                <td class="text-muted small">${log.id}</td>
                <td class="small">${timestamp}</td>
                <td><span class="fw-medium">${escapeHtml(userName)}</span><br><small class="text-muted">${log.role || ''}</small></td>
                <td><span class="badge bg-${badgeClass}"><i class="bi ${badgeIcon} me-1"></i>${log.action || 'N/A'}</span></td>
                <td><span class="badge bg-light text-dark">${log.table_name || log.module || 'N/A'}</span></td>
                <td class="small text-muted" title="${escapeHtml(log.details || '')}">${escapeHtml(details)}</td>
                <td><code class="small">${log.ip_address || '-'}</code></td>
            </tr>
        `;
    }).join('');
}

function updateStatistics(stats) {
    if(stats) {
        document.getElementById('statTotal').innerText = formatNumber(stats.total || 0);
        document.getElementById('statLogin').innerText = formatNumber(stats.login || 0);
        document.getElementById('statFailed').innerText = formatNumber(stats.failed || 0);
        document.getElementById('statCrud').innerText = formatNumber(stats.crud || 0);
        document.getElementById('logCountInfo').innerHTML = `<i class="bi bi-database me-1"></i>${formatNumber(stats.total || 0)} records`;
    }
}

function updatePagination(pagination) {
    if(!pagination) return;
    
    totalPages = pagination.total_pages || 1;
    currentPage = pagination.current_page || 1;
    
    const container = document.getElementById('pagination');
    let html = '';
    
    // Previous button
    html += `<button class="btn btn-sm btn-outline-secondary" onclick="goToPage(${currentPage - 1})" ${currentPage <= 1 ? 'disabled' : ''}><i class="bi bi-chevron-left"></i></button>`;
    
    // Page numbers
    let startPage = Math.max(1, currentPage - 2);
    let endPage = Math.min(totalPages, currentPage + 2);
    
    if(startPage > 1) {
        html += `<button class="btn btn-sm btn-outline-secondary" onclick="goToPage(1)">1</button>`;
        if(startPage > 2) html += `<button class="btn btn-sm btn-outline-secondary disabled">...</button>`;
    }
    
    for(let i = startPage; i <= endPage; i++) {
        html += `<button class="btn btn-sm ${i === currentPage ? 'btn-primary' : 'btn-outline-secondary'}" onclick="goToPage(${i})" style="${i === currentPage ? 'background: #0d9488; border-color: #0d9488;' : ''}">${i}</button>`;
    }
    
    if(endPage < totalPages) {
        if(endPage < totalPages - 1) html += `<button class="btn btn-sm btn-outline-secondary disabled">...</button>`;
        html += `<button class="btn btn-sm btn-outline-secondary" onclick="goToPage(${totalPages})">${totalPages}</button>`;
    }
    
    // Next button
    html += `<button class="btn btn-sm btn-outline-secondary" onclick="goToPage(${currentPage + 1})" ${currentPage >= totalPages ? 'disabled' : ''}><i class="bi bi-chevron-right"></i></button>`;
    
    container.innerHTML = html;
}

function goToPage(page) {
    if(page < 1 || page > totalPages) return;
    currentPage = page;
    loadLogs();
}

function searchLogs() {
    currentPage = 1;
    loadLogs();
}

function exportLogs() {
    // ✅ Check export permission
    if (!logsPermissions.canExport) {
        Swal.fire('Access Denied', 'You don\'t have permission to export logs.', 'warning');
        return;
    }
    
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const logType = document.getElementById('logType').value;
    
    Swal.fire({
        title: 'Exporting Logs...',
        text: `Exporting ${logType} logs from ${startDate} to ${endDate}`,
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    fetch(`api/logs.php?action=export&clinic_id=<?php echo $clinic_id; ?>&start_date=${startDate}&end_date=${endDate}&type=${logType}`)
        .then(res => res.blob())
        .then(blob => {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `activity_logs_${startDate}_to_${endDate}.csv`;
            document.body.appendChild(a);
            a.click();
            URL.revokeObjectURL(url);
            document.body.removeChild(a);
            
            Swal.fire({
                icon: 'success',
                title: 'Export Complete',
                text: 'Logs exported successfully',
                timer: 1500,
                showConfirmButton: false
            });
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to export logs', 'error');
        });
}

function formatNumber(num) {
    return num ? num.toLocaleString() : '0';
}

function escapeHtml(text) {
    if(!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>