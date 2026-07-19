<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// ✅ Auth check
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ✅ RBAC Permission Check - MUST HAVE CRM DASHBOARD VIEW PERMISSION
if (!RBACHelper::hasPermission('crm_dashboard_view')) {
    echo json_encode(['error' => 'Permission denied: Cannot view CRM Dashboard']);
    exit;
}

// ✅ Permission helper functions
function canViewDashboard() { return RBACHelper::hasPermission('crm_dashboard_view'); }
function canCreateDashboard() { return RBACHelper::hasPermission('crm_dashboard_create'); }
function canEditDashboard() { return RBACHelper::hasPermission('crm_dashboard_edit'); }
function canDeleteDashboard() { return RBACHelper::hasPermission('crm_dashboard_delete'); }
function canApproveDashboard() { return RBACHelper::hasPermission('crm_dashboard_approve'); }
function canRejectDashboard() { return RBACHelper::hasPermission('crm_dashboard_reject'); }

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// Get dashboard data
if ($action === 'chart_data') {
    $period = $_GET['period'] ?? 'weekly';
    
    if ($period === 'weekly') {
        // Last 7 days
        $labels = [];
        $appointments = [];
        $patients = [];
        
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $labels[] = date('D', strtotime($date));
            
            // Appointments count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND appointment_date = ?");
            $stmt->execute([$clinicId, $date]);
            $appointments[] = (int)$stmt->fetchColumn();
            
            // New patients
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND DATE(created_at) = ?");
            $stmt->execute([$clinicId, $date]);
            $patients[] = (int)$stmt->fetchColumn();
        }
    } else {
        // Last 6 months
        $labels = [];
        $appointments = [];
        $patients = [];
        
        for ($i = 5; $i >= 0; $i--) {
            $month = date('Y-m', strtotime("-$i months"));
            $labels[] = date('M', strtotime($month));
            
            // Appointments count
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND DATE_FORMAT(appointment_date, '%Y-%m') = ?");
            $stmt->execute([$clinicId, $month]);
            $appointments[] = (int)$stmt->fetchColumn();
            
            // New patients
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND DATE_FORMAT(created_at, '%Y-%m') = ?");
            $stmt->execute([$clinicId, $month]);
            $patients[] = (int)$stmt->fetchColumn();
        }
    }
    
    echo json_encode([
        'labels' => $labels,
        'appointments' => $appointments,
        'patients' => $patients
    ]);
    exit;
}

// ==================== MAIN DASHBOARD DATA ====================
$today = date('Y-m-d');

// Total patients
$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND status = 'Active'");
$stmt->execute([$clinicId]);
$totalPatients = (int)$stmt->fetchColumn();

// New patients this month
$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
$stmt->execute([$clinicId]);
$newPatients = (int)$stmt->fetchColumn();

// Today's appointments
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND appointment_date = ?");
$stmt->execute([$clinicId, $today]);
$todayAppointments = (int)$stmt->fetchColumn();

// Completed appointments today
$stmt = $pdo->prepare("SELECT COUNT(*) FROM appointments WHERE clinic_id = ? AND appointment_date = ? AND status = 'completed'");
$stmt->execute([$clinicId, $today]);
$completedAppointments = (int)$stmt->fetchColumn();

// Pending follow-ups
$stmt = $pdo->prepare("SELECT COUNT(*) FROM followups WHERE clinic_id = ? AND status = 'Pending'");
$stmt->execute([$clinicId]);
$pendingFollowups = (int)$stmt->fetchColumn();

// Unread messages (if messages table exists)
$unreadMessages = 0;
$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE clinic_id = ? AND receiver_id = ? AND is_read = 0");
$stmt->execute([$clinicId, $userId]);
$unreadMessages = (int)$stmt->fetchColumn();

// Patient types
$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND (patient_type = 'Regular' OR patient_type IS NULL)");
$stmt->execute([$clinicId]);
$regularPatients = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND patient_type = 'Senior'");
$stmt->execute([$clinicId]);
$seniorPatients = (int)$stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ? AND patient_type = 'PWD'");
$stmt->execute([$clinicId]);
$pwdPatients = (int)$stmt->fetchColumn();

// Average rating from feedback
$stmt = $pdo->prepare("SELECT AVG(rating) FROM feedback WHERE clinic_id = ?");
$stmt->execute([$clinicId]);
$avgRating = round((float)$stmt->fetchColumn(), 1);

// Return rate (patients with >1 appointment)
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT patient_id) 
    FROM appointments 
    WHERE clinic_id = ? 
    GROUP BY patient_id 
    HAVING COUNT(*) > 1
