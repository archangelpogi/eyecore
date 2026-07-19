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

// ✅ RBAC Permission Check - MUST HAVE CLINIC REVIEWS VIEW PERMISSION
if (!RBACHelper::hasPermission('clinic_reviews_view')) {
    ?>
    <div class="container-fluid p-5 text-center">
        <div class="alert alert-danger">
            <i class="bi bi-shield-lock display-4 d-block mb-3"></i>
            <h3>Access Denied</h3>
            <p>You don't have permission to access Clinic Reviews.</p>
        </div>
    </div>
    <?php
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'] ?? '';

// ✅ Get user permissions for UI
$canView = RBACHelper::hasPermission('clinic_reviews_view');
$canCreate = RBACHelper::hasPermission('clinic_reviews_create');
$canEdit = RBACHelper::hasPermission('clinic_reviews_edit');
$canDelete = RBACHelper::hasPermission('clinic_reviews_delete');
$canApprove = RBACHelper::hasPermission('clinic_reviews_approve');
$canReject = RBACHelper::hasPermission('clinic_reviews_reject');

// Get statistics
$stats = ['total' => 0, 'pending' => 0, 'approved' => 0, 'rejected' => 0, 'avg_rating' => 0];
// Stats query
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
        AVG(CASE WHEN status = 'approved' THEN rating ELSE NULL END) as avg_rating
    FROM clinic_reviews
    WHERE clinic_id = ?
");
$stmt->execute([$clinicId]);
$statsResult = $stmt->fetch(PDO::FETCH_ASSOC);
if ($statsResult) {
    $stats = $statsResult;
    $stats['avg_rating'] = round($stats['avg_rating'] ?? 0, 1);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Reviews - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
            --teal-soft: #f0fdfa;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Inter', system-ui, sans-serif;
        }
        
        /* Stats Cards */
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 1.25rem;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
        }
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 4px;
            background: linear-gradient(90deg, var(--teal), var(--teal-light));
            border-radius: 20px 20px 0 0;
        }
        .stat-card { position: relative; overflow: hidden; }
        .stat-value { font-size: 28px; font-weight: 700; color: #0f172a; }
        .stat-label { font-size: 13px; color: #64748b; font-weight: 500; }
        
        /* Rating Stars */
        .rating-stars {
            color: #fbbf24;
            font-size: 14px;
            letter-spacing: 2px;
        }
        .rating-big {
            font-size: 48px;
            font-weight: 700;
            color: #fbbf24;
        }
        
        /* Review Cards */
        .review-card {
            background: white;
            border-radius: 16px;
            border: 1px solid #e2e8f0;
            transition: all 0.2s;
            margin-bottom: 16px;
        }
        .review-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transform: translateY(-2px);
        }
        
        .review-header {
            padding: 16px 20px;
            border-bottom: 1px solid #f1f5f9;
        }
        .review-body {
            padding: 16px 20px;
        }
        .review-footer {
            padding: 12px 20px;
            background: #f8fafc;
            border-top: 1px solid #f1f5f9;
            border-radius: 0 0 16px 16px;
        }
        
        .patient-avatar {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--teal), var(--teal-dark));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 18px;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #d1fae5; color: #059669; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        
        .btn-teal {
            background: var(--teal);
            color: white;
            border: none;
            padding: 8px 20px;
            border-radius: 10px;
            transition: all 0.2s;
        }
        .btn-teal:hover {
            background: var(--teal-dark);
            transform: translateY(-1px);
        }
        .btn-outline-teal {
            background: transparent;
            border: 1px solid var(--teal);
            color: var(--teal);
            padding: 6px 16px;
            border-radius: 10px;
        }
        .btn-outline-teal:hover {
            background: var(--teal);
            color: white;
        }
        
        .page-title {
            font-size: 1.75rem;
            font-weight: 600;
            color: #0f172a;
        }
        .page-subtitle {
            font-size: 0.875rem;
            color: #64748b;
        }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 6px 16px;
            border-radius: 30px;
            background: white;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .filter-tab:hover {
            border-color: var(--teal);
            color: var(--teal);
        }
        .filter-tab.active {
            background: var(--teal);
            border-color: var(--teal);
            color: white;
        }
        
        .no-permission-badge {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #ef4444;
            color: white;
            padding: 8px 16px;
            border-radius: 30px;
            font-size: 12px;
            z-index: 9999;
        }
    </style>
</head>
<body>

