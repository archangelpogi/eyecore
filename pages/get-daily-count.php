<?php

include '../includes/config.php';

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit();
}

$user_id = $_SESSION['user_id'];
$date = $_GET['date'] ?? '';

if (!$date) {
    echo json_encode(['error' => 'No date provided']);
    exit();
}

$query = mysqli_query($conn, "
    SELECT COUNT(*) as total 
    FROM appointments 
    WHERE user_id = $user_id 
    AND appointment_date = '$date'
    AND status != 'cancelled'
");

$result = mysqli_fetch_assoc($query);
echo json_encode(['count' => $result['total']]);
?>