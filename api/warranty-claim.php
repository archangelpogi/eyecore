<?php
include '../includes/config.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Please login first']);
    exit();
}

$user_id     = $_SESSION['user_id'];
$reservation_id = (int)($_POST['reservation_id'] ?? 0);
$product_id     = (int)($_POST['product_id'] ?? 0);
$product_name   = $_POST['product_name'] ?? '';
$claim_date     = $_POST['claim_date'] ?? '';
$claim_time     = $_POST['claim_time'] ?? '';
$description    = $_POST['description'] ?? '';

if (!$reservation_id || !$product_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Generate claim number
$claim_number = 'WCL-' . strtoupper(uniqid());

// Get clinic_id — try reservations first, fallback sa appointments
$clinic_id = null;

$stmt = $conn->prepare("SELECT clinic_id FROM reservations WHERE id = ? AND user_id = ?");
$stmt->bind_param("ii", $reservation_id, $user_id);
$stmt->execute();
$result = $stmt->get_result();
$reservation = $result->fetch_assoc();

if ($reservation) {
    $clinic_id = $reservation['clinic_id'];
} else {
    // Fallback — reservation_id is actually appointment_id
    $stmt2 = $conn->prepare("SELECT clinic_id FROM appointments WHERE id = ? AND user_id = ?");
    $stmt2->bind_param("ii", $reservation_id, $user_id);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    $appointment_row = $result2->fetch_assoc();

    if ($appointment_row) {
        $clinic_id = $appointment_row['clinic_id'];
    } else {
        echo json_encode(['success' => false, 'message' => 'Record not found']);
        exit();
    }
}

// Insert warranty claim
$stmt = $conn->prepare("
    INSERT INTO warranty_claims 
    (claim_number, reservation_id, product_id, user_id, clinic_id, 
     issue_description, schedule_date, schedule_time, status, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending', NOW())
");
$stmt->bind_param("siiissss", 
    $claim_number, $reservation_id, $product_id, 
    $user_id, $clinic_id, $description, $claim_date, $claim_time
);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Warranty claim submitted! The clinic will contact you within 2-3 business days.'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to submit claim: ' . $conn->error
    ]);
}
?>