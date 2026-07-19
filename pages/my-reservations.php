<?php
include '../includes/config.php';
include '../includes/theme.php';
require_once '../includes/payment-helper.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authenticated. Please log in again.']);
        exit();
    }
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = (int) $_SESSION['user_id'];

// ===== AJAX: CANCEL RESERVATION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    header('Content-Type: application/json');

    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid reservation ID.']);
        exit();
    }

    // Verify ownership and get reservation details
    $check = mysqli_query($conn,
        "SELECT r.id, r.status, r.product_id, p.name as product_name 
         FROM reservations r
         JOIN products p ON r.product_id = p.id
         WHERE r.id = $id AND r.user_id = $user_id LIMIT 1"
    );

    if (!$check || mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Reservation not found or access denied.']);
        exit();
    }

    $res = mysqli_fetch_assoc($check);
    
    // Check if reservation is cancellable
    $cancellable = ['pending', 'confirmed'];
    $current_status = $res['status'];

    if (!in_array($current_status, $cancellable)) {
        $status_display = ucfirst(str_replace('_', ' ', $current_status));
        echo json_encode(['success' => false, 'message' => "Cannot cancel a reservation that is already \"{$status_display}\"."]);
        exit();
    }

    // Update reservation status to cancelled
    $update = mysqli_query($conn,
        "UPDATE reservations SET status = 'cancelled', updated_at = NOW() WHERE id = $id AND user_id = $user_id"
    );

    if ($update && mysqli_affected_rows($conn) > 0) {
        // Restore product quantity if color tracking exists
        // Get color info from reservation
        $color_query = mysqli_query($conn, "SELECT color_code, color_name FROM reservations WHERE id = $id");
        $color_data = mysqli_fetch_assoc($color_query);
        
        if ($color_data && !empty($color_data['color_code'])) {
            // Restore quantity for the specific color
            $restore_qty = mysqli_query($conn, "
                UPDATE product_color_inventory 
                SET quantity = quantity + 1 
                WHERE product_id = {$res['product_id']} 
                AND color_code = '{$color_data['color_code']}'
                AND clinic_id IN (SELECT clinic_id FROM products WHERE id = {$res['product_id']})
            ");
        }
        
        if (function_exists('addNotification')) {
            addNotification(
                $user_id, 'reservation',
                'Reservation Cancelled',
                'Your reservation for "' . $res['product_name'] . '" has been cancelled.',
                'my-reservations.php'
            );
        }
        echo json_encode(['success' => true, 'message' => 'Reservation cancelled successfully.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to cancel. Please try again.']);
    }
    exit();
}

// Get user data for navbar
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);
$avatar_query = mysqli_query($conn, "SELECT avatar FROM users WHERE id = $user_id");
$user_data = mysqli_fetch_assoc($avatar_query);

// Get pending appointments count for navbar badge
$pending_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($pending_q)['total'] ?? 0;

// Get unread notifications
$unread_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $unread_count = getUnreadNotificationCount($user_id);
}
$recent_notifications = [];
if (function_exists('getRecentNotifications')) {
    $recent_notifications = getRecentNotifications($user_id);
}

// Get total bookings for navbar
$total_bookings_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$total_bookings = mysqli_fetch_assoc($total_bookings_q)['total'] ?? 0;

// Get total points
$points_row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(points) as t FROM user_rewards WHERE user_id=$user_id"));
$total_points = $points_row['t'] ?: 0;

