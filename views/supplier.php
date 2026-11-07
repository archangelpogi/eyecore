<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';

RBACHelper::init($pdo);

$subHelper = new SubscriptionHelper($pdo, $_SESSION['clinic_id']);
if (!$subHelper->canAccessModule('supply_chain')) {
    header('Location: ../views/subscription.php');
    exit;
}

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!RBACHelper::hasPermission('supplier_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Suppliers Directory.</p>
        </div>
    </div>
    <?php
    exit;
}

function getCategoryIcon($category) {
    $icons = [
        'Frames'           => 'bi-eye',
        'Lenses'           => 'bi-brightness-high',
        'Contact Lenses'   => 'bi-eye-fill',
        'Accessories'      => 'bi-watch',
        'Medical Supplies' => 'bi-heart-pulse',
        'Equipment'        => 'bi-tools',
        'Others'           => 'bi-grid',
    ];
    return $icons[$category] ?? 'bi-tag';
}

$current_user_id = $_SESSION['user_id'];
$user_role       = $_SESSION['role'] ?? 'SCM';
$user_name       = $_SESSION['name'] ?? 'User';
$canView         = RBACHelper::hasPermission('supplier_view');

$stats      = ['total' => 0, 'active' => 0, 'top_rated' => 0];
$categories = [];
$suppliers  = [];

