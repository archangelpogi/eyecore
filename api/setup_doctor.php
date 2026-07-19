<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// Check if user is logged in and is ClinicAdmin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'ClinicAdmin') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];

// Make sure first_name and last_name are in session
if (empty($_SESSION['first_name']) || empty($_SESSION['last_name'])) {
    $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if ($user) {
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
    }
}

$fullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$specialty = $data['specialty'] ?? 'Optometrist';

try {
    // Check if doctor already exists (by user_id or name)
    $stmt = $pdo->prepare("
        SELECT id FROM doctors 
        WHERE clinic_id = ? AND (user_id = ? OR name = ?) AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$clinicId, $userId, $fullName]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // Update existing record to link to this user and set specialty
        $stmt = $pdo->prepare("
            UPDATE doctors 
            SET user_id = ?, specialty = ?, is_active = 1, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$userId, $specialty, $existing['id']]);
        $doctorId = $existing['id'];
    } else {
        // Create new doctor record
        $stmt = $pdo->prepare("
            INSERT INTO doctors (clinic_id, user_id, name, specialty, is_active, created_at)
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$clinicId, $userId, $fullName, $specialty]);
        $doctorId = $pdo->lastInsertId();
    }
    
    // Save doctor_id to session
    $_SESSION['doctor_id'] = $doctorId;
    
    echo json_encode([
        'success' => true,
        'doctor_id' => $doctorId,
        'message' => 'Doctor profile created successfully'
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>