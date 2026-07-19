<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';
require_once __DIR__ . '/../include/SubscriptionHelper.php';  // ✅ IDAGDAG ITO!

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ✅ SUBSCRIPTION CHECK - Supply Chain module (Professional or Enterprise plan required)
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
    // GLOBAL stats — no clinic_id filter
    $statsQuery = $pdo->query("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Active' THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN average_rating >= 4 THEN 1 ELSE 0 END) as top_rated
        FROM suppliers
    ");
    $statsRow = $statsQuery->fetch(PDO::FETCH_ASSOC);
    $stats    = array_merge($stats, $statsRow);

    // GLOBAL categories — no clinic_id filter
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

    // GLOBAL suppliers — no clinic_id filter
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Directory - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root {
            --primary: #008080;
            --primary-dark: #006666;
            --primary-light: #e6f3f3;
        }

        body {
            background: #f8fafc;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
        }

        .hero-section {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: 32px;
            padding: 48px 40px;
            margin-bottom: 40px;
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
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .hero-section::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 200px;
            height: 200px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
        }

        .hero-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: white;
            margin-bottom: 12px;
            position: relative;
            z-index: 1;
        }

        .hero-subtitle {
            font-size: 1rem;
            color: rgba(255,255,255,0.9);
            margin-bottom: 0;
            position: relative;
            z-index: 1;
        }

        .search-wrapper {
            max-width: 500px;
            position: relative;
            z-index: 1;
        }

        .search-input {
            border-radius: 50px;
            border: none;
            padding: 14px 20px 14px 48px;
            font-size: 0.95rem;
            width: 100%;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }

        .search-input:focus {
            outline: none;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }

        .search-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.1rem;
        }

        .categories-wrapper {
            margin-bottom: 32px;
            overflow-x: auto;
            white-space: nowrap;
            padding-bottom: 8px;
            scrollbar-width: thin;
        }

        .categories-wrapper::-webkit-scrollbar { height: 4px; }
        .categories-wrapper::-webkit-scrollbar-track { background: #e2e8f0; border-radius: 10px; }
        .categories-wrapper::-webkit-scrollbar-thumb { background: var(--primary); border-radius: 10px; }

        .category-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: white;
            border: 1px solid #e2e8f0;
            padding: 10px 24px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 0.9rem;
            font-weight: 500;
            color: #475569;
            margin-right: 12px;
        }

        .category-pill i { font-size: 1rem; }

        .category-pill:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
        }

        .category-pill.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .supplier-card {
            background: white;
            border-radius: 24px;
            border: 1px solid #eef2f6;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            cursor: pointer;
            height: 100%;
        }

        .supplier-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.08);
            border-color: transparent;
        }

        .supplier-image {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
        }

        .supplier-logo {
            width: 80px;
            height: 80px;
            background: white;
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 20px rgba(0,0,0,0.05);
        }

        .supplier-logo i { font-size: 40px; color: var(--primary); }

        .supplier-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(4px);
            padding: 4px 12px;
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            color: #10b981;
        }

        .supplier-content { padding: 20px; }

        .supplier-name {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 6px;
            color: #0f172a;
        }

        .supplier-category {
            display: inline-block;
            padding: 4px 12px;
            background: var(--primary-light);
            color: var(--primary);
            border-radius: 30px;
            font-size: 0.7rem;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .rating-stars {
            color: #fbbf24;
            font-size: 0.85rem;
            letter-spacing: 2px;
            margin-bottom: 12px;
        }

        .rating-text { font-size: 0.7rem; color: #64748b; margin-left: 6px; }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 0.75rem;
            color: #475569;
        }

        .info-item i { width: 18px; color: var(--primary); font-size: 0.8rem; }

        .product-count {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px solid #eef2f6;
            font-size: 0.7rem;
            color: var(--primary);
            font-weight: 500;
        }

        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            border: 1px solid #eef2f6;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,0,0,0.04);
        }

        .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 13px; color: #64748b; font-weight: 500; }

        .modal-content-custom { border-radius: 24px; overflow: hidden; }

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
        }

        .modal-supplier-logo i { font-size: 30px; color: var(--primary); }

        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i { font-size: 64px; color: #cbd5e1; margin-bottom: 20px; display: block; }
        .empty-state h5 { color: #475569; margin-bottom: 8px; }
        .empty-state p { color: #94a3b8; }

        .permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: var(--primary);
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11px;
            z-index: 9999;
            opacity: 0.7;
        }

        @media (max-width: 768px) {
            .hero-section { padding: 32px 24px; }
            .hero-title { font-size: 1.75rem; }
        }
    </style>
</head>
<body>

<div class="container px-4 py-4">

    <!-- Hero Section -->
    <div class="hero-section">
        <div class="row align-items-center">
            <div class="col-lg-7 mb-4 mb-lg-0">
                <h1 class="hero-title">Find Your Perfect Supplier</h1>
                <p class="hero-subtitle">Discover trusted partners for frames, lenses, and more</p>
            </div>
            <div class="col-lg-5">
                <div class="search-wrapper">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" id="searchInput" class="search-input"
                           placeholder="Search suppliers by name or location...">
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value"><?php echo (int)$stats['total']; ?></div>
                        <div class="stat-label">Total Suppliers</div>
                    </div>
                    <i class="bi bi-shop fs-2 text-muted opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-success"><?php echo (int)$stats['active']; ?></div>
                        <div class="stat-label">Active Partners</div>
                    </div>
                    <i class="bi bi-check-circle-fill fs-2 text-success opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-warning"><?php echo (int)$stats['top_rated']; ?></div>
                        <div class="stat-label">Top Rated</div>
                    </div>
                    <i class="bi bi-star-fill fs-2 text-warning opacity-50"></i>
                </div>
            </div>
        </div>
    </div>

    <!-- Category Pills -->
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

    <!-- Supplier Grid -->
    <div class="row g-4" id="suppliersGrid">
        <?php if (empty($suppliers)): ?>
            <div class="col-12">
                <div class="empty-state">
                    <i class="bi bi-shop"></i>
                    <h5>No Suppliers Found</h5>
                    <p>Check back later for new partners</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($suppliers as $supplier):
                $ratingRaw    = !empty($supplier['average_rating']) ? (float)$supplier['average_rating']
                              : (!empty($supplier['rating'])        ? (float)$supplier['rating'] : 3);
                $rating       = max(0, min(5, $ratingRaw));
                $fullStars    = (int)floor($rating);
                $halfStar     = ($rating - $fullStars) >= 0.5;
                $emptyStars   = 5 - $fullStars - ($halfStar ? 1 : 0);
                $cardCategory = strtolower($supplier['category'] ?? 'general');
            ?>
            <div class="col-md-6 col-lg-4 supplier-item"
                 data-name="<?php echo strtolower(htmlspecialchars($supplier['supplier_name'])); ?>"
                 data-category="<?php echo htmlspecialchars($cardCategory); ?>"
                 data-city="<?php echo strtolower(htmlspecialchars($supplier['city'] ?? '')); ?>">
                <div class="supplier-card" onclick="viewSupplierDetails(<?php echo (int)$supplier['id']; ?>)">
                    <div class="supplier-image">
                        <div class="supplier-logo">
                            <i class="bi bi-building"></i>
                        </div>
                        <?php if (($supplier['status'] ?? '') === 'Active'): ?>
                            <div class="supplier-badge">
                                <i class="bi bi-check-circle-fill"></i> Active
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="supplier-content">
                        <h3 class="supplier-name"><?php echo htmlspecialchars($supplier['supplier_name']); ?></h3>

                        <?php if (!empty($supplier['category'])): ?>
                            <div class="supplier-category">
                                <i class="bi bi-tag"></i> <?php echo htmlspecialchars($supplier['category']); ?>
                            </div>
                        <?php endif; ?>

                        <div class="rating-stars">
                            <?php for ($i = 0; $i < $fullStars; $i++): ?>
                                <i class="bi bi-star-fill"></i>
                            <?php endfor; ?>
                            <?php if ($halfStar): ?>
                                <i class="bi bi-star-half"></i>
                            <?php endif; ?>
                            <?php for ($i = 0; $i < $emptyStars; $i++): ?>
                                <i class="bi bi-star"></i>
                            <?php endfor; ?>
                            <span class="rating-text">(<?php echo number_format($rating, 1); ?>)</span>
                        </div>

                        <?php if (!empty($supplier['contact_person'])): ?>
                            <div class="info-item">
                                <i class="bi bi-person"></i>
                                <span><?php echo htmlspecialchars($supplier['contact_person']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($supplier['city'])): ?>
                            <div class="info-item">
                                <i class="bi bi-geo-alt"></i>
                                <span><?php echo htmlspecialchars($supplier['city']); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php
                        $phone = !empty($supplier['phone'])  ? $supplier['phone']
                               : (!empty($supplier['mobile']) ? $supplier['mobile'] : '');
                        ?>
                        <?php if ($phone): ?>
                            <div class="info-item">
                                <i class="bi bi-telephone"></i>
                                <span><?php echo htmlspecialchars($phone); ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($supplier['product_count']) && $supplier['product_count'] > 0): ?>
                            <div class="product-count">
                                <i class="bi bi-box-seam"></i> <?php echo (int)$supplier['product_count']; ?> products available
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Supplier Details Modal -->
<div class="modal fade" id="supplierDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content modal-content-custom">
            <div id="supplierModalContent"></div>
        </div>
    </div>
</div>

<!-- Permission Badge -->
<div class="permission-badge">
    <i class="bi bi-shield-check"></i>
    <?php echo $canView ? 'View Only' : 'No Access'; ?>
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

    $('.supplier-item').each(function () {
        const $item    = $(this);
        const name     = ($item.data('name')     || '').toString().toLowerCase();
        const category = ($item.data('category') || '').toString().toLowerCase();
        const city     = ($item.data('city')     || '').toString().toLowerCase();

        const matchesSearch   = searchTerm === '' || name.includes(searchTerm) || city.includes(searchTerm);
        const matchesCategory = activeCategory === 'all' || category === activeCategory.toLowerCase();

        $item.toggle(matchesSearch && matchesCategory);
    });

    const visibleCount = $('.supplier-item:visible').length;
    if (visibleCount === 0 && $('.supplier-item').length > 0) {
        if ($('#emptyStateMessage').length === 0) {
            $('#suppliersGrid').append(`
                <div class="col-12" id="emptyStateMessage">
                    <div class="empty-state">
                        <i class="bi bi-search"></i>
                        <h5>No suppliers found</h5>
                        <p>Try adjusting your search or category filter</p>
                    </div>
                </div>
            `);
        }
    } else {
        $('#emptyStateMessage').remove();
    }
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
                            <div class="rating-stars fs-5">${ratingHtml}</div>
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
            <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">
                <i class="bi bi-x-lg me-2"></i>Close
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