try {
    $statsQuery = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN average_rating >= 4 THEN 1 ELSE 0 END) as top_rated
        FROM suppliers
    ");
    $statsRow = $statsQuery->fetch(PDO::FETCH_ASSOC);
    $stats    = array_merge($stats, $statsRow);

    $catQuery = $pdo->query("
        SELECT DISTINCT category FROM supplier_products 
        WHERE category IS NOT NULL AND category != ''
        UNION
        SELECT 'Frames' UNION SELECT 'Lenses' UNION SELECT 'Contact Lenses' 
        UNION SELECT 'Accessories' UNION SELECT 'Medical Supplies'
        UNION SELECT 'Equipment' UNION SELECT 'Others'
        ORDER BY category
    ");
    $categories = $catQuery->fetchAll(PDO::FETCH_COLUMN);

    $supplierQuery = $pdo->query("
        SELECT s.*,
               (SELECT COUNT(*) FROM supplier_products WHERE supplier_id = s.id) as product_count
        FROM suppliers s
        ORDER BY s.average_rating DESC, s.supplier_name ASC
    ");
    $suppliers = $supplierQuery->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    $categories = ['Frames', 'Lenses', 'Contact Lenses', 'Accessories', 'Others'];
    $suppliers  = [];
}

// Helper function — lahat ng supplier "Open now" para sa demo
function isSupplierOpen($supplier) {
    // Static: lahat ng suppliers ay "Open" for demo purposes
    // TODO: Gawing dynamic sa production base sa business hours
    return true;
}

// Helper function para sa random image (placeholder)
function getSupplierImage($supplierId) {
    $images = [
        'https://images.unsplash.com/photo-1580281658223-9b93f18ae9ae?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1574258495973-f010dfbb5371?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1577803645773-f96470509666?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1509695507497-903c140c43b0?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1591076482161-42ce6da69f67?w=600&h=400&fit=crop',
        'https://images.unsplash.com/photo-1556306535-0f09a537f0a3?w=600&h=400&fit=crop',
    ];
    return $images[$supplierId % count($images)];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Supplier Directory - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #008080;
            --primary-dark: #006666;
            --primary-light: #e6f3f3;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * { box-sizing: border-box; }

        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        /* ============================================
           HERO SECTION
        ============================================ */
        .hero-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 20px;
            padding: 32px 24px;
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
        }

        .hero-section::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -20%;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.08);
            border-radius: 50%;
        }

        .hero-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: white;
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
        }

        .hero-subtitle {
            font-size: 0.95rem;
            color: rgba(255,255,255,0.85);
            margin-bottom: 0;
            position: relative;
            z-index: 1;
        }

        .hero-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            position: relative;
            z-index: 1;
            margin-top: 20px;
        }

        .hero-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            color: var(--primary);
            padding: 11px 22px;
            border-radius: 50px;
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            border: none;
            cursor: pointer;
            transition: all 0.2s;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }

        .hero-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.15);
            color: var(--primary);
        }

        .hero-btn-outline {
            background: transparent;
            color: white;
            border: 2px solid rgba(255,255,255,0.4);
            box-shadow: none;
        }

        .hero-btn-outline:hover {
            background: rgba(255,255,255,0.15);
            color: white;
            border-color: white;
        }

        /* ============================================
           SEARCH BAR
        ============================================ */
        .search-wrapper {
            position: relative;
            margin-bottom: 20px;
        }

        .search-input {
            width: 100%;
            border-radius: 50px;
            border: 1.5px solid #e2e8f0;
            padding: 14px 20px 14px 48px;
            font-size: 0.95rem;
            background: white;
            transition: all 0.2s;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0,128,128,0.1);
        }

        .search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.1rem;
            pointer-events: none;
        }

        /* ============================================
           STATS CARDS
        ============================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 18px 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: #0f172a;
            line-height: 1;
            margin-bottom: 6px;
        }

        .stat-label {
            font-size: 0.8rem;
            color: #64748b;
            font-weight: 500;
        }

        /* ============================================
           CATEGORY PILLS (Shopee-style)
        ============================================ */
        .categories-wrapper {
            margin-bottom: 20px;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 8px;
            scrollbar-width: thin;
            -webkit-overflow-scrolling: touch;
        }

        .categories-wrapper::-webkit-scrollbar { height: 4px; }
        .categories-wrapper::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        .categories-wrapper::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px; }

        .category-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: white;
            border: 1.5px solid #e2e8f0;
            padding: 9px 18px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.85rem;
            font-weight: 500;
            color: #475569;
            margin-right: 8px;
            white-space: nowrap;
        }

        .category-pill:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .category-pill.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* ============================================
           SUPPLIER CARDS (Shopee-style)
        ============================================ */
        .suppliers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
        }

        .supplier-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 1px 4px rgba(0,0,0,0.04);
            transition: all 0.3s;
            cursor: pointer;
            border: 1px solid #eef2f6;
            display: flex;
            flex-direction: column;
            height: 100%;
        }

        .supplier-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px rgba(0,0,0,0.1);
            border-color: transparent;
        }

        /* Supplier Image */
        .supplier-img-wrap {
            position: relative;
            width: 100%;
            height: 160px;
            overflow: hidden;
            background: #f1f5f9;
        }

        .supplier-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.4s;
        }

        .supplier-card:hover .supplier-img {
            transform: scale(1.05);
        }

        /* Status Badge (top-left) */
        .status-badge {
            position: absolute;
            top: 12px;
            left: 12px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 700;
            color: white;
            display: flex;
            align-items: center;
            gap: 5px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
        }

        .status-badge.open {
            background: var(--success);
        }

        .status-badge.closed {
            background: var(--danger);
        }

        .status-badge::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: white;
        }

        /* Rating Badge (top-right) */
        .rating-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: white;
            padding: 5px 10px;
            border-radius: 50px;
            font-size: 0.75rem;
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .rating-badge i {
            color: var(--warning);
            font-size: 0.85rem;
        }

        /* Card Body */
        .supplier-body {
            padding: 16px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .supplier-name {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
            line-height: 1.3;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .supplier-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 12px;
        }

        .info-row {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            color: #64748b;
        }

        .info-row i {
            color: var(--primary);
            font-size: 0.9rem;
            flex-shrink: 0;
            width: 16px;
        }

        .info-row-text {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .info-row.highlight {
            color: #0f172a;
            font-weight: 500;
        }

        .hours-open { color: var(--success); }
        .hours-closed { color: var(--danger); }

        /* Product count */
        .product-count {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            color: var(--primary);
            font-weight: 600;
            padding: 8px 0;
            border-top: 1px solid #f1f5f9;
            margin-top: auto;
        }

        /* Card Actions */
        .supplier-actions {
            padding: 12px 16px;
            background: #fafbfc;
            border-top: 1px solid #f1f5f9;
            display: flex;
            gap: 8px;
        }

        .btn-action {
            flex: 1;
            padding: 10px 12px;
            border-radius: 50px;
            font-size: 0.82rem;
            font-weight: 600;
            border: none;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            transition: all 0.2s;
            text-decoration: none;
        }

        .btn-view {
            background: var(--primary);
            color: white;
        }

        .btn-view:hover {
            background: var(--primary-dark);
            color: white;
            transform: translateY(-1px);
        }

        .btn-contact {
            background: white;
            color: #475569;
            border: 1.5px solid #e2e8f0;
        }

        .btn-contact:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-1px);
        }

        /* ============================================
           EMPTY STATE
        ============================================ */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            grid-column: 1 / -1;
        }

        .empty-state i {
            font-size: 64px;
            color: #cbd5e1;
            margin-bottom: 20px;
            display: block;
        }

        .empty-state h5 {
            color: #475569;
            margin-bottom: 8px;
        }

        .empty-state p {
            color: #94a3b8;
        }

        /* ============================================
           MODAL (Shopee-style)
        ============================================ */
        .modal-content-custom {
            border-radius: 20px;
            overflow: hidden;
            border: none;
        }

        .modal-header-custom {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 24px;
            position: relative;
        }

        .modal-supplier-logo {
            width: 60px;
            height: 60px;
            background: white;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .modal-supplier-logo i {
            font-size: 30px;
            color: var(--primary);
        }

        /* ============================================
           RESPONSIVE (Mobile-first)
        ============================================ */
        @media (max-width: 992px) {
            .suppliers-grid {
                grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
                gap: 16px;
            }
        }

        @media (max-width: 768px) {
            .hero-section {
                padding: 24px 20px;
                border-radius: 16px;
            }

            .hero-title {
                font-size: 1.4rem;
            }

            .hero-subtitle {
                font-size: 0.85rem;
            }

            .hero-actions {
                flex-direction: column;
            }

            .hero-btn {
                width: 100%;
                justify-content: center;
            }

            .stats-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .stat-card {
                padding: 14px 16px;
            }

            .stat-value {
                font-size: 1.4rem;
            }

            .suppliers-grid {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .supplier-img-wrap {
                height: 140px;
            }

            .category-pill {
                padding: 8px 14px;
                font-size: 0.8rem;
            }
        }

        @media (max-width: 480px) {
            .supplier-card {
                border-radius: 12px;
            }

            .supplier-name {
                font-size: 0.95rem;
            }

            .info-row {
                font-size: 0.78rem;
            }

            .btn-action {
                font-size: 0.75rem;
                padding: 9px 10px;
            }

            .status-badge, .rating-badge {
                font-size: 0.65rem;
                padding: 4px 9px;
            }
        }

        /* Permission Badge */
        .permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            z-index: 9999;
            opacity: 0.85;
            box-shadow: 0 4px 12px rgba(0,128,128,0.3);
        }
    </style>
</head>
<body>

<div class="container px-3 px-md-4 py-4">

    <!-- HERO SECTION -->
    <div class="hero-section">
        <h1 class="hero-title">Find Your Perfect Supplier</h1>
        <p class="hero-subtitle">Discover trusted partners for frames, lenses, and more</p>

        <!-- Actions -->
        <div class="hero-actions">
            <a href="main.php?view=purchase_orders" class="hero-btn">
                <i class="bi bi-box-seam"></i> My Orders
            </a>
            <a href="main.php?view=select_products" class="hero-btn hero-btn-outline">
                <i class="bi bi-grid-3x3-gap"></i> Browse All Products
            </a>
        </div>
    </div>

    <!-- SEARCH BAR -->
    <div class="search-wrapper">
        <i class="bi bi-search search-icon"></i>
        <input type="text" id="searchInput" class="search-input"
               placeholder="Search suppliers by name or location...">
    </div>

    <!-- STATS CARDS -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
            <div class="stat-label"><i class="bi bi-shop me-1"></i>Total Suppliers</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: var(--success);"><?php echo (int)$stats['active']; ?></div>
            <div class="stat-label"><i class="bi bi-check-circle me-1"></i>Active Partners</div>
        </div>
        <div class="stat-card">
            <div class="stat-value" style="color: var(--warning);"><?php echo (int)$stats['top_rated']; ?></div>
            <div class="stat-label"><i class="bi bi-star me-1"></i>Top Rated</div>
        </div>
    </div>

    <!-- CATEGORY PILLS -->
    <div class="categories-wrapper">
        <div class="category-pill active" data-category="all">
            <i class="bi bi-grid-3x3-gap-fill"></i> All
        </div>
        <?php foreach ($categories as $cat): ?>
            <div class="category-pill" data-category="<?php echo strtolower(htmlspecialchars($cat)); ?>">
                <i class="bi <?php echo getCategoryIcon($cat); ?>"></i>
                <?php echo htmlspecialchars($cat); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- SUPPLIER GRID -->
    <div class="suppliers-grid" id="suppliersGrid">
        <?php if (empty($suppliers)): ?>
            <div class="empty-state">
                <i class="bi bi-shop"></i>
                <h5>No Suppliers Found</h5>
                <p>Check back later for new partners</p>
            </div>
        <?php else: ?>
            <?php foreach ($suppliers as $supplier):
                $ratingRaw    = !empty($supplier['average_rating']) ? (float)$supplier['average_rating']
                              : (!empty($supplier['rating'])        ? (float)$supplier['rating'] : 3);
                $rating       = max(0, min(5, $ratingRaw));
                $cardCategory = strtolower($supplier['category'] ?? 'general');
                $isOpen       = isSupplierOpen($supplier);
                $imageUrl     = getSupplierImage($supplier['id']);
                $productCount = (int)($supplier['product_count'] ?? 0);
            ?>
            <div class="supplier-item"
                 data-name="<?php echo strtolower(htmlspecialchars($supplier['supplier_name'])); ?>"
                 data-category="<?php echo htmlspecialchars($cardCategory); ?>"
                 data-city="<?php echo strtolower(htmlspecialchars($supplier['city'] ?? '')); ?>">
                <div class="supplier-card">
                    
                    <!-- IMAGE with badges -->
                    <div class="supplier-img-wrap"
                         onclick="viewSupplierDetails(<?php echo (int)$supplier['id']; ?>)">
                        <img src="<?php echo $imageUrl; ?>" 
                             alt="<?php echo htmlspecialchars($supplier['supplier_name']); ?>"
                             class="supplier-img"
                             onerror="this.src='https://via.placeholder.com/600x400/e6f3f3/008080?text=Supplier'">
                        
<!-- Status Badge — Always Open for demo -->
<div class="status-badge open">
    Open now
</div>
                        <!-- Rating Badge -->
                        <div class="rating-badge">
                            <i class="bi bi-star-fill"></i>
                            <?php echo number_format($rating, 1); ?>
                            <span style="color:#94a3b8;font-weight:500;font-size:0.65rem;">
                                (<?php echo (int)($supplier['total_reviews'] ?? 0); ?>)
                            </span>
                        </div>
                    </div>

                    <!-- BODY -->
                    <div class="supplier-body">
                        <div class="supplier-name"
                             onclick="viewSupplierDetails(<?php echo (int)$supplier['id']; ?>)">
                            <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                        </div>

                        <div class="supplier-info">
                            <!-- Location -->
                            <?php if (!empty($supplier['city'])): ?>
                                <div class="info-row">
                                    <i class="bi bi-geo-alt-fill"></i>
                                    <span class="info-row-text">
                                        <?php echo htmlspecialchars($supplier['city']); ?>
                                        <?php if (!empty($supplier['state'])): ?>
                                            , <?php echo htmlspecialchars($supplier['state']); ?>
                                        <?php endif; ?>
                                    </span>
                                </div>
                            <?php endif; ?>

<!-- Business Hours — Static -->
<div class="info-row">
    <i class="bi bi-clock-fill"></i>
    <span class="info-row-text">
        8:00 AM - 8:00 PM
        <span class="ms-1 hours-open">• Open</span>
    </span>
</div>

                            <!-- Category -->
                            <?php if (!empty($supplier['category'])): ?>
                                <div class="info-row">
                                    <i class="bi bi-tag-fill"></i>
                                    <span class="info-row-text"><?php echo htmlspecialchars($supplier['category']); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Product Count -->
                        <div class="product-count">
                            <i class="bi bi-box-seam-fill"></i>
                            <?php echo $productCount; ?> 
                            <?php echo $productCount === 1 ? 'product' : 'products'; ?> available
                        </div>
                    </div>

                    <!-- ACTIONS -->
                    <div class="supplier-actions">
                        <button class="btn-action btn-view"
                                onclick="event.stopPropagation(); viewSupplierProducts(<?php echo (int)$supplier['id']; ?>)">
                            <i class="bi bi-grid"></i> View Products
                        </button>
                        <button class="btn-action btn-contact"
                                onclick="event.stopPropagation(); viewSupplierDetails(<?php echo (int)$supplier['id']; ?>)">
                            <i class="bi bi-telephone"></i> Contact
                        </button>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Empty state message para sa filter -->
    <div id="emptyStateMessage" style="display:none;">
        <div class="empty-state">
            <i class="bi bi-search"></i>
            <h5>No suppliers found</h5>
            <p>Try adjusting your search or category filter</p>
        </div>
    </div>

</div>

<!-- Supplier Details Modal -->
<div class="modal fade" id="supplierDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content modal-content-custom">
            <div id="supplierModalContent"></div>
        </div>
    </div>
</div>



<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const permissions = { canView: <?php echo json_encode($canView); ?> };
let activeCategory = 'all';

$(document).ready(function () {
    if (!permissions.canView) {
        $('.container').html(`
            <div class="p-5 text-center">
                <div class="alert alert-danger">
                    <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
                    <h3>Access Denied</h3>
                    <p>You don't have permission to view suppliers.</p>
                </div>
            </div>
        `);
        return;
    }

    $('.category-pill').on('click', function () {
        $('.category-pill').removeClass('active');
        $(this).addClass('active');
        activeCategory = $(this).data('category');
        filterSuppliers();
    });

    $('#searchInput').on('keyup', function () {
        filterSuppliers();
    });
});

function filterSuppliers() {
    const searchTerm = $('#searchInput').val().toLowerCase().trim();
    let visibleCount = 0;

    $('.supplier-item').each(function () {
        const $item    = $(this);
        const name     = ($item.data('name')     || '').toString().toLowerCase();
        const category = ($item.data('category') || '').toString().toLowerCase();
        const city     = ($item.data('city')     || '').toString().toLowerCase();

        const matchesSearch   = searchTerm === '' || name.includes(searchTerm) || city.includes(searchTerm);
        const matchesCategory = activeCategory === 'all' || category === activeCategory.toLowerCase();

        if (matchesSearch && matchesCategory) {
            $item.show();
            visibleCount++;
        } else {
            $item.hide();
        }
    });

    if (visibleCount === 0 && $('.supplier-item').length > 0) {
        $('#emptyStateMessage').show();
    } else {
        $('#emptyStateMessage').hide();
    }
}

// ✅ NEW: Redirect sa select_products.php with supplier filter
function viewSupplierProducts(supplierId) {
    window.location.href = 'views/select_products.php?supplier_id=' + supplierId;
}

function viewSupplierDetails(supplierId) {
    if (!permissions.canView) {
        Swal.fire('Access Denied', "You don't have permission to view supplier details", 'error');
        return;
    }

    $('#supplierDetailsModal').modal('show');
    $('#supplierModalContent').html(`
        <div class="text-center p-5">
            <div class="spinner-border text-primary" role="status"></div>
            <p class="mt-3 text-muted">Loading supplier details...</p>
        </div>
    `);

    $.ajax({
        url: 'api/supplier.php',
        method: 'GET',
        data: { action: 'get', id: supplierId },
        dataType: 'json',
        success: function (response) {
            if (response.success && response.data) {
                displaySupplierDetails(response.data);
            } else {
                $('#supplierModalContent').html(`
                    <div class="text-center p-5">
                        <i class="bi bi-exclamation-triangle fs-1 text-danger"></i>
                        <p class="mt-3 text-danger">Failed to load supplier details</p>
                    </div>
                `);
            }
        },
        error: function () {
            $('#supplierModalContent').html(`
                <div class="text-center p-5">
                    <i class="bi bi-wifi-off fs-1 text-danger"></i>
                    <p class="mt-3 text-danger">Network error. Please try again.</p>
                </div>
            `);
        }
    });
}

function displaySupplierDetails(supplier) {
    const ratingRaw  = supplier.average_rating ? parseFloat(supplier.average_rating)
                     : (supplier.rating ? parseFloat(supplier.rating) : 3);
    const rating     = Math.min(5, Math.max(0, ratingRaw));
    const fullStars  = Math.floor(rating);
    const halfStar   = (rating - fullStars) >= 0.5;
    const emptyStars = 5 - fullStars - (halfStar ? 1 : 0);

    let ratingHtml = '';
    for (let i = 0; i < fullStars; i++)  ratingHtml += '<i class="bi bi-star-fill"></i>';
    if (halfStar)                         ratingHtml += '<i class="bi bi-star-half"></i>';
    for (let i = 0; i < emptyStars; i++) ratingHtml += '<i class="bi bi-star"></i>';

    const partnerDate = supplier.created_at
        ? new Date(supplier.created_at).toLocaleDateString()
        : 'N/A';

    const html = `
        <div class="modal-header-custom">
            <div class="d-flex align-items-center gap-3">
                <div class="modal-supplier-logo">
                    <i class="bi bi-building"></i>
                </div>
                <div>
                    <h4 class="mb-1 fw-bold">${escapeHtml(supplier.supplier_name)}</h4>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <span class="badge bg-white text-dark px-3 py-1 rounded-pill">
                            <i class="bi bi-tag me-1"></i>${escapeHtml(supplier.category || 'General')}
                        </span>
                        <span class="badge ${supplier.status === 'Active' ? 'bg-success' : 'bg-secondary'} rounded-pill px-3 py-1">
                            ${supplier.status === 'Active'
                                ? '<i class="bi bi-check-circle me-1"></i>Active'
                                : '<i class="bi bi-x-circle me-1"></i>Inactive'}
                        </span>
                    </div>
                </div>
            </div>
            <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3"
                    data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body p-4">
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="card border-0 bg-light p-3 rounded-4 h-100">
                        <h6 class="fw-bold mb-3"><i class="bi bi-info-circle me-2 text-primary"></i>Contact Information</h6>
                        ${supplier.contact_person ? `<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-person text-primary"></i><span>${escapeHtml(supplier.contact_person)}</span></div>` : ''}
                        ${supplier.email  ? `<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-envelope text-primary"></i><a href="mailto:${escapeHtml(supplier.email)}" class="text-decoration-none">${escapeHtml(supplier.email)}</a></div>` : ''}
                        ${supplier.phone  ? `<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-telephone text-primary"></i><a href="tel:${escapeHtml(supplier.phone)}" class="text-decoration-none">${escapeHtml(supplier.phone)}</a></div>` : ''}
                        ${supplier.mobile ? `<div class="d-flex align-items-center gap-2 mb-2"><i class="bi bi-phone text-primary"></i><a href="tel:${escapeHtml(supplier.mobile)}" class="text-decoration-none">${escapeHtml(supplier.mobile)}</a></div>` : ''}
                        ${!supplier.contact_person && !supplier.email && !supplier.phone && !supplier.mobile ? '<p class="text-muted small mb-0">No contact info available.</p>' : ''}
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 bg-light p-3 rounded-4 h-100">
                        <h6 class="fw-bold mb-3"><i class="bi bi-geo-alt me-2 text-primary"></i>Location</h6>
                        ${supplier.address ? `<div class="mb-2"><i class="bi bi-building me-2 text-primary"></i>${escapeHtml(supplier.address)}</div>` : ''}
                        ${supplier.city    ? `<div class="mb-2"><i class="bi bi-geo-alt me-2 text-primary"></i>${escapeHtml(supplier.city)}</div>` : ''}
                        ${supplier.country ? `<div><i class="bi bi-globe me-2 text-primary"></i>${escapeHtml(supplier.country)}</div>` : ''}
                        ${!supplier.address && !supplier.city && !supplier.country ? '<p class="text-muted small mb-0">No location info available.</p>' : ''}
                    </div>
                </div>
            </div>

            <div class="row g-4 mt-2">
                <div class="col-md-6">
                    <div class="card border-0 bg-light p-3 rounded-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-star me-2 text-primary"></i>Rating</h6>
                        <div class="d-flex align-items-center gap-2">
                            <div class="rating-stars fs-5" style="color:#fbbf24;">${ratingHtml}</div>
                            <span class="fw-bold">${rating.toFixed(1)}</span>
                            <span class="text-muted">/ 5</span>
                        </div>
                        <div class="mt-2 small text-muted">
                            Based on ${parseInt(supplier.total_reviews) || 0} review(s)
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card border-0 bg-light p-3 rounded-4">
                        <h6 class="fw-bold mb-3"><i class="bi bi-cash-stack me-2 text-primary"></i>Payment Terms</h6>
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-credit-card text-primary"></i>
                            <span>${escapeHtml(supplier.payment_terms || 'Net 30')}</span>
                        </div>
                        ${supplier.credit_limit > 0 ? `<div class="mt-2 small"><strong>Credit Limit:</strong> ₱${parseFloat(supplier.credit_limit).toLocaleString()}</div>` : ''}
                    </div>
                </div>
            </div>

            ${supplier.notes ? `
                <div class="mt-4">
                    <div class="card border-0 bg-light p-3 rounded-4">
                        <h6 class="fw-bold mb-2"><i class="bi bi-file-text me-2 text-primary"></i>Additional Notes</h6>
                        <p class="mb-0">${escapeHtml(supplier.notes)}</p>
                    </div>
                </div>` : ''}

            <div class="mt-4 pt-3 border-top text-muted small text-center">
                <i class="bi bi-calendar me-1"></i> Partner since ${partnerDate}
            </div>
        </div>

        <div class="modal-footer border-0 pb-4">
            <button type="button" class="btn btn-light px-4 me-2" data-bs-dismiss="modal">
                <i class="bi bi-x-lg me-2"></i>Close
            </button>
            <button type="button" class="btn px-4" style="background:var(--primary);color:white;" 
                    onclick="viewSupplierProducts(${supplier.id})">
                <i class="bi bi-grid me-2"></i>View Products
            </button>
        </div>
    `;

    $('#supplierModalContent').html(html);
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
</script>

</body>
</html>