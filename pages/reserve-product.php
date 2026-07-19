<?php

header('Content-Type: application/json');

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login to reserve this product']);
    exit();
}

include '../includes/config.php';

$user_id = $_SESSION['user_id'];
$clinic_id = isset($_POST['clinic_id']) ? (int)$_POST['clinic_id'] : 0;
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
$product_name = isset($_POST['product_name']) ? mysqli_real_escape_string($conn, $_POST['product_name']) : '';
$pickup_date = isset($_POST['pickup_date']) ? mysqli_real_escape_string($conn, $_POST['pickup_date']) : '';
$contact_number = isset($_POST['contact_number']) ? mysqli_real_escape_string($conn, $_POST['contact_number']) : '';
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$color_code = isset($_POST['color_code']) ? mysqli_real_escape_string($conn, $_POST['color_code']) : null;
$notes = isset($_POST['notes']) ? mysqli_real_escape_string($conn, $_POST['notes']) : '';

// Validate inputs
if (!$clinic_id || !$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product or clinic information']);
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

// Validate pickup date (must be today or future)
$today = date('Y-m-d');
if ($pickup_date < $today) {
    echo json_encode(['success' => false, 'message' => 'Pickup date cannot be in the past']);
    exit();
}

// Check if product exists
$product_check = mysqli_query($conn, "SELECT id, name FROM products WHERE id = $product_id AND clinic_id = $clinic_id");
if (mysqli_num_rows($product_check) == 0) {
    echo json_encode(['success' => false, 'message' => 'Product not found']);
    exit();
}

$product = mysqli_fetch_assoc($product_check);

// Check if user already has a pending reservation for this product
$existing_reservation = mysqli_query($conn, "
    SELECT id FROM product_reservations 
    WHERE user_id = $user_id 
    AND product_id = $product_id 
    AND clinic_id = $clinic_id 
    AND status IN ('pending', 'confirmed')
");

if (mysqli_num_rows($existing_reservation) > 0) {
    echo json_encode(['success' => false, 'message' => 'You already have a pending reservation for this product']);
    exit();
}

// Create reservation
$insert_query = "INSERT INTO product_reservations 
                  (product_id, clinic_id, user_id, color_code, quantity, status, reservation_date, notes, created_at) 
                  VALUES 
                  ($product_id, $clinic_id, $user_id, " . ($color_code ? "'$color_code'" : "NULL") . ", $quantity, 'pending', '$pickup_date', '$notes', NOW())";

if (mysqli_query($conn, $insert_query)) {
    $reservation_id = mysqli_insert_id($conn);
    
    // Add notification for user
    $notification_message = "You have reserved {$product['name']} for pickup on " . date('F j, Y', strtotime($pickup_date)) . ". Please wait for clinic confirmation.";
    addNotification($user_id, 'reservation', 'Product Reserved', $notification_message, 'my-reservations.php');
    
    // Add notification for clinic admin
    $clinic_admin_query = mysqli_query($conn, "SELECT id FROM users WHERE clinic_id = $clinic_id AND role = 'ClinicAdmin' LIMIT 1");
    if ($clinic_admin = mysqli_fetch_assoc($clinic_admin_query)) {
        $admin_message = "User has reserved {$product['name']} (Qty: $quantity) for pickup on " . date('F j, Y', strtotime($pickup_date)) . ". Contact: $contact_number";
        addNotification($clinic_admin['id'], 'reservation', 'New Product Reservation', $admin_message, '../clinic/reservations.php');
    }
    
    echo json_encode([
        'success' => true, 
        'message' => 'Product reserved successfully! We will notify you once confirmed.',
        'reservation_id' => $reservation_id
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to reserve product: ' . mysqli_error($conn)]);
}
?>