$reservations_query = mysqli_query($conn, "
    SELECT r.*, 
           p.name as product_name, 
           p.price, 
           p.image as product_image,
           p.images as product_images_old,
           p.images_json as product_images_json,
           p.category as product_category,
           p.warranty_period, 
           p.warranty_coverage, 
           p.warranty_terms, 
           p.warranty_premium_price,
           c.name as clinic_name,
           c.logo as clinic_logo
    FROM reservations r
    JOIN products p ON r.product_id = p.id
    JOIN clinics c ON r.clinic_id = c.id
    WHERE r.user_id = $user_id
    ORDER BY r.created_at DESC
");
// Helper function to get product image URL
function getProductImageUrl($product) {
    $imageUrl = '/assets/img/no-image.png';
    
    // PRIORITY 1: Check images_json
    if (!empty($product['product_images_json'])) {
        $images = json_decode($product['product_images_json'], true);
        if (!empty($images) && isset($images[0])) {
            $imagePath = $images[0];
            $imagePath = str_replace('uploads/uploads/', 'uploads/', $imagePath);
            
            if (strpos($imagePath, 'uploads/') === 0) {
                $imageUrl = '/' . $imagePath;
            } else {
                $imageUrl = '/uploads/products/' . $imagePath;
            }
            return $imageUrl;
        }
    }
    
    // PRIORITY 2: Check images column
    if (!empty($product['product_images_old'])) {
        $imagePath = $product['product_images_old'];
        if (strpos($imagePath, '[') === 0) {
            $images = json_decode($imagePath, true);
            if (!empty($images) && isset($images[0])) {
                $imagePath = $images[0];
            }
        }
        $imagePath = str_replace('uploads/uploads/', 'uploads/', $imagePath);
        
        if (strpos($imagePath, 'uploads/') === 0) {
            $imageUrl = '/' . $imagePath;
        } else {
            $imageUrl = '/uploads/products/' . $imagePath;
        }
        return $imageUrl;
    }
    
    // PRIORITY 3: Check image column
    if (!empty($product['product_image'])) {
        if (strpos($product['product_image'], 'uploads/') === false && strpos($product['product_image'], '/') === false) {
            $imageUrl = '/assets/images/products/' . $product['product_image'];
        } else {
            $imagePath = str_replace('uploads/uploads/', 'uploads/', $product['product_image']);
            if (strpos($imagePath, 'uploads/') === 0) {
                $imageUrl = '/' . $imagePath;
            } else {
                $imageUrl = '/uploads/products/' . $imagePath;
            }
        }
        return $imageUrl;
    }
    
    return $imageUrl;
}

// Helper function for category icon
function getCategoryIcon($category) {
    $icons = [
        'Frames' => 'fa-glasses',
        'Contact Lenses' => 'fa-eye',
        'Service' => 'fa-stethoscope',
        'Lenses' => 'fa-eye',
        'Accessories' => 'fa-shopping-bag',
        'Eyeglasses' => 'fa-glasses',
        'Sunglasses' => 'fa-sunglasses'
    ];
    return $icons[$category] ?? 'fa-box';
}

// Helper function for clinic logo
if (!function_exists('getClinicLogo')) {
    function getClinicLogo($c) {
        if (!empty($c['clinic_logo'])) return '/assets/images/clinic-logos/' . $c['clinic_logo'];
        return null;
    }
}

// Include navbar
$active_nav = 'reservations';


// Get stats
$total_reservations = mysqli_num_rows($reservations_query);
$pending_count = 0;
$confirmed_count = 0;
$reservations_data = [];
while($temp = mysqli_fetch_assoc($reservations_query)) {
    if($temp['status'] == 'pending') $pending_count++;
    if($temp['status'] == 'confirmed') $confirmed_count++;
    $reservations_data[] = $temp;
}

// ============================================
// WARRANTY HELPER FUNCTIONS
// ============================================

function calculateWarrantyEndDateRes($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return null;
    
    $date = new DateTime($purchase_date);
    
    $period_map = [
        '3_months' => '+3 months',
        '6_months' => '+6 months',
        '12_months' => '+12 months',
        '24_months' => '+24 months',
        '36_months' => '+36 months'
    ];
    
    if (isset($period_map[$warranty_period])) {
        $date->modify($period_map[$warranty_period]);
        return $date->format('Y-m-d');
    }
    
    return null;
}

function isWithinWarrantyRes($purchase_date, $warranty_period) {
    if (empty($purchase_date) || empty($warranty_period)) return false;
    if ($warranty_period == 'no_warranty') return false;
    
    $end_date = calculateWarrantyEndDateRes($purchase_date, $warranty_period);
    if (!$end_date) return false;
    
    $today = date('Y-m-d');
    return $end_date >= $today;
}

function getWarrantyPeriodDisplayRes($warranty_period) {
    $labels = [
        '3_months' => '3 Months',
        '6_months' => '6 Months',
        '12_months' => '12 Months',
        '24_months' => '24 Months',
        '36_months' => '36 Months'
    ];
    return $labels[$warranty_period] ?? '';
}
mysqli_data_seek($reservations_query, 0);
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>My Reservations - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- IDAGDAG ITO sa <head> -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        /* ===== RESET AND BASE STYLES ===== */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s;
        }

        /* Main Content */
        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 40px 100px;
        }

        @media (max-width: 1024px) {
            .main-content {
                padding: 24px;
            }
        }

        @media (max-width: 768px) {
            .main-content {
                padding: 18px 16px 100px;
            }
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 28px;
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }

        .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            border: 1px solid var(--border-light);
            transition: all 0.2s;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary);
            line-height: 1;
        }

        .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 5px;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        /* Reservations Grid */
        .reservations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 20px;
        }

        /* Reservation Card */
        .reservation-card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            overflow: hidden;
            transition: all 0.2s;
        }

        .reservation-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        /* Product Image Section */
        .product-image-section {
            position: relative;
            height: 200px;
            overflow: hidden;
            background: linear-gradient(135deg, var(--primary-light), var(--bg-primary));
            cursor: pointer;
        }

        .product-image {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }

        .product-image-section:hover .product-image {
            transform: scale(1.05);
        }

        .image-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.4);
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .product-image-section:hover .image-overlay {
            opacity: 1;
        }

        .image-overlay i {
            color: white;
            font-size: 32px;
            background: rgba(0,0,0,0.6);
            padding: 12px;
            border-radius: 50%;
        }

        .product-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            background: var(--bg-primary);
        }

        .product-placeholder i {
            font-size: 56px;
            color: var(--primary);
            opacity: 0.5;
        }

        .product-placeholder span {
            font-size: 13px;
            color: var(--text-muted);
        }

        /* Card Body */
        .card-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        /* Header with Clinic Info */
        .card-header-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 4px;
        }

        .clinic-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--bg-primary);
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 11px;
            color: var(--text-secondary);
        }

        .clinic-badge i {
            color: var(--primary);
            font-size: 10px;
        }

        /* Product Name */
        .product-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        /* Status Badge */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 600;
        }

        .status-pending {
            background: #FEF3C7;
            color: #92400E;
        }

        .status-confirmed {
            background: #D1FAE5;
            color: #065F46;
        }

        .status-cancelled {
            background: #FEE2E2;
            color: #991B1B;
        }

        .status-completed {
            background: #EDE9FE;
            color: #5B21B6;
        }

        .status-ready {
            background: #DBEAFE;
            color: #1E40AF;
        }

        .status-expired {
            background: #F3F4F6;
            color: #6B7280;
        }

        .status-noshow {
            background: #FFF7ED;
            color: #9A3412;
        }

        /* Details */
        .reservation-details {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin: 8px 0;
            padding: 8px 0;
            border-top: 1px solid var(--border-light);
            border-bottom: 1px solid var(--border-light);
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 12px;
        }

        .detail-label {
            color: var(--text-muted);
        }

        .detail-value {
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .color-preview {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: inline-block;
            border: 2px solid white;
            box-shadow: 0 0 0 1px var(--border-color);
        }

        /* Price */
        .product-price {
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
            margin-top: 4px;
        }

        /* Payment Info */
        .payment-info {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 10px 12px;
            margin-top: 4px;
        }

        .payment-row {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            margin-bottom: 4px;
        }

        .payment-row:last-child {
            margin-bottom: 0;
        }

        .payment-label {
            color: var(--text-muted);
        }

        .payment-amount {
            font-weight: 700;
            color: var(--primary);
        }

        .payment-balance {
            font-weight: 700;
            color: var(--warning);
        }

        /* Action Buttons */
        .card-actions {
            display: flex;
            gap: 10px;
            margin-top: 8px;
        }

        .btn {
            flex: 1;
            padding: 10px;
            border-radius: var(--radius-md);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.2s;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,183,97,0.3);
        }

        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .btn-outline:hover {
            background: var(--bg-primary);
            border-color: var(--primary);
            color: var(--primary);
        }

        .btn-danger {
            background: transparent;
            border: 1px solid var(--danger);
            color: var(--danger);
        }

        .btn-danger:hover {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
            border: none;
        }

        .btn-warning:hover {
            background: #e67e22;
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
        }

        .empty-state i {
            font-size: 64px;
            color: var(--text-muted);
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 8px;
            color: var(--text-primary);
        }

        .empty-state p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 20px;
        }

        .btn-browse {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }

        .btn-browse:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,183,97,0.3);
        }

        /* Image Modal */
        .image-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.95);
            z-index: 10000;
            cursor: pointer;
            align-items: center;
            justify-content: center;
        }

        .image-modal.show {
            display: flex;
        }

        .modal-image {
            max-width: 90%;
            max-height: 90%;
            object-fit: contain;
            border-radius: var(--radius-lg);
        }

        .modal-close {
            position: absolute;
            top: 20px;
            right: 30px;
            color: white;
            font-size: 40px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .modal-close:hover {
            color: var(--primary);
        }

        /* CSS Variables */
        :root {
            --primary: #00B761;
            --primary-dark: #00874A;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg,#00B761,#00A86B);
            --secondary: #FF8C42;
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --card-bg: #FFFFFF;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,0.10);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-xl: 28px;
            --radius-full: 999px;
            --danger: #EF4444;
            --warning: #F59E0B;
            --success: #00B761;
            --info: #3B82F6;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #0D2818;
            --bg-primary: #0D0D0D;
            --bg-secondary: #161616;
            --card-bg: #1E1E1E;
            --text-primary: #F9FAFB;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #2A2A2A;
            --border-light: #222222;
        }

        /* Warranty Section Styles */
.warranty-section {
    background: var(--bg-primary);
    border-radius: var(--radius-md);
    padding: 10px 12px;
    margin-top: 8px;
}

.warranty-badge {
    background: var(--primary-light);
    color: var(--primary);
    padding: 2px 6px;
    border-radius: var(--radius-full);
    font-size: 10px;
    font-weight: 600;
    margin-left: 6px;
}

.warranty-status.active {
    background: #E8F5E9;
    color: #2E7D32;
    border-radius: var(--radius-md);
}

.warranty-status.expired {
    background: #FFEBEE;
    color: #EF4444;
    border-radius: var(--radius-md);
}

.theme-dark .warranty-status.active {
    background: #0D2818;
    color: #00E676;
}

.theme-dark .warranty-status.expired {
    background: #3B0F0F;
    color: #EF4444;
}

.btn-warranty-claim-small {
    background: linear-gradient(135deg, #00B761, #00A86B);
    color: white;
    border: none;
    border-radius: var(--radius-sm);
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
}

.btn-warranty-claim-small:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 8px rgba(0,183,97,0.3);
}

.btn-warranty-expired-small {
    background: #9CA3AF;
    color: white;
    border: none;
    border-radius: var(--radius-sm);
    padding: 8px 12px;
    font-size: 12px;
    font-weight: 600;
    cursor: not-allowed;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    opacity: 0.7;
}
    </style>
</head>
<body>
   <?php include '../includes/navbar.php'; ?>

    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <div class="page-title">
                <i class="fas fa-clock"></i>
                <h1>My Reservations</h1>
            </div>
            <a href="dashboard.php" class="back-btn">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo $total_reservations; ?></div>
                <div class="stat-label"><i class="fas fa-calendar-alt"></i> Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $pending_count; ?></div>
                <div class="stat-label"><i class="fas fa-clock"></i> Pending</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo $confirmed_count; ?></div>
                <div class="stat-label"><i class="fas fa-check-circle"></i> Confirmed</div>
            </div>
        </div>

        <!-- Reservations Grid -->
        <?php if ($total_reservations > 0): ?>
            <div class="reservations-grid">
                <?php foreach($reservations_data as $res): 
                    $status_class = '';
                    $status_text = '';
                    
                    switch($res['status']) {
                        case 'pending':
                            $status_class = 'status-pending';
                            $status_text = 'Pending';
                            break;
                        case 'confirmed':
                            $status_class = 'status-confirmed';
                            $status_text = 'Confirmed';
                            break;
                        case 'ready_for_pickup':
                            $status_class = 'status-ready';
                            $status_text = 'Ready for Pickup';
                            break;
                        case 'completed':
                            $status_class = 'status-completed';
                            $status_text = 'Completed';
                            break;
                        case 'cancelled':
                            $status_class = 'status-cancelled';
                            $status_text = 'Cancelled';
                            break;
                        case 'expired':
                            $status_class = 'status-expired';
                            $status_text = 'Expired';
                            break;
                        case 'no_show':
                            $status_class = 'status-noshow';
                            $status_text = 'No Show';
                            break;
                        default:
                            $status_class = 'status-pending';
                            $status_text = ucfirst(str_replace('_', ' ', $res['status']));
                    }
                    
                    // Get product image URL
                    $product_image_url = getProductImageUrl($res);
                    $category_icon = getCategoryIcon($res['product_category']);
                    $clinic_logo = getClinicLogo($res);
                    
                    // Calculate payment info
                    $downpayment_percent = 30; // default
                    if (!empty($res['downpayment_amount']) && $res['downpayment_amount'] > 0 && $res['total_amount'] > 0) {
                        $downpayment_percent = round(($res['downpayment_amount'] / $res['total_amount']) * 100);
                    }

                    // Calculate warranty info for this reservation
$has_warranty = false;
$is_within_warranty = false;
$warranty_end_date = null;
$warranty_display = '';
$can_claim_warranty = false;
$warranty_coverage = [];
$warranty_terms = '';

if (!empty($res['product_id']) && !empty($res['warranty_period']) && $res['warranty_period'] != 'no_warranty') {
    $has_warranty = true;
    $warranty_display = getWarrantyPeriodDisplayRes($res['warranty_period']);
    
    // Decode warranty coverage if exists
    if (!empty($res['warranty_coverage'])) {
        $warranty_coverage = json_decode($res['warranty_coverage'], true);
        if (!is_array($warranty_coverage)) $warranty_coverage = [];
    }
    if (!empty($res['warranty_terms'])) {
        $warranty_terms = $res['warranty_terms'];
    }
    
    // Check if within warranty period (using created_at as purchase date)
    if (!empty($res['created_at'])) {
        $warranty_end_date = calculateWarrantyEndDateRes($res['created_at'], $res['warranty_period']);
        $is_within_warranty = isWithinWarrantyRes($res['created_at'], $res['warranty_period']);
        
        // Can claim if: reservation is completed AND within warranty period
        $can_claim_warranty = ($res['status'] == 'completed' && $is_within_warranty);
    }
}
                ?>
                    <div class="reservation-card" data-reservation-id="<?php echo $res['id']; ?>">
                        <!-- Product Image Section -->
                        <div class="product-image-section" onclick="showImageModal('<?php echo htmlspecialchars($product_image_url); ?>', '<?php echo htmlspecialchars($res['product_name']); ?>')">
                            <?php if ($product_image_url && strpos($product_image_url, 'no-image.png') === false): ?>
                                <img src="<?php echo htmlspecialchars($product_image_url); ?>" alt="<?php echo htmlspecialchars($res['product_name']); ?>" class="product-image"
                                     onerror="this.onerror=null; this.parentElement.innerHTML='<div class=\'product-placeholder\'><i class=\'fas <?php echo $category_icon; ?>\'></i><span><?php echo htmlspecialchars($res['product_name']); ?></span></div>'">
                            <?php else: ?>
                                <div class="product-placeholder">
                                    <i class="fas <?php echo $category_icon; ?>"></i>
                                    <span><?php echo htmlspecialchars($res['product_name']); ?></span>
                                </div>
                            <?php endif; ?>
                            <div class="image-overlay">
                                <i class="fas fa-search-plus"></i>
                            </div>
                        </div>
                        
                        <!-- Card Body -->
                        <div class="card-body">
                            <!-- Header with Clinic -->
                            <div class="card-header-info">
                                <div class="clinic-badge">
                                    <i class="fas fa-map-marker-alt"></i>
                                    <?php echo htmlspecialchars($res['clinic_name']); ?>
                                </div>
                                <div style="margin-left: auto;">
                                    <span class="status-badge <?php echo $status_class; ?>">
                                        <i class="fas <?php 
                                            $icon_map = [
                                                'pending'          => 'fa-clock',
                                                'confirmed'        => 'fa-check-circle',
                                                'ready_for_pickup' => 'fa-box-open',
                                                'completed'        => 'fa-check-double',
                                                'cancelled'        => 'fa-times-circle',
                                                'expired'          => 'fa-hourglass-end',
                                                'no_show'          => 'fa-user-times',
                                            ];
                                            echo $icon_map[$res['status']] ?? 'fa-circle';
                                        ?>"></i>
                                        <?php echo $status_text; ?>
                                    </span>
                                </div>
                            </div>
                            
                            <!-- Product Name -->
                            <div class="product-name">
                                <?php echo htmlspecialchars($res['product_name']); ?>
                            </div>
                            
                            <!-- Details -->
                            <div class="reservation-details">
                                <div class="detail-row">
                                    <span class="detail-label">Reservation #:</span>
                                    <span class="detail-value">RES-<?php echo str_pad($res['id'], 6, '0', STR_PAD_LEFT); ?></span>
                                </div>
                                <?php if (!empty($res['color_name'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Color:</span>
                                    <span class="detail-value">
                                        <?php echo htmlspecialchars($res['color_name']); ?>
                                        <?php if (!empty($res['color_code'])): ?>
                                            <span class="color-preview" style="background-color: <?php echo htmlspecialchars($res['color_code']); ?>;"></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <?php endif; ?>
                                <div class="detail-row">
                                    <span class="detail-label">Reservation Date:</span>
                                    <span class="detail-value"><?php 
                                        $res_date = !empty($res['preferred_date']) ? $res['preferred_date'] : $res['created_at'];
                                        echo date('M d, Y', strtotime($res_date)); 
                                    ?></span>
                                </div>
                                <?php if (!empty($res['notes'])): ?>
                                <div class="detail-row">
                                    <span class="detail-label">Notes:</span>
                                    <span class="detail-value"><?php echo htmlspecialchars(substr($res['notes'], 0, 50)) . (strlen($res['notes']) > 50 ? '...' : ''); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <!-- Price and Payment Info -->
                            <?php if (!empty($res['total_amount']) || !empty($res['price'])): ?>
                            <div class="payment-info">
                                <div class="payment-row">
                                    <span class="payment-label">Total Amount:</span>
                                    <span class="payment-amount">₱<?php echo number_format($res['total_amount'] ?: $res['price'], 2); ?></span>
                                </div>
                                <?php if (!empty($res['downpayment_amount']) && $res['downpayment_amount'] > 0): ?>
                                <div class="payment-row">
                                    <span class="payment-label">Downpayment (<?php echo $downpayment_percent; ?>%):</span>
                                    <span class="payment-amount">₱<?php echo number_format($res['downpayment_amount'], 2); ?></span>
                                </div>
                                <div class="payment-row">
                                    <span class="payment-label">Balance (upon visit):</span>
                                    <span class="payment-balance">₱<?php echo number_format($res['balance_amount'], 2); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if ($res['payment_status'] == 'unpaid' && $res['status'] == 'pending'): ?>
                                <div class="payment-row">
                                    <span class="payment-label">Payment Status:</span>
                                    <span class="payment-balance" style="color: var(--danger);">Unpaid</span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Warranty Section -->
<?php if ($has_warranty): ?>
<div class="warranty-section mt-2 pt-2 border-top">
    <div class="warranty-header" style="display: flex; align-items: center; gap: 6px; margin-bottom: 8px; font-size: 12px;">
        <i class="fas fa-shield-alt" style="color: var(--primary);"></i>
        <strong>Warranty</strong>
        <?php if ($warranty_display): ?>
        <span class="warranty-badge"><?php echo $warranty_display; ?></span>
        <?php endif; ?>
    </div>
    
    <?php if ($is_within_warranty && $warranty_end_date): ?>
    <div class="warranty-status active small" style="font-size: 11px; padding: 4px 8px;">
        <i class="fas fa-check-circle"></i> Active until <?php echo date('M d, Y', strtotime($warranty_end_date)); ?>
    </div>
    <?php elseif ($has_warranty && !$is_within_warranty && $warranty_end_date): ?>
    <div class="warranty-status expired small" style="font-size: 11px; padding: 4px 8px;">
        <i class="fas fa-clock"></i> Expired on <?php echo date('M d, Y', strtotime($warranty_end_date)); ?>
    </div>
    <?php endif; ?>
    
    <!-- Claim Warranty Button -->
    <?php if ($can_claim_warranty): ?>
    <button class="btn-warranty-claim-small mt-2" onclick="openWarrantyClaimRes(<?php echo $res['id']; ?>, <?php echo $res['product_id']; ?>, '<?php echo htmlspecialchars($res['product_name']); ?>')">
        <i class="fas fa-tools"></i> Claim Warranty
    </button>
    <?php elseif ($has_warranty && $res['status'] == 'completed' && !$is_within_warranty): ?>
    <button class="btn-warranty-expired-small mt-2" disabled>
        <i class="fas fa-clock"></i> Warranty Expired
    </button>
    <?php elseif ($has_warranty && $res['status'] != 'completed'): ?>
    <div class="warranty-note small mt-1">
        <small class="text-muted"><i class="fas fa-info-circle"></i> Warranty available after completion</small>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
                            
                            <!-- Actions -->
                            <div class="card-actions">
                                <?php if ($res['status'] === 'pending'): ?>
                                    <?php if ($res['payment_status'] == 'unpaid'): ?>
                                        <a href="payment.php?reservation_id=<?php echo $res['id']; ?>" class="btn btn-warning">
                                            <i class="fas fa-credit-card"></i> Pay Now
                                        </a>
                                    <?php endif; ?>
                                    <button class="btn btn-danger" onclick="cancelReservation(<?php echo $res['id']; ?>, '<?php echo addslashes($res['product_name']); ?>', '<?php echo addslashes($res['color_name'] ?? ''); ?>')">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                    <a href="3d-view.php?id=<?php echo $res['product_id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-cube"></i> View 3D
                                    </a>
                                <?php elseif ($res['status'] === 'confirmed'): ?>
                                    <button class="btn btn-danger" onclick="cancelReservation(<?php echo $res['id']; ?>, '<?php echo addslashes($res['product_name']); ?>', '<?php echo addslashes($res['color_name'] ?? ''); ?>')">
                                        <i class="fas fa-times"></i> Cancel
                                    </button>
                                    <a href="book-appointment.php?clinic_id=<?php echo $res['clinic_id']; ?>&item_id=<?php echo $res['product_id']; ?>&type=product" class="btn btn-primary">
                                        <i class="fas fa-calendar-plus"></i> Book Now
                                    </a>
                                <?php elseif ($res['status'] === 'ready_for_pickup'): ?>
                                    <a href="clinic-details.php?id=<?php echo $res['clinic_id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-map-marker-alt"></i> Clinic Details
                                    </a>
                                    <a href="3d-view.php?id=<?php echo $res['product_id']; ?>" class="btn btn-outline">
                                        <i class="fas fa-cube"></i> View 3D
                                    </a>
                                <?php elseif ($res['status'] === 'completed'): ?>
                                    <a href="product-view.php?id=<?php echo $res['product_id']; ?>" class="btn btn-primary">
                                        <i class="fas fa-eye"></i> View Product
                                    </a>
                                    <a href="clinic-details.php?id=<?php echo $res['clinic_id']; ?>" class="btn btn-outline">
                                        <i class="fas fa-clinic-medical"></i> Visit Clinic
                                    </a>
                                <?php else: ?>
                                    <a href="product-view.php?id=<?php echo $res['product_id']; ?>" class="btn btn-outline">
                                        <i class="fas fa-eye"></i> View Product
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-calendar-times"></i>
                <h3>No Reservations Yet</h3>
                <p>Browse products and reserve your favorite items!</p>
                <a href="dashboard.php" class="btn-browse">
                    <i class="fas fa-shopping-bag"></i> Browse Products
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Image Modal -->
    <div class="image-modal" id="imageModal" onclick="closeImageModal()">
        <span class="modal-close" onclick="closeImageModal()">&times;</span>
        <img class="modal-image" id="modalImage" src="" alt="Product Image">
    </div>

    <!-- Warranty Claim Modal -->
<div class="modal fade" id="warrantyClaimModalRes" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #00B761, #00A86B); color: white;">
                <h5 class="modal-title"><i class="fas fa-shield-alt"></i> Request Warranty Service</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="warrantyProductInfoRes" class="alert alert-light border mb-3"></div>
                
                <div class="alert alert-warning small">
                    <i class="fas fa-info-circle"></i>
                    <strong>Walk-in Service Only</strong><br>
                    Please bring your product to the clinic for inspection.
                </div>
                
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Preferred Date</label>
                        <input type="date" id="claim_date_res" class="form-control" min="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Preferred Time</label>
                        <input type="time" id="claim_time_res" class="form-control">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Issue Description</label>
                        <textarea id="claim_description_res" class="form-control" rows="2" placeholder="Describe the issue..."></textarea>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Upload Reference Photos (Optional)</label>
                        <input type="file" id="claim_photos_res" class="form-control" multiple accept="image/*">
                        <small class="text-muted">Upload up to 3 photos</small>
                    </div>
                </div>
                
                <input type="hidden" id="claim_reservation_id_res">
                <input type="hidden" id="claim_product_id_res">
                <input type="hidden" id="claim_product_name_res">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn" style="background: #00B761; color: white;" onclick="submitWarrantyClaimRes()">Submit Request</button>
            </div>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ============================================
        // CANCEL RESERVATION FUNCTION - FIXED
        // ============================================
        function cancelReservation(reservationId, productName, colorName) {
            // Validate reservation ID
            if (!reservationId || reservationId <= 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Invalid reservation ID. Please refresh the page and try again.',
                    background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                });
                return;
            }
            
            Swal.fire({
                title: 'Cancel Reservation?',
                html: `
                    <div style="text-align: left;">
                        <p>Are you sure you want to cancel your reservation for:</p>
                        <p style="font-weight: 600; color: var(--primary); margin: 10px 0;">${escapeHtml(productName)}</p>
                        ${colorName ? `<p style="font-weight: 500;">Color: ${escapeHtml(colorName)}</p>` : ''}
                        <p class="text-muted" style="margin-top: 15px; color: var(--text-muted);">This action cannot be undone.</p>
                    </div>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, cancel it',
                cancelButtonText: 'No, keep it',
                reverseButtons: true,
                background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF',
                color: document.documentElement.classList.contains('theme-dark') ? '#FFFFFF' : '#111827'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Cancelling...',
                        text: 'Please wait while we process your request',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); },
                        background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                    });

                    // Use fetch with proper POST data
                    const formData = new URLSearchParams();
                    formData.append('action', 'cancel');
                    formData.append('id', reservationId);
                    
                    fetch('my-reservations.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-Requested-With': 'XMLHttpRequest'
                        },
                        body: formData.toString()
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Cancelled!',
                                text: 'Your reservation has been cancelled.',
                                timer: 1500,
                                showConfirmButton: false,
                                background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                            }).then(() => { 
                                location.reload(); 
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: data.message || 'Failed to cancel reservation.',
                                background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                            });
                        }
                    })
                    .catch((error) => {
                        console.error('Fetch error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'An error occurred. Please try again.',
                            background: document.documentElement.classList.contains('theme-dark') ? '#1E1E1E' : '#FFFFFF'
                        });
                    });
                }
            });
        }
        
        // Helper function to escape HTML
        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // ============================================
        // IMAGE MODAL FUNCTIONS
        // ============================================
        function showImageModal(imageUrl, productName) {
            const modal = document.getElementById('imageModal');
            const modalImg = document.getElementById('modalImage');
            modalImg.src = imageUrl;
            modalImg.alt = productName;
            modal.classList.add('show');
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('show');
        }

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeImageModal();
            }
        });

        // ============================================
        // THEME FUNCTIONS
        // ============================================
        function toggleTheme() {
            const html = document.documentElement;
            if (html.classList.contains('theme-dark')) {
                html.classList.remove('theme-dark');
                localStorage.setItem('theme', 'light');
            } else {
                html.classList.add('theme-dark');
                localStorage.setItem('theme', 'dark');
            }
        }

        // Load saved theme
        document.addEventListener('DOMContentLoaded', function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            if (savedTheme === 'dark') {
                document.documentElement.classList.add('theme-dark');
            }
        });

        // ============================================
// WARRANTY CLAIM FUNCTIONS FOR RESERVATIONS
// ============================================

function openWarrantyClaimRes(reservationId, productId, productName) {
    document.getElementById('claim_date_res').value = '';
    document.getElementById('claim_time_res').value = '';
    document.getElementById('claim_description_res').value = '';
    document.getElementById('claim_photos_res').value = '';
    
    document.getElementById('claim_reservation_id_res').value = reservationId;
    document.getElementById('claim_product_id_res').value = productId;
    document.getElementById('claim_product_name_res').value = productName;
    
    document.getElementById('warrantyProductInfoRes').innerHTML = `
        <div class="d-flex gap-3">
            <i class="fas fa-box" style="font-size: 40px; color: #00B761;"></i>
            <div>
                <strong>${escapeHtml(productName)}</strong><br>
                <small>Reservation #: ${reservationId}</small><br>
                <small class="text-teal">Warranty claim will be verified by the clinic</small>
            </div>
        </div>
    `;
    
    const tomorrow = new Date();
    tomorrow.setDate(tomorrow.getDate() + 1);
    document.getElementById('claim_date_res').value = tomorrow.toISOString().split('T')[0];
    
    const modal = new bootstrap.Modal(document.getElementById('warrantyClaimModalRes'));
    modal.show();
}

function submitWarrantyClaimRes() {
    const reservationId = document.getElementById('claim_reservation_id_res').value;
    const productId = document.getElementById('claim_product_id_res').value;
    const productName = document.getElementById('claim_product_name_res').value;
    const claimDate = document.getElementById('claim_date_res').value;
    const claimTime = document.getElementById('claim_time_res').value;
    const description = document.getElementById('claim_description_res').value;
    
    if (!claimDate) {
        Swal.fire('Error', 'Please select a date', 'error');
        return;
    }
    if (!claimTime) {
        Swal.fire('Error', 'Please select a time', 'error');
        return;
    }
    
    Swal.fire({
        title: 'Submitting...',
        allowOutsideClick: false,
        didOpen: () => Swal.showLoading()
    });
    
    const formData = new FormData();
    formData.append('action', 'warranty_claim');
    formData.append('reservation_id', reservationId);
    formData.append('product_id', productId);
    formData.append('product_name', productName);
    formData.append('claim_date', claimDate);
    formData.append('claim_time', claimTime);
    formData.append('description', description);
    
    const photosInput = document.getElementById('claim_photos_res');
    if (photosInput.files) {
        for (let i = 0; i < Math.min(photosInput.files.length, 3); i++) {
            formData.append('photos[]', photosInput.files[i]);
        }
    }
    
    fetch('ajax/warranty-claim.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        Swal.close();
        if (data.success) {
            const modal = bootstrap.Modal.getInstance(document.getElementById('warrantyClaimModalRes'));
            modal.hide();
            
            Swal.fire({
                icon: 'success',
                title: 'Success!',
                text: data.message,
                confirmButtonColor: '#00B761'
            }).then(() => {
                location.reload();
            });
        } else {
            Swal.fire('Error!', data.message, 'error');
        }
    })
    .catch(err => {
        Swal.close();
        Swal.fire('Error!', 'Network error. Please try again.', 'error');
    });
}
    </script>
</body>
</html>