");
$stmt->execute([$clinicId]);
$returningPatients = $stmt->rowCount();
$returnRate = $totalPatients > 0 ? round(($returningPatients / $totalPatients) * 100) : 0;

// ==================== RECENT ACTIVITIES - LIMIT 5 ====================
$activities = [];

// Recent appointments - LIMIT 5
$stmt = $pdo->prepare("
    SELECT a.id, a.service_type, a.created_at, 
           CONCAT(p.first_name, ' ', p.last_name) as patient_name, 
           'Appointment' as activity_type, 
           a.created_at as activity_time
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    WHERE a.clinic_id = ?
    ORDER BY a.created_at DESC
    LIMIT 5
");
$stmt->execute([$clinicId]);
$activities = array_merge($activities, $stmt->fetchAll(PDO::FETCH_ASSOC));

// Recent follow-ups - LIMIT 5
$stmt = $pdo->prepare("
    SELECT f.id, f.description, f.created_at,
           CONCAT(p.first_name, ' ', p.last_name) as patient_name, 
           'Follow-up' as activity_type, 
           f.created_at as activity_time
    FROM followups f
    LEFT JOIN patients p ON f.patient_id = p.id
    WHERE f.clinic_id = ?
    ORDER BY f.created_at DESC
    LIMIT 5
");
$stmt->execute([$clinicId]);
$activities = array_merge($activities, $stmt->fetchAll(PDO::FETCH_ASSOC));

// Recent feedback - LIMIT 5
$stmt = $pdo->prepare("
    SELECT f.id, f.feedback, f.created_at,
           CONCAT(p.first_name, ' ', p.last_name) as patient_name, 
           'Feedback' as activity_type, 
           f.created_at as activity_time
    FROM feedback f
    LEFT JOIN patients p ON f.patient_id = p.id
    WHERE f.clinic_id = ?
    ORDER BY f.created_at DESC
    LIMIT 5
");
$stmt->execute([$clinicId]);
$activities = array_merge($activities, $stmt->fetchAll(PDO::FETCH_ASSOC));

// Sort by time and take top 5 only
usort($activities, function($a, $b) {
    return strtotime($b['activity_time']) - strtotime($a['activity_time']);
});
$activities = array_slice($activities, 0, 5);

foreach ($activities as &$act) {
    $act['activity_time'] = date('h:i A', strtotime($act['activity_time']));
    $act['description'] = $act['service_type'] ?? $act['description'] ?? $act['feedback'] ?? 'No description';
    $act['patient_name'] = $act['patient_name'] ?? 'Unknown';
    
    // Truncate long descriptions
    if (strlen($act['description']) > 60) {
        $act['description'] = substr($act['description'], 0, 60) . '...';
    }
}

// ==================== TODAY'S APPOINTMENTS - LIMIT 5 ====================
$stmt = $pdo->prepare("
    SELECT a.id, a.appointment_time, a.status, a.service_type,
           CONCAT(p.first_name, ' ', p.last_name) as patient_name,
           d.name as doctor_name,
           COALESCE(pr.name, a.service_type, 'Check-up') as service_type_display
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    LEFT JOIN products pr ON a.product_id = pr.id
    WHERE a.clinic_id = ? AND a.appointment_date = ?
    ORDER BY a.appointment_time ASC
    LIMIT 5
");
$stmt->execute([$clinicId, $today]);
$appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($appointments as &$app) {
    $app['appointment_time'] = date('g:i A', strtotime($app['appointment_time']));
    $app['patient_name'] = $app['patient_name'] ?? 'Walk-in Patient';
    $app['service_type'] = $app['service_type_display'] ?? $app['service_type'] ?? 'Check-up';
}

// ✅ Return data with permissions info (optional - for debugging)
echo json_encode([
    'stats' => [
        'total_patients' => $totalPatients,
        'new_patients' => $newPatients,
        'today_appointments' => $todayAppointments,
        'completed_appointments' => $completedAppointments,
        'pending_followups' => $pendingFollowups,
        'unread_messages' => $unreadMessages,
        'regular_patients' => $regularPatients,
        'senior_patients' => $seniorPatients,
        'pwd_patients' => $pwdPatients,
        'avg_rating' => $avgRating,
        'return_rate' => $returnRate
    ],
    'activities' => $activities,
    'appointments' => $appointments,
    // Optional: Include user permissions for debugging
    '_permissions' => [
        'canView' => canViewDashboard(),
        'canCreate' => canCreateDashboard(),
        'canEdit' => canEditDashboard(),
        'canDelete' => canDeleteDashboard(),
        'canApprove' => canApproveDashboard(),
        'canReject' => canRejectDashboard()
    ]
]);
?>