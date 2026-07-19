<?php
include __DIR__ . '/../config/db.php';
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    http_response_code(403);
    exit();
}

$sql = "SELECT c.*, 
        u.first_name as admin_first_name, 
        u.last_name as admin_last_name, 
        u.email as admin_email
        FROM clinics c
        LEFT JOIN users u ON c.admin_id = u.id
        WHERE 1=1";
$params = [];
$types = "";

if (!empty($_GET['status'])) {
    $sql .= " AND c.status = ?";
    $params[] = $_GET['status'];
    $types .= "s";
}

if (!empty($_GET['risk'])) {
    $sql .= " AND c.risk_level = ?";
    $params[] = $_GET['risk'];
    $types .= "s";
}

if (!empty($_GET['score'])) {
    if ($_GET['score'] == 'high') {
        $sql .= " AND c.verification_score >= 80";
    } elseif ($_GET['score'] == 'medium') {
        $sql .= " AND c.verification_score BETWEEN 60 AND 79";
    } elseif ($_GET['score'] == 'low') {
        $sql .= " AND c.verification_score < 60";
    }
}

if (!empty($_GET['search'])) {
    $sql .= " AND (c.clinic_name LIKE ? OR c.clinic_email LIKE ? OR c.contact LIKE ?)";
    $search = "%{$_GET['search']}%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
    $types .= "sss";
}

$sql .= " ORDER BY c.created_at DESC";

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

$clinics = [];
while ($row = $result->fetch_assoc()) {
    $clinics[] = $row;
}

header('Content-Type: application/json');
echo json_encode($clinics);
?>