<div class="container-fluid p-4">
    
    <!-- Header -->
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <h1 class="page-title mb-0">
                <i class="bi bi-star-fill me-2" style="color: var(--teal);"></i>Clinic Reviews
            </h1>
            <p class="page-subtitle mt-1">Manage patient reviews and ratings for your clinic</p>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
            <div class="stat-card" onclick="filterReviews('all')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value"><?= $stats['total'] ?></div>
                        <div class="stat-label">Total Reviews</div>
                    </div>
                    <i class="bi bi-chat-text fs-2 text-teal opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card" onclick="filterReviews('pending')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-warning"><?= $stats['pending'] ?></div>
                        <div class="stat-label">Pending</div>
                    </div>
                    <i class="bi bi-hourglass-split fs-2 text-warning opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card" onclick="filterReviews('approved')">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="stat-value text-success"><?= $stats['approved'] ?></div>
                        <div class="stat-label">Approved</div>
                    </div>
                    <i class="bi bi-check-circle fs-2 text-success opacity-50"></i>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-6">
            <div class="stat-card">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="rating-big"><?= $stats['avg_rating'] ?></div>
                        <div class="stat-label">Average Rating</div>
                    </div>
                    <div>
                        <div class="rating-stars fs-4">
                            <?php 
                            $fullStars = floor($stats['avg_rating']);
                            $halfStar = ($stats['avg_rating'] - $fullStars) >= 0.5;
                            for($i = 0; $i < $fullStars; $i++) echo '★';
                            if($halfStar) echo '½';
                            for($i = $fullStars + ($halfStar ? 1 : 0); $i < 5; $i++) echo '☆';
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter Tabs -->
    <div class="mb-4">
        <div class="filter-tabs">
            <div class="filter-tab active" data-status="all" onclick="filterReviews('all')">All Reviews</div>
            <div class="filter-tab" data-status="pending" onclick="filterReviews('pending')">Pending</div>
            <div class="filter-tab" data-status="approved" onclick="filterReviews('approved')">Approved</div>
            <div class="filter-tab" data-status="rejected" onclick="filterReviews('rejected')">Rejected</div>
        </div>
    </div>

    <!-- Reviews Container -->
    <div id="reviewsContainer">
        <div class="text-center py-5">
            <div class="spinner-border text-teal" role="status"></div>
            <p class="mt-2 text-muted">Loading reviews...</p>
        </div>
    </div>
</div>

<!-- Add/Edit Review Modal -->
<div class="modal fade" id="reviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold" id="reviewModalTitle">
                    <i class="bi bi-star-fill me-2"></i>Add Review
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" id="reviewId">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Patient</label>
                    <select id="patientId" class="form-select" style="border-radius: 12px;">
                        <option value="">Select Patient</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Rating</label>
                    <div class="rating-input">
                        <div class="d-flex gap-2" id="ratingStarsInput">
                            <?php for($i = 1; $i <= 5; $i++): ?>
                            <i class="bi bi-star fs-2" style="cursor: pointer; color: #cbd5e1;" data-rating="<?= $i ?>" onclick="setRating(<?= $i ?>)"></i>
                            <?php endfor; ?>
                        </div>
                        <input type="hidden" id="rating" value="0">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Review Title</label>
                    <input type="text" id="reviewTitle" class="form-control" placeholder="Summarize your experience..." style="border-radius: 12px;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Review Comment</label>
                    <textarea id="reviewComment" class="form-control" rows="4" placeholder="Share your experience with our clinic..." style="border-radius: 12px;"></textarea>
                </div>
            </div>
            <div class="modal-footer border-0 pb-4">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" style="border-radius: 12px;">Cancel</button>
                <button type="button" class="btn btn-teal" onclick="saveReview()" style="border-radius: 12px;">
                    <i class="bi bi-send me-1"></i>Submit Review
                </button>
            </div>
        </div>
    </div>
</div>

<!-- View Review Modal -->
<div class="modal fade" id="viewReviewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header" style="background: linear-gradient(135deg, var(--teal), var(--teal-dark)); color: white; border-radius: 20px 20px 0 0;">
                <h5 class="modal-title fw-bold"><i class="bi bi-star-fill me-2"></i>Review Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4" id="viewReviewContent">
                <div class="text-center py-4">
                    <div class="spinner-border text-teal"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ✅ Pass permissions to JavaScript
const permissions = {
    canView: <?= json_encode($canView) ?>,
    canCreate: <?= json_encode($canCreate) ?>,
    canEdit: <?= json_encode($canEdit) ?>,
    canDelete: <?= json_encode($canDelete) ?>,
    canApprove: <?= json_encode($canApprove) ?>,
    canReject: <?= json_encode($canReject) ?>
};

let currentFilter = 'all';
let currentReviewId = null;
let reviewModal;
let viewModal;

$(document).ready(function() {
    reviewModal = new bootstrap.Modal(document.getElementById('reviewModal'));
    viewModal = new bootstrap.Modal(document.getElementById('viewReviewModal'));
    
    if (permissions.canView) {
        loadReviews();
        loadPatients();
    } else {
        $('#reviewsContainer').html('<div class="alert alert-danger text-center">You don\'t have permission to view reviews.</div>');
    }
});

