<?php
include __DIR__ . '/../config/db.php';

// --- Only for SuperAdmin ---
$currentRole = $_SESSION['role'] ?? 'SuperAdmin';
if ($currentRole != 'SuperAdmin') {
    die("Access Denied");
}

// --- Handle payment verification ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['verify_payment'])) {
    $request_id = $_POST['request_id'];
    
    $stmt = $pdo->prepare("UPDATE custom_3d_requests SET payment_status = 'paid', payment_date = NOW() WHERE id = ?");
    $stmt->execute([$request_id]);
    
    $success = "Payment verified for request #{$request_id}";
}

// --- Handle model upload (when completed) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_model'])) {
    $request_id = $_POST['request_id'];
    
    if (isset($_FILES['model_file']) && $_FILES['model_file']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/completed_models/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        $file = $_FILES['model_file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'request_' . $request_id . '_' . time() . '.' . $ext;
        
        if (move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
            $relative_path = 'uploads/completed_models/' . $filename;
            
            // Get request details before updating
            $req_stmt = $pdo->prepare("SELECT user_id, product_name, request_number FROM custom_3d_requests WHERE id = ?");
            $req_stmt->execute([$request_id]);
            $request = $req_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Update request status
            $stmt = $pdo->prepare("UPDATE custom_3d_requests SET completed_model_file = ?, status = 'completed', completed_at = NOW() WHERE id = ?");
            $stmt->execute([$relative_path, $request_id]);
            
            // ===== SEND NOTIFICATION TO CLINIC OWNER =====
            if ($request) {
                $title = "3D Model Completed!";
                $message = "Your 3D model for '" . $request['product_name'] . "' is now ready for download.";
                $type = "model_completed";
                $reference_number = $request['request_number'];
                $link = "main.php?view=my-3d-models&tab=completed";
                
                // Include the notification function
                require_once __DIR__ . '/api/3d_request.php';
                createNotification($pdo, $request['user_id'], $title, $message, $type, $reference_number, $link);
            }
            // ===== END NOTIFICATION =====
            
            $success = "3D model uploaded for request #{$request_id}";
        }
    }
}

// --- Fetch stats ---
$totalRequests = $pdo->query("SELECT COUNT(*) FROM custom_3d_requests")->fetchColumn();
$pendingRequests = $pdo->query("SELECT COUNT(*) FROM custom_3d_requests WHERE payment_status = 'unpaid'")->fetchColumn();
$processingRequests = $pdo->query("SELECT COUNT(*) FROM custom_3d_requests WHERE payment_status = 'paid' AND status != 'completed'")->fetchColumn();
$completedRequests = $pdo->query("SELECT COUNT(*) FROM custom_3d_requests WHERE status = 'completed'")->fetchColumn();

