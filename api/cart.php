<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

if (!isset($_SESSION['product_cart'])) {
    $_SESSION['product_cart'] = [];
}

$action = $_POST['action'] ?? '';

if ($action === 'add') {
    $id = $_POST['product_id'] ?? 0;
    $_SESSION['product_cart'][$id] = 1;
    echo json_encode(['success' => true, 'count' => count($_SESSION['product_cart'])]);
    
} elseif ($action === 'update') {
    $id = $_POST['product_id'] ?? 0;
    $update_action = $_POST['update_action'] ?? '';
    
    if (isset($_SESSION['product_cart'][$id])) {
        if ($update_action === 'increase') {
            $_SESSION['product_cart'][$id]++;
        } elseif ($update_action === 'decrease') {
            if ($_SESSION['product_cart'][$id] > 1) {
                $_SESSION['product_cart'][$id]--;
            }
        }
    }
    echo json_encode(['success' => true]);
    
} elseif ($action === 'remove') {
    $id = $_POST['product_id'] ?? 0;
    unset($_SESSION['product_cart'][$id]);
    echo json_encode(['success' => true]);
} elseif ($action === 'clear') {
    $_SESSION['product_cart'] = [];
    echo json_encode(['success' => true]);
}
?>