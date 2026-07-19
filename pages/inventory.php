<?php
include __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Handle AJAX CRUD
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = $_POST['name'] ?? '';
    $category = $_POST['category'] ?? '';
    $clinic = $_POST['clinic'] ?? '';
    $stock = (int)($_POST['stock'] ?? 0);
    $min_stock = (int)($_POST['min_stock'] ?? 10);
    $price = (float)($_POST['price'] ?? 0);
    
    try {
        switch($action) {
            case 'add':
                if (empty($name) || empty($category) || empty($clinic)) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all fields']);
                    exit();
                }
                
                $item_id = 'INV' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $status = $stock == 0 ? 'out-of-stock' : ($stock <= $min_stock ? 'low-stock' : 'in-stock');
                
                $stmt = $pdo->prepare("SELECT id FROM clinics WHERE clinic_name = ? LIMIT 1");
                $stmt->execute([$clinic]);
                $clinicData = $stmt->fetch();
                
                if (!$clinicData) {
                    echo json_encode(['success' => false, 'message' => 'Invalid clinic']);
                    exit();
                }
                
                $clinic_id = $clinicData['id'];
                
                $stmt = $pdo->prepare("INSERT INTO inventory (item_id, item_code, name, category, brand, stock, min_stock, reorder_level, price, item_status, clinic_id) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$item_id, $item_id, $name, $category, $category, $stock, $min_stock, $min_stock, $price, $status, $clinic_id]);
                
                echo json_encode(['success' => true, 'message' => 'Item added!']);
                break;
                
            case 'update':
                if ($id <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
                    exit();
                }
                
                $status = $stock == 0 ? 'out-of-stock' : ($stock <= $min_stock ? 'low-stock' : 'in-stock');
                
                $stmt = $pdo->prepare("SELECT id FROM clinics WHERE clinic_name = ? LIMIT 1");
                $stmt->execute([$clinic]);
                $clinicData = $stmt->fetch();
                $clinic_id = $clinicData['id'] ?? 0;
                
                $stmt = $pdo->prepare("UPDATE inventory SET name=?, category=?, brand=?, stock=?, min_stock=?, reorder_level=?, price=?, item_status=?, clinic_id=? WHERE id=?");
                $stmt->execute([$name, $category, $category, $stock, $min_stock, $min_stock, $price, $status, $clinic_id, $id]);
                
                echo json_encode(['success' => true, 'message' => 'Item updated!']);
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->execute([$id]);
                
                echo json_encode(['success' => true, 'message' => 'Item deleted!']);
                break;
                
            default:
                echo json_encode(['success' => false, 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit();
}

// Load inventory data
$search = $_GET['search'] ?? '';
$clinic_filter = $_GET['clinic'] ?? 'all';
$status_filter = $_GET['status'] ?? 'all';

// Get clinics
$clinics = $pdo->query("SELECT clinic_name FROM clinics WHERE status='Active'")->fetchAll(PDO::FETCH_COLUMN);

// Build query
$sql = "SELECT i.*, c.clinic_name FROM inventory i LEFT JOIN clinics c ON i.clinic_id = c.id WHERE 1=1";
$params = [];

if (!empty($search)) {
    $sql .= " AND (i.name LIKE ? OR i.item_id LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if ($clinic_filter != 'all') {
    $sql .= " AND c.clinic_name = ?";
    $params[] = $clinic_filter;
}
if ($status_filter != 'all') {
    $sql .= " AND i.item_status = ?";
    $params[] = $status_filter;
}

$sql .= " ORDER BY i.item_status, i.stock ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inventory = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate stats
$totalItems = count($inventory);
$inStock = count(array_filter($inventory, fn($item) => ($item['item_status'] ?? '') == 'in-stock'));
$lowStock = count(array_filter($inventory, fn($item) => ($item['item_status'] ?? '') == 'low-stock'));
$outOfStock = count(array_filter($inventory, fn($item) => ($item['item_status'] ?? '') == 'out-of-stock'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Inventory Management</title>
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
    .badge-in-stock{background:#dcfce7;color:#166534;padding:6px 12px;border-radius:20px}
    .badge-low-stock{background:#fef3c7;color:#92400e;padding:6px 12px;border-radius:20px}
    .badge-out-of-stock{background:#fee2e2;color:#991b1b;padding:6px 12px;border-radius:20px}
    .search-input{padding-left:40px;border-radius:8px}
    .modal-content{border-radius:16px;box-shadow:0 20px 40px rgba(0,0,0,0.1)}
    .loading{opacity:0.6}
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">Inventory Management</h2>
            <p class="text-muted">Multi-clinic stock monitoring</p>
        </div>
        <button class="btn gradient-btn px-4 py-2 d-flex align-items-center gap-2" onclick="openAddModal()">
            <i data-lucide="plus"></i> Add Item
        </button>
    </div>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Total Items</div><div class="stats-number"><?= $totalItems ?></div></div><div class="icon-box bg-teal-100"><i data-lucide="package" class="text-teal-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">In Stock</div><div class="stats-number"><?= $inStock ?></div></div><div class="icon-box bg-green-100"><i data-lucide="trending-up" class="text-green-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Low Stock</div><div class="stats-number"><?= $lowStock ?></div></div><div class="icon-box bg-yellow-100"><i data-lucide="alert-triangle" class="text-yellow-600"></i></div></div></div>
        <div class="col-md-3"><div class="card-soft p-4 d-flex justify-content-between"><div><div class="stats-label">Out of Stock</div><div class="stats-number"><?= $outOfStock ?></div></div><div class="icon-box bg-red-100"><i data-lucide="alert-triangle" class="text-red-600"></i></div></div></div>
    </div>

    <!-- SEARCH & FILTERS -->
    <div class="card-soft p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-5 position-relative"><i data-lucide="search" class="position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i><input type="text" id="searchInput" class="form-control search-input ps-5" placeholder="Search items..." value="<?= htmlspecialchars($search) ?>"></div>
            <div class="col-md-3"><select id="clinicFilter" class="form-select"><?php foreach($clinics as $c): ?><option value="<?= htmlspecialchars($c) ?>" <?= $clinic_filter==$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-2"><select id="statusFilter" class="form-select"><option value="all">All Status</option><option value="in-stock" <?= $status_filter=='in-stock'?'selected':'' ?>>In Stock</option><option value="low-stock" <?= $status_filter=='low-stock'?'selected':'' ?>>Low Stock</option><option value="out-of-stock" <?= $status_filter=='out-of-stock'?'selected':'' ?>>Out of Stock</option></select></div>
            <div class="col-md-2"><button class="btn btn-outline-secondary w-100" onclick="resetFilters()"><i data-lucide="refresh-ccw" class="me-2"></i>Reset</button></div>
        </div>
    </div>

    <!-- TABLE -->
    <div class="card-soft overflow-hidden">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="bg-light"><tr><th class="ps-4">Item ID</th><th>Item Name</th><th class="d-none d-md-table-cell">Category</th><th>Clinic</th><th>Stock</th><th class="d-none d-lg-table-cell">Price</th><th>Status</th><th class="text-end pe-4">Actions</th></tr></thead>
                <tbody id="inventoryTableBody">
                    <?php if(empty($inventory)): ?>
                    <tr><td colspan="8" class="text-center py-5"><div class="d-flex flex-column align-items-center"><i data-lucide="package" class="text-muted mb-3" style="width:48px;height:48px"></i><h5 class="text-muted">No items found</h5></div></td></tr>
                    <?php else: foreach($inventory as $item): 
                        $badge = match($item['item_status'] ?? ''){'in-stock'=>'badge-in-stock','low-stock'=>'badge-low-stock','out-of-stock'=>'badge-out-of-stock',default=>'badge-in-stock'};
                        $itemId = $item['item_id'] ?? $item['item_code'] ?? 'N/A';
                    ?>
                    <tr id="item-<?= $item['id'] ?>">
                        <td class="ps-4"><span class="fw-semibold text-muted"><?= htmlspecialchars($itemId) ?></span></td>
                        <td><p class="fw-medium mb-0"><?= htmlspecialchars($item['name']) ?></p></td>
                        <td class="d-none d-md-table-cell"><span class="text-muted"><?= htmlspecialchars($item['category']) ?></span></td>
                        <td><div class="d-flex align-items-center gap-2"><i data-lucide="building-2" class="text-muted" style="width:16px;height:16px"></i><span><?= htmlspecialchars($item['clinic_name'] ?? 'N/A') ?></span></div></td>
                        <td><p class="mb-0 fw-semibold"><?= $item['stock'] ?> units</p><small class="text-muted">Min: <?= $item['min_stock'] ?? $item['reorder_level'] ?></small></td>
                        <td class="d-none d-lg-table-cell"><p class="fw-semibold mb-0">₱<?= number_format($item['price'],2) ?></p></td>
                        <td><span class="<?= $badge ?>"><?= $item['item_status']=='low-stock'?'<i data-lucide="alert-triangle" class="me-1" style="width:12px;height:12px"></i>':'' ?><?= ucfirst(str_replace('-',' ',$item['item_status'] ?? 'in-stock')) ?></span></td>
                        <td class="text-end pe-4"><div class="btn-group"><button class="btn btn-sm btn-outline-primary" onclick="editItem(<?= $item['id'] ?>,'<?= htmlspecialchars($item['name']) ?>')"><i data-lucide="edit" style="width:16px;height:16px"></i></button><button class="btn btn-sm btn-outline-danger" onclick="deleteItem(<?= $item['id'] ?>,'<?= htmlspecialchars(addslashes($item['name'])) ?>')"><i data-lucide="trash-2" style="width:16px;height:16px"></i></button></div></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL -->
<div class="modal fade" id="itemModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="modalTitle">Add New Item</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="itemForm"><input type="hidden" id="itemId" name="id"><input type="hidden" id="actionType" name="action" value="add">
                    <div class="mb-3"><label class="form-label">Item Name *</label><input type="text" id="itemName" name="name" class="form-control" placeholder="Item name" required></div>
                    <div class="mb-3"><label class="form-label">Category *</label><select id="itemCategory" name="category" class="form-select" required><option value="">Select</option><option value="Frames">Frames</option><option value="Lenses">Lenses</option><option value="Accessories">Accessories</option></select></div>
                    <div class="mb-3"><label class="form-label">Clinic *</label><select id="itemClinic" name="clinic" class="form-select" required><option value="">Select</option><?php foreach($clinics as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?></select></div>
                    <div class="row mb-3"><div class="col-md-6"><label class="form-label">Stock *</label><input type="number" id="itemStock" name="stock" class="form-control" min="0" value="0" required></div><div class="col-md-6"><label class="form-label">Min Stock *</label><input type="number" id="itemMinStock" name="min_stock" class="form-control" min="1" value="10" required></div></div>
                    <div class="mb-3"><label class="form-label">Price (₱) *</label><input type="number" id="itemPrice" name="price" class="form-control" step="0.01" min="0" value="0" required></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="saveItemBtn" class="btn gradient-btn" onclick="saveItem()"><span id="saveBtnText">Add Item</span><span id="saveBtnSpinner" class="spinner-border spinner-border-sm d-none"></span></button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const itemModal = new bootstrap.Modal(document.getElementById('itemModal'));
let currentItem = null;

function showToast(msg,icon='success'){
    Swal.fire({toast:true,position:'top-end',icon,title:msg,showConfirmButton:false,timer:3000})
}

function openAddModal(){
    document.getElementById('itemForm').reset();
    document.getElementById('itemId').value='';
    document.getElementById('actionType').value='add';
    document.getElementById('modalTitle').textContent='Add New Item';
    document.getElementById('saveBtnText').textContent='Add Item';
    itemModal.show();
}

function editItem(id,name){
    const row=document.getElementById(`item-${id}`);
    document.getElementById('itemId').value=id;
    document.getElementById('itemName').value=name;
    document.getElementById('itemCategory').value=row.querySelector('td:nth-child(3)').textContent.trim();
    document.getElementById('itemClinic').value=row.querySelector('td:nth-child(4) span').textContent.trim();
    document.getElementById('itemStock').value=parseInt(row.querySelector('td:nth-child(5) p').textContent);
    document.getElementById('itemMinStock').value=parseInt(row.querySelector('td:nth-child(5) small').textContent.replace('Min: ',''));
    document.getElementById('itemPrice').value=parseFloat(row.querySelector('td:nth-child(6) p').textContent.replace('₱','').replace(',',''));
    document.getElementById('actionType').value='update';
    document.getElementById('modalTitle').textContent='Edit Item';
    document.getElementById('saveBtnText').textContent='Update Item';
    itemModal.show();
}

async function saveItem(){
    const form=document.getElementById('itemForm');
    if(!form.checkValidity()) return form.reportValidity();
    
    const btn=document.getElementById('saveItemBtn');
    btn.disabled=true;
    document.getElementById('saveBtnSpinner').classList.remove('d-none');
    
    try{
        const formData=new FormData(form);
        const res=await fetch('inventory.php',{method:'POST',body:formData});
        const data=await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success){
            itemModal.hide();
            location.reload();
        }
    }catch(e){
        showToast('Error saving item','error');
    }finally{
        btn.disabled=false;
        document.getElementById('saveBtnSpinner').classList.add('d-none');
    }
}

async function deleteItem(id,name){
    const result=await Swal.fire({title:'Delete?',html:`Delete <strong>${name}</strong>?`,icon:'warning',showCancelButton:true,confirmButtonText:'Yes, delete'});
    if(result.isConfirmed){
        try{
            const formData=new FormData();
            formData.append('action','delete');
            formData.append('id',id);
            const res=await fetch('inventory.php',{method:'POST',body:formData});
            const data=await res.json();
            showToast(data.message,data.success?'success':'error');
            if(data.success) location.reload();
        }catch(e){
            showToast('Error deleting item','error');
        }
    }
}

function applyFilters(){
    const search=document.getElementById('searchInput').value;
    const clinic=document.getElementById('clinicFilter').value;
    const status=document.getElementById('statusFilter').value;
    let url='inventory.php?';
    if(search) url+=`search=${encodeURIComponent(search)}&`;
    if(clinic!='all') url+=`clinic=${encodeURIComponent(clinic)}&`;
    if(status!='all') url+=`status=${encodeURIComponent(status)}&`;
    window.location.href=url.replace(/[&?]$/,'');
}

function resetFilters(){
    window.location.href='inventory.php';
}

// Event listeners
document.getElementById('searchInput').addEventListener('keypress',e=>{if(e.key=='Enter')applyFilters()});
document.getElementById('clinicFilter').addEventListener('change',applyFilters);
document.getElementById('statusFilter').addEventListener('change',applyFilters);

// Initialize icons
lucide.createIcons();
</script>
</body>
</html>