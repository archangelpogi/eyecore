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
                $name = $_POST['name'] ?? '';
                $brand = $_POST['brand'] ?? '';
                $clinic = $_POST['clinic'] ?? '';
                $price = (float)($_POST['price'] ?? 0);
                $stock = (int)($_POST['stock'] ?? 0);
                $color = $_POST['color'] ?? 'from-blue-400 to-blue-600';
                
                if (empty($name) || empty($brand) || empty($clinic) || $price <= 0) {
                    echo json_encode(['success' => false, 'message' => 'Please fill all required fields']);
                    exit();
                }
                
                // Generate frame ID
                $frame_id = 'FR' . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
                
                // Get clinic ID
                $stmt = $pdo->prepare("SELECT id FROM clinics WHERE clinic_name = ?");
                $stmt->execute([$clinic]);
                $clinic_data = $stmt->fetch();
                $clinic_id = $clinic_data['id'] ?? 1;
                
                // Add to inventory
                $stmt = $pdo->prepare("INSERT INTO inventory (item_code, name, category, brand, stock, price, clinic_id, item_status) 
                                      VALUES (?, ?, 'Frames', ?, ?, ?, ?, 'in-stock')");
                $stmt->execute([
                    $frame_id, 
                    $name, 
                    $brand, 
                    $stock, 
                    $price, 
                    $clinic_id
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Frame added successfully!']);
                break;
                
            case 'delete':
                $stmt = $pdo->prepare("DELETE FROM inventory WHERE id = ?");
                $stmt->execute([$id]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Frame deleted successfully!']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Frame not found']);
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

// Load frames
$search = $_GET['search'] ?? '';
$clinic_filter = $_GET['clinic'] ?? 'all';

// Get clinics
$clinics = $pdo->query("SELECT clinic_name FROM clinics WHERE status='Active'")->fetchAll(PDO::FETCH_COLUMN);

// Build query for frames (category = 'Frames')
$sql = "SELECT i.*, c.clinic_name 
        FROM inventory i 
        LEFT JOIN clinics c ON i.clinic_id = c.id 
        WHERE i.category = 'Frames'";
$params = [];

if (!empty($search)) {
    $sql .= " AND (i.name LIKE ? OR i.brand LIKE ? OR i.item_code LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}
if ($clinic_filter != 'all') {
    $sql .= " AND c.clinic_name = ?";
    $params[] = $clinic_filter;
}

$sql .= " ORDER BY i.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$frames = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Add mock data for rating and reviews (in real app, these would come from reviews table)
foreach($frames as &$frame) {
    $frame['rating'] = round(mt_rand(35, 50) / 10, 1); // 3.5 to 5.0
    $frame['reviews'] = mt_rand(5, 50);
    $frame['date_added'] = date('Y-m-d', strtotime($frame['created_at']));
    
    // Assign color based on brand or random
    $brandColors = [
        'Ray-Ban' => 'from-amber-400 to-orange-500',
        'Oakley' => 'from-gray-700 to-gray-900',
        'Silhouette' => 'from-blue-400 to-blue-600',
        'Warby Parker' => 'from-green-400 to-teal-500',
        'Gucci' => 'from-red-500 to-pink-500',
        'Prada' => 'from-purple-500 to-indigo-600'
    ];
    $frame['image_color'] = $brandColors[$frame['brand']] ?? 'from-blue-400 to-blue-600';
}

// Calculate stats
$totalFrames = count($frames);
$avgRating = $totalFrames > 0 ? round(array_sum(array_column($frames, 'rating')) / $totalFrames, 1) : 0;
$totalReviews = array_sum(array_column($frames, 'reviews'));
$totalStock = array_sum(array_column($frames, 'stock'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>3D Frame Review System</title>
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
    .frame-card{transition:all 0.3s ease}
    .frame-card:hover{transform:translateY(-4px);box-shadow:0 10px 25px rgba(0,0,0,0.1)}
    .rating-badge{background:rgba(255,255,255,0.9);backdrop-filter:blur(10px)}
    .star-filled{color:#fbbf24;fill:#fbbf24}
    .star-empty{color:#d1d5db}
    </style>
</head>
<body>

<div class="container-fluid p-4">
    <!-- HEADER -->
    <div class="d-flex flex-column flex-md-row justify-content-between gap-3 mb-4">
        <div>
            <h2 class="fw-bold">3D Frame Review System</h2>
            <p class="text-muted mt-1">Virtual frame catalog and review management</p>
        </div>
        <button class="btn gradient-btn px-4 py-2 d-flex align-items-center gap-2" onclick="openAddModal()">
            <i data-lucide="glasses"></i> Add New Frame
        </button>
    </div>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><div class="stats-label">Total Frames</div><div class="stats-number"><?= $totalFrames ?></div></div>
                <div class="icon-box bg-teal-100"><i data-lucide="glasses" class="text-teal-600"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><div class="stats-label">Avg Rating</div><div class="stats-number"><?= $avgRating ?></div></div>
                <div class="icon-box bg-yellow-100"><i data-lucide="star" class="text-yellow-600"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><div class="stats-label">Total Reviews</div><div class="stats-number"><?= $totalReviews ?></div></div>
                <div class="icon-box bg-blue-100"><i data-lucide="eye" class="text-blue-600"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><div class="stats-label">In Stock</div><div class="stats-number"><?= $totalStock ?></div></div>
                <div class="icon-box bg-green-100"><i data-lucide="building-2" class="text-green-600"></i></div>
            </div>
        </div>
    </div>

    <!-- SEARCH & FILTERS -->
    <div class="card-soft p-4 mb-4">
        <div class="row g-3">
            <div class="col-md-5 position-relative">
                <i data-lucide="search" class="position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
                <input type="text" id="searchInput" class="form-control search-input ps-5" 
                       placeholder="Search frames by name or brand..." 
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <select id="clinicFilter" class="form-select">
                    <option value="all">All Clinics</option>
                    <?php foreach($clinics as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $clinic_filter==$c?'selected':'' ?>>
                        <?= htmlspecialchars($c) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" onclick="applyFilters()">
                    <i data-lucide="filter" class="me-2"></i>Apply Filters
                </button>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" onclick="resetFilters()">
                    <i data-lucide="refresh-ccw" class="me-2"></i>Reset
                </button>
            </div>
        </div>
    </div>

    <!-- FRAMES GRID -->
    <div class="row g-4">
        <?php if(empty($frames)): ?>
        <div class="col-12">
            <div class="card-soft p-5 text-center">
                <i data-lucide="glasses" class="text-muted mb-3" style="width:64px;height:64px"></i>
                <h4 class="text-muted">No frames found</h4>
                <p class="text-muted mb-0">Add frames to get started</p>
            </div>
        </div>
        <?php else: foreach($frames as $frame): ?>
        <div class="col-md-6 col-lg-4 col-xl-3">
            <div class="card-soft overflow-hidden frame-card">
                <!-- 3D Frame Preview -->
                <div class="h-48 <?= $frame['image_color'] ?> d-flex align-items-center justify-content-center position-relative">
                    <i data-lucide="glasses" class="text-white opacity-90" style="width:80px;height:80px"></i>
                    <div class="position-absolute top-3 end-3 rating-badge px-3 py-2 rounded-pill">
                        <div class="d-flex align-items-center gap-1">
                            <i data-lucide="star" class="text-warning" style="width:16px;height:16px;fill:#fbbf24"></i>
                            <span class="text-sm fw-semibold"><?= $frame['rating'] ?></span>
                        </div>
                    </div>
                </div>

                <!-- Frame Details -->
                <div class="p-4">
                    <div class="d-flex justify-content-between mb-2">
                        <div>
                            <h4 class="fw-semibold mb-0"><?= htmlspecialchars($frame['name']) ?></h4>
                            <p class="text-sm text-muted"><?= htmlspecialchars($frame['brand']) ?></p>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-1 mb-3">
                        <?php 
                        $fullStars = floor($frame['rating']);
                        $hasHalfStar = $frame['rating'] - $fullStars >= 0.5;
                        
                        for($i = 0; $i < 5; $i++): 
                            if($i < $fullStars): ?>
                                <i data-lucide="star" class="star-filled" style="width:16px;height:16px"></i>
                            <?php elseif($i == $fullStars && $hasHalfStar): ?>
                                <i data-lucide="star-half" class="star-filled" style="width:16px;height:16px"></i>
                            <?php else: ?>
                                <i data-lucide="star" class="star-empty" style="width:16px;height:16px"></i>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <span class="text-xs text-muted ms-1">(<?= $frame['reviews'] ?> reviews)</span>
                    </div>

                    <div class="mb-4 text-sm">
                        <div class="d-flex align-items-center gap-2 text-muted mb-2">
                            <i data-lucide="building-2" class="text-muted" style="width:16px;height:16px"></i>
                            <?= htmlspecialchars($frame['clinic_name'] ?? 'N/A') ?>
                        </div>
                        <div class="d-flex align-items-center gap-2 text-muted">
                            <i data-lucide="calendar" class="text-muted" style="width:16px;height:16px"></i>
                            Added <?= date('M d, Y', strtotime($frame['date_added'])) ?>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between pt-3 border-top">
                        <div>
                            <p class="text-xs text-muted mb-1">Price</p>
                            <p class="h5 fw-bold mb-0">₱<?= number_format($frame['price'], 2) ?></p>
                        </div>
                        <div class="text-end">
                            <p class="text-xs text-muted mb-1">Stock</p>
                            <p class="fw-semibold mb-0 <?= $frame['stock'] <= 5 ? 'text-danger' : '' ?>">
                                <?= $frame['stock'] ?> units
                            </p>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn gradient-btn flex-grow-1 d-flex align-items-center justify-content-center gap-2" 
                                onclick="view3DModel('<?= $frame['id'] ?>', '<?= htmlspecialchars(addslashes($frame['name'])) ?>')">
                            <i data-lucide="eye" style="width:16px;height:16px"></i>
                            View 3D Model
                        </button>
                        <button class="btn btn-outline-danger" 
                                onclick="deleteFrame(<?= $frame['id'] ?>, '<?= htmlspecialchars(addslashes($frame['name'])) ?>')">
                            <i data-lucide="trash-2" style="width:16px;height:16px"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<!-- MODAL ADD FRAME -->
<div class="modal fade" id="frameModal">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Add New Frame</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="frameForm" onsubmit="return false;">
                    <input type="hidden" name="action" value="add">
                    <div class="mb-3">
                        <label class="form-label">Frame Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="Classic Aviator" required>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Brand *</label>
                            <select name="brand" class="form-select" required>
                                <option value="">Select Brand</option>
                                <option value="Ray-Ban">Ray-Ban</option>
                                <option value="Oakley">Oakley</option>
                                <option value="Silhouette">Silhouette</option>
                                <option value="Warby Parker">Warby Parker</option>
                                <option value="Gucci">Gucci</option>
                                <option value="Prada">Prada</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Clinic *</label>
                            <select name="clinic" class="form-select" required>
                                <option value="">Select Clinic</option>
                                <?php foreach($clinics as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Price (₱) *</label>
                            <input type="number" name="price" class="form-control" step="0.01" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Stock *</label>
                            <input type="number" name="stock" class="form-control" min="0" value="1" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Display Color</label>
                        <select name="color" class="form-select">
                            <option value="from-amber-400 to-orange-500">Amber to Orange</option>
                            <option value="from-gray-700 to-gray-900">Gray to Dark Gray</option>
                            <option value="from-blue-400 to-blue-600" selected>Blue to Dark Blue</option>
                            <option value="from-green-400 to-teal-500">Green to Teal</option>
                            <option value="from-red-500 to-pink-500">Red to Pink</option>
                            <option value="from-purple-500 to-indigo-600">Purple to Indigo</option>
                        </select>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn gradient-btn" onclick="saveFrame()">
                    <span id="saveBtnText">Add Frame</span>
                    <span id="saveBtnSpinner" class="spinner-border spinner-border-sm d-none"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
const frameModal = new bootstrap.Modal(document.getElementById('frameModal'));

function showToast(msg,icon='success'){
    Swal.fire({toast:true,position:'top-end',icon,title:msg,showConfirmButton:false,timer:3000})
}

function openAddModal(){
    document.getElementById('frameForm').reset();
    frameModal.show();
}

async function saveFrame(){
    const form = document.getElementById('frameForm');
    if(!form.checkValidity()) return form.reportValidity();
    
    const btn = form.querySelector('.btn.gradient-btn');
    const spinner = btn.querySelector('.spinner-border');
    const text = btn.querySelector('#saveBtnText');
    
    btn.disabled = true;
    spinner.classList.remove('d-none');
    
    try{
        const formData = new FormData(form);
        const res = await fetch('frames.php',{method:'POST',body:formData});
        const data = await res.json();
        showToast(data.message,data.success?'success':'error');
        if(data.success){
            frameModal.hide();
            setTimeout(() => location.reload(), 1500);
        }
    }catch(e){
        showToast('Error saving frame','error');
    }finally{
        btn.disabled = false;
        spinner.classList.add('d-none');
    }
}

function view3DModel(id, name){
    // Simulate 3D model viewer
    Swal.fire({
        title: '3D Model Viewer',
        html: `
            <div class="text-center my-4">
                <i data-lucide="glasses" style="width:100px;height:100px" class="text-primary"></i>
                <p class="mt-3">3D Preview: <strong>${name}</strong></p>
                <p class="text-muted">This would show an interactive 3D model of the frame</p>
                <div class="bg-light rounded p-3 mt-3">
                    <p class="text-sm text-muted mb-1">Features:</p>
                    <ul class="text-sm text-muted text-start">
                        <li>360° rotation</li>
                        <li>Zoom in/out</li>
                        <li>Virtual try-on simulation</li>
                        <li>Multiple color options</li>
                    </ul>
                </div>
            </div>
        `,
        showCancelButton: true,
        confirmButtonText: 'Try Virtual Try-On',
        cancelButtonText: 'Close'
    }).then((result) => {
        if(result.isConfirmed){
            showToast('Virtual try-on feature would launch here','info');
        }
    });
    
    lucide.createIcons();
}

async function deleteFrame(id,name){
    const result = await Swal.fire({
        title: 'Delete Frame?',
        html: `Are you sure you want to delete <strong>${name}</strong>?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'Yes, delete it!',
        cancelButtonText: 'Cancel'
    });
    
    if(result.isConfirmed){
        try{
            const formData = new FormData();
            formData.append('action','delete');
            formData.append('id',id);
            const res = await fetch('frames.php',{method:'POST',body:formData});
            const data = await res.json();
            showToast(data.message,data.success?'success':'error');
            if(data.success) setTimeout(() => location.reload(), 1500);
        }catch(e){
            showToast('Error deleting frame','error');
        }
    }
}

function applyFilters(){
    const search = document.getElementById('searchInput').value;
    const clinic = document.getElementById('clinicFilter').value;
    let url = 'frames.php?';
    if(search) url += `search=${encodeURIComponent(search)}&`;
    if(clinic !== 'all') url += `clinic=${encodeURIComponent(clinic)}&`;
    window.location.href = url.replace(/[&?]$/,'');
}

function resetFilters(){
    window.location.href = 'frames.php';
}

// Event listeners
document.getElementById('searchInput').addEventListener('keypress',e=>{
    if(e.key === 'Enter') applyFilters();
});

document.getElementById('clinicFilter').addEventListener('change',applyFilters);

// Initialize icons
lucide.createIcons();
</script>
</body>
</html>