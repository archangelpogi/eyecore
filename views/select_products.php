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
$cart_count = count($_SESSION['product_cart']);

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
    </style>
</head>
<body class="bg-light">
    <!-- Header with Cart - Teal theme -->
    <nav class="navbar navbar-expand-lg navbar-dark" style="background-color: #008080;">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="../main.php?view=purchase_requests">
                <i class="bi bi-arrow-left me-2"></i>Back to PR List
            </a>
            <div>
                <a href="cart.php" class="btn btn-light text-teal position-relative" style="color: #008080; border-color: #008080;">
                    <i class="bi bi-cart3 me-1"></i> Cart
                    <?php if ($cart_count > 0): ?>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?php echo $cart_count; ?>
                        </span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 py-4">

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
                    <div class="card h-100 border-0 shadow-sm">
                        <div class="position-relative">
                            <img src="<?php echo $imagePath; ?>" 
                                 class="card-img-top" style="height: 200px; object-fit: cover; background-color: #f8f9fa;">
                            <?php if ($inCart): ?>
                                <span class="position-absolute top-0 end-0 m-2 badge bg-success">
                                    <i class="bi bi-check-circle-fill me-1"></i>Added
                                </span>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <h6 class="card-title fw-semibold mb-1"><?php echo htmlspecialchars($product['product_name']); ?></h6>
                            <p class="small text-secondary mb-2">
                                <i class="bi bi-shop me-1"></i><?php echo htmlspecialchars($product['supplier_name']); ?>
                            </p>
                            <div class="d-flex justify-content-between align-items-center">
                                <span class="fw-bold" style="color: #008080;">₱<?php echo number_format($product['cost_price'], 2); ?></span>
                                <button class="btn btn-sm" 
                                        style="background-color: <?php echo $inCart ? '#e9ecef' : '#008080'; ?>; color: <?php echo $inCart ? '#6c757d' : 'white'; ?>;"
                                        onclick="addToCart(<?php echo $product['id']; ?>)"
                                        <?php echo $inCart ? 'disabled' : ''; ?>>
                                    <i class="bi <?php echo $inCart ? 'bi-check-lg' : 'bi-cart-plus'; ?> me-1"></i>
                                    <?php echo $inCart ? 'Added' : 'Add'; ?>
                                </button>
                            </div>
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

    <script>
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
    </script>
</body>
</html>