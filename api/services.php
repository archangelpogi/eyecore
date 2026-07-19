<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');

// Debug: Log session info (remove in production)
error_log("Services API - Session ID: " . session_id());
error_log("Services API - User ID: " . ($_SESSION['user_id'] ?? 'not set'));
error_log("Services API - Clinic ID: " . ($_SESSION['clinic_id'] ?? 'not set'));

// Check authentication
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false, 
        'message' => 'Unauthorized',
        'debug' => 'Session user_id not set'
    ]); 
    exit;
}

$clinicId = $_SESSION['clinic_id'] ?? 1;
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// GET requests
if ($method === 'GET') {
    if ($action === 'get_services') {
        try {
            $stmt = $pdo->prepare("
                SELECT id, clinic_id, category, name, description, price, duration_minutes,
                       COALESCE(status, 'active') as status,
                       created_at, updated_at
                FROM services
                WHERE clinic_id = ?
                ORDER BY category, name
            ");
            $stmt->execute([$clinicId]);
            $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode(['success' => true, 'data' => $services]);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// POST requests
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    
    // If no body, try to get from $_POST
    if (!$body) {
        $body = $_POST;
    }
    
    $action = $body['action'] ?? $_POST['action'] ?? '';

    if ($action === 'add_service') {
        try {
            $name        = trim($body['name'] ?? '');
            $category    = trim($body['category'] ?? '');
            $description = trim($body['description'] ?? '');
            $price       = $body['price'] ?? 0;
            $duration    = !empty($body['duration_minutes']) ? (int)$body['duration_minutes'] : null;
            $status      = $body['status'] ?? 'active';

            if (!$name || !$category || !$price) {
                echo json_encode(['success' => false, 'message' => 'Name, category, and price are required']);
                exit;
            }

            // Check if status column exists, add if not
            try {
                $pdo->query("SELECT status FROM services LIMIT 1");
            } catch(Exception $e) {
                $pdo->exec("ALTER TABLE services ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER duration_minutes");
            }

            $stmt = $pdo->prepare("
                INSERT INTO services (clinic_id, category, name, description, price, duration_minutes, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
            ");
            $stmt->execute([$clinicId, $category, $name, $description, $price, $duration, $status]);
            $id = $pdo->lastInsertId();

            echo json_encode(['success' => true, 'id' => $id, 'message' => 'Service added successfully']);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'update_service') {
        try {
            $id          = (int)($body['id'] ?? 0);
            $name        = trim($body['name'] ?? '');
            $category    = trim($body['category'] ?? '');
            $description = trim($body['description'] ?? '');
            $price       = $body['price'] ?? 0;
            $duration    = !empty($body['duration_minutes']) ? (int)$body['duration_minutes'] : null;
            $status      = $body['status'] ?? 'active';

            if (!$id || !$name || !$category || !$price) {
                echo json_encode(['success' => false, 'message' => 'Missing required fields']);
                exit;
            }

            // Verify it belongs to this clinic
            $check = $pdo->prepare("SELECT id FROM services WHERE id=? AND clinic_id=?");
            $check->execute([$id, $clinicId]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Service not found']);
                exit;
            }

            // Check status column
            try {
                $pdo->query("SELECT status FROM services LIMIT 1");
            } catch(Exception $e) {
                $pdo->exec("ALTER TABLE services ADD COLUMN status VARCHAR(20) DEFAULT 'active' AFTER duration_minutes");
            }

            $stmt = $pdo->prepare("
                UPDATE services
                SET category=?, name=?, description=?, price=?, duration_minutes=?, status=?, updated_at=NOW()
                WHERE id=? AND clinic_id=?
            ");
            $stmt->execute([$category, $name, $description, $price, $duration, $status, $id, $clinicId]);

            echo json_encode(['success' => true, 'message' => 'Service updated successfully']);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'delete_service') {
        try {
            $id = (int)($body['id'] ?? 0);
            if (!$id) { 
                echo json_encode(['success' => false, 'message' => 'Invalid ID']); 
                exit; 
            }

            $check = $pdo->prepare("SELECT id FROM services WHERE id=? AND clinic_id=?");
            $check->execute([$id, $clinicId]);
            if (!$check->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Service not found']);
                exit;
            }

            $pdo->prepare("DELETE FROM services WHERE id=? AND clinic_id=?")->execute([$id, $clinicId]);
            echo json_encode(['success' => true, 'message' => 'Service deleted']);
        } catch(Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>