function loadPatients() {
    $.ajax({
        url: 'api/clinic_reviews.php?action=get_patients',
        method: 'GET',
        success: function(data) {
            let options = '<option value="">Select Patient</option>';
            data.forEach(p => {
                options += `<option value="${p.id}">${escapeHtml(p.name)}</option>`;
            });
            $('#patientId').html(options);
        }
    });
}

function loadReviews() {
    $('#reviewsContainer').html('<div class="text-center py-5"><div class="spinner-border text-teal"></div><p class="mt-2">Loading reviews...</p></div>');
    
    $.ajax({
        url: `api/clinic_reviews.php?action=get_reviews&status=${currentFilter}`,
        method: 'GET',
        success: function(data) {
            if (data.success) {
                renderReviews(data.reviews);
            } else {
                $('#reviewsContainer').html('<div class="alert alert-danger">' + (data.message || 'Failed to load reviews') + '</div>');
            }
        },
        error: function() {
            $('#reviewsContainer').html('<div class="alert alert-danger">Connection error</div>');
        }
    });
}

function renderReviews(reviews) {
    if (!reviews || reviews.length === 0) {
        $('#reviewsContainer').html(`
            <div class="text-center py-5">
                <i class="bi bi-inbox fs-1 text-muted"></i>
                <p class="text-muted mt-2">No reviews found</p>
            </div>
        `);
        return;
    }
    
    let html = '';
    reviews.forEach(review => {
        const status = review.status || 'pending';
        const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
        
        html += `
            <div class="review-card">
                <div class="review-header">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="d-flex gap-3">
                            <div class="patient-avatar">
                                ${getInitials(review.patient_name)}
                            </div>
                            <div>
                                <h6 class="mb-0 fw-bold">${escapeHtml(review.patient_name)}</h6>
                                <div class="rating-stars mt-1">${stars}</div>
                            </div>
                        </div>
                        <div>
                            ${getStatusBadge(status)}
                        </div>
                    </div>
                </div>
                <div class="review-body">
                    <h6 class="fw-semibold mb-2">${escapeHtml(review.title || 'No title')}</h6>
                    <p class="text-muted mb-0">${escapeHtml(review.comment || 'No comment')}</p>
                </div>
                <div class="review-footer">
                    <div class="d-flex justify-content-between align-items-center">
                        <small class="text-muted">
                            <i class="bi bi-calendar me-1"></i>${new Date(review.created_at).toLocaleDateString()}
                        </small>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-teal" onclick="viewReview(${review.id})">
                                <i class="bi bi-eye me-1"></i>View
                            </button>
                            ${(status === 'pending' && permissions.canApprove) ? `
                            <button class="btn btn-sm btn-outline-success" onclick="approveReview(${review.id})">
                                <i class="bi bi-check-lg me-1"></i>Approve
                            </button>
                            ` : ''}
                            ${(status === 'pending' && permissions.canReject) ? `
                            <button class="btn btn-sm btn-outline-danger" onclick="rejectReview(${review.id})">
                                <i class="bi bi-x-lg me-1"></i>Reject
                            </button>
                            ` : ''}
                            ${permissions.canDelete ? `
                            <button class="btn btn-sm btn-outline-danger" onclick="deleteReview(${review.id})">
                                <i class="bi bi-trash me-1"></i>Delete
                            </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });
    
    $('#reviewsContainer').html(html);
}

function getStatusBadge(status) {
    const badges = {
        'pending': '<span class="status-badge status-pending"><i class="bi bi-hourglass-split me-1"></i>Pending</span>',
        'approved': '<span class="status-badge status-approved"><i class="bi bi-check-circle me-1"></i>Approved</span>',
        'rejected': '<span class="status-badge status-rejected"><i class="bi bi-x-circle me-1"></i>Rejected</span>'
    };
    return badges[status] || badges.pending;
}

function getInitials(name) {
    if (!name) return '?';
    return name.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
}

function filterReviews(status) {
    currentFilter = status;
    
    // Update active tab
    $('.filter-tab').removeClass('active');
    $(`.filter-tab[data-status="${status}"]`).addClass('active');
    
    loadReviews();
}


function viewReview(id) {
    currentReviewId = id;
    viewModal.show();
    
    $('#viewReviewContent').html('<div class="text-center py-4"><div class="spinner-border text-teal"></div></div>');
    
    $.ajax({
        url: `api/clinic_reviews.php?action=get_review&id=${id}`,
        method: 'GET',
        success: function(data) {
            if (data.success) {
                renderReviewDetails(data.review);
            } else {
                $('#viewReviewContent').html('<div class="alert alert-danger">' + (data.message || 'Failed to load review') + '</div>');
            }
        }
    });
}