// --- Fetch all requests (simple table - View button lang) ---
$requests = $pdo->query("
    SELECT r.*, 
           c.clinic_name,
           u.first_name,
           u.last_name
    FROM custom_3d_requests r
    LEFT JOIN clinics c ON r.clinic_id = c.id
    LEFT JOIN users u ON r.user_id = u.id
    ORDER BY r.created_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>3D Model Requests - Developer Panel</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Lucide icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
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
    
    <!-- Three.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>

    <style>
        :root { --teal: #008080; }
        body { background: #f8fafc; font-family: 'Inter', sans-serif; }
        .card-soft { background: #fff; border: 1px solid #e5e7eb; border-radius: 12px; }
        .icon-box { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .bg-teal { background-color: var(--teal) !important; }
        .text-teal { color: var(--teal) !important; }
        .btn-teal { background-color: var(--teal); color: white; border: none; }
        .btn-teal:hover { background-color: #006666; }
        .btn-outline-teal { border: 1px solid var(--teal); color: var(--teal); background: transparent; }
        .btn-outline-teal:hover { background-color: var(--teal); color: white; }
        .badge-unpaid { background: #fef3c7; color: #92400e; }
        .badge-paid { background: #dbeafe; color: #1e40af; }
        .badge-completed { background: #dcfce7; color: #166534; }
        .table td, .table th { vertical-align: middle; }
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        .bg-pink {
            background-color: #ff69b4 !important;
            color: white !important;
        }
        .bg-dark {
            background-color: #343a40 !important;
            color: white !important;
        }
        .bg-warning {
            background-color: #ffc107 !important;
            color: #212529 !important;
        }
        .bg-secondary {
            background-color: #6c757d !important;
            color: white !important;
        }
        .bg-info {
            background-color: #0dcaf0 !important;
            color: #212529 !important;
        }
        
        /* Color Switcher Styles */
        .color-switcher-btn {
            padding: 8px 20px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            font-weight: 500;
            transition: all 0.2s;
            cursor: pointer;
        }
        .color-switcher-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .color-switcher-btn.active {
            border-color: var(--teal);
            background: var(--teal);
            color: white;
        }
        .color-preview-container {
            background: #f8fafc;
            border-radius: 12px;
            padding: 16px;
            margin-top: 16px;
        }
        .current-color-label {
            font-size: 0.85rem;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .color-dot-large {
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: inline-block;
            border: 2px solid white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.2);
        }
        .lens-warning {
            background: #fff3cd;
            border: 1px solid #ffeeba;
            color: #856404;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 12px;
            margin-top: 8px;
        }
    </style>
</head>
<body>
<div class="container-fluid p-4">

    <!-- HEADER -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-teal">3D Model Requests</h2>
            <p class="text-muted">Click View to manage each request</p>
        </div>
    </div>

    <?php if (isset($success)): ?>
    <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><small>Total</small><h4><?= $totalRequests ?></h4></div>
                <div class="icon-box bg-light"><i data-lucide="box" class="text-teal"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><small>Awaiting Payment</small><h4><?= $pendingRequests ?></h4></div>
                <div class="icon-box bg-light"><i data-lucide="clock" class="text-teal"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><small>In Progress</small><h4><?= $processingRequests ?></h4></div>
                <div class="icon-box bg-light"><i data-lucide="loader" class="text-teal"></i></div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card-soft p-4 d-flex justify-content-between">
                <div><small>Completed</small><h4><?= $completedRequests ?></h4></div>
                <div class="icon-box bg-light"><i data-lucide="check-circle" class="text-teal"></i></div>
            </div>
        </div>
    </div>

    <!-- FILTERS -->
    <div class="card-soft p-3 mb-4">
        <div class="row g-3">
            <div class="col-md-4">
                <input type="text" id="searchInput" class="form-control" placeholder="Search by clinic, product, or ID...">
            </div>
            <div class="col-md-4">
                <select id="statusFilter" class="form-select">
                    <option value="">All Status</option>
                    <option value="unpaid">Awaiting Payment</option>
                    <option value="paid">Paid / In Progress</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div class="col-md-4">
                <select id="clinicFilter" class="form-select">
                    <option value="">All Clinics</option>
                    <?php
                    $clinics = $pdo->query("SELECT clinic_name FROM clinics ORDER BY clinic_name ASC")->fetchAll();
                    foreach($clinics as $c) {
                        echo "<option value='{$c['clinic_name']}'>{$c['clinic_name']}</option>";
                    }
                    ?>
                </select>
            </div>
        </div>
    </div>

    <!-- SIMPLE TABLE - VIEW BUTTON LANG -->
    <div class="card-soft overflow-hidden">
        <table id="requestsTable" class="table table-hover mb-0">
            <thead class="bg-light">
                <tr>
                    <th>ID</th>
                    <th>Date</th>
                    <th>Clinic</th>
                    <th>Product</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($requests as $req): ?>
                <tr>
                    <td>#<?= $req['id'] ?></td>
                    <td><?= date('M d, Y', strtotime($req['created_at'])) ?></td>
                    <td><?= htmlspecialchars($req['clinic_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($req['product_name']) ?></td>
                    <td><?= $req['model_type'] ?></td>
                    <td>
                        <?php 
                        if ($req['payment_status'] == 'unpaid') echo '<span class="badge badge-unpaid">Awaiting Payment</span>';
                        elseif ($req['payment_status'] == 'paid' && $req['status'] != 'completed') echo '<span class="badge badge-paid">Paid / In Progress</span>';
                        elseif ($req['status'] == 'completed') echo '<span class="badge badge-completed">Completed</span>';
                        ?>
                    </td>
                    <td>
                        <button class="btn btn-sm btn-outline-teal" onclick="viewRequest(<?= $req['id'] ?>)">
                            <i data-lucide="eye"></i> View
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-teal text-white">
                <h5 class="modal-title">Request Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                Loading...
            </div>
        </div>
    </div>
</div>

<!-- VERIFY PAYMENT FORM (hidden, submitted via JS) -->
<form method="POST" id="verifyForm" style="display: none;">
    <input type="hidden" name="request_id" id="verify_id">
    <input type="hidden" name="verify_payment" value="1">
</form>

<!-- UPLOAD MODEL FORM (hidden, submitted via JS) -->
<form method="POST" enctype="multipart/form-data" id="uploadForm" style="display: none;">
    <input type="hidden" name="request_id" id="upload_id">
    <input type="file" name="model_file" id="upload_file" accept=".glb,.gltf,.obj,.fbx">
    <input type="hidden" name="upload_model" value="1">
</form>

<script>
lucide.createIcons();

$(document).ready(function () {
    const table = $('#requestsTable').DataTable({
        dom: 'Bfrtip',
        buttons: ['copy', 'excel', 'print'],
        pageLength: 10,
        order: [[0, 'desc']]
    });

    $('#statusFilter').on('change', function () { 
        const val = this.value;
        if (val === 'unpaid') {
            table.column(5).search('Awaiting Payment').draw();
        } else if (val === 'paid') {
            table.column(5).search('Paid / In Progress').draw();
        } else if (val === 'completed') {
            table.column(5).search('Completed').draw();
        } else {
            table.search('').draw();
        }
    });
    
    $('#clinicFilter').on('change', function () { 
        table.column(2).search(this.value).draw(); 
    });
    
    $('#searchInput').on('keyup', function () { 
        table.search(this.value).draw(); 
    });
});

function viewRequest(id) {
    $('#viewModal').modal('show');
    $('#viewModalBody').html('<div class="text-center p-4"><div class="spinner-border text-teal"></div><p class="mt-2">Loading...</p></div>');
    
    $.ajax({
        url: '/api/3d_request.php?action=get_request_details&id=' + id,
        dataType: 'json',
        success: function(res) {
            console.log('API Response:', res);
            if (res.success && res.data) {
                displayRequestDetails(res.data);
            } else {
                $('#viewModalBody').html(`
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i>
                        ${res.message || 'Failed to load request details'}
                    </div>
                `);
            }
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            $('#viewModalBody').html(`
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i>
                    Error connecting to server. Please try again.
                </div>
            `);
        }
    });
}

function displayRequestDetails(req) {
    console.log('Displaying request:', req);
    
    try {
        let colorsArray = [];
        if (req.colors_requested) {
            colorsArray = req.colors_requested.split(',').map(c => c.trim());
        }
        
        let colorsBadgesHtml = '';
        if (colorsArray.length > 0) {
            colorsBadgesHtml = colorsArray.map(color => {
                let bgColor = 'secondary';
                if (color.toLowerCase() === 'black') bgColor = 'dark';
                else if (color.toLowerCase() === 'red') bgColor = 'danger';
                else if (color.toLowerCase() === 'pink') bgColor = 'pink';
                else if (color.toLowerCase() === 'gold') bgColor = 'warning';
                else if (color.toLowerCase() === 'silver') bgColor = 'secondary';
                else bgColor = 'info';
                return `<span class="badge bg-${bgColor} me-1">${escapeHtml(color)}</span>`;
            }).join('');
        } else {
            colorsBadgesHtml = '<span class="text-muted">Not specified</span>';
        }
        
        let createdDate = 'N/A';
        if (req.created_at) {
            try {
                createdDate = new Date(req.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' });
            } catch(e) {
                createdDate = req.created_at;
            }
        }
        
        let formattedPrice = '₱0.00';
        if (req.price) {
            formattedPrice = '₱' + parseFloat(req.price).toFixed(2);
        } else if (req.formatted_price) {
            formattedPrice = req.formatted_price;
        }
        
        let paymentStatusBadge = '';
        if (req.payment_status == 'paid') {
            paymentStatusBadge = '<span class="badge bg-success">paid</span>';
        } else if (req.payment_status == 'unpaid') {
            paymentStatusBadge = '<span class="badge bg-warning">unpaid</span>';
        } else {
            paymentStatusBadge = '<span class="badge bg-secondary">' + (req.payment_status || 'unknown') + '</span>';
        }
        
        let modelStatusBadge = '';
        if (req.status == 'completed') {
            modelStatusBadge = '<span class="badge bg-success">completed</span>';
        } else if (req.status == 'processing') {
            modelStatusBadge = '<span class="badge bg-info">processing</span>';
        } else if (req.status == 'pending_payment') {
            modelStatusBadge = '<span class="badge bg-warning">pending payment</span>';
        } else {
            modelStatusBadge = '<span class="badge bg-secondary">' + (req.status || 'pending') + '</span>';
        }
        
        let html = `
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Request #:</strong> ${escapeHtml(req.request_number || 'N/A')}</p>
                    <p><strong>Date:</strong> ${escapeHtml(createdDate)}</p>
                    <p><strong>Clinic:</strong> ${escapeHtml(req.clinic_name || 'N/A')}</p>
                    <p><strong>Requested by:</strong> ${escapeHtml(req.first_name || '')} ${escapeHtml(req.last_name || '')}</p>
                    <p><strong>Email:</strong> ${escapeHtml(req.user_email || 'N/A')}</p>
                </div>
                <div class="col-md-6">
                    <p><strong>Product:</strong> ${escapeHtml(req.product_name || 'N/A')}</p>
                    <p><strong>Type:</strong> ${escapeHtml(req.model_type || 'Frame')}</p>
                    <p><strong>Price:</strong> ${formattedPrice}</p>
                    <p><strong>Payment Status:</strong> ${paymentStatusBadge}</p>
                    <p><strong>Model Status:</strong> ${modelStatusBadge}</p>
                </div>
            </div>
            
            <hr>
            <p><strong>Notes:</strong> ${escapeHtml(req.notes || 'No notes')}</p>
            <div class="mb-3">
                <strong>🎨 Colors Requested:</strong><br>
                <div class="d-flex flex-wrap gap-1 mt-1">
                    ${colorsBadgesHtml}
                </div>
                <small class="text-muted">Please ensure the GLB file contains ALL these colors as materials</small>
            </div>
        `;
        
        if (req.payment_proof) {
            html += `
                <hr>
                <h6 class="fw-bold">Payment Receipt</h6>
                <div class="text-center">
                    <img src="/${escapeHtml(req.payment_proof)}" class="img-fluid rounded border" style="max-height: 200px; cursor: pointer;" 
                         onclick="window.open('/${escapeHtml(req.payment_proof)}', '_blank')">
                    <p class="mt-2"><small class="text-muted">Click image to enlarge</small></p>
                </div>
            `;
        } else {
            html += `<hr><p class="text-muted">No payment receipt uploaded</p>`;
        }
        
        if (req.images && req.images.length > 0) {
            html += `<hr><h6 class="fw-bold">Reference Images</h6><div class="row">`;
            req.images.forEach(img => {
                html += `
                    <div class="col-3">
                        <img src="/${escapeHtml(img.image_path)}" class="img-fluid rounded border" style="height: 80px; object-fit: cover; cursor: pointer;"
                             onclick="window.open('/${escapeHtml(img.image_path)}', '_blank')">
                    </div>
                `;
            });
            html += `</div>`;
        }
        
        if (req.completed_model_file) {
            html += `
                <hr>
                <div class="border rounded p-3 mb-3" style="background-color: #f8f9fa;">
                    <div class="d-flex align-items-center mb-2">
                        <i class="bi bi-check-circle-fill text-teal me-2"></i>
                        <strong class="text-teal">Model Completed!</strong>
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-teal" onclick="previewCompletedModel('${escapeHtml(req.completed_model_file)}')">
                            <i data-lucide="eye"></i> Preview Model
                        </button>
                        <a href="/${escapeHtml(req.completed_model_file)}" class="btn btn-sm btn-teal" download>
                            <i data-lucide="download"></i> Download GLB
                        </a>
                    </div>
                </div>
            `;
        }
        
        html += `<hr><div class="action-buttons d-flex justify-content-center gap-2">`;
        
        if (req.payment_status == 'unpaid') {
            html += `
                <button class="btn btn-success" onclick="verifyPayment(${req.id})">
                    <i data-lucide="check-circle"></i> Verify Payment
                </button>
            `;
        }
        
        if (req.payment_status == 'paid' && req.status != 'completed') {
            html += `
                <button class="btn btn-teal" onclick="uploadModel(${req.id})">
                    <i data-lucide="upload"></i> Upload Completed GLB
                </button>
            `;
        }
        
        html += `
            <button class="btn btn-secondary" onclick="$('#viewModal').modal('hide')">
                Close
            </button>
        </div>`;
        
        $('#viewModalBody').html(html);
        
        if (typeof lucide !== 'undefined') {
            setTimeout(() => { lucide.createIcons(); }, 100);
        }
        
    } catch (error) {
        console.error('Error in displayRequestDetails:', error);
        $('#viewModalBody').html(`
            <div class="alert alert-danger">
                Error displaying request details: ${escapeHtml(error.message)}
            </div>
        `);
    }
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function verifyPayment(id) {
    Swal.fire({
        title: 'Verify Payment',
        text: 'Mark this payment as verified?',
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#008080',
        confirmButtonText: 'Yes, verify'
    }).then((result) => {
        if (result.isConfirmed) {
            $('#verify_id').val(id);
            $('#verifyForm').submit();
        }
    });
}

// ============================================
// GLOBAL THREE.JS STATE
// ============================================
let currentPreviewModel = null;
let currentScene        = null;
let currentCamera       = null;
let currentRenderer     = null;
let currentControls     = null;
let currentColorsArray  = [];
let previewAnimationId  = null;
let originalLensColors = new Map(); // Store original lens colors

// ============================================
// LENS DETECTION HELPER
// ============================================
function isLensMaterial(node, material) {
    // Check by material name
    const materialName = (material.name || '').toLowerCase();
    const nodeName = (node.name || '').toLowerCase();
    
    // Common lens material keywords
    const lensKeywords = ['lens', 'glass', 'clear', 'transparent', 'window', 'lense', 'optic', 'lense', 'lenses'];
    
    // Check if material name or node name contains lens keyword
    for (let keyword of lensKeywords) {
        if (materialName.includes(keyword) || nodeName.includes(keyword)) {
            return true;
        }
    }
    
    // Check if material has transparency (lenses are often transparent)
    if (material.transparent === true || material.opacity < 1) {
        return true;
    }
    
    // Check if material is not a standard opaque material (optional)
    // Some lenses might be fully opaque but still named as lens
    if (materialName.includes('lens') || nodeName.includes('lens')) {
        return true;
    }
    
    return false;
}

// ============================================
// GET COLOR HEX VALUE (with case-insensitive matching)
// ============================================
function getColorHexValue(colorName) {
    const colorMap = {
        'Black': '#000000', 'Red': '#ff0000', 'Green': '#00ff00',
        'Blue': '#0000ff', 'Gold': '#ffd700', 'Silver': '#c0c0c0',
        'Pink': '#ff69b4', 'White': '#ffffff', 'Purple': '#800080',
        'Orange': '#ffa500', 'Brown': '#8b4513', 'Cyan': '#00ffff',
        'Magenta': '#ff00ff', 'Yellow': '#ffff00', 'Navy': '#000080',
        'Maroon': '#800000', 'Olive': '#808000', 'Teal': '#008080',
        'Lavender': '#e6e6fa', 'Coral': '#ff7f50', 'Rose Gold': '#B76E79',
        'Copper': '#B87333', 'Gunmetal': '#2C3539', 'Matte Black': '#2C2C2C',
        'Gloss Black': '#111111', 'Tortoise': '#8B5A2B', 'Beige': '#F5F5DC',
        'Champagne': '#F7E7CE', 'Bronze': '#CD7F32', 'Turquoise': '#40E0D0',
        'Crimson': '#DC143C', 'Indigo': '#4B0082'
    };
    
    // Exact match first
    if (colorMap[colorName]) return colorMap[colorName];
    
    // Case-insensitive match
    const key = Object.keys(colorMap).find(k => k.toLowerCase() === colorName.toLowerCase());
    if (key) return colorMap[key];
    
    // If it's a hex color, return as is
    if (/^#[0-9a-f]{6}$/i.test(colorName)) return colorName;
    
    return '#888888';
}

function getColorHexNumber(colorName) {
    const hex = getColorHexValue(colorName);
    return parseInt(hex.replace('#', ''), 16);
}

// ============================================
// SWITCH PREVIEW COLOR (EXCLUDES LENS)
// ============================================
function switchPreviewColor(colorName) {
    if (!currentPreviewModel) return;

    // Update button active states
    document.querySelectorAll('.color-switcher-btn').forEach(btn => {
        const isActive = btn.getAttribute('data-color') === colorName;
        btn.classList.toggle('active', isActive);
        if (isActive) {
            btn.style.borderColor = '#008080';
            btn.style.background = '#008080';
            btn.style.color = 'white';
        } else {
            btn.style.borderColor = '#e5e7eb';
            btn.style.background = 'white';
            btn.style.color = '#374151';
        }
    });

    // Update current color display
    const colorDot = document.getElementById('currentColorDot');
    const colorLabel = document.getElementById('currentColorLabel');
    if (colorDot) colorDot.style.backgroundColor = getColorHexValue(colorName);
    if (colorLabel) colorLabel.textContent = colorName;

    try {
        currentPreviewModel.traverse((node) => {
            if (node.isMesh && node.material) {
                const materials = Array.isArray(node.material) ? node.material : [node.material];
                
                materials.forEach((mat, idx) => {
                    if (mat && mat.color) {
                        const isLens = isLensMaterial(node, mat);
                        const key = `${node.uuid}_${mat.uuid}_${idx}`;
                        
                        if (!isLens) {
                            // Change frame color
                            mat.color.setHex(getColorHexNumber(colorName));
                            mat.emissiveIntensity = ['Black', 'Navy', 'Maroon', 'Matte Black', 'Gloss Black', 'Gunmetal'].includes(colorName) ? 0.1 : 0;
                        } else if (originalLensColors.has(key)) {
                            // Restore original lens color
                            const original = originalLensColors.get(key);
                            mat.color.setHex(original.color);
                            mat.transparent = original.transparent;
                            mat.opacity = original.opacity;
                        }
                    }
                });
            }
        });
    } catch (error) {
        console.error('Error changing color:', error);
    }
}

// ============================================
// PREVIEW GLB WITH COLOR SWITCHER
// ============================================
function previewGLBWithColors(file, colorsArray) {
    currentColorsArray = colorsArray || [];

    const container = document.getElementById('previewContainer');
    if (!container) { console.log('Preview container not found'); return; }

    // Clean up previous Three.js resources
    if (previewAnimationId) { cancelAnimationFrame(previewAnimationId); previewAnimationId = null; }
    if (currentRenderer) { 
        try { currentRenderer.dispose(); } catch(e) {}
        currentRenderer = null; 
    }
    if (currentScene) {
        while (currentScene.children.length > 0) currentScene.remove(currentScene.children[0]);
        currentScene = null;
    }
    
    // Clear original lens colors
    originalLensColors.clear();

    container.innerHTML = '';

    // Build color switcher UI
    const switcherDiv = document.createElement('div');
    switcherDiv.className = 'color-preview-container';

    let colorButtonsHtml = currentColorsArray.length > 0
        ? currentColorsArray.map(color => `
            <button type="button" class="color-switcher-btn" data-color="${escapeHtml(color)}"
                    onclick="switchPreviewColor('${escapeHtml(color)}')" style="cursor:pointer; margin:2px;">
                <span style="display:inline-block; width:14px; height:14px; border-radius:50%;
                             background-color:${getColorHexValue(color)}; margin-right:6px; border:1px solid #ccc;"></span>
                ${escapeHtml(color)}
            </button>`).join('')
        : '<p class="text-muted mb-0">No colors specified</p>';

    switcherDiv.innerHTML = `
        <div class="current-color-label" style="margin-bottom:12px;">
            <span style="display:inline-flex; align-items:center; gap:8px;">
                <span id="currentColorDot" style="width:20px; height:20px; border-radius:50%;
                      background-color:#008080; display:inline-block; border:2px solid white;
                      box-shadow:0 1px 3px rgba(0,0,0,0.2);"></span>
                <span>Current Color: <strong id="currentColorLabel">Loading...</strong></span>
            </span>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3" id="colorButtonsContainer">
            ${colorButtonsHtml}
        </div>
        <div id="threeContainer" style="width:100%; height:280px; background:#f5f5f5;
             border-radius:8px; overflow:hidden; position:relative;"></div>
    `;

    container.appendChild(switcherDiv);

    const threeContainer = document.getElementById('threeContainer');
    if (!threeContainer) { console.log('Three container not found'); return; }

    const reader = new FileReader();
    reader.onload = function(e) {
        try {
            currentScene = new THREE.Scene();
            currentScene.background = new THREE.Color(0xf5f5f5);

            const width  = threeContainer.clientWidth  || 400;
            const height = threeContainer.clientHeight || 280;

            currentCamera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
            currentCamera.position.set(2, 1.5, 3);
            currentCamera.lookAt(0, 0, 0);

            currentRenderer = new THREE.WebGLRenderer({ antialias: true });
            currentRenderer.setSize(width, height);
            currentRenderer.setClearColor(0xf5f5f5);
            threeContainer.innerHTML = '';
            threeContainer.appendChild(currentRenderer.domElement);

            currentControls = new THREE.OrbitControls(currentCamera, currentRenderer.domElement);
            currentControls.enableDamping   = true;
            currentControls.dampingFactor   = 0.05;
            currentControls.autoRotate      = true;
            currentControls.autoRotateSpeed = 1.5;
            currentControls.enableZoom      = true;
            currentControls.enablePan       = true;
            currentControls.target.set(0, 0, 0);

            // Add lights
            currentScene.add(new THREE.AmbientLight(0xffffff, 0.6));
            
            const mainLight = new THREE.DirectionalLight(0xffffff, 1);
            mainLight.position.set(2, 3, 2);
            currentScene.add(mainLight);
            
            const fillLight = new THREE.PointLight(0x88aaff, 0.4);
            fillLight.position.set(-1, 1, 2);
            currentScene.add(fillLight);
            
            const backLight = new THREE.PointLight(0xffaa88, 0.3);
            backLight.position.set(0, 1, -2);
            currentScene.add(backLight);
            
            const rimLight = new THREE.PointLight(0xffaa88, 0.4);
            rimLight.position.set(1, 2, -1.5);
            currentScene.add(rimLight);

            const loader = new THREE.GLTFLoader();
            loader.parse(e.target.result, '', function(gltf) {
                currentPreviewModel = gltf.scene;
                
                // Store original lens colors
                currentPreviewModel.traverse((node) => {
                    if (node.isMesh && node.material) {
                        const materials = Array.isArray(node.material) ? node.material : [node.material];
                        materials.forEach((mat, idx) => {
                            if (isLensMaterial(node, mat) && mat.color) {
                                const key = `${node.uuid}_${mat.uuid}_${idx}`;
                                originalLensColors.set(key, {
                                    color: mat.color.getHex(),
                                    transparent: mat.transparent || false,
                                    opacity: mat.opacity !== undefined ? mat.opacity : 1
                                });
                            }
                        });
                    }
                });

                const box    = new THREE.Box3().setFromObject(currentPreviewModel);
                const center = box.getCenter(new THREE.Vector3());
                const size   = box.getSize(new THREE.Vector3());
                const scale  = 1.5 / Math.max(size.x, size.y, size.z);

                currentPreviewModel.scale.set(scale, scale, scale);
                currentPreviewModel.position.sub(center.multiplyScalar(scale));
                currentScene.add(currentPreviewModel);

                if (currentColorsArray.length > 0) {
                    setTimeout(() => switchPreviewColor(currentColorsArray[0]), 100);
                } else {
                    const lbl = document.getElementById('currentColorLabel');
                    if (lbl) lbl.textContent = 'No colors specified';
                }

                // Check if lens materials were detected
                if (originalLensColors.size === 0 && currentColorsArray.length > 0) {
                    const warningDiv = document.createElement('div');
                    warningDiv.className = 'lens-warning';
                    warningDiv.innerHTML = `
                        <i class="bi bi-info-circle"></i>
                        <strong>Note:</strong> No lens material detected in this GLB file. 
                        The entire frame (including lens area) will change color.
                        For better results, ensure lens materials are named with "lens", "glass", or "clear".
                    `;
                    const colorButtonsContainer = document.getElementById('colorButtonsContainer');
                    if (colorButtonsContainer) {
                        colorButtonsContainer.parentNode.insertBefore(warningDiv, colorButtonsContainer.nextSibling);
                    }
                }

                function animate() {
                    previewAnimationId = requestAnimationFrame(animate);
                    if (currentControls) currentControls.update();
                    if (currentRenderer && currentScene && currentCamera) {
                        currentRenderer.render(currentScene, currentCamera);
                    }
                }
                animate();

            }, undefined, function(error) {
                console.error('Error parsing GLB:', error);
                threeContainer.innerHTML = '<div style="display:flex; justify-content:center; align-items:center; height:100%; color:#dc2626;">❌ Failed to preview model. Please ensure the file is a valid GLB format.</div>';
            });

        } catch (error) {
            console.error('Error setting up 3D preview:', error);
            threeContainer.innerHTML = '<div style="display:flex; justify-content:center; align-items:center; height:100%; color:#dc2626;">❌ Error loading 3D preview</div>';
        }
    };

    reader.onerror = () => {
        console.error('Error reading file');
        threeContainer.innerHTML = '<div style="display:flex; justify-content:center; align-items:center; height:100%; color:#dc2626;">❌ Error reading file</div>';
    };

    reader.readAsArrayBuffer(file);

    const resizeObserver = new ResizeObserver(() => {
        if (currentCamera && currentRenderer && threeContainer) {
            const w = threeContainer.clientWidth;
            const h = threeContainer.clientHeight;
            if (w > 0 && h > 0) {
                currentCamera.aspect = w / h;
                currentCamera.updateProjectionMatrix();
                currentRenderer.setSize(w, h);
            }
        }
    });
    resizeObserver.observe(threeContainer);
}

// ============================================
// UPLOAD MODEL
// ============================================
function uploadModel(id) {
    $.ajax({
        url: '/api/3d_request.php?action=get_request_details&id=' + id,
        dataType: 'json',
        success: function(res) {
            if (res.success && res.data) {
                const req = res.data;
                let colorsArray = [];
                if (req.colors_requested) {
                    colorsArray = req.colors_requested.split(',').map(c => c.trim());
                }

                let colorsHtml = '';
                if (colorsArray.length > 0) {
                    let colorBadges = colorsArray.map(color => {
                        let bgColor = 'secondary';
                        if (color.toLowerCase() === 'black')  bgColor = 'dark';
                        else if (color.toLowerCase() === 'red')   bgColor = 'danger';
                        else if (color.toLowerCase() === 'pink')  bgColor = 'pink';
                        else if (color.toLowerCase() === 'gold')  bgColor = 'warning';
                        else if (color.toLowerCase() === 'silver') bgColor = 'secondary';
                        else bgColor = 'info';
                        return `<span class="badge bg-${bgColor} p-2 me-1">${escapeHtml(color)}</span>`;
                    }).join('');

                    colorsHtml = `
                        <div class="alert alert-info mb-3">
                            <strong>🎨 Required Colors for this Request:</strong><br>
                            <div class="d-flex flex-wrap gap-1 mt-2">${colorBadges}</div>
                            <small class="text-muted mt-2 d-block">⚠️ Please ensure the GLB file contains ALL these colors as materials</small>
                            <small class="text-muted mt-1 d-block">💡 Tip: Name lens materials with "lens", "glass", or "clear" to prevent them from changing color with the frame.</small>
                        </div>
                    `;
                } else {
                    colorsHtml = `<div class="alert alert-warning mb-3">⚠️ No colors specified for this request.</div>`;
                }

                Swal.fire({
                    title: 'Upload Completed 3D Model',
                    html: `
                        ${colorsHtml}
                        <div class="text-center mb-3">
                            <div class="border rounded-3 p-4 bg-light" style="cursor:pointer;" id="uploadArea">
                                <i class="bi bi-cube fs-1 text-teal"></i>
                                <h6 class="mt-2">Click to select 3D Model</h6>
                                <p class="text-muted small mb-0">GLB, GLTF, OBJ, FBX (Max 50MB)</p>
                            </div>
                            <input type="file" id="modelFileInput" class="d-none" accept=".glb,.gltf,.obj,.fbx">
                            <div id="filePreview" class="mt-3" style="display:none;">
                                <div class="alert alert-info">
                                    <i class="bi bi-file-earmark"></i>
                                    <span id="fileName"></span> (<span id="fileSize"></span> MB)
                                </div>
                                <div id="previewContainer" style="width:100%; min-height:350px;"></div>
                            </div>
                        </div>
                    `,
                    showCancelButton: true,
                    confirmButtonText: 'Upload Model',
                    confirmButtonColor: '#008080',
                    cancelButtonText: 'Cancel',
                    width: '800px',
                    preConfirm: () => {
                        const fileInput = document.getElementById('modelFileInput');
                        if (!fileInput.files || !fileInput.files[0]) {
                            Swal.showValidationMessage('Please select a file');
                            return false;
                        }
                        return fileInput.files[0];
                    },
                    didOpen: () => {
                        const uploadArea      = document.getElementById('uploadArea');
                        const modelFileInput  = document.getElementById('modelFileInput');
                        const filePreview     = document.getElementById('filePreview');
                        const fileNameEl      = document.getElementById('fileName');
                        const fileSizeEl      = document.getElementById('fileSize');

                        uploadArea.addEventListener('click', () => modelFileInput.click());

                        modelFileInput.addEventListener('change', function () {
                            if (!this.files || !this.files[0]) return;

                            const file     = this.files[0];
                            const fileName = file.name;
                            const fileSize = (file.size / 1024 / 1024).toFixed(2);

                            if (filePreview)  filePreview.style.display = 'block';
                            if (fileNameEl)   fileNameEl.textContent = fileName;
                            if (fileSizeEl)   fileSizeEl.textContent = fileSize;

                            if (fileName.toLowerCase().endsWith('.glb')) {
                                previewGLBWithColors(file, colorsArray);
                            } else {
                                const previewContainer = document.getElementById('previewContainer');
                                if (previewContainer) {
                                    previewContainer.innerHTML = `
                                        <div class="alert alert-warning text-center">
                                            <i class="bi bi-info-circle"></i> Preview only available for GLB files
                                        </div>
                                    `;
                                }
                            }
                        });
                    }
                }).then((result) => {
                    if (result.isConfirmed) {
                        const file = result.value;
                        const formData = new FormData();
                        formData.append('request_id', id);
                        formData.append('model_file', file);
                        formData.append('upload_model', '1');

                        Swal.fire({
                            title: 'Uploading...',
                            text: 'Please wait',
                            allowOutsideClick: false,
                            didOpen: () => Swal.showLoading()
                        });

                        $.ajax({
                            url: window.location.href,
                            method: 'POST',
                            data: formData,
                            processData: false,
                            contentType: false,
                            success: function() {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Success!',
                                    text: 'Model uploaded successfully',
                                    timer: 1500
                                }).then(() => location.reload());
                            },
                            error: function() {
                                Swal.fire({ icon: 'error', title: 'Error', text: 'Upload failed' });
                            }
                        });
                    }
                });
            } else {
                Swal.fire('Error', 'Failed to load request details', 'error');
            }
        },
        error: function() {
            Swal.fire('Error', 'Failed to load request details', 'error');
        }
    });
}

// ============================================
// PREVIEW COMPLETED MODEL (read-only viewer)
// ============================================
function previewCompletedModel(modelPath) {
    Swal.fire({
        title: '3D Model Preview',
        html: '<div id="previewModalContainer" style="width:100%; height:400px;"></div>',
        showConfirmButton: false,
        showCloseButton: true,
        didOpen: () => {
            const container = document.getElementById('previewModalContainer');

            const scene = new THREE.Scene();
            scene.background = new THREE.Color(0xf5f5f5);

            const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 0.1, 1000);
            camera.position.set(2, 1, 3);

            const renderer = new THREE.WebGLRenderer({ antialias: true });
            renderer.setSize(container.clientWidth, container.clientHeight);
            container.appendChild(renderer.domElement);

            const controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.autoRotate      = true;
            controls.autoRotateSpeed = 2.0;

            scene.add(new THREE.AmbientLight(0xffffff, 0.7));

            const dirLight = new THREE.DirectionalLight(0xffffff, 1);
            dirLight.position.set(2, 3, 2);
            scene.add(dirLight);

            const loader = new THREE.GLTFLoader();
            loader.load('/' + modelPath, function(gltf) {
                const model = gltf.scene;

                const box    = new THREE.Box3().setFromObject(model);
                const size   = box.getSize(new THREE.Vector3());
                const scale  = 2 / Math.max(size.x, size.y, size.z);
                model.scale.set(scale, scale, scale);

                const center = box.getCenter(new THREE.Vector3());
                model.position.sub(center.multiplyScalar(scale));

                scene.add(model);
            }, undefined, function(error) {
                console.error('Error loading model:', error);
                container.innerHTML = '<p class="text-danger text-center mt-3">Failed to load model</p>';
            });

            function animate() {
                requestAnimationFrame(animate);
                controls.update();
                renderer.render(scene, camera);
            }
            animate();

            const resizeObserver = new ResizeObserver(() => {
                camera.aspect = container.clientWidth / container.clientHeight;
                camera.updateProjectionMatrix();
                renderer.setSize(container.clientWidth, container.clientHeight);
            });
            resizeObserver.observe(container);
        }
    });
}
</script>

</body>
</html>