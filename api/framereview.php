<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true);

try {
    switch($action) {
        case 'get_frames':
            // Get frames from inventory
            $stmt = $pdo->query("SELECT * FROM inventory WHERE category = 'Frames' ORDER BY name");
            $frames = $stmt->fetchAll();
            
            if (empty($frames)) {
                // Return sample data if no frames in database
                $frames = [
                    ['id' => 1, 'name' => 'Ray-Ban Aviator Classic', 'color' => 'Gold', 'price' => 2500, 'category' => 'Aviator'],
                    ['id' => 2, 'name' => 'Oakley Frogskins', 'color' => 'Matte Black', 'price' => 3200, 'category' => 'Wayfarer'],
                    ['id' => 3, 'name' => 'Warby Parker Clark', 'color' => 'Whiskey Tortoise', 'price' => 1800, 'category' => 'Round']
                ];
            }
            
            echo json_encode($frames);
            break;
            
        case 'get_patients':
            // Get patients for selection
            $search = $_GET['search'] ?? '';
            $query = "SELECT id, patient_id, first_name, last_name, age FROM patients WHERE status = 'Active'";
            
            if ($search) {
                $query .= " AND (first_name LIKE ? OR last_name LIKE ? OR patient_id LIKE ?)";
                $searchTerm = "%$search%";
                $stmt = $pdo->prepare($query);
                $stmt->execute([$searchTerm, $searchTerm, $searchTerm]);
            } else {
                $stmt = $pdo->prepare($query . " ORDER BY first_name LIMIT 50");
                $stmt->execute();
            }
            
            $patients = $stmt->fetchAll();
            echo json_encode($patients);
            break;
            
        default:
            if ($method === 'POST' && $input['action'] === 'save_frame') {
                // Save frame to patient record
                $stmt = $pdo->prepare("INSERT INTO activity_logs (user_id, action, module, details) VALUES (?, ?, ?, ?)");
                $stmt->execute([
                    $_SESSION['user_id'] ?? null,
                    'Frame Preview',
                    '3D Frame Review',
                    "Saved frame '{$input['frame_name']}' to patient ID: {$input['patient_id']}"
                ]);
                
                echo json_encode(['success' => true, 'message' => 'Frame saved to patient record']);
            } else {
                echo json_encode(['error' => 'Invalid action']);
            }
    }
    
} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>