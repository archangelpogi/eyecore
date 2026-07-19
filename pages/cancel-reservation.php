<?php
// cancel-reservation.php
include '../includes/config.php';


header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$reservation_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if (!$reservation_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID']);
    exit();
}

// Start transaction
mysqli_begin_transaction($conn);

try {
    // Get reservation details to restore stock
    $get_query = mysqli_query($conn, "
        SELECT product_id, clinic_id, color_code, quantity 
        FROM reservations 
        WHERE id = $reservation_id AND user_id = $user_id
    ");
    
    if (mysqli_num_rows($get_query) == 0) {
        throw new Exception('Reservation not found');
    }
    
    $reservation = mysqli_fetch_assoc($get_query);
    
    // Restore stock to inventory
    $update_inventory = mysqli_query($conn, "
        UPDATE product_color_inventory 
        SET quantity = quantity + {$reservation['quantity']} 
        WHERE product_id = {$reservation['product_id']} 
        AND clinic_id = {$reservation['clinic_id']} 
        AND color_code = '{$reservation['color_code']}'
    ");
    
    if (!$update_inventory) {
        throw new Exception('Failed to restore stock');
    }
    
    // Update reservation status
    $update_query = mysqli_query($conn, "
        UPDATE reservations 
        SET status = 'cancelled' 
        WHERE id = $reservation_id AND user_id = $user_id
    ");
    
    if (!$update_query || mysqli_affected_rows($conn) == 0) {
        throw new Exception('Failed to cancel reservation');
    }
    
    mysqli_commit($conn);
    
    echo json_encode(['success' => true, 'message' => 'Reservation cancelled successfully']);
    
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>