<?php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if(!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])){
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$clinic_id = $_SESSION['clinic_id'];

if(isset($_GET['sale_id'])) {
    $sale_id = (int)$_GET['sale_id'];
    $stmt = $pdo->prepare("
        SELECT * FROM sale_items 
        WHERE sale_id = ? AND clinic_id = ?
    ");
    $stmt->execute([$sale_id, $clinic_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($items);
} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Sale ID required']);
}
?>