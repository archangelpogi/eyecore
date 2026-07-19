<?php

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login to reserve']);
    exit();
}

include '../includes/config.php';

$user_id = $_SESSION['user_id'];
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$clinic_id = isset($_POST['clinic_id']) ? (int)$_POST['clinic_id'] : 0;
$product_name = isset($_POST['product_name']) ? mysqli_real_escape_string($conn, $_POST['product_name']) : '';
$color_code = isset($_POST['color_code']) ? mysqli_real_escape_string($conn, $_POST['color_code']) : '';
$color_name = isset($_POST['color_name']) ? mysqli_real_escape_string($conn, $_POST['color_name']) : '';
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$pickup_date = isset($_POST['pickup_date']) ? mysqli_real_escape_string($conn, $_POST['pickup_date']) : '';
$contact_number = isset($_POST['contact_number']) ? mysqli_real_escape_string($conn, $_POST['contact_number']) : '';
$notes = isset($_POST['notes']) ? mysqli_real_escape_string($conn, $_POST['notes']) : '';

// Validate inputs
if (!$product_id || !$clinic_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or clinic']);
    exit();
}

if (!$color_code || !$color_name) {
    echo json_encode(['success' => false, 'message' => 'Please select a color']);
    exit();
}

if (!$pickup_date) {
    echo json_encode(['success' => false, 'message' => 'Please select a pickup date']);
    exit();
}

if (!$contact_number) {
    echo json_encode(['success' => false, 'message' => 'Please enter your contact number']);
    exit();
}

// Check if product color has enough stock
$stock_check = mysqli_query($conn, "
    SELECT quantity FROM product_color_inventory 
    WHERE product_id = $product_id 
    AND clinic_id = $clinic_id 
    AND color_code = '$color_code'
    AND is_available = 1
");

$stock = mysqli_fetch_assoc($stock_check);
if (!$stock || $stock['quantity'] < $quantity) {
    echo json_encode(['success' => false, 'message' => 'Insufficient stock for selected color']);
    exit();
}

// Create reservation
$insert_query = "INSERT INTO product_reservations 
                  (product_id, clinic_id, user_id, color_code, color_name, quantity, pickup_date, contact_number, notes, status, created_at) 
                  VALUES 
                  ($product_id, $clinic_id, $user_id, '$color_code', '$color_name', $quantity, '$pickup_date', '$contact_number', '$notes', 'pending', NOW())";

if (mysqli_query($conn, $insert_query)) {
    // Update stock
    $new_quantity = $stock['quantity'] - $quantity;
    mysqli_query($conn, "
        UPDATE product_color_inventory 
        SET quantity = $new_quantity,
            updated_at = NOW()
        WHERE product_id = $product_id 
        AND clinic_id = $clinic_id 
        AND color_code = '$color_code'
    ");
    
    echo json_encode([
        'success' => true, 
        'message' => 'Reservation successful!',
        'remaining_stock' => $new_quantity
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to create reservation: ' . mysqli_error($conn)]);
}
?>