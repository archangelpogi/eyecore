<?php

include '../includes/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit();
}

$user_id    = (int)$_SESSION['user_id'];
$product_id = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;

if (!$product_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid product']);
    exit();
}

// Check if already favorited
$check = mysqli_query($conn, "SELECT id FROM favorites WHERE user_id = $user_id AND product_id = $product_id");

if (mysqli_num_rows($check) > 0) {
    // Remove from favorites
    $row = mysqli_fetch_assoc($check);
    mysqli_query($conn, "DELETE FROM favorites WHERE user_id = $user_id AND product_id = $product_id");
    echo json_encode(['success' => true, 'action' => 'removed', 'message' => 'Removed from favorites']);
} else {
    // Add to favorites
    $product_query = mysqli_query($conn, "SELECT name FROM products WHERE id = $product_id");
    $product = mysqli_fetch_assoc($product_query);
    $product_name = $product['name'] ?? 'Product';

    mysqli_query($conn, "INSERT INTO favorites (user_id, product_id, created_at) VALUES ($user_id, $product_id, NOW())");

    if (function_exists('addNotification')) {
        addNotification(
            $user_id,
            'favorite',
            'Product Saved ❤️',
            "You added \"$product_name\" to your favorites.",
            "product-view.php?id=$product_id"
        );
    }

    echo json_encode(['success' => true, 'action' => 'added', 'message' => 'Added to favorites']);
}