function renderReviewDetails(review) {
    const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
    const statusClass = review.status === 'approved' ? 'success' : (review.status === 'rejected' ? 'danger' : 'warning');
    
    let actionButtons = '';
    if (review.status === 'pending') {
        if (permissions.canApprove) {
            actionButtons += `<button class="btn btn-success" onclick="approveReview(${review.id})"><i class="bi bi-check-lg me-1"></i>Approve</button>`;
        }
        if (permissions.canReject) {
            actionButtons += `<button class="btn btn-danger ms-2" onclick="rejectReview(${review.id})"><i class="bi bi-x-lg me-1"></i>Reject</button>`;
        }
    }
    
    const html = `
        <div class="card border-0 shadow-none">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="patient-avatar">${getInitials(review.patient_name)}</div>
                            <div>
                                <h5 class="mb-0 fw-bold">${escapeHtml(review.patient_name)}</h5>
                                <small class="text-muted">${new Date(review.created_at).toLocaleString()}</small>
                            </div>
                        </div>
                        <div class="rating-stars fs-4">${stars}</div>
                    </div>
                    <span class="badge bg-${statusClass}">${review.status.toUpperCase()}</span>
                </div>
                
                <h6 class="fw-bold mb-2">${escapeHtml(review.title)}</h6>
                <p class="text-muted mb-3">${escapeHtml(review.comment)}</p>
                
                ${review.admin_response ? `
                <div class="bg-light rounded p-3 mt-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-reply-fill text-teal"></i>
                        <strong class="small">Admin Response</strong>
                    </div>
                    <p class="mb-0 small">${escapeHtml(review.admin_response)}</p>
                    <small class="text-muted">Replied on ${new Date(review.responded_at).toLocaleString()}</small>
                </div>
                ` : ''}
                
                ${actionButtons ? `
                <div class="mt-4 pt-3 border-top">
                    ${actionButtons}
                </div>
                ` : ''}
            </div>
        </div>
    `;
    
    $('#viewReviewContent').html(html);
}

function approveReview(id) {
    if (!permissions.canApprove) {
        Swal.fire('Access Denied', 'You don\'t have permission to approve reviews', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Approve Review?',
        text: 'This review will be published on your clinic page.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Yes, Approve',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            updateReviewStatus(id, 'approved');
        }
    });
}

function rejectReview(id) {
    if (!permissions.canReject) {
        Swal.fire('Access Denied', 'You don\'t have permission to reject reviews', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Reject Review?',
        input: 'textarea',
        inputLabel: 'Reason for rejection (optional)',
        inputPlaceholder: 'Enter reason...',
        showCancelButton: true,
        confirmButtonText: 'Yes, Reject',
        cancelButtonText: 'Cancel'
    }).then(result => {
        if (result.isConfirmed) {
            updateReviewStatus(id, 'rejected', result.value);
        }
    });
}

function updateReviewStatus(id, status, reason = '') {
    Swal.fire({
        title: 'Processing...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    $.ajax({
        url: 'api/clinic_reviews.php',
        method: 'POST',
        data: JSON.stringify({
            action: 'update_status',
            review_id: id,
            status: status,
            admin_notes: reason
        }),
        contentType: 'application/json',
        success: function(data) {
            Swal.close();
            if (data.success) {
                viewModal.hide();
                Swal.fire('Success!', data.message, 'success');
                loadReviews();
            } else {
                Swal.fire('Error', data.message, 'error');
            }
        },
        error: function() {
            Swal.close();
            Swal.fire('Error', 'Connection error', 'error');
        }
    });
}

function deleteReview(id) {
    if (!permissions.canDelete) {
        Swal.fire('Access Denied', 'You don\'t have permission to delete reviews', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Delete Review?',
        text: 'This action cannot be undone.',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, Delete',
        confirmButtonColor: '#dc2626'
    }).then(result => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Deleting...',
                allowOutsideClick: false,
                didOpen: () => Swal.showLoading()
            });
            
            $.ajax({
                url: 'api/clinic_reviews.php',
                method: 'POST',
                data: JSON.stringify({
                    action: 'delete_review',
                    review_id: id
                }),
                contentType: 'application/json',
                success: function(data) {
                    Swal.close();
                    if (data.success) {
                        Swal.fire('Deleted!', data.message, 'success');
                        loadReviews();
                    } else {
                        Swal.fire('Error', data.message, 'error');
                    }
                },
                error: function() {
                    Swal.close();
                    Swal.fire('Error', 'Connection error', 'error');
                }
            });
        }
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
</body>
</html>