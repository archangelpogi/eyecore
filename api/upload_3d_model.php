<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

include __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

if ($_SESSION['role'] !== 'SuperAdmin') {
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$request_id = $_POST['request_id'] ?? null;
if (!$request_id) {
    echo json_encode(['success' => false, 'message' => 'Missing request ID']);
    exit;
}

if (!isset($_FILES['model_file']) || $_FILES['model_file']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded']);
    exit;
}

try {
    $upload_dir = __DIR__ . '/../uploads/completed_models/';
    if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);

    $file = $_FILES['model_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowed = ['glb', 'gltf', 'obj', 'fbx'];

    if (!in_array($ext, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid file type']);
        exit;
    }

    if ($file['size'] > 50 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 50MB)']);
        exit;
    }

    $filename = 'request_' . $request_id . '_' . time() . '.' . $ext;
    $relative_path = 'uploads/completed_models/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $upload_dir . $filename)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file']);
        exit;
    }

    // Update request
    $stmt = $pdo->prepare("
        UPDATE custom_3d_requests 
        SET completed_model_file = ?, status = 'completed', completed_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$relative_path, $request_id]);

    // Notify clinic owner
    $req = $pdo->prepare("SELECT user_id, product_name, request_number FROM custom_3d_requests WHERE id = ?");
    $req->execute([$request_id]);
    $request = $req->fetch(PDO::FETCH_ASSOC);

    if ($request) {
        $notif = $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, '3D Model Completed!', ?, 'model_completed', ?, 'main.php?view=my-3d-models&tab=completed', NOW())
        ");
        $notif->execute([
            $request['user_id'],
            "Your 3D model for '{$request['product_name']}' is now ready.",
            $request['request_number']
        ]);
    }

    echo json_encode(['success' => true, 'message' => 'Model uploaded successfully']);

} catch (Exception $e) {
    error_log("Upload error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}