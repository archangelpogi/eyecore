<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['ClinicAdmin', 'CRM', 'Staff'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';
$data = json_decode(file_get_contents('php://input'), true);

try {
    switch($action) {
        // ==================== FOLLOWUPS ====================
        case 'get_followups':
            $status = $_GET['status'] ?? '';
            $type = $_GET['type'] ?? '';
            
            $sql = "SELECT f.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name 
                    FROM followups f
                    LEFT JOIN patients p ON f.patient_id = p.id
                    WHERE f.clinic_id = ?";
            $params = [$clinicId];
            
            if (!empty($status)) { $sql .= " AND f.status = ?"; $params[] = $status; }
            if (!empty($type)) { $sql .= " AND f.followup_type = ?"; $params[] = $type; }
            
            $sql .= " ORDER BY f.followup_date DESC, f.followup_time ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Stats
            $today = date('Y-m-d');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE clinic_id = ? AND followup_date = ?");
            $stmt->execute([$clinicId, $today]);
            $stats['today'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE clinic_id = ? AND status = 'Pending'");
            $stmt->execute([$clinicId]);
            $stats['pending'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE clinic_id = ? AND status = 'Completed'");
            $stmt->execute([$clinicId]);
            $stats['completed'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE clinic_id = ? AND YEARWEEK(followup_date) = YEARWEEK(CURDATE())");
            $stmt->execute([$clinicId]);
            $stats['week'] = (int)$stmt->fetchColumn();
            
            echo json_encode(['success' => true, 'followups' => $followups, 'stats' => $stats]);
            break;
            
        case 'add':
            $stmt = $pdo->prepare("INSERT INTO followups (clinic_id, patient_id, followup_type, followup_date, followup_time, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $success = $stmt->execute([$clinicId, $data['patient_id'], $data['followup_type'], $data['followup_date'], $data['followup_time'], $data['description'], $userId]);
            echo json_encode(['success' => $success]);
            break;
            
        case 'complete':
            $stmt = $pdo->prepare("UPDATE followups SET status='Completed' WHERE id=? AND clinic_id=?");
            echo json_encode(['success' => $stmt->execute([$data['id'], $clinicId])]);
            break;
            
        case 'delete':
            $stmt = $pdo->prepare("DELETE FROM followups WHERE id=? AND clinic_id=?");
            echo json_encode(['success' => $stmt->execute([$data['id'], $clinicId])]);
            break;
        
        // ==================== FEEDBACK ====================
        case 'get_feedbacks':
            $rating = $_GET['rating'] ?? '';
            $status = $_GET['status'] ?? '';
            
            $sql = "SELECT f.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                           CONCAT(u.first_name, ' ', u.last_name) as replied_by_name
                    FROM feedback f
                    LEFT JOIN patients p ON f.patient_id = p.id
                    LEFT JOIN users u ON f.replied_by = u.id
                    WHERE f.clinic_id = ?";
            $params = [$clinicId];
            
            if (!empty($rating)) { $sql .= " AND f.rating = ?"; $params[] = $rating; }
            if (!empty($status)) { $sql .= " AND f.status = ?"; $params[] = $status; }
            
            $sql .= " ORDER BY f.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Stats
            $stmt = $pdo->prepare("SELECT AVG(rating) as avg_rating, COUNT(*) as total FROM feedback WHERE clinic_id = ?");
            $stmt->execute([$clinicId]);
            $stats = $stmt->fetch(PDO::FETCH_ASSOC);
            $stats['avg_rating'] = number_format((float)($stats['avg_rating'] ?? 0), 1);
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE clinic_id = ? AND MONTH(feedback_date) = MONTH(CURDATE()) AND YEAR(feedback_date) = YEAR(CURDATE())");
            $stmt->execute([$clinicId]);
            $stats['this_month'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM feedback WHERE clinic_id = ? AND status = 'pending'");
            $stmt->execute([$clinicId]);
            $stats['pending_reply'] = (int)$stmt->fetchColumn();
            
            echo json_encode(['success' => true, 'feedbacks' => $feedbacks, 'stats' => $stats]);
            break;
            
        case 'get':
            $id = $_GET['id'] ?? 0;
            $stmt = $pdo->prepare("SELECT * FROM feedback WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$id, $clinicId]);
            echo json_encode(['success' => true, 'feedback' => $stmt->fetch(PDO::FETCH_ASSOC)]);
            break;
            
        case 'reply':
            $stmt = $pdo->prepare("UPDATE feedback SET reply = ?, replied_by = ?, replied_date = NOW(), status = 'responded' WHERE id = ? AND clinic_id = ?");
            $success = $stmt->execute([$data['reply'], $userId, $data['id'], $clinicId]);
            echo json_encode(['success' => $success]);
            break;
        
        // ==================== PATIENT TAGS ====================
        case 'get_patients':
            $tag = $_GET['tag'] ?? '';
            $search = $_GET['search'] ?? '';
            
            $sql = "SELECT p.* FROM patients p WHERE p.clinic_id = ? AND p.status = 'Active'";
            $params = [$clinicId];
            
            if (!empty($search)) {
                $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ?)";
                $term = "%$search%";
                $params[] = $term; $params[] = $term; $params[] = $term;
            }
            $sql .= " ORDER BY p.first_name ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'get_custom_tags':
            $stmt = $pdo->prepare("SELECT * FROM custom_tags WHERE clinic_id = ? ORDER BY name");
            $stmt->execute([$clinicId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'add_custom_tag':
            $stmt = $pdo->prepare("INSERT INTO custom_tags (clinic_id, name, color) VALUES (?, ?, ?)");
            echo json_encode(['success' => $stmt->execute([$clinicId, $data['name'], $data['color']])]);
            break;
            
        case 'delete_custom_tag':
            $stmt = $pdo->prepare("DELETE FROM custom_tags WHERE id = ? AND clinic_id = ?");
            echo json_encode(['success' => $stmt->execute([$data['id'], $clinicId])]);
            break;
            
        case 'get_patient_tags':
            $patientId = $_GET['patient_id'] ?? 0;
            $stmt = $pdo->prepare("SELECT c.name FROM patient_custom_tags pct JOIN custom_tags c ON pct.tag_id = c.id WHERE pct.patient_id = ?");
            $stmt->execute([$patientId]);
            echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
            break;
            
        case 'update_patient_tags':
            $patientId = $data['patient_id'];
            $stmt = $pdo->prepare("UPDATE patients SET patient_type = ?, remarks = ? WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$data['patient_type'], $data['remarks'], $patientId, $clinicId]);
            
            $stmt = $pdo->prepare("DELETE FROM patient_custom_tags WHERE patient_id = ?");
            $stmt->execute([$patientId]);
            
            if (!empty($data['custom_tags'])) {
                $tags = explode(',', $data['custom_tags']);
                foreach ($tags as $tagName) {
                    if (empty(trim($tagName))) continue;
                    $stmt = $pdo->prepare("SELECT id FROM custom_tags WHERE clinic_id = ? AND name = ?");
                    $stmt->execute([$clinicId, trim($tagName)]);
                    $tagId = $stmt->fetchColumn();
                    if ($tagId) {
                        $stmt = $pdo->prepare("INSERT INTO patient_custom_tags (patient_id, tag_id) VALUES (?, ?)");
                        $stmt->execute([$patientId, $tagId]);
                    }
                }
            }
            echo json_encode(['success' => true]);
            break;
        
        // ==================== PATIENT SUMMARY ====================
        case 'get_summary':
            $search = $_GET['search'] ?? '';
            $type = $_GET['type'] ?? '';
            $gender = $_GET['gender'] ?? '';
            
            $sql = "SELECT p.* FROM patients p WHERE p.clinic_id = ? AND p.status = 'Active'";
            $params = [$clinicId];
            
            if (!empty($type)) { $sql .= " AND p.patient_type = ?"; $params[] = $type; }
            if (!empty($gender)) { $sql .= " AND p.gender = ?"; $params[] = $gender; }
            if (!empty($search)) {
                $sql .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ?)";
                $term = "%$search%";
                $params[] = $term; $params[] = $term; $params[] = $term;
            }
            $sql .= " ORDER BY p.created_at DESC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($patients as &$p) {
                $stmt = $pdo->prepare("SELECT appointment_date FROM appointments WHERE patient_id = ? AND status = 'Completed' ORDER BY appointment_date DESC LIMIT 1");
                $stmt->execute([$p['id']]);
                $p['last_visit'] = $stmt->fetchColumn() ? date('M d, Y', strtotime($stmt->fetchColumn())) : null;
                
                $stmt = $pdo->prepare("SELECT appointment_date FROM appointments WHERE patient_id = ? AND appointment_date >= CURDATE() AND status IN ('confirmed', 'paid') ORDER BY appointment_date ASC LIMIT 1");
                $stmt->execute([$p['id']]);
                $p['next_appointment'] = $stmt->fetchColumn() ? date('M d, Y', strtotime($stmt->fetchColumn())) : null;
            }
            
            // Stats
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND status = 'Active'");
            $stmt->execute([$clinicId]);
            $stats['total'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM appointments WHERE clinic_id = ? AND MONTH(appointment_date) = MONTH(CURDATE())");
            $stmt->execute([$clinicId]);
            $stats['with_appointments'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM followups WHERE clinic_id = ? AND status = 'Pending'");
            $stmt->execute([$clinicId]);
            $stats['with_followups'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM feedback WHERE clinic_id = ? AND MONTH(created_at) = MONTH(CURDATE())");
            $stmt->execute([$clinicId]);
            $stats['with_feedback'] = (int)$stmt->fetchColumn();
            
            echo json_encode(['success' => true, 'patients' => $patients, 'stats' => $stats]);
            break;
        
        // ==================== TASKS ====================
        case 'get_tasks':
            $status = $_GET['status'] ?? '';
            
            $sql = "SELECT * FROM tasks WHERE clinic_id = ? AND user_id = ?";
            $params = [$clinicId, $userId];
            if (!empty($status)) { $sql .= " AND status = ?"; $params[] = $status; }
            $sql .= " ORDER BY 
                CASE priority WHEN 'high' THEN 1 WHEN 'medium' THEN 2 WHEN 'low' THEN 3 END,
                due_date ASC";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $pending = []; $inProgress = []; $completed = [];
            foreach ($tasks as $t) {
                if ($t['status'] === 'pending') $pending[] = $t;
                elseif ($t['status'] === 'in_progress') $inProgress[] = $t;
                else $completed[] = $t;
            }
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE clinic_id = ? AND user_id = ?");
            $stmt->execute([$clinicId, $userId]);
            $stats['total'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE clinic_id = ? AND user_id = ? AND status = 'pending'");
            $stmt->execute([$clinicId, $userId]);
            $stats['pending'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE clinic_id = ? AND user_id = ? AND status = 'in_progress'");
            $stmt->execute([$clinicId, $userId]);
            $stats['in_progress'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE clinic_id = ? AND user_id = ? AND status = 'completed'");
            $stmt->execute([$clinicId, $userId]);
            $stats['completed'] = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM tasks WHERE clinic_id = ? AND user_id = ? AND due_date < CURDATE() AND status != 'completed'");
            $stmt->execute([$clinicId, $userId]);
            $stats['overdue'] = (int)$stmt->fetchColumn();
            
            echo json_encode(['success' => true, 'pending' => $pending, 'in_progress' => $inProgress, 'completed' => $completed, 'stats' => $stats]);
            break;
            
        case 'add_task':
            $stmt = $pdo->prepare("INSERT INTO tasks (clinic_id, user_id, title, description, priority, due_date) VALUES (?, ?, ?, ?, ?, ?)");
            echo json_encode(['success' => $stmt->execute([$clinicId, $userId, $data['title'], $data['description'], $data['priority'], $data['due_date']])]);
            break;
            
        case 'complete_task':
            $stmt = $pdo->prepare("UPDATE tasks SET status = 'completed', completed_date = CURDATE() WHERE id = ? AND clinic_id = ? AND user_id = ?");
            echo json_encode(['success' => $stmt->execute([$data['id'], $clinicId, $userId])]);
            break;
            
        case 'delete_task':
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ? AND clinic_id = ? AND user_id = ?");
            echo json_encode(['success' => $stmt->execute([$data['id'], $clinicId, $userId])]);
            break;
            
                    case 'start_task':
            $stmt = $pdo->prepare("UPDATE tasks SET status = 'in_progress' WHERE id = ? AND clinic_id = ? AND user_id = ?");
            echo json_encode(['success' => $stmt->execute([$data['id'], $clinicId, $userId])]);
            break;
            
        case 'move_to_pending':
            $stmt = $pdo->prepare("UPDATE tasks SET status = 'pending' WHERE id = ? AND clinic_id = ? AND user_id = ?");
            echo json_encode(['success' => $stmt->execute([$data['id'], $clinicId, $userId])]);
            break;
        
        // ==================== EXPORT ====================
        case 'export':
            $type = $_GET['type'] ?? 'summary';
            $start = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
            $end = $_GET['end'] ?? date('Y-m-d');
            
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="crm_' . $type . '_' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            if ($type === 'followups') {
                fputcsv($output, ['Patient', 'Type', 'Date', 'Time', 'Description', 'Status']);
                $stmt = $pdo->prepare("SELECT f.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name FROM followups f LEFT JOIN patients p ON f.patient_id = p.id WHERE f.clinic_id = ? AND f.followup_date BETWEEN ? AND ?");
                $stmt->execute([$clinicId, $start, $end]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [$row['patient_name'], $row['followup_type'], $row['followup_date'], $row['followup_time'], $row['description'], $row['status']]);
                }
            } elseif ($type === 'feedback') {
                fputcsv($output, ['Patient', 'Rating', 'Feedback', 'Date', 'Status', 'Reply']);
                $stmt = $pdo->prepare("SELECT f.*, CONCAT(p.first_name, ' ', p.last_name) as patient_name FROM feedback f LEFT JOIN patients p ON f.patient_id = p.id WHERE f.clinic_id = ? AND f.feedback_date BETWEEN ? AND ?");
                $stmt->execute([$clinicId, $start, $end]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [$row['patient_name'], $row['rating'], $row['feedback'], $row['feedback_date'], $row['status'], $row['reply']]);
                }
            } else {
                fputcsv($output, ['Patient Code', 'First Name', 'Last Name', 'Age', 'Gender', 'Patient Type', 'Phone', 'Email', 'Status', 'Last Visit', 'Next Appointment']);
                $stmt = $pdo->prepare("SELECT * FROM patients WHERE clinic_id = ? AND created_at BETWEEN ? AND ?");
                $stmt->execute([$clinicId, $start, $end]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $stmt2 = $pdo->prepare("SELECT appointment_date FROM appointments WHERE patient_id = ? AND status = 'Completed' ORDER BY appointment_date DESC LIMIT 1");
                    $stmt2->execute([$row['id']]);
                    $lastVisit = $stmt2->fetchColumn();
                    
                    $stmt2 = $pdo->prepare("SELECT appointment_date FROM appointments WHERE patient_id = ? AND appointment_date >= CURDATE() AND status IN ('confirmed', 'paid') ORDER BY appointment_date ASC LIMIT 1");
                    $stmt2->execute([$row['id']]);
                    $nextApp = $stmt2->fetchColumn();
                    
                    fputcsv($output, [$row['patient_code'], $row['first_name'], $row['last_name'], $row['age'], $row['gender'], $row['patient_type'], $row['phone'], $row['email'], $row['status'], $lastVisit, $nextApp]);
                }
            }
            fclose($output);
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>