<?php
include __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Initialize cart if not exists
if (!isset($_SESSION['product_cart'])) {
    $_SESSION['product_cart'] = [];
}

$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$supplier_id = (int)($_GET['supplier_id'] ?? 0);  // ✅ NEW
$cart_count = count($_SESSION['product_cart']);

// ✅ NEW: Fetch supplier info kung may filter
$supplier_info = null;
if ($supplier_id) {
    $supplierStmt = $pdo->prepare("SELECT id, supplier_name, city, category FROM suppliers WHERE id = ?");
    $supplierStmt->execute([$supplier_id]);
    $supplier_info = $supplierStmt->fetch(PDO::FETCH_ASSOC);
}

// ✅ CHECK KUNG MAY REORDER SUGGESTIONS NA DUMATING
$reorderItems = [];
$showReorderGuide = false;

if (isset($_GET['action']) && $_GET['action'] === 'reorder' && isset($_GET['items'])) {
    $showReorderGuide = true;
    $reorderItems = json_decode(urldecode($_GET['items']), true);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Select Products</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.8.1/font/bootstrap-icons.css">
    
    <!-- ✅ SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- ✅ Bootstrap JS (para sa modal) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- ✅ SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        .reorder-guide-card {
            background: linear-gradient(135deg, #fff9e6 0%, #fff4d6 100%);
            border-left: 4px solid #ffc107;
        }
        .suggested-item {
            transition: all 0.2s;
        }
        .suggested-item:hover {
            background-color: #fff8e8;
        }
        /* ══════════════════════════════════════════════════════════
   PAGE NAVBAR — CLEAN & SIMPLE (Shopee-style)
   ══════════════════════════════════════════════════════════ */
.page-navbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 12px 24px;
    background: transparent;
    border-bottom: none;
    position: sticky;
    top: 0;
    z-index: 1000;
    backdrop-filter: blur(8px);
}

/* ── BACK BUTTON ── */
.nav-back {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #4b5563;
    text-decoration: none;
    font-weight: 500;
    font-size: 0.9rem;
    padding: 8px 12px;
    border-radius: 8px;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.nav-back:hover {
    background: #f3f4f6;
    color: #008080;
}

.nav-back i {
    font-size: 1rem;
}

/* ── PAGE TITLE ── */
.nav-title {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #111827;
    font-weight: 600;
    font-size: 0.95rem;
    padding: 6px 16px;
    flex: 1;
    justify-content: center;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.nav-title i {
    font-size: 1rem;
    color: #008080;
    flex-shrink: 0;
}

.nav-title span {
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ── CART BUTTON ── */
.nav-cart {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #008080;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
    padding: 8px 16px;
    border-radius: 8px;
    background: #f0fdfa;
    border: 1px solid #99f6e4;
    transition: all 0.15s ease;
    position: relative;
    flex-shrink: 0;
}

.nav-cart:hover {
    background: #ccfbf1;
    border-color: #5eead4;
    color: #006666;
}

.nav-cart i {
    font-size: 1.05rem;
}

.cart-count {
    background: #dc2626;
    color: white;
    font-size: 0.7rem;
    font-weight: 700;
    min-width: 20px;
    height: 20px;
    border-radius: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 5px;
    margin-left: 2px;
}

/* ── MOBILE RESPONSIVE ── */
@media (max-width: 768px) {
    .page-navbar {
        padding: 10px 14px;
        gap: 8px;
    }

    .nav-back {
        padding: 6px 10px;
        font-size: 0.85rem;
    }

    .nav-back span {
        display: none;
    }

    .nav-back i {
        font-size: 1.1rem;
    }

    .nav-title {
        font-size: 0.85rem;
        padding: 6px 10px;
    }

    .nav-title span {
        max-width: 140px;
    }

    .nav-cart {
        padding: 6px 12px;
        font-size: 0.85rem;
    }

    .nav-cart span:not(.cart-count) {
        display: none;
    }

    .nav-cart i {
        font-size: 1.15rem;
    }
}
    </style>
</head>
<body class="bg-light">
<div class="page-navbar">
    <a class="nav-back" href="../main.php?view=supplier">
        <i class="bi bi-arrow-left"></i>
        <span>Back to Suppliers</span>
    </a>

    <?php if ($supplier_info): ?>
    <div class="nav-title">
        <i class="bi bi-shop"></i>
        <span><?php echo htmlspecialchars($supplier_info['supplier_name']); ?></span>
    </div>
    <?php else: ?>
    <div class="nav-title">
        <i class="bi bi-grid-3x3-gap"></i>
        <span>Browse Products</span>
    </div>
    <?php endif; ?>

    <a href="/views/cart.php" class="nav-cart">
        <i class="bi bi-cart3"></i>
        <span>Cart</span>
        <?php if ($cart_count > 0): ?>
            <span class="cart-count"><?php echo $cart_count; ?></span>
        <?php endif; ?>
    </a>
</div>

    <div class="container-fluid px-4 py-4">
    
    <?php if ($supplier_info): ?>
    <!-- ✅ NEW: Supplier Header kung may filter -->
    <div class="alert mb-4" style="background: linear-gradient(135deg, #008080 0%, #006666 100%); color: white; border-radius: 12px; padding: 20px 24px;">
        <div class="d-flex align-items-center gap-3">
            <div style="width:60px;height:60px;background:white;border-radius:12px;display:flex;align-items:center;justify-content:center;">
                <i class="bi bi-building" style="font-size:28px;color:#008080;"></i>
            </div>
            <div>
                <h4 class="mb-1" style="font-weight:700;">
                    <?php echo htmlspecialchars($supplier_info['supplier_name']); ?>
                </h4>
                <div style="opacity:0.9;font-size:0.9rem;">
                    <?php if ($supplier_info['city']): ?>
                        <i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($supplier_info['city']); ?>
                    <?php endif; ?>
                    <?php if ($supplier_info['category']): ?>
                        <span class="ms-3"><i class="bi bi-tag"></i> <?php echo htmlspecialchars($supplier_info['category']); ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Existing content... -->

        <!-- ✅✅✅ DAGDAG LANG ITO - REORDER SUGGESTIONS GUIDE SECTION ✅✅✅ -->
        <?php if ($showReorderGuide && !empty($reorderItems)): ?>
        <div class="card shadow-sm mb-4 reorder-guide-card">
            <div class="card-header bg-transparent border-0 pt-3">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="bi bi-cart-plus fs-4 me-2" style="color: #ffc107;"></i>
                        <span class="fw-bold fs-5">Reorder Suggestions Guide</span>
                        <span class="badge bg-warning text-dark ms-2">For Reference Only</span>
                    </div>
                    <button type="button" class="btn-close" onclick="this.closest('.card').remove()"></button>
                </div>
                <p class="text-muted small mt-2 mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    These items need reordering based on current inventory levels. 
                    <strong>This is just a guide</strong> - you can search and add products below.
                </p>
            </div>
            <div class="card-body pt-0">
                <div class="table-responsive">
                    <table class="table table-sm table-borderless">
                        <thead class="table-light">
                            <tr>
                                <th class="bg-transparent">Item Name</th>
                                <th class="bg-transparent text-center">Current Stock</th>
                                <th class="bg-transparent text-center">Reorder Level</th>
                                <th class="bg-transparent text-center">Suggested Qty</th>
                                <th class="bg-transparent text-end">Est. Cost</th>
                                <th class="bg-transparent text-center"></th>
                            </thead>
                            <tbody>
                                <?php foreach ($reorderItems as $item): ?>
                                <tr class="suggested-item">
                                    <td>
                                        <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                        <br><small class="text-muted">ID: <?php echo $item['id']; ?></small>
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="badge bg-secondary"><?php echo $item['current_stock']; ?></span>
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="badge bg-warning text-dark"><?php echo $item['reorder_level']; ?></span>
                                    </td>
                                    <td class="text-center align-middle">
                                        <span class="fw-bold text-primary fs-6"><?php echo $item['suggested_quantity']; ?></span>
                                        <small class="text-muted d-block">pcs</small>
                                    </td>
                                    <td class="text-end align-middle text-primary fw-bold">
                                        ₱<?php echo number_format($item['estimated_cost'], 2); ?>
                                    </td>
                                    <td class="text-center align-middle">
                                        <button class="btn btn-sm btn-outline-primary" 
                                                onclick="searchSuggestedProduct('<?php echo htmlspecialchars($item['name']); ?>')"
                                                title="Search this product below">
                                            <i class="bi bi-search me-1"></i>Find
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <td colspan="4" class="text-end fw-bold">Total Estimated Cost:</td>
                                    <td class="text-end fw-bold text-primary fs-6">
                                        ₱<?php 
                                            $total = array_sum(array_column($reorderItems, 'estimated_cost'));
                                            echo number_format($total, 2);
                                        ?>
                                    </td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div class="alert alert-warning small mt-2 mb-0">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>
                        <strong>Note:</strong> You can change quantities and choose different suppliers below. 
                        This table is only a guide - actual order can be adjusted based on supplier availability and pricing.
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <!-- ✅✅✅ END OF REORDER SUGGESTIONS GUIDE SECTION ✅✅✅ -->

        <!-- Search and Filter - Minimal (ORIGINAL - WALANG BINAGO) -->
        <div class="row g-3 mb-4">
            <div class="col-md-8">
                <form method="GET" class="d-flex">
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0">
                            <i class="bi bi-search text-teal" style="color: #008080;"></i>
                        </span>
                        <input type="text" name="search" class="form-control border-start-0 ps-0" 
                               placeholder="Search products..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn" style="background-color: #008080; color: white;">Search</button>
                    </div>
                </form>
            </div>
            <div class="col-md-4">
                <select class="form-select" onchange="window.location.href='?category='+this.value">
                    <option value="">All Categories</option>
                    <option value="Frames" <?php echo $category == 'Frames' ? 'selected' : ''; ?>>Frames</option>
                    <option value="Lenses" <?php echo $category == 'Lenses' ? 'selected' : ''; ?>>Lenses</option>
                    <option value="Contact Lenses" <?php echo $category == 'Contact Lenses' ? 'selected' : ''; ?>>Contact Lenses</option>
                    <option value="Accessories" <?php echo $category == 'Accessories' ? 'selected' : ''; ?>>Accessories</option>
                    <option value="Others" <?php echo $category == 'Others' ? 'selected' : ''; ?>>Others</option>
                </select>
            </div>
        </div>

        <!-- Products Grid (ORIGINAL - WALANG BINAGO) -->
        <?php
$query = "SELECT sp.*, s.supplier_name 
          FROM supplier_products sp
          LEFT JOIN suppliers s ON sp.supplier_id = s.id
          WHERE sp.stock > 0";
if ($search) $query .= " AND sp.product_name LIKE '%$search%'";
if ($category) $query .= " AND sp.category = '$category'";
if ($supplier_id) $query .= " AND sp.supplier_id = " . $supplier_id;  // ✅ NEW
$query .= " ORDER BY sp.product_name";
        
        $result = $pdo->query($query);
        $product_count = $result->rowCount();
        ?>
        
        <!-- Results count (ORIGINAL) -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <p class="text-secondary small mb-0">
                <i class="bi bi-grid-3x3-gap-fill me-1"></i>
                <?php echo $product_count; ?> products found
            </p>
        </div>

        <div class="row g-4">
            <?php if ($product_count > 0): ?>
                <?php while ($product = $result->fetch(PDO::FETCH_ASSOC)):
                    $inCart = isset($_SESSION['product_cart'][$product['id']]);
                    $imagePath = $product['photo_path'] ? '../' . $product['photo_path'] : '../assets/img/no-image.png';
                ?>
                <div class="col-md-3">
<div class="card h-100 border-0 shadow-sm product-clickable" 
     onclick="showProductModal(<?php echo $product['id']; ?>)"
     style="cursor: pointer;">
    <div class="position-relative">
        <img src="<?php echo $imagePath; ?>" 
             class="card-img-top" style="height: 200px; object-fit: cover; background-color: #f8f9fa;">
                            <?php if ($inCart): ?>
                                <span class="position-absolute top-0 end-0 m-2 badge bg-success">
                                    <i class="bi bi-check-circle-fill me-1"></i>Added
                                </span>
                            <?php endif; ?>
                            
                            <!-- ✅ 3D BADGE (bagong idagdag) -->
                            <?php if (!empty($product['has_3d_model']) && $product['has_3d_model'] == 1): ?>
                                <span class="position-absolute top-0 start-0 m-2 badge" 
                                      style="background: #008080; color: white; font-size: 0.72rem; padding: 4px 10px;">
                                    <i class="bi bi-cube-fill me-1"></i>3D
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <h6 class="card-title fw-semibold mb-1"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                            <p class="small text-secondary mb-2">
                                <i class="bi bi-shop me-1"></i><?php echo htmlspecialchars($product['supplier_name']); ?>
                            </p>
<div class="d-flex justify-content-between align-items-center">
    <span class="fw-bold" style="color: #008080; font-size: 1.1rem;">
        ₱<?php echo number_format($product['cost_price'], 2); ?>
    </span>
    <i class="bi bi-chevron-right" style="color: #94a3b8; font-size: 1.1rem;"></i>
</div>
                            
                            <!-- ✅ PREVIEW 3D BUTTON (bagong idagdag) -->
                            <?php if (!empty($product['has_3d_model']) && $product['has_3d_model'] == 1): ?>
<button class="btn btn-sm btn-outline-secondary w-100 mt-2" 
        onclick="event.stopPropagation(); preview3D(<?php echo $product['id']; ?>, '<?php echo htmlspecialchars(addslashes($product['product_name'])); ?>')">
                                    <i class="bi bi-eye me-1"></i>Preview 3D
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endwhile; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-box-seam display-1 text-secondary opacity-50"></i>
                        <h5 class="mt-3 text-secondary">No products found</h5>
                        <p class="text-secondary">Try adjusting your search or filter</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ✅ 3D VIEWER MODAL (bagong idagdag) -->
    <div class="modal fade" id="viewerModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header" style="background: #008080; color: white;">
                    <h5 class="modal-title">
                        <i class="bi bi-cube me-2"></i>
                        <span id="viewerTitle">3D Preview</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-0" style="background: #f5f5f5;">
                    <div id="viewer3DContainer" style="width: 100%; height: 500px; position: relative;">
                        <!-- 3D viewer will be rendered here -->
                    </div>
                </div>
                <div class="modal-footer py-2 d-flex justify-content-between">
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-secondary" onclick="rotate3D('left')">
                            <i class="bi bi-arrow-left"></i> Left
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="rotate3D('right')">
                            <i class="bi bi-arrow-right"></i> Right
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" onclick="reset3D()">
                            <i class="bi bi-arrow-repeat"></i> Reset
                        </button>
                    </div>
                    <div class="d-flex gap-2 align-items-center">
                        <small class="text-muted">Drag to rotate • Scroll to zoom</small>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ PRODUCT DETAIL MODAL (Shopee-style) -->
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden; border: none;">
            
            <!-- Close Button -->
            <button type="button" class="btn-close position-absolute top-0 end-0 m-3" 
                    data-bs-dismiss="modal" 
                    style="z-index: 10; background: white; border-radius: 50%; padding: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.15);">
            </button>

            <div class="modal-body p-0">
                <div class="row g-0">
                    
                    <!-- LEFT: Product Image -->
                    <div class="col-md-5">
                        <div style="height: 100%; min-height: 400px; background: #f8f9fa; display: flex; align-items: center; justify-content: center; padding: 20px;">
                            <img id="modalProductImage" 
                                 src="" 
                                 alt="Product"
                                 style="width: 100%; max-height: 400px; object-fit: contain; border-radius: 12px;">
                        </div>
                    </div>

                    <!-- RIGHT: Product Details -->
                    <div class="col-md-7">
                        <div style="padding: 32px 28px;">
                            
                            <!-- 3D Badge -->
                            <div id="modal3dBadge" style="display: none; margin-bottom: 12px;">
                                <span style="background: #008080; color: white; padding: 4px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 600;">
                                    <i class="bi bi-cube-fill"></i> 3D Model Available
                                </span>
                            </div>

                            <!-- Product Name -->
                            <h3 id="modalProductName" style="font-weight: 700; font-size: 1.5rem; color: #0f172a; margin-bottom: 8px; line-height: 1.3;"></h3>

                            <!-- Supplier -->
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 16px;">
                                <i class="bi bi-shop" style="color: #008080;"></i>
                                <span id="modalProductSupplier" style="color: #64748b; font-size: 0.9rem;"></span>
                            </div>

                            <!-- Rating (static placeholder) -->
                            <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #f1f5f9;">
                                <div style="color: #fbbf24; font-size: 1rem;">
                                    <i class="bi bi-star-fill"></i>
                                    <i class="bi bi-star-fill"></i>
                                    <i class="bi bi-star-fill"></i>
                                    <i class="bi bi-star-fill"></i>
                                    <i class="bi bi-star-half"></i>
                                </div>
                                <span style="color: #64748b; font-size: 0.85rem;">4.5 (12 reviews)</span>
                            </div>

                            <!-- Price -->
                            <div style="background: linear-gradient(90deg, #f0fdfa 0%, #e6fffa 100%); padding: 16px 20px; border-radius: 12px; margin-bottom: 20px;">
                                <div style="font-size: 0.75rem; color: #64748b; margin-bottom: 4px;">Price</div>
                                <div id="modalProductPrice" style="font-size: 1.75rem; font-weight: 700; color: #008080;"></div>
                            </div>

                            <!-- Description -->
                            <div id="modalDescriptionWrap" style="margin-bottom: 20px;">
                                <div style="font-size: 0.8rem; font-weight: 600; color: #0f172a; margin-bottom: 6px;">Description</div>
                                <p id="modalProductDescription" style="color: #64748b; font-size: 0.88rem; line-height: 1.6; margin: 0;"></p>
                            </div>

                            <!-- Stock Info -->
                            <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 20px; padding: 10px 14px; background: #f8fafc; border-radius: 8px;">
                                <i class="bi bi-box-seam" style="color: #008080;"></i>
                                <span style="font-size: 0.85rem; color: #475569;">In Stock: </span>
                                <span id="modalProductStock" style="font-size: 0.85rem; font-weight: 600; color: #0f172a;"></span>
                            </div>

                            <!-- Quantity Selector -->
                            <div style="margin-bottom: 24px;">
                                <div style="font-size: 0.8rem; font-weight: 600; color: #0f172a; margin-bottom: 8px;">Quantity</div>
                                <div style="display: inline-flex; align-items: center; border: 1.5px solid #e2e8f0; border-radius: 10px; overflow: hidden;">
                                    <button type="button" onclick="changeModalQty(-1)" 
                                            style="width: 40px; height: 40px; border: none; background: white; color: #475569; font-size: 1.1rem; cursor: pointer; transition: all 0.15s;">
                                        <i class="bi bi-dash"></i>
                                    </button>
                                    <input type="text" id="modalQtyInput" value="1" readonly
                                           style="width: 60px; height: 40px; border: none; border-left: 1.5px solid #e2e8f0; border-right: 1.5px solid #e2e8f0; text-align: center; font-weight: 600; color: #0f172a; font-size: 0.95rem; outline: none;">
                                    <button type="button" onclick="changeModalQty(1)"
                                            style="width: 40px; height: 40px; border: none; background: white; color: #475569; font-size: 1.1rem; cursor: pointer; transition: all 0.15s;">
                                        <i class="bi bi-plus"></i>
                                    </button>
                                </div>
                            </div>

                            <!-- Action Buttons -->
                            <div style="display: flex; gap: 10px;">
                                <button type="button" class="modal-btn-secondary" onclick="addToCartFromModal()" style="flex: 1; padding: 14px; border: 1.5px solid #008080; background: white; color: #008080; border-radius: 12px; font-weight: 600; font-size: 0.9rem; transition: all 0.2s;">
                                    <i class="bi bi-cart-plus"></i> Add to Cart
                                </button>
                                <button type="button" class="modal-btn-primary" onclick="buyNowFromModal()" style="flex: 1; padding: 14px; border: none; background: linear-gradient(135deg, #008080 0%, #006666 100%); color: white; border-radius: 12px; font-weight: 700; font-size: 0.9rem; box-shadow: 0 4px 12px rgba(0,128,128,0.3); transition: all 0.2s;">
                                    <i class="bi bi-lightning-charge-fill"></i> Buy Now
                                </button>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
/* ✅ PRODUCT DETAIL MODAL STYLES */
.product-clickable:hover {
    transform: translateY(-4px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.1);
    transition: all 0.3s;
}

.modal-btn-secondary:hover {
    background: #f0fdfa;
    transform: translateY(-2px);
}

.modal-btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(0,128,128,0.4);
}

#modalQtyInput:focus {
    background: #f0fdfa;
}
</style>
<!-- ✅ SweetAlert2 JS -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- ✅ THREE.JS LIBRARIES -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
<script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/loaders/GLTFLoader.js"></script>

    <script>
        // ============================================
// ✅ PRODUCT DETAIL MODAL FUNCTIONS
// ============================================
let currentModalProduct = null;
let currentModalQty = 1;

// Store products data para accessible sa modal
const productsData = {};

<?php
// Generate JS object ng lahat ng products
$result->execute(); // Re-execute para makuha lahat (kung PDO::FETCH_ASSOC na una na-execute)
// Actually, i-query natin muli
$allProductsQuery = $pdo->query($query);
$productsJs = [];
while ($p = $allProductsQuery->fetch(PDO::FETCH_ASSOC)) {
    $productsJs[$p['id']] = [
        'id' => $p['id'],
        'name' => $p['product_name'],
        'supplier' => $p['supplier_name'],
        'price' => (float)$p['cost_price'],
        'image' => $p['photo_path'] ? '../' . $p['photo_path'] : '../assets/img/no-image.png',
        'description' => $p['description'] ?? 'No description available.',
        'stock' => (int)$p['stock'],
        'min_order_qty' => (int)$p['min_order_qty'],
        'has_3d' => !empty($p['has_3d_model']) && $p['has_3d_model'] == 1
    ];
}
?>
const PRODUCTS = <?php echo json_encode($productsJs); ?>;

function showProductModal(productId) {
    const product = PRODUCTS[productId];
    if (!product) {
        console.error('Product not found:', productId);
        return;
    }

    currentModalProduct = product;
    currentModalQty = 1;

    // Populate modal
    document.getElementById('modalProductImage').src = product.image;
    document.getElementById('modalProductImage').onerror = function() {
        this.src = '../assets/img/no-image.png';
    };
    document.getElementById('modalProductName').textContent = product.name;
    document.getElementById('modalProductSupplier').textContent = product.supplier;
    document.getElementById('modalProductPrice').textContent = '₱' + product.price.toLocaleString('en-PH', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    document.getElementById('modalProductDescription').textContent = product.description;
    document.getElementById('modalProductStock').textContent = product.stock + ' units';
    document.getElementById('modalQtyInput').value = 1;

    // 3D Badge
    document.getElementById('modal3dBadge').style.display = product.has_3d ? 'block' : 'none';

    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('productModal'));
    modal.show();
}

function changeModalQty(delta) {
    if (!currentModalProduct) return;

    const newQty = currentModalQty + delta;
    const minQty = currentModalProduct.min_order_qty || 1;
    const maxQty = currentModalProduct.stock || 999;

    if (newQty < minQty) {
        Swal.fire({
            icon: 'info',
            title: 'Minimum Quantity',
            text: `Minimum order quantity is ${minQty}`,
            timer: 1500,
            showConfirmButton: false
        });
        return;
    }

    if (newQty > maxQty) {
        Swal.fire({
            icon: 'info',
            title: 'Maximum Quantity',
            text: `Only ${maxQty} units available`,
            timer: 1500,
            showConfirmButton: false
        });
        return;
    }

    currentModalQty = newQty;
    document.getElementById('modalQtyInput').value = newQty;
}

function addToCartFromModal() {
    if (!currentModalProduct) return;

    const productId = currentModalProduct.id;
    const qty = currentModalQty;

    // ✅ Gamitin yung existing cart API
    const formData = 'action=add&product_id=' + productId + '&quantity=' + qty;

    fetch('../api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire({
                icon: 'success',
                title: 'Added to Cart!',
                text: `${qty}x ${currentModalProduct.name} added to your cart.`,
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                // Close modal
                const modalEl = document.getElementById('productModal');
                const modalInstance = bootstrap.Modal.getInstance(modalEl);
                if (modalInstance) modalInstance.hide();

                // Redirect sa cart
                window.location.href = '/views/cart.php';
            });
        } else {
            Swal.fire('Error', data.message || 'Failed to add to cart', 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        Swal.fire('Error', 'Network error. Please try again.', 'error');
    });
}

