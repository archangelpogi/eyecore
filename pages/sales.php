<?php

require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    
    try {
        switch($action) {
            case 'add':
                $patient_id = $_POST['patient_id'] ?? '';
                $clinic = $_POST['clinic'] ?? '';
                $items = isset($_POST['items']) ? (is_array($_POST['items']) ? $_POST['items'] : json_decode($_POST['items'], true)) : [];
                $amount = (float)($_POST['amount'] ?? 0);
                $status = $_POST['status'] ?? 'pending';
                
                if (empty($patient_id) || empty($clinic) || empty($items) || $amount <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                    exit();
                }
                
                // Generate invoice ID
                $invoice_id = 'INV-' . date('Y-m-d') . '-' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Get clinic ID
                $stmt = $pdo->prepare("SELECT id FROM clinics WHERE clinic_name = ?");
                $stmt->execute([$clinic]);
                $clinic_data = $stmt->fetch();
                $clinic_id = $clinic_data['id'] ?? 1;
                
                // Convert status
                $db_status = match($status) {
                    'paid' => 'Paid',
                    'pending' => 'Partial',
                    'overdue' => 'Unpaid',
                    default => 'Partial'
                };
                
                $stmt = $pdo->prepare("INSERT INTO invoices (invoice_code, patient_id, invoice_date, items, subtotal, total, status, clinic_id) 
                                      VALUES (?, (SELECT id FROM patients WHERE patient_code = ?), CURDATE(), ?, ?, ?, ?, ?)");
                $stmt->execute([
                    $invoice_id, 
                    $patient_id, 
                    json_encode($items), 
                    $amount,
                    $amount,
                    $db_status, 
                    $clinic_id
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Invoice created!']);
                break;
                
            case 'update_status':
                $status = $_POST['status'] ?? '';
                $db_status = match($status) {
                    'paid' => 'Paid',
                    'pending' => 'Partial',
                    'overdue' => 'Unpaid',
                    default => 'Partial'
                };
                
                $stmt = $pdo->prepare("UPDATE invoices SET status = ? WHERE id = ?");
                $stmt->execute([$db_status, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Status updated!']);
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
                $stmt->execute([$id]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Invoice deleted!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Invoice not found']);
                }
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Load invoices
$search = $_GET['search'] ?? '';
$clinic_filter = $_GET['clinic'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Get clinics and patients
$clinics = $pdo->query("SELECT clinic_name FROM clinics WHERE status='Active'")->fetchAll(PDO::FETCH_COLUMN);
$patients = $pdo->query("SELECT patient_code, CONCAT(first_name, ' ', last_name) as name FROM patients WHERE status='Active'")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$sql = "SELECT i.*, p.first_name, p.last_name, p.patient_code, c.clinic_name 
        FROM invoices i 
        LEFT JOIN patients p ON i.patient_id = p.id 
        LEFT JOIN clinics c ON i.clinic_id = c.id 
        WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR i.invoice_code LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if ($clinic_filter != 'all') {
    $sql .= " AND c.clinic_name = ?";
    $params[] = $clinic_filter;
}
if ($status_filter != 'all') {
    $db_status = match($status_filter) {
        'paid' => 'Paid',
        'pending' => 'Partial',
        'overdue' => 'Unpaid',
        default => 'Partial'
    };
    $sql .= " AND i.status = ?";
    $params[] = $db_status;
}

$sql .= " ORDER BY i.invoice_date DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Convert database status to React format and fix items display
foreach($invoices as &$inv) {
    $inv['status_react'] = match($inv['status']) {
        'Paid' => 'paid',
        'Partial' => 'pending',
        'Unpaid' => 'overdue',
        default => 'pending'
    };
    
    // Decode items JSON safely
    $items_array = json_decode($inv['items'] ?? '[]', true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($items_array)) {
        $inv['items_array'] = $items_array;
        // Only get item names for table display
        $inv['items_text'] = implode(', ', array_map(fn($i) => $i['name'], $items_array));
    } else {
        $inv['items_array'] = [];
        $inv['items_text'] = '';
    }
}

// Calculate stats
$totalRevenue = array_sum(array_column($invoices, 'total'));
$paidRevenue = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] == 'Paid'), 'total'));
$pendingRevenue = array_sum(array_column(array_filter($invoices, fn($i) => $i['status'] == 'Partial'), 'total'));
$totalInvoices = count($invoices);
?>

<!-- Rest of your HTML remains unchanged -->

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales & Billing</title>
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
    .badge-paid{background:#dcfce7;color:#166534;padding:6px 12px;border-radius:20px}
    .badge-pending{background:#fef3c7;color:#92400e;padding:6px 12px;border-radius:20px}
    .badge-overdue{background:#fee2e2;color:#991b1b;padding:6px 12px;border-radius:20px}
    .search-input{padding-left:40px;border-radius:8px}
    </style>
</head>
<body>

<!-- ... THE REST OF YOUR HTML AND JS REMAINS UNCHANGED ... -->


<div class="container-fluid p-4">
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Sales & Billing</h2>
            <p class="text-muted">Multi-clinic invoice and revenue management</p>
        </div>
        <button class="btn gradient-btn px-4 py-2 d-flex align-items-center gap-2" onclick="openAddModal()">
            <i data-lucide="file-text"></i> New Invoice
        </button>
    </div>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Total Revenue</div><div class="stats-number">₱<?= number_format($totalRevenue, 2) ?></div></div><div class="icon-box bg-teal-100"><i data-lucide="dollar-sign" class="text-teal-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Paid</div><div class="stats-number">₱<?= number_format($paidRevenue, 2) ?></div></div><div class="icon-box bg-green-100"><i data-lucide="trending-up" class="text-green-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Pending</div><div class="stats-number">₱<?= number_format($pendingRevenue, 2) ?></div></div><div class="icon-box bg-yellow-100"><i data-lucide="file-text" class="text-yellow-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Total Invoices</div><div class="stats-number"><?= $totalInvoices ?></div></div><div class="icon-box bg-blue-100"><i data-lucide="shopping-cart" class="text-blue-600"></i></div></div></div>
    </div>

    <!-- SEARCH & FILTERS -->
    <div class="card-soft p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-5 position-relative"><i data-lucide="search" class="position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i><input type="text" id="searchInput" class="form-control search-input ps-5" placeholder="Search by patient or invoice number..." value="<?= htmlspecialchars($search) ?>"></div>
            <div class="col-md-3"><select id="clinicFilter" class="form-select"><option value="all">All Clinics</option><?php foreach($clinics as $c): ?><option value="<?= htmlspecialchars($c) ?>" <?= $clinic_filter==$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><select id="statusFilter" class="form-select"><option value="all">All Status</option><option value="paid" <?= $status_filter=='paid'?'selected':'' ?>>Paid</option><option value="pending" <?= $status_filter=='pending'?'selected':'' ?>>Pending</option><option value="overdue" <?= $status_filter=='overdue'?'selected':'' ?>>Overdue</option></select></div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100" onclick="resetFilters()"><i data-lucide="refresh-ccw" class="me-2"></i>Reset</button></div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="card-soft overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4">Invoice #</th>
                        <th>Patient Name</th>
                        <th class="d-none d-md-table-cell">Items</th>
                        <th>Clinic</th>
                        <th class="d-none d-lg-table-cell">Date</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody id="invoicesTableBody">
                    <?php if(empty($invoices)): ?>
                    <tr><td colspan="8" class="text-center py-5"><div class="d-flex flex-column align-items-center"><i data-lucide="file-text" class="text-muted mb-3" style="width:48px;height:48px"></i><h5 class="text-muted">No invoices found</h5></div></td></tr>
                    <?php else: foreach($invoices as $inv): 
                        $badge = 'badge-' . $inv['status_react'];
                        $patient_name = htmlspecialchars($inv['first_name'] . ' ' . $inv['last_name']);
                        $items_text = htmlspecialchars($inv['items_text']);
                    ?>
                    <tr id="invoice-<?= $inv['id'] ?>">
                        <td class="ps-4"><span class="fw-semibold text-muted"><?= htmlspecialchars($inv['invoice_code']) ?></span></td>
                        <td><p class="fw-medium mb-0"><?= $patient_name ?></p></td>
                        <td class="d-none d-md-table-cell"><p class="text-sm text-muted"><?= $items_text ?></p></td>
                        <td><div class="d-flex align-items-center gap-2 text-sm"><i data-lucide="building-2" class="text-muted" style="width:16px;height:16px"></i><?= htmlspecialchars($inv['clinic_name'] ?? 'N/A') ?></div></td>
                        <td class="d-none d-lg-table-cell"><p class="text-sm text-muted"><?= date('Y-m-d', strtotime($inv['invoice_date'])) ?></p></td>
                        <td><p class="fw-semibold mb-0">₱<?= number_format($inv['total'], 2) ?></p></td>
                        <td><span class="<?= $badge ?>"><?= ucfirst($inv['status_react']) ?></span></td>
                        <td class="text-end pe-4">
                            <div class="btn-group">
                                <button class="btn btn-sm btn-outline-primary" onclick="viewInvoice(<?= $inv['id'] ?>)"><i data-lucide="eye" style="width:16px;height:16px"></i></button>
                                <button class="btn btn-sm btn-outline-success" onclick="updateStatus(<?= $inv['id'] ?>, 'paid')"><i data-lucide="check" style="width:16px;height:16px"></i></button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteInvoice(<?= $inv['id'] ?>,'<?= addslashes($patient_name) ?>')"><i data-lucide="trash-2" style="width:16px;height:16px"></i></button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL ADD INVOICE -->
<div class="modal fade" id="invoiceModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">New Invoice</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="invoiceForm" onsubmit="return false;">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Patient *</label>
                        <select name="patient_id" class="form-select" required>
                            <option value="">Select Patient</option>
                            <?php foreach($patients as $p): ?>
                            <option value="<?= htmlspecialchars($p['patient_code']) ?>"><?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['patient_code']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Clinic *</label>
                        <select name="clinic" class="form-select" required>
                            <option value="">Select Clinic</option>
                            <?php foreach($clinics as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Items *</label>
                        <div id="itemsContainer">
                            <div class="input-group mb-2">
                                <input type="text" class="form-control item-input" placeholder="Item name" name="items[]" required>
                                <button type="button" class="btn btn-outline-danger" onclick="removeItem(this)"><i data-lucide="x"></i></button>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary mt-1" onclick="addItem()"><i data-lucide="plus" class="me-1"></i>Add Item</button>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Amount *</label>
                            <input type="number" name="amount" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="pending">Pending</option>
                                <option value="paid">Paid</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn gradient-btn" onclick="saveInvoice()">
                    <span id="saveBtnText">Create Invoice</span>
                    <span id="saveBtnSpinner" class="spinner-border spinner-border-sm d-none"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const invoiceModal = new bootstrap.Modal(document.getElementById('invoiceModal'));
let itemCount = 1;

function showToast(msg,icon='success'){
    Swal.fire({toast:true,position:'top-end',icon,title:msg,showConfirmButton:false,timer:3000})
}

function openAddModal(){
    document.getElementById('invoiceForm').reset();
    document.getElementById('itemsContainer').innerHTML = '<div class="input-group mb-2"><input type="text" class="form-control item-input" placeholder="Item name" name="items[]" required><button type="button" class="btn btn-outline-danger" onclick="removeItem(this)"><i data-lucide="x"></i></button></div>';
    itemCount = 1;
    invoiceModal.show();
}

function addItem(){
    const container = document.getElementById('itemsContainer');
    const div = document.createElement('div');
    div.className = 'input-group mb-2';
    div.innerHTML = `<input type="text" class="form-control item-input" placeholder="Item name" name="items[]" required><button type="button" class="btn btn-outline-danger" onclick="removeItem(this)"><i data-lucide="x"></i></button>`;
    container.appendChild(div);
    itemCount++;
    lucide.createIcons();
}

function removeItem(btn){
    if(itemCount > 1){
        btn.parentElement.remove();
        itemCount--;
    }
}

async function saveInvoice(){
    const form = document.getElementById('invoiceForm');
    
    // Validate form
    let isValid = true;
    const requiredFields = form.querySelectorAll('[required]');
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            field.classList.add('is-invalid');
            isValid = false;
        } else {
            field.classList.remove('is-invalid');
        }
    });
    
    if (!isValid) {
        showToast('Please fill in all required fields', 'error');
        return;
    }
    
    // Get items
    const items = Array.from(form.querySelectorAll('input[name="items[]"]'))
        .map(input => input.value.trim())
        .filter(val => val);
    
    if (items.length === 0) {
        showToast('Please add at least one item', 'error');
        return;
    }
    
    // Show loading state
    const saveBtn = document.querySelector('#invoiceModal .btn.gradient-btn');
    const saveBtnText = document.getElementById('saveBtnText');
    const saveBtnSpinner = document.getElementById('saveBtnSpinner');
    
    saveBtn.disabled = true;
    saveBtnText.classList.add('d-none');
    saveBtnSpinner.classList.remove('d-none');
    
    try {
        const formData = new FormData(form);
        formData.append('items', JSON.stringify(items));
        
        const res = await fetch('sales.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        
        if (data.success) {
            invoiceModal.hide();
            setTimeout(() => location.reload(), 1500);
        }
    } catch (e) {
        console.error('Error:', e);
        showToast('Error creating invoice', 'error');
    } finally {
        // Reset loading state
        saveBtn.disabled = false;
        saveBtnText.classList.remove('d-none');
        saveBtnSpinner.classList.add('d-none');
    }
}

function viewInvoice(id){
    window.open(`view-invoice.php?id=${id}`, '_blank');
}

async function updateStatus(id, status){
    try {
        const formData = new FormData();
        formData.append('action', 'update_status');
        formData.append('id', id);
        formData.append('status', status);
        
        const res = await fetch('sales.php', {
            method: 'POST',
            body: formData
        });
        
        const data = await res.json();
        showToast(data.message, data.success ? 'success' : 'error');
        
        if (data.success) {
            setTimeout(() => location.reload(), 1500);
        }
    } catch (e) {
        console.error('Error:', e);
        showToast('Error updating status', 'error');
    }
}

async function deleteInvoice(id, name){
    const result = await Swal.fire({
        title: 'Delete Invoice?',
        html: `Are you sure you want to delete invoice for <strong>${name}</strong>?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    });
    
    if (result.isConfirmed) {
        try {
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', id);
            
            const res = await fetch('sales.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await res.json();
            showToast(data.message, data.success ? 'success' : 'error');
            
            if (data.success) {
                setTimeout(() => location.reload(), 1500);
            }
        } catch (e) {
            console.error('Error:', e);
            showToast('Error deleting invoice', 'error');
        }
    }
}

function applyFilters(){
    const search = document.getElementById('searchInput').value;
    const clinic = document.getElementById('clinicFilter').value;
    const status = document.getElementById('statusFilter').value;
    
    let url = 'sales.php?';
    if (search) url += `search=${encodeURIComponent(search)}&`;
    if (clinic !== 'all') url += `clinic=${encodeURIComponent(clinic)}&`;
    if (status !== 'all') url += `status=${encodeURIComponent(status)}&`;
    
    window.location.href = url.replace(/[&?]$/, '');
}

function resetFilters(){
    window.location.href = 'sales.php';
}

// Event listeners
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') applyFilters();
});

document.getElementById('clinicFilter').addEventListener('change', applyFilters);
document.getElementById('statusFilter').addEventListener('change', applyFilters);

// Initialize icons
lucide.createIcons();
</script>
</body>
</html>