function buyNowFromModal() {
    if (!currentModalProduct) return;

    const productId = currentModalProduct.id;
    const qty = currentModalQty;

    // Add to cart + redirect
    fetch('../api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=add&product_id=' + productId + '&quantity=' + qty
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // Redirect agad sa cart
            window.location.href = '/views/cart.php';
        } else {
            Swal.fire('Error', data.message || 'Failed to proceed', 'error');
        }
    })
    .catch(err => {
        console.error('Error:', err);
        Swal.fire('Error', 'Network error. Please try again.', 'error');
    });
}
    function addToCart(id) {
        fetch('../api/cart.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=add&product_id=' + id + '&quantity=1'
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) location.reload();
        });
    }
    
    // ✅ Dagdag function para sa "Find" button
    function searchSuggestedProduct(productName) {
        // I-set ang search input sa product name
        const searchInput = document.querySelector('input[name="search"]');
        if (searchInput) {
            searchInput.value = productName;
            // Submit ang search form
            const searchForm = searchInput.closest('form');
            if (searchForm) {
                searchForm.submit();
            }
        }
    }
    
    // ============================================
    // ✅ 3D VIEWER FUNCTIONS (bagong idagdag)
    // ============================================
    let viewerScene, viewerCamera, viewerRenderer, viewerControls, viewerModel;
    let viewerAnimationId = null;
    
    function preview3D(productId, productName) {
        // Show modal
        const modalEl = document.getElementById('viewerModal');
        const modal = new bootstrap.Modal(modalEl);
        
        // Update title
        document.getElementById('viewerTitle').textContent = productName || '3D Preview';
        
        // Show loading state
        const container = document.getElementById('viewer3DContainer');
        container.innerHTML = '<div class="text-center p-5" style="display: flex; align-items: center; justify-content: center; height: 100%;"><div class="spinner-border" style="color: #008080;"></div></div>';
        
        modal.show();
        
        // Fetch 3D model path
        fetch('../api/get_3d_model.php?id=' + productId)
            .then(res => res.json())
            .then(data => {
                if (data.success && data.model_path) {
                    // Small delay to ensure modal is fully rendered
                    setTimeout(() => initViewer3D(data.model_path, container), 200);
                } else {
                    container.innerHTML = `
                        <div class="text-center p-5" style="display: flex; align-items: center; justify-content: center; height: 100%; flex-direction: column;">
                            <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i>
                            <p class="mt-3 text-muted">3D model not available for this product.</p>
                        </div>
                    `;
                }
            })
            .catch(err => {
                console.error('Error loading 3D:', err);
                container.innerHTML = `
                    <div class="text-center p-5" style="display: flex; align-items: center; justify-content: center; height: 100%; flex-direction: column;">
                        <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i>
                        <p class="mt-3 text-muted">Failed to load 3D model.</p>
                    </div>
                `;
            });
    }
    
    function initViewer3D(modelPath, container) {
        // Cleanup previous instance
        if (viewerAnimationId) {
            cancelAnimationFrame(viewerAnimationId);
            viewerAnimationId = null;
        }
        if (viewerRenderer) {
            try { viewerRenderer.dispose(); } catch(e) {}
            viewerRenderer = null;
        }
        
        // Clear container
        container.innerHTML = '';
        
        // Ensure path is correct
        let fullPath = modelPath;
        if (!fullPath.startsWith('http') && !fullPath.startsWith('/')) {
            fullPath = '../' + fullPath;
        }
        
        // Setup scene
        viewerScene = new THREE.Scene();
        viewerScene.background = new THREE.Color(0xf5f5f5);
        
        const width = container.clientWidth;
        const height = container.clientHeight;
        
        viewerCamera = new THREE.PerspectiveCamera(45, width / height, 0.1, 1000);
        viewerCamera.position.set(3, 1.5, 4);
        
        viewerRenderer = new THREE.WebGLRenderer({ antialias: true });
        viewerRenderer.setSize(width, height);
        viewerRenderer.shadowMap.enabled = true;
        container.appendChild(viewerRenderer.domElement);
        
        // Controls
        viewerControls = new THREE.OrbitControls(viewerCamera, viewerRenderer.domElement);
        viewerControls.enableDamping = true;
        viewerControls.dampingFactor = 0.05;
        viewerControls.autoRotate = true;
        viewerControls.autoRotateSpeed = 1.5;
        viewerControls.enableZoom = true;
        
        // Lights
        viewerScene.add(new THREE.AmbientLight(0xffffff, 0.7));
        
        const mainLight = new THREE.DirectionalLight(0xffffff, 1.2);
        mainLight.position.set(3, 5, 4);
        viewerScene.add(mainLight);
        
        const fillLight = new THREE.DirectionalLight(0xffeedd, 0.5);
        fillLight.position.set(-2, 2, 3);
        viewerScene.add(fillLight);
        
        const backLight = new THREE.PointLight(0x88aaff, 0.4);
        backLight.position.set(0, 1, -3);
        viewerScene.add(backLight);
        
        // Load model
        const loader = new THREE.GLTFLoader();
        loader.load(fullPath, function(gltf) {
            viewerModel = gltf.scene;
            
            // Auto-scale and center
            const box = new THREE.Box3().setFromObject(viewerModel);
            const center = box.getCenter(new THREE.Vector3());
            const size = box.getSize(new THREE.Vector3());
            const scale = 2.5 / Math.max(size.x, size.y, size.z);
            
            viewerModel.scale.set(scale, scale, scale);
            viewerModel.position.set(-center.x * scale, -center.y * scale, -center.z * scale);
            
            viewerScene.add(viewerModel);
            
            // Update controls target
            const newCenter = new THREE.Box3().setFromObject(viewerModel).getCenter(new THREE.Vector3());
            viewerControls.target.copy(newCenter);
            viewerControls.update();
            
        }, undefined, function(err) {
            console.error('3D load error:', err);
            container.innerHTML = `
                <div class="text-center p-5" style="display: flex; align-items: center; justify-content: center; height: 100%; flex-direction: column;">
                    <i class="bi bi-exclamation-triangle" style="font-size: 3rem; color: #dc3545;"></i>
                    <p class="mt-3 text-muted">Failed to load 3D model. The file may be corrupted or missing.</p>
                </div>
            `;
        });
        
        // Animation loop
        function animate() {
            viewerAnimationId = requestAnimationFrame(animate);
            if (viewerControls) viewerControls.update();
            if (viewerRenderer && viewerScene && viewerCamera) {
                viewerRenderer.render(viewerScene, viewerCamera);
            }
        }
        animate();
        
        // Handle resize
        window.addEventListener('resize', function() {
            if (viewerCamera && viewerRenderer && container.clientWidth && container.clientHeight) {
                viewerCamera.aspect = container.clientWidth / container.clientHeight;
                viewerCamera.updateProjectionMatrix();
                viewerRenderer.setSize(container.clientWidth, container.clientHeight);
            }
        });
    }
    
    function rotate3D(direction) {
        if (!viewerModel) return;
        viewerModel.rotation.y += direction === 'left' ? 0.5 : -0.5;
    }
    
    function reset3D() {
        if (!viewerModel) return;
        viewerModel.rotation.set(0, 0, 0);
        if (viewerCamera) {
            viewerCamera.position.set(3, 1.5, 4);
            viewerCamera.lookAt(0, 0, 0);
        }
    }
    
    // Cleanup kapag isinara yung modal
    document.addEventListener('DOMContentLoaded', function() {
        const modalEl = document.getElementById('viewerModal');
        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function() {
                if (viewerAnimationId) {
                    cancelAnimationFrame(viewerAnimationId);
                    viewerAnimationId = null;
                }
                if (viewerRenderer) {
                    try { viewerRenderer.dispose(); } catch(e) {}
                    viewerRenderer = null;
                }
                viewerScene = viewerCamera = viewerControls = viewerModel = null;
                
                const container = document.getElementById('viewer3DContainer');
                if (container) container.innerHTML = '';
            });
        }
    });
    </script>
</body>
</html>