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

// ✅ Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// ✅ RBAC Permission Check - MUST HAVE DOCTOR DASHBOARD VIEW PERMISSION
if (!RBACHelper::hasPermission('doctor_dashboard_view')) {
    echo json_encode(['success' => false, 'message' => 'Permission denied: You do not have access to doctor dashboard']);
    exit;
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$action = $_GET['action'] ?? '';

// ✅ Permission helper functions - GANTO LANG
function canViewDashboard() { return RBACHelper::hasPermission('doctor_dashboard_view'); }
function canCreateDashboard() { return RBACHelper::hasPermission('doctor_dashboard_create'); }
function canEditDashboard() { return RBACHelper::hasPermission('doctor_dashboard_edit'); }
function canDeleteDashboard() { return RBACHelper::hasPermission('doctor_dashboard_delete'); }
function canApproveDashboard() { return RBACHelper::hasPermission('doctor_dashboard_approve'); }
function canRejectDashboard() { return RBACHelper::hasPermission('doctor_dashboard_reject'); }

// Get client IP for consent
$clientIp = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

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

// Get doctor ID
$doctorId = $_SESSION['doctor_id'] ?? null;

// If user is Optometrist, try to get doctor_id
if (!$doctorId && $_SESSION['role'] === 'Optometrist') {
    $fullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    $stmt = $pdo->prepare("
        SELECT id FROM doctors 
        WHERE clinic_id = ? AND user_id = ? AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([$clinicId, $_SESSION['user_id']]);
    $doc = $stmt->fetch();
    $doctorId = $doc ? $doc['id'] : null;
    $_SESSION['doctor_id'] = $doctorId;
}

// If user is ClinicAdmin, try to get doctor_id
if (!$doctorId && $_SESSION['role'] === 'ClinicAdmin') {
    $stmt = $pdo->prepare("
        SELECT id FROM doctors 
        WHERE clinic_id = ? AND user_id = ? AND is_active = 1 
        LIMIT 1
    ");
    $stmt->execute([$clinicId, $userId]);
    $doc = $stmt->fetch();
    if ($doc) {
        $doctorId = $doc['id'];
        $_SESSION['doctor_id'] = $doctorId;
    }
}

if ($action === 'add_walkin') {
    // ✅ Permission check - need create permission
    if (!canCreateDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot create walk-in appointments']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    try {
        $consentGiven = filter_var($data['consent_given'] ?? false, FILTER_VALIDATE_BOOLEAN);
        
        if (!$consentGiven) {
            echo json_encode(['success' => false, 'message' => 'Data Privacy consent is required']);
            exit;
        }
        
        $pdo->beginTransaction();
        
        $patientId = $data['patient_id'] ?? 0;
        
        if ($patientId > 0) {
            $checkPatient = $pdo->prepare("SELECT id FROM patients WHERE id = ? AND clinic_id = ?");
            $checkPatient->execute([$patientId, $clinicId]);
            if (!$checkPatient->fetch()) {
                $patientId = 0;
            }
        }
        
        if (!$patientId) {
            $patientCode = 'PAT' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            
            $stmt = $pdo->prepare("
                INSERT INTO patients 
                    (clinic_id, patient_code, first_name, last_name, age, gender, 
                     phone, address, patient_type, status, 
                     consent_date, consent_ip, consent_version, data_privacy_accepted, 
                     consent_method, consent_recorded_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 
                        NOW(), ?, ?, 1, 'digital', ?, NOW())
            ");
            
            $stmt->execute([
                $clinicId,
                $patientCode,
                $data['first_name'] ?? '',
                $data['last_name'] ?? '',
                $data['age'] ?? null,
                $data['gender'] ?? null,
                $data['contact'] ?? null,
                $data['address'] ?? null,
                $data['patient_type'] ?? 'Regular',
                $clientIp,
                $data['consent_version'] ?? 'v1.0',
                $userId
            ]);
            
            $patientId = $pdo->lastInsertId();
            
            try {
                $consentStmt = $pdo->prepare("
                    INSERT INTO patient_consent_history 
                    (patient_id, consent_version, consent_date, consent_ip, consent_method, recorded_by)
                    VALUES (?, ?, NOW(), ?, 'digital', ?)
                ");
                $consentStmt->execute([$patientId, $data['consent_version'] ?? 'v1.0', $clientIp, $userId]);
            } catch (Exception $e) {
                // Table might not exist, ignore
            }
        }
        
        if (!$patientId) {
            throw new Exception('Failed to create or find patient');
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO appointments
                (clinic_id, patient_id, doctor_id, service_type, appointment_date,
                 appointment_time, notes, status, appointment_type, created_at)
            VALUES (?, ?, ?, ?, CURDATE(), ?, ?, 'confirmed', 'walk_in', NOW())
        ");
        
        $stmt->execute([
            $clinicId,
            $patientId,
            $doctorId,
            $data['service'] ?? 'Check-up',
            $data['appointment_time'],
            $data['notes'] ?? null
        ]);
        
        $appointmentId = $pdo->lastInsertId();
        
        if ($doctorId) {
            $stmt = $pdo->prepare("
                INSERT INTO clinical_notes
                    (clinic_id, patient_id, appointment_id, optometrist_id, service_date, status)
                VALUES (?, ?, ?, ?, CURDATE(), 'draft')
            ");
            
            $stmt->execute([
                $clinicId,
                $patientId,
                $appointmentId,
                $doctorId
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'appointment_id' => $appointmentId,
            'patient_id' => $patientId,
            'consent_recorded' => true,
            'message' => 'Walk-in appointment created successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_queue') {
    $today = date('Y-m-d');
    $doctorId = $_SESSION['doctor_id'] ?? null;
    $isAdmin = in_array($_SESSION['role'] ?? '', ['ClinicAdmin', 'SuperAdmin']);
    
    try {
        // ========== TODAY'S QUEUE - ONLY ARRIVED AND IN_PROGRESS ==========
        // Para sa mga dumating na at nasa consultation
        if ($isAdmin) {
            $stmtToday = $pdo->prepare("
                SELECT a.*,
                       CASE 
                           WHEN p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                           WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
                           ELSE 'Walk-in Patient'
                       END AS patient_name,
                       p.age, p.gender, p.patient_type,
                       COALESCE(pr.name, srv.name, a.service_type, 'Check-up') AS product_name,
                       d.name AS doctor_name,
                       a.payment_status,
                       a.status as appointment_status,
                       u.id AS user_id,
                       a.arrived_at,
                       CASE 
                           WHEN a.patient_id IS NOT NULL AND p.id IS NOT NULL THEN 0
                           WHEN a.patient_id IS NULL AND a.user_id IS NOT NULL AND EXISTS (
                               SELECT 1 FROM patients p2 
                               WHERE p2.clinic_id = a.clinic_id 
                               AND p2.email = (SELECT email FROM users WHERE id = a.user_id)
                           ) THEN 0
                           ELSE 1
                       END AS is_new_patient
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN products pr ON a.product_id = pr.id
                LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
                LEFT JOIN doctors d ON a.doctor_id = d.id
                WHERE a.clinic_id = ? 
                  AND a.appointment_date = ?
                  AND a.status IN ('arrived', 'in_progress')  -- ✅ Only these statuses
                ORDER BY a.arrived_at ASC
            ");
            $stmtToday->execute([$clinicId, $today]);
        } else {
            $stmtToday = $pdo->prepare("
                SELECT a.*,
                       CASE 
                           WHEN p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                           WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
                           ELSE 'Walk-in Patient'
                       END AS patient_name,
                       p.age, p.gender, p.patient_type,
                       COALESCE(pr.name, srv.name, a.service_type, 'Check-up') AS product_name,
                       d.name AS doctor_name,
                       a.payment_status,
                       a.status as appointment_status,
                       u.id AS user_id,
                       a.arrived_at,
                       CASE 
                           WHEN a.patient_id IS NOT NULL AND p.id IS NOT NULL THEN 0
                           WHEN a.patient_id IS NULL AND a.user_id IS NOT NULL AND EXISTS (
                               SELECT 1 FROM patients p2 
                               WHERE p2.clinic_id = a.clinic_id 
                               AND p2.email = (SELECT email FROM users WHERE id = a.user_id)
                           ) THEN 0
                           ELSE 1
                       END AS is_new_patient
                FROM appointments a
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.user_id = u.id
                LEFT JOIN products pr ON a.product_id = pr.id
                LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
                LEFT JOIN doctors d ON a.doctor_id = d.id
                WHERE a.clinic_id = ? 
                  AND a.appointment_date = ?
                  AND a.status IN ('arrived', 'in_progress')
                  AND (a.doctor_id = ? OR a.doctor_id IS NULL)
                ORDER BY a.arrived_at ASC
            ");
            $stmtToday->execute([$clinicId, $today, $doctorId]);
        }
        
        $queueAppointments = $stmtToday->fetchAll(PDO::FETCH_ASSOC);
        
        // Separate waiting (arrived) vs in_progress
        $waiting = [];
        $inProgress = [];
        
        foreach ($queueAppointments as $appt) {
            if ($appt['status'] === 'in_progress') {
                $inProgress[] = $appt;
            } else {
                $waiting[] = $appt;
            }
        }
        
        // ========== COMPLETED TODAY (View Only) ==========
        $stmtCompleted = $pdo->prepare("
            SELECT a.*,
                   CASE 
                       WHEN p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                       WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
                       ELSE 'Walk-in Patient'
                   END AS patient_name,
                   p.age, p.gender, p.patient_type,
                   COALESCE(pr.name, srv.name, a.service_type, 'Check-up') AS product_name,
                   d.name AS doctor_name,
                   a.status as appointment_status,
                   a.completed_at
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN products pr ON a.product_id = pr.id
            LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
            LEFT JOIN doctors d ON a.doctor_id = d.id
            WHERE a.clinic_id = ? 
              AND a.appointment_date = ?
              AND a.status = 'completed'  -- ✅ Only fully paid and done
            ORDER BY a.completed_at DESC
        ");
        $stmtCompleted->execute([$clinicId, $today]);
        $completed = $stmtCompleted->fetchAll(PDO::FETCH_ASSOC);
        
        // ========== WAITING FOR PAYMENT (View Only) ==========
        $stmtWaitingPayment = $pdo->prepare("
            SELECT a.*,
                   CASE 
                       WHEN p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                       WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
                       ELSE 'Walk-in Patient'
                   END AS patient_name,
                   p.age, p.gender, p.patient_type,
                   COALESCE(pr.name, srv.name, a.service_type, 'Check-up') AS product_name,
                   d.name AS doctor_name,
                   a.status as appointment_status,
                   a.total_amount,
                   a.consultation_completed_at
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN products pr ON a.product_id = pr.id
            LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
            LEFT JOIN doctors d ON a.doctor_id = d.id
            WHERE a.clinic_id = ? 
              AND a.appointment_date = ?
              AND a.status = 'waiting_payment'  -- ✅ Consultation done, waiting for payment
            ORDER BY a.consultation_completed_at DESC
        ");
        $stmtWaitingPayment->execute([$clinicId, $today]);
        $waitingPayment = $stmtWaitingPayment->fetchAll(PDO::FETCH_ASSOC);
        
        // ========== UPCOMING APPOINTMENTS (View Only) ==========
        $stmtUpcoming = $pdo->prepare("
            SELECT a.*,
                   CASE 
                       WHEN p.id IS NOT NULL THEN CONCAT(p.first_name, ' ', p.last_name)
                       WHEN u.id IS NOT NULL THEN CONCAT(u.first_name, ' ', u.last_name)
                       ELSE 'Walk-in Patient'
                   END AS patient_name,
                   p.age, p.gender, p.patient_type,
                   COALESCE(pr.name, srv.name, a.service_type, 'Check-up') AS product_name,
                   d.name AS doctor_name,
                   a.payment_status,
                   a.status as appointment_status,
                   a.appointment_date as future_date
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN users u ON a.user_id = u.id
            LEFT JOIN products pr ON a.product_id = pr.id
            LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
            LEFT JOIN doctors d ON a.doctor_id = d.id
            WHERE a.clinic_id = ? 
              AND a.appointment_date > ?
              AND a.appointment_date <= DATE_ADD(?, INTERVAL 7 DAY)
              AND a.status IN ('confirmed', 'paid')  -- ✅ Upcoming appointments
            ORDER BY a.appointment_date ASC, a.appointment_time ASC
        ");
        $stmtUpcoming->execute([$clinicId, $today, $today]);
        $upcomingAppointments = $stmtUpcoming->fetchAll(PDO::FETCH_ASSOC);
        
        // Group upcoming by date
        $groupedUpcoming = [];
        foreach ($upcomingAppointments as $appt) {
            $date = $appt['appointment_date'];
            if (!isset($groupedUpcoming[$date])) {
                $groupedUpcoming[$date] = [];
            }
            $groupedUpcoming[$date][] = $appt;
        }
        
        echo json_encode([
            'success' => true,
            'today' => [
                'waiting' => $waiting,           // ARRIVED status - ready for consultation
                'in_progress' => $inProgress,    // IN_PROGRESS status - currently being examined
                'waiting_payment' => $waitingPayment, // WAITING_PAYMENT status - consultation done, need payment
                'completed' => $completed        // COMPLETED status - fully paid and done
            ],
            'upcoming' => $groupedUpcoming,       // Future appointments - view only
            'doctor_id' => $doctorId,
            'is_admin' => $isAdmin,
            'current_date' => $today
        ]);
        
    } catch (Exception $e) {
        error_log("Get Queue Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'claim_appointment') {
    // ✅ Permission check - need edit permission
    if (!canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot claim appointments']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $claimingDoctorId = (int)($data['doctor_id'] ?? 0);
    
    if (!$appointmentId || !$claimingDoctorId) {
        echo json_encode(['success' => false, 'message' => 'Missing data']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE appointments 
            SET doctor_id = ?, updated_at = NOW()
            WHERE id = ? AND clinic_id = ? AND doctor_id IS NULL
        ");
        
        $success = $stmt->execute([$claimingDoctorId, $appointmentId, $clinicId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Appointment already claimed by another doctor']);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'search_patients') {
    // ✅ No permission check - view is already checked at top
    $search = $_GET['q'] ?? '';
    
    if (strlen($search) < 2) {
        echo json_encode([]);
        exit;
    }
    
    $stmt = $pdo->prepare("
        SELECT id, patient_code, 
               CONCAT(first_name, ' ', last_name) as full_name,
               age, gender, phone, email, address, patient_type
        FROM patients 
        WHERE clinic_id = ? 
          AND (first_name LIKE ? OR last_name LIKE ? OR phone LIKE ? OR email LIKE ?)
          AND status = 'Active'
        ORDER BY last_name ASC
        LIMIT 10
    ");
    
    $searchTerm = "%$search%";
    $stmt->execute([$clinicId, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'get_user_details') {
    // ✅ No permission check
    $userId = (int)($_GET['user_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT first_name, last_name, contact, address FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($user);
    exit;
}

if ($action === 'save_patient') {
    // ✅ Permission check - need create permission for patients
    if (!canCreateDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot save patient']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $userId = (int)($data['user_id'] ?? 0);
    
    if (!$appointmentId || !$userId) {
        echo json_encode(['success' => false, 'message' => 'Missing data']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $patientCode = 'PAT' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            INSERT INTO patients 
                (clinic_id, patient_code, first_name, last_name, age, gender, 
                 phone, address, patient_type, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', NOW())
        ");
        
        $stmt->execute([
            $clinicId,
            $patientCode,
            $data['first_name'],
            $data['last_name'],
            $data['age'] ?? null,
            $data['gender'] ?? null,
            $data['contact'] ?? null,
            $data['address'] ?? null,
            $data['patient_type'] ?? 'Regular'
        ]);
        
        $patientId = $pdo->lastInsertId();
        
        $stmt = $pdo->prepare("UPDATE appointments SET patient_id = ? WHERE id = ?");
        $stmt->execute([$patientId, $appointmentId]);
        
        $stmt = $pdo->prepare("UPDATE clinical_notes SET patient_id = ? WHERE appointment_id = ?");
        $stmt->execute([$patientId, $appointmentId]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'patient_id' => $patientId,
            'message' => 'Patient saved successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'start_exam') {
    // ✅ Permission check - need edit permission
    if (!canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot start examination']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $pdo->prepare("
            UPDATE appointments SET status = 'in_progress', updated_at = NOW()
            WHERE id = ? AND clinic_id = ? AND status IN ('paid', 'confirmed')
        ")->execute([$appointmentId, $clinicId]);
        
        $stmt = $pdo->prepare("SELECT patient_id, user_id FROM appointments WHERE id = ?");
        $stmt->execute([$appointmentId]);
        $appt = $stmt->fetch();
        
        if ($appt && $appt['patient_id'] && $doctorId) {
            $stmt = $pdo->prepare("SELECT id FROM clinical_notes WHERE appointment_id = ?");
            $stmt->execute([$appointmentId]);
            if (!$stmt->fetch()) {
                $pdo->prepare("
                    INSERT INTO clinical_notes 
                        (clinic_id, patient_id, appointment_id, optometrist_id, service_date, status)
                    VALUES (?, ?, ?, ?, CURDATE(), 'draft')
                ")->execute([$clinicId, $appt['patient_id'], $appointmentId, $doctorId]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_patient_data') {
    // ✅ No permission check - view is already checked at top
    $patientId = (int)($_GET['patient_id'] ?? 0);
    $appointmentId = (int)($_GET['appointment_id'] ?? 0);
    
    if (!$patientId) {
        echo json_encode(['success' => false, 'message' => 'Missing patient ID']);
        exit;
    }
    
    try {
        // Get history from clinical_notes
        $stmt = $pdo->prepare("
            SELECT cn.*, 
                   DATE_FORMAT(cn.service_date, '%M %d, %Y') as formatted_date,
                   CONCAT(u.first_name, ' ', u.last_name) AS doctor_name
            FROM clinical_notes cn
            LEFT JOIN users u ON cn.optometrist_id = u.id
            WHERE cn.patient_id = ? 
              AND cn.clinic_id = ? 
              AND cn.status IN ('completed', 'draft')
            ORDER BY cn.service_date DESC 
            LIMIT 10
        ");
        $stmt->execute([$patientId, $clinicId]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get latest notes for this appointment
        $latestNotes = null;
        if ($appointmentId) {
            $stmt = $pdo->prepare("
                SELECT * FROM clinical_notes 
                WHERE appointment_id = ? AND clinic_id = ? 
                LIMIT 1
            ");
            $stmt->execute([$appointmentId, $clinicId]);
            $latestNotes = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Get latest prescription
        $latestRx = null;
        if ($appointmentId) {
            $stmt = $pdo->prepare("
                SELECT * FROM prescriptions 
                WHERE appointment_id = ? AND clinic_id = ? 
                LIMIT 1
            ");
            $stmt->execute([$appointmentId, $clinicId]);
            $latestRx = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$latestRx) {
            $stmt = $pdo->prepare("
                SELECT * FROM prescriptions 
                WHERE patient_id = ? AND clinic_id = ? 
                ORDER BY created_at DESC LIMIT 1
            ");
            $stmt->execute([$patientId, $clinicId]);
            $latestRx = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        echo json_encode([
            'success' => true,
            'latest_notes' => $latestNotes,
            'latest_rx' => $latestRx,
            'history' => $history
        ]);
        
    } catch (Exception $e) {
        error_log("Error in get_patient_data: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_notes') {
    // ✅ Permission check - need create/edit permission
    if (!canCreateDashboard() && !canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot save clinical notes']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $patientId = (int)($data['patient_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    $clinicId = $_SESSION['clinic_id'];
    $doctorId = $_SESSION['doctor_id'] ?? null;
    $userRole = $_SESSION['role'] ?? '';
    
    if ($userRole === 'ClinicAdmin' && !$doctorId) {
        if (empty($_SESSION['first_name']) || empty($_SESSION['last_name'])) {
            $stmt = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['user_id']]);
            $user = $stmt->fetch();
            if ($user) {
                $_SESSION['first_name'] = $user['first_name'];
                $_SESSION['last_name'] = $user['last_name'];
            }
        }
        
        $fullName = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
        
        $stmt = $pdo->prepare("
            SELECT id FROM doctors 
            WHERE clinic_id = ? AND user_id = ? AND is_active = 1 
            LIMIT 1
        ");
        $stmt->execute([$clinicId, $_SESSION['user_id']]);
        $existingDoctor = $stmt->fetch();
        
        if ($existingDoctor) {
            $doctorId = $existingDoctor['id'];
            $_SESSION['doctor_id'] = $doctorId;
        } else {
            echo json_encode([
                'success' => false, 
                'message' => 'You need a doctor profile to create clinical notes. Would you like to set up your doctor profile?',
                'code' => 'NO_DOCTOR_PROFILE',
                'can_setup' => true
            ]);
            exit;
        }
    }
    
    if ($userRole !== 'Optometrist' && $userRole !== 'ClinicAdmin') {
        echo json_encode([
            'success' => false, 
            'message' => 'Only Optometrists and Doctors can create clinical notes.'
        ]);
        exit;
    }
    
    if (!$doctorId) {
        echo json_encode([
            'success'   => false,
            'message'   => 'No doctor profile is linked to your account.',
            'code'      => 'NO_DOCTOR_PROFILE',
            'can_setup' => true
        ]);
        exit;
    }
    
    try {
        if (!$patientId) {
            $stmt = $pdo->prepare("SELECT patient_id, user_id FROM appointments WHERE id = ?");
            $stmt->execute([$appointmentId]);
            $appt = $stmt->fetch();
            
            if (!$appt['patient_id'] && $appt['user_id']) {
                $stmt = $pdo->prepare("
                    SELECT first_name, last_name, contact, email, address 
                    FROM users WHERE id = ?
                ");
                $stmt->execute([$appt['user_id']]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    $patientCode = 'PAT' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                    
                    $pdo->prepare("
                        INSERT INTO patients 
                            (clinic_id, patient_code, first_name, last_name, phone, 
                             email, address, patient_type, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Regular', 'Active', NOW())
                    ")->execute([
                        $clinicId,
                        $patientCode,
                        $user['first_name'],
                        $user['last_name'],
                        $user['contact'],
                        $user['email'],
                        $user['address']
                    ]);
                    
                    $patientId = $pdo->lastInsertId();
                    
                    $pdo->prepare("UPDATE appointments SET patient_id = ? WHERE id = ?")
                        ->execute([$patientId, $appointmentId]);
                }
            }
        }
        
        $stmt = $pdo->prepare("SELECT id FROM clinical_notes WHERE appointment_id = ? AND clinic_id = ?");
        $stmt->execute([$appointmentId, $clinicId]);
        $existing = $stmt->fetch();
        
        $optometristId = $doctorId;
        
        if (!$patientId) {
            $stmt = $pdo->prepare("SELECT patient_id FROM appointments WHERE id = ? AND clinic_id = ?");
            $stmt->execute([$appointmentId, $clinicId]);
            $row = $stmt->fetch();
            $patientId = $row['patient_id'] ?? null;
        }
        if (!$patientId) {
            echo json_encode(['success' => false, 'message' => 'Patient not yet saved. Please save patient details first.']);
            exit;
        }
        
        if ($existing) {
            $pdo->prepare("
                UPDATE clinical_notes SET
                    chief_complaint = ?,
                    va_left = ?,
                    va_right = ?,
                    findings = ?,
                    diagnosis = ?,
                    treatment_plan = ?,
                    notes = ?,
                    status = 'completed',
                    updated_at = NOW()
                WHERE appointment_id = ? AND clinic_id = ?
            ")->execute([
                $data['chief_complaint'] ?? null,
                $data['va_left'] ?? null,
                $data['va_right'] ?? null,
                $data['findings'] ?? null,
                $data['diagnosis'] ?? null,
                $data['treatment_plan'] ?? null,
                $data['notes'] ?? null,
                $appointmentId,
                $clinicId
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO clinical_notes
                    (clinic_id, patient_id, appointment_id, optometrist_id, service_date,
                     chief_complaint, va_left, va_right, findings, diagnosis,
                     treatment_plan, notes, status)
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, 'completed')
            ")->execute([
                $clinicId,
                $patientId,
                $appointmentId,
                $optometristId,
                $data['chief_complaint'] ?? null,
                $data['va_left'] ?? null,
                $data['va_right'] ?? null,
                $data['findings'] ?? null,
                $data['diagnosis'] ?? null,
                $data['treatment_plan'] ?? null,
                $data['notes'] ?? null
            ]);
        }
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'save_rx') {
    // ✅ Permission check - need create/edit permission
    if (!canCreateDashboard() && !canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot save prescriptions']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $patientId = (int)($data['patient_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        $clinicId = $_SESSION['clinic_id'];
        $doctorId = $_SESSION['doctor_id'] ?? null;
        $optometristName = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
        
        // ========== 1. SAVE TO prescriptions TABLE ==========
        $stmt = $pdo->prepare("SELECT id FROM prescriptions WHERE appointment_id = ? AND clinic_id = ?");
        $stmt->execute([$appointmentId, $clinicId]);
        $existing = $stmt->fetch();
        
        $expiryDate = date('Y-m-d', strtotime('+1 year'));
        
        if ($existing) {
            $pdo->prepare("
                UPDATE prescriptions SET
                    sph_r = ?, cyl_r = ?, axis_r = ?, add_r = ?,
                    sph_l = ?, cyl_l = ?, axis_l = ?, add_l = ?,
                    pd = ?, pd_type = ?, notes = ?, updated_at = NOW()
                WHERE appointment_id = ? AND clinic_id = ?
            ")->execute([
                $data['sph_r'] ?? null,
                $data['cyl_r'] ?? null,
                $data['axis_r'] ?? null,
                $data['add_r'] ?? null,
                $data['sph_l'] ?? null,
                $data['cyl_l'] ?? null,
                $data['axis_l'] ?? null,
                $data['add_l'] ?? null,
                $data['pd'] ?? null,
                $data['pd_type'] ?? 'Binocular',
                $data['notes'] ?? null,
                $appointmentId,
                $clinicId
            ]);
        } else {
            $pdo->prepare("
                INSERT INTO prescriptions
                    (clinic_id, patient_id, appointment_id, doctor_id, prescription_date, expiry_date,
                     sph_r, cyl_r, axis_r, add_r, sph_l, cyl_l, axis_l, add_l,
                     pd, pd_type, notes)
                VALUES (?, ?, ?, ?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $clinicId,
                $patientId,
                $appointmentId,
                $doctorId,
                $expiryDate,
                $data['sph_r'] ?? null,
                $data['cyl_r'] ?? null,
                $data['axis_r'] ?? null,
                $data['add_r'] ?? null,
                $data['sph_l'] ?? null,
                $data['cyl_l'] ?? null,
                $data['axis_l'] ?? null,
                $data['add_l'] ?? null,
                $data['pd'] ?? null,
                $data['pd_type'] ?? 'Binocular',
                $data['notes'] ?? null
            ]);
        }
        
        // ========== 2. SAVE/UPDATE optical_records TABLE (ONLY HERE - NO DUPLICATES) ==========
        // Check if optical record exists for this appointment
        $checkOptical = $pdo->prepare("
            SELECT id FROM optical_records 
            WHERE appointment_id = ? AND clinic_id = ?
            LIMIT 1
        ");
        $checkOptical->execute([$appointmentId, $clinicId]);
        $existingOptical = $checkOptical->fetch();
        
        if ($existingOptical) {
            // ✅ UPDATE existing record - safe, no duplicate
            $pdo->prepare("
                UPDATE optical_records SET
                    od_sph = ?, od_cyl = ?, od_axis = ?, od_add = ?,
                    os_sph = ?, os_cyl = ?, os_axis = ?, os_add = ?,
                    pd = ?, notes = ?, optometrist = ?, updated_at = NOW()
                WHERE appointment_id = ? AND clinic_id = ?
            ")->execute([
                $data['sph_r'] ?? null,
                $data['cyl_r'] ?? null,
                $data['axis_r'] ?? null,
                $data['add_r'] ?? null,
                $data['sph_l'] ?? null,
                $data['cyl_l'] ?? null,
                $data['axis_l'] ?? null,
                $data['add_l'] ?? null,
                $data['pd'] ?? null,
                $data['notes'] ?? null,
                $optometristName,
                $appointmentId,
                $clinicId
            ]);
        } else {
            // ✅ INSERT new record - only if no record exists yet
            $recordCode = 'REC-' . date('Ymd') . '-' . str_pad($appointmentId, 6, '0', STR_PAD_LEFT);
            
            $pdo->prepare("
                INSERT INTO optical_records 
                    (clinic_id, patient_id, appointment_id, record_code, examination_date,
                     od_sph, od_cyl, od_axis, od_add,
                     os_sph, os_cyl, os_axis, os_add,
                     pd, notes, optometrist, created_at)
                VALUES (?, ?, ?, ?, CURDATE(),
                        ?, ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, NOW())
            ")->execute([
                $clinicId,
                $patientId,
                $appointmentId,
                $recordCode,
                $data['sph_r'] ?? null,
                $data['cyl_r'] ?? null,
                $data['axis_r'] ?? null,
                $data['add_r'] ?? null,
                $data['sph_l'] ?? null,
                $data['cyl_l'] ?? null,
                $data['axis_l'] ?? null,
                $data['add_l'] ?? null,
                $data['pd'] ?? null,
                $data['notes'] ?? null,
                $optometristName
            ]);
        }
        
        echo json_encode(['success' => true]);
        
    } catch (Exception $e) {
        error_log("save_rx error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
if ($action === 'complete') {
    // ✅ Permission check - need edit permission
    if (!canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot complete appointments']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $patientId = (int)($data['patient_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            SELECT a.*, p.first_name, p.last_name 
            FROM appointments a
            LEFT JOIN patients p ON a.patient_id = p.id
            WHERE a.id = ? AND a.clinic_id = ?
        ");
        $stmt->execute([$appointmentId, $clinicId]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            throw new Exception('Appointment not found');
        }
        
        $pdo->prepare("
            UPDATE appointments SET 
                status = 'completed', 
                updated_at = NOW() 
            WHERE id = ? AND clinic_id = ?
        ")->execute([$appointmentId, $clinicId]);
        
        $pdo->prepare("
            UPDATE clinical_notes SET 
                status = 'completed', 
                updated_at = NOW() 
            WHERE appointment_id = ? AND clinic_id = ?
        ")->execute([$appointmentId, $clinicId]);
        
        if (!empty($appointment['user_id'])) {
            $formattedDate = date('F j, Y');
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications
                    (user_id, title, message, type, reference_id, link, created_at)
                VALUES (?, 'Appointment Completed ✅', ?, 'success', ?, 'my-appointments.php', NOW())
            ");
            
            $clinicName = $pdo->query("SELECT name FROM clinics WHERE id = $clinicId")->fetchColumn();
            
            $notifStmt->execute([
                $appointment['user_id'],
                "Your appointment at {$clinicName} on {$formattedDate} has been completed. Thank you!",
                $appointmentId
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Appointment completed successfully',
            'appointment_id' => $appointmentId,
            'patient_name' => ($appointment['first_name'] ?? '') . ' ' . ($appointment['last_name'] ?? '')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ==================== DECISION SUPPORT FUNCTIONS ====================

// ================== GET DASHBOARD STATS ==================
if ($action === 'get_stats') {
    function getDashboardStats($pdo, $clinicId, $userId) {
        $stats = [];
        
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT patient_id) as count 
            FROM exam_results 
            WHERE clinic_id = ?
        ");
        $stmt->execute([$clinicId]);
        $stats['total_analyzed'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM exam_results 
            WHERE clinic_id = ? 
            AND (
                LOWER(diagnosis) LIKE '%cataract%' 
                OR LOWER(diagnosis) LIKE '%glaucoma%' 
                OR LOWER(diagnosis) LIKE '%keratoconus%'
                OR LOWER(diagnosis) LIKE '%pterygium%'
            )
        ");
        $stmt->execute([$clinicId]);
        $stats['surgery_recs'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM surgery_referrals 
            WHERE clinic_id = ? AND from_doctor = ?
        ");
        $stmt->execute([$clinicId, $userId]);
        $stats['referrals'] = (int)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT p.id) as count
            FROM patients p
            LEFT JOIN exam_results e ON p.id = e.patient_id
            WHERE p.clinic_id = ? 
            AND (
                p.age >= 60 
                OR LOWER(e.diagnosis) LIKE '%glaucoma%'
                OR LOWER(e.diagnosis) LIKE '%diabetic%'
                OR LOWER(e.diagnosis) LIKE '%cataract%'
            )
        ");
        $stmt->execute([$clinicId]);
        $stats['high_risk'] = (int)$stmt->fetchColumn();
        
        return $stats;
    }
    
    echo json_encode(getDashboardStats($pdo, $clinicId, $userId));
    exit;
}

// ================== GET PATIENTS ==================
if ($action === 'get_patients') {
    $stmt = $pdo->prepare("
        SELECT id, 
               CONCAT(first_name, ' ', last_name) as name,
               patient_code,
               age,
               gender
        FROM patients 
        WHERE clinic_id = ? AND status = 'Active' 
        ORDER BY first_name
    ");
    $stmt->execute([$clinicId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ================== GET SURGICAL CENTERS ==================
if ($action === 'get_surgical_centers') {
    $stmt = $pdo->prepare("
        SELECT id, clinic_name, clinic_type, city, offers_eye_surgery
        FROM clinics 
        WHERE status = 'Active'
        ORDER BY offers_eye_surgery DESC, clinic_name
    ");
    $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ================== GET PATIENT DATA FOR DECISION SUPPORT ==================
if ($action === 'get_patient_data_dss') {  // Different name para hindi magka-conflict
    $patientId = $_GET['patient_id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
    $stmt->execute([$patientId, $clinicId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT * FROM exam_results 
        WHERE patient_id = ? 
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("
        SELECT * FROM prescriptions 
        WHERE patient_id = ? 
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$patientId]);
    $prescription = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'latest_exam' => $exam,
        'latest_prescription' => $prescription
    ]);
    exit;
}

if ($action === 'analyze') {
    // ✅ Permission check - need edit permission for analysis
    if (!canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot analyze patient data']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $patientId = $data['patient_id'];
    
    if (!$patientId) {
        echo json_encode(['success' => false, 'message' => 'No patient ID']);
        exit;
    }
    
    // Get patient info
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ?");
    $stmt->execute([$patientId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit;
    }
    
    // ✅ GET LATEST CLINICAL NOTES (where diagnosis is saved)
    $stmt = $pdo->prepare("
        SELECT cn.* FROM clinical_notes cn
        WHERE cn.patient_id = ? AND cn.clinic_id = ?
        ORDER BY cn.service_date DESC LIMIT 1
    ");
    $stmt->execute([$patientId, $clinicId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get latest prescription for myopia check
    $stmt = $pdo->prepare("
        SELECT sph_l, sph_r FROM prescriptions 
        WHERE patient_id = ? AND clinic_id = ?
        ORDER BY created_at DESC LIMIT 1
    ");
    $stmt->execute([$patientId, $clinicId]);
    $rx = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $riskLevel = 'low';
    $riskMessage = 'Patient appears to be low risk. Regular monitoring recommended.';
    $surgeryRecommended = false;
    $surgeryType = '';
    $surgeryReason = '';
    
    $diagnoses = [];
    $treatments = [];
    
    // ✅ CHECK DIAGNOSIS FROM clinical_notes
    if ($exam && !empty($exam['diagnosis'])) {
        $diagnosis = strtolower(trim($exam['diagnosis']));
        error_log('Found diagnosis: ' . $diagnosis);
        
        if (strpos($diagnosis, 'cataract') !== false) {
            $riskLevel = 'high';
            $riskMessage = 'Cataract detected. Surgery may be needed if affecting daily activities.';
            $surgeryRecommended = true;
            $surgeryType = 'Cataract Surgery';
            $surgeryReason = 'Cataract affecting vision based on examination';
            $diagnoses[] = ['name' => 'Cataract', 'reason' => 'Diagnosed in latest exam', 'confidence' => 'high'];
        }
        
        if (strpos($diagnosis, 'glaucoma') !== false) {
            $riskLevel = 'high';
            $riskMessage = 'Glaucoma detected. Requires immediate management and possible surgery.';
            $surgeryRecommended = true;
            $surgeryType = 'Glaucoma Surgery';
            $surgeryReason = 'Glaucoma with elevated IOP';
            $diagnoses[] = ['name' => 'Glaucoma', 'reason' => 'Diagnosed in latest exam', 'confidence' => 'high'];
        }
        
        if (strpos($diagnosis, 'keratoconus') !== false) {
            $riskLevel = 'high';
            $riskMessage = 'Keratoconus detected. May require corneal cross-linking or transplant.';
            $surgeryRecommended = true;
            $surgeryType = 'Corneal Transplant';
            $surgeryReason = 'Advanced keratoconus';
            $diagnoses[] = ['name' => 'Keratoconus', 'reason' => 'Corneal thinning detected', 'confidence' => 'high'];
        }
        
        if (strpos($diagnosis, 'diabetic') !== false) {
            $riskLevel = 'medium';
            $riskMessage = 'Diabetic retinopathy detected. Coordinate with primary care physician.';
            $diagnoses[] = ['name' => 'Diabetic Retinopathy', 'reason' => 'Diabetic eye complications', 'confidence' => 'high'];
        }
        
        if (strpos($diagnosis, 'macular') !== false) {
            $riskLevel = 'medium';
            $riskMessage = 'Macular degeneration detected. Monitor with Amsler grid.';
            $diagnoses[] = ['name' => 'Macular Degeneration', 'reason' => 'Age-related macular changes', 'confidence' => 'medium'];
        }
    }
    
    // Check age-related risk if no diagnosis
    if ($patient && $patient['age'] >= 60 && empty($diagnoses)) {
        $riskLevel = 'medium';
        $riskMessage = 'Senior patient. Increased risk for age-related eye conditions.';
        $diagnoses[] = [
            'name' => 'Age-related risk',
            'reason' => 'Patient age ≥ 60 years',
            'confidence' => 'medium'
        ];
    }
    
    // Check high myopia
    if ($rx) {
        $myopiaL = abs(floatval($rx['sph_l'] ?? 0));
        $myopiaR = abs(floatval($rx['sph_r'] ?? 0));
        
        if ($myopiaL > 6 || $myopiaR > 6) {
            $riskLevel = 'medium';
            $riskMessage = 'High myopia detected. Increased risk for retinal detachment.';
            $diagnoses[] = [
                'name' => 'High Myopia',
                'reason' => "Refractive error > -6.00D (L: {$rx['sph_l']}, R: {$rx['sph_r']})",
                'confidence' => 'high'
            ];
        }
    }
    
    // Set treatment recommendations based on risk
    if ($riskLevel === 'high') {
        $treatments[] = ['icon' => 'hospital', 'description' => 'Schedule surgical consultation'];
        $treatments[] = ['icon' => 'calendar-check', 'description' => 'Follow-up in 1-2 weeks'];
    } elseif ($riskLevel === 'medium') {
        $treatments[] = ['icon' => 'eye', 'description' => 'Comprehensive eye exam in 6 months'];
        $treatments[] = ['icon' => 'prescription2', 'description' => 'Review medications if any'];
    } else {
        $treatments[] = ['icon' => 'check-circle', 'description' => 'Continue regular monitoring'];
        $treatments[] = ['icon' => 'calendar', 'description' => 'Schedule next appointment in 1 year'];
    }
    
    // Default if no conditions detected
    if (empty($diagnoses)) {
        $diagnoses[] = [
            'name' => 'Normal Examination',
            'reason' => 'No significant findings detected',
            'confidence' => 'low'
        ];
    }
    
    // Log analysis
    $stmt = $pdo->prepare("
        INSERT INTO decision_support_logs 
        (clinic_id, patient_id, doctor_id, risk_level, recommendations, surgery_recommended) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $clinicId,
        $patientId,
        $userId,
        $riskLevel,
        json_encode($diagnoses),
        $surgeryRecommended ? 1 : 0
    ]);
    
    echo json_encode([
        'success' => true,
        'risk_level' => $riskLevel,
        'risk_message' => $riskMessage,
        'diagnoses' => $diagnoses,
        'treatments' => $treatments,
        'surgery_recommended' => $surgeryRecommended,
        'surgery_type' => $surgeryType,
        'surgery_reason' => $surgeryReason,
        'patient_city' => $patient['address'] ?? ''
    ]);
    exit;
}


// ================== NEARBY CLINICS ==================
if ($action === 'nearby_clinics') {
    $city = $_GET['city'] ?? '';
    
    $sql = "SELECT id, clinic_name, city, clinic_type FROM clinics WHERE offers_eye_surgery = 1 AND status = 'Active'";
    $params = [];
    
    if (!empty($city)) {
        $cityParts = explode(',', $city);
        $searchCity = trim(end($cityParts));
        if (!empty($searchCity)) {
            $sql .= " AND city LIKE ?";
            $params[] = "%$searchCity%";
        }
    }
    
    $sql .= " LIMIT 5";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ================== REFER PATIENT ==================
if ($action === 'refer') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $stmt = $pdo->prepare("
        INSERT INTO surgery_referrals 
        (clinic_id, patient_id, from_doctor, to_clinic, surgery_type, priority, notes) 
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $success = $stmt->execute([
        $clinicId,
        $data['patient_id'],
        $userId,
        $data['clinic_id'],
        $data['surgery_type'],
        $data['priority'],
        $data['notes']
    ]);
    
    $stmt = $pdo->prepare("
        UPDATE decision_support_logs 
        SET referral_made = TRUE, referral_to = ? 
        WHERE patient_id = ? 
        ORDER BY id DESC LIMIT 1
    ");
    $stmt->execute([$data['clinic_id'], $data['patient_id']]);
    
    echo json_encode(['success' => $success]);
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
 
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';
 
if ($action === 'get_surgery_clinics') {
    // ✅ No permission check - view is already checked at top
    try {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                name,
                clinic_email,
                logo,
                clinic_logo,
                city,
                province,
                address,
                contact,
                phone,
                average_rating,
                hours,
                clinic_type,
                offers_eye_surgery
            FROM clinics
            WHERE offers_eye_surgery = 1
              AND status = 'Active'
            ORDER BY city ASC, name ASC
        ");
        $stmt->execute();
        $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($clinics as &$clinic) {
            $logoFile = $clinic['logo'] ?: $clinic['clinic_logo'];
            if ($logoFile) {
                if (strpos($logoFile, '/') !== false || strpos($logoFile, 'clinic_') === 0) {
                    $clinic['logo_url'] = 'uploads/' . $logoFile;
                } else {
                    $clinic['logo_url'] = 'uploads/clinic_logos/' . $logoFile;
                }
            } else {
                $clinic['logo_url'] = null;
            }
            unset($clinic['logo'], $clinic['clinic_logo']);
        }
        unset($clinic);
        
        echo json_encode([
            'success' => true,
            'clinics' => $clinics,
            'total'   => count($clinics)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
 
if ($action === 'send_surgery_referral') {
    // ✅ Permission check - need create permission
    if (!canCreateDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: Cannot send referrals']);
        exit;
    }
    $data = json_decode(file_get_contents('php://input'), true);
 
    $targetClinicId  = (int)($data['clinic_id']       ?? 0);
    $clinicName      = $data['clinic_name']            ?? 'Unknown Clinic';
    $clinicEmail     = $data['clinic_email']           ?? '';
    $patientId       = (int)($data['patient_id']       ?? 0);
    $patientName     = $data['patient_name']           ?? 'Patient';
    $patientAge      = $data['patient_age']            ?? '?';
    $patientGender   = $data['patient_gender']         ?? '?';
    $appointmentId   = (int)($data['appointment_id']  ?? 0);
    $diagnosis       = $data['diagnosis']              ?? 'See clinical notes';
    $refNotes        = $data['notes']                  ?? '';
    $referringDoctor = $data['referring_doctor']       ?? 'Unknown Doctor';
 
    // Get referring clinic name
    $clinicRow = $pdo->query("SELECT name FROM clinics WHERE id = $clinicId")->fetch();
    $referringClinic = $clinicRow['name'] ?? 'Eyecore Clinic';
 
    $today      = date('F j, Y');
    $emailSent  = false;
    $errors     = [];
 
    try {
        $pdo->beginTransaction();
 
        // ── 1. Save referral record ──────────────────────────────
        // Reuse existing surgery_referrals table if it exists,
        // otherwise create a simple log in decision_support_logs
        try {
            $stmt = $pdo->prepare("
                INSERT INTO surgery_referrals
                    (clinic_id, patient_id, from_doctor, to_clinic,
                     surgery_type, priority, notes, created_at)
                VALUES (?, ?, ?, ?, ?, 'high', ?, NOW())
            ");
            $stmt->execute([
                $clinicId,
                $patientId,
                $userId,         // from_doctor = current logged-in user
                $targetClinicId,
                $diagnosis,
                $refNotes
            ]);
        } catch (\Throwable $e) {
            // Table might not exist yet — log to decision_support_logs instead
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO decision_support_logs
                        (clinic_id, patient_id, doctor_id, risk_level,
                         recommendations, surgery_recommended, referral_made, referral_to)
                    VALUES (?, ?, ?, 'high', ?, 1, 1, ?)
                ");
                $stmt->execute([
                    $clinicId,
                    $patientId,
                    $userId,
                    json_encode(['Referred to ' . $clinicName . ' for: ' . $diagnosis]),
                    $targetClinicId
                ]);
            } catch (\Throwable $e2) {
                // Silently continue — saving is nice-to-have
            }
        }
 
        // ── 2. In-app notification + get ClinicAdmin email ──────────
        // Query directly from users table: role = ClinicAdmin, clinic_id = target
        $ownerEmail = '';  // will be filled below if found
        try {
            $ownerStmt = $pdo->prepare("
                SELECT id, email, CONCAT(first_name, ' ', last_name) AS full_name
                FROM users
                WHERE clinic_id = ?
                  AND role = 'ClinicAdmin'
                  AND status = 'Active'
                ORDER BY id ASC
                LIMIT 1
            ");
            $ownerStmt->execute([$targetClinicId]);
            $owner = $ownerStmt->fetch(PDO::FETCH_ASSOC);
 
            if ($owner) {
                // In-app notification
                $notifStmt = $pdo->prepare("
                    INSERT INTO notifications
                        (user_id, title, message, type, reference_id, link, created_at)
                    VALUES (?, ?, ?, 'referral', ?, '#', NOW())
                ");
                $notifStmt->execute([
                    $owner['id'],
                    "🏥 Surgery Referral from {$referringClinic}",
                    "Dr. {$referringDoctor} referred patient {$patientName} "
                    . "({$patientAge} yrs / {$patientGender}) for: {$diagnosis}. "
                    . ($refNotes ? "Notes: {$refNotes}" : ''),
                    $appointmentId
                ]);
 
                // Save owner email so PHPMailer can use it
                $ownerEmail = $owner['email'] ?? '';
            }
        } catch (\Throwable $e) {
            $errors[] = 'Notification not sent: ' . $e->getMessage();
        }
 
        $pdo->commit();
 
    } catch (\Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
 
    // ── 3. Determine email recipients ───────────────────────────
    // Priority: ClinicAdmin email from users table (most reliable)
    // Fallback: clinic_email passed from the frontend (from clinics table)
    $emailRecipients = [];
 
    // Add ClinicAdmin email (from users table) — primary
    if (!empty($ownerEmail)) {
        $emailRecipients[] = ['email' => $ownerEmail, 'name' => $clinicName . ' (Admin)'];
    }
 
    // Add clinic_email (from clinics table) — only if different from ownerEmail
    if (!empty($clinicEmail) && $clinicEmail !== $ownerEmail) {
        $emailRecipients[] = ['email' => $clinicEmail, 'name' => $clinicName];
    }
 
    // ── 4. Send email via PHPMailer ──────────────────────────────
    if (!empty($emailRecipients)) {
        try {
            $mail = new PHPMailer(true);
 
            // SMTP config
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'angelloricanmendoza27@gmail.com';
            $mail->Password   = 'tkyv vypr pxvm pfse';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;
 
            // Sender
            $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Clinic System');
 
            // Add all recipients
            foreach ($emailRecipients as $recipient) {
                $mail->addAddress($recipient['email'], $recipient['name']);
            }
            $mail->addReplyTo('angelloricanmendoza27@gmail.com', 'Eyecore Clinic System');
 
            // Content
            $mail->isHTML(true);
            $mail->Subject = "Patient Referral – {$patientName} – {$diagnosis}";
 
            // ── HTML Email Body ──────────────────────────────────
            $mail->Body = "
<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<meta name='viewport' content='width=device-width,initial-scale=1'>
</head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;'>
 
  <table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:30px 0;'>
  <tr><td align='center'>
  <table width='580' cellpadding='0' cellspacing='0' style='background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.08);'>
 
    <!-- Header -->
    <tr>
      <td style='background:linear-gradient(135deg,#0d9488,#0f766e);padding:28px 36px;'>
        <table width='100%'><tr>
          <td>
            <div style='font-size:22px;font-weight:800;color:#fff;letter-spacing:-.3px;'>
              👁 Eyecore Clinic
            </div>
            <div style='font-size:12px;color:rgba(255,255,255,.75);margin-top:3px;'>
              Clinic Management System
            </div>
          </td>
          <td align='right'>
            <span style='background:rgba(255,255,255,.18);color:#fff;border-radius:20px;
                         padding:5px 14px;font-size:12px;font-weight:700;'>
              PATIENT REFERRAL
            </span>
          </td>
        </tr></table>
      </td>
    </tr>
 
    <!-- Body -->
    <tr><td style='padding:32px 36px;'>
 
      <p style='margin:0 0 20px;font-size:15px;color:#374151;'>
        Dear <strong>{$clinicName}</strong>,
      </p>
      <p style='margin:0 0 24px;font-size:14px;color:#6b7280;line-height:1.6;'>
        We are referring the following patient to your clinic for surgical evaluation
        and management. Please review the details below and schedule an appointment
        at your earliest convenience.
      </p>
 
      <!-- Patient Info Card -->
      <table width='100%' cellpadding='0' cellspacing='0'
             style='background:#f0fdfa;border:1px solid #99f6e4;border-radius:10px;
                    margin-bottom:24px;overflow:hidden;'>
        <tr>
          <td style='background:#0d9488;padding:10px 18px;'>
            <span style='color:#fff;font-size:11px;font-weight:700;
                         letter-spacing:.08em;text-transform:uppercase;'>
              Patient Information
            </span>
          </td>
        </tr>
        <tr>
          <td style='padding:18px;'>
            <table width='100%' cellpadding='6'>
              <tr>
                <td width='40%' style='font-size:12px;color:#6b7280;font-weight:700;
                                        text-transform:uppercase;letter-spacing:.05em;'>Patient Name</td>
                <td style='font-size:14px;color:#111827;font-weight:700;'>{$patientName}</td>
              </tr>
              <tr style='background:rgba(255,255,255,.5);'>
                <td style='font-size:12px;color:#6b7280;font-weight:700;
                             text-transform:uppercase;letter-spacing:.05em;'>Age / Gender</td>
                <td style='font-size:14px;color:#111827;'>{$patientAge} yrs / {$patientGender}</td>
              </tr>
              <tr>
                <td style='font-size:12px;color:#6b7280;font-weight:700;
                             text-transform:uppercase;letter-spacing:.05em;'>Diagnosis</td>
                <td style='font-size:14px;color:#dc2626;font-weight:700;'>{$diagnosis}</td>
              </tr>
              " . ($refNotes ? "
              <tr style='background:rgba(255,255,255,.5);'>
                <td style='font-size:12px;color:#6b7280;font-weight:700;
                             text-transform:uppercase;letter-spacing:.05em;'>Notes</td>
                <td style='font-size:13px;color:#374151;'>{$refNotes}</td>
              </tr>" : "") . "
            </table>
          </td>
        </tr>
      </table>
 
      <!-- Referring Clinic Card -->
      <table width='100%' cellpadding='0' cellspacing='0'
             style='background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;
                    margin-bottom:24px;overflow:hidden;'>
        <tr>
          <td style='background:#475569;padding:10px 18px;'>
            <span style='color:#fff;font-size:11px;font-weight:700;
                         letter-spacing:.08em;text-transform:uppercase;'>
              Referring Details
            </span>
          </td>
        </tr>
        <tr>
          <td style='padding:18px;'>
            <table width='100%' cellpadding='6'>
              <tr>
                <td width='40%' style='font-size:12px;color:#6b7280;font-weight:700;
                                        text-transform:uppercase;letter-spacing:.05em;'>Referring Doctor</td>
                <td style='font-size:14px;color:#111827;font-weight:700;'>Dr. {$referringDoctor}</td>
              </tr>
              <tr style='background:rgba(255,255,255,.8);'>
                <td style='font-size:12px;color:#6b7280;font-weight:700;
                             text-transform:uppercase;letter-spacing:.05em;'>From Clinic</td>
                <td style='font-size:14px;color:#111827;'>{$referringClinic}</td>
              </tr>
              <tr>
                <td style='font-size:12px;color:#6b7280;font-weight:700;
                             text-transform:uppercase;letter-spacing:.05em;'>Date</td>
                <td style='font-size:14px;color:#111827;'>{$today}</td>
              </tr>
            </table>
          </td>
        </tr>
      </table>
 
      <p style='margin:0 0 8px;font-size:13px;color:#6b7280;line-height:1.6;'>
        This referral was sent through the <strong>Eyecore Clinic Management System</strong>.
        You may also view this referral in your clinic dashboard under notifications.
      </p>
      <p style='margin:0;font-size:13px;color:#6b7280;'>
        Thank you for your cooperation and for providing the best care for our patients.
      </p>
 
    </td></tr>
 
    <!-- Footer -->
    <tr>
      <td style='background:#f8fafc;border-top:1px solid #e2e8f0;
                  padding:18px 36px;text-align:center;'>
        <p style='margin:0;font-size:11px;color:#9ca3af;'>
          This is an automated email from the Eyecore Clinic System.
          Please do not reply directly to this email.
        </p>
        <p style='margin:4px 0 0;font-size:11px;color:#9ca3af;'>
          © " . date('Y') . " Eyecore · Clinic Management System
        </p>
      </td>
    </tr>
 
  </table>
  </td></tr>
  </table>
 
</body>
</html>";
 
            // Plain text fallback
            $mail->AltBody = "PATIENT REFERRAL - Eyecore Clinic\n\n"
                . "To: {$clinicName}\n"
                . "From: Dr. {$referringDoctor} ({$referringClinic})\n"
                . "Date: {$today}\n\n"
                . "Patient: {$patientName}\n"
                . "Age/Gender: {$patientAge} yrs / {$patientGender}\n"
                . "Diagnosis: {$diagnosis}\n"
                . ($refNotes ? "Notes: {$refNotes}\n" : '')
                . "\nKindly schedule this patient at your earliest convenience.\n"
                . "Thank you.";
 
            $mail->send();
            $emailSent = true;
 
        } catch (Exception $e) {
            $errors[] = 'Email error: ' . $mail->ErrorInfo;
        }
    } else {
        // No recipients found at all
        $errors[] = 'No email address found — '
            . 'no ClinicAdmin email in users table and no clinic_email on record.';
    }
 
    // Build a friendly summary of who was notified
    $notifiedEmails = array_column($emailRecipients, 'email');
 
    echo json_encode([
        'success'          => true,
        'email_sent'       => $emailSent,
        'notified_emails'  => $notifiedEmails,   // for debugging / logging on frontend
        'message'          => $emailSent
            ? 'Referral sent. Email delivered to: ' . implode(', ', $notifiedEmails)
            : 'Referral saved but email not sent. '
              . (count($errors) ? '(' . implode('; ', $errors) . ')' : ''),
        'errors'           => $errors
    ]);
    exit;
}

if ($action === 'get_prescriptions') {
    // ✅ No permission check - view is already checked at top
    $patientId = (int)($_GET['patient_id'] ?? 0);
    
    if (!$patientId) {
        echo json_encode(['success' => false, 'message' => 'Missing patient_id']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.prescription_date,
                p.prescription_type,
                p.expiry_date,
 
                -- Right Eye (OD)
                p.sph_r,
                p.cyl_r,
                p.axis_r,
                p.add_r,
                p.prism_r,
                p.base_r,
 
                -- Left Eye (OS)
                p.sph_l,
                p.cyl_l,
                p.axis_l,
                p.add_l,
                p.prism_l,
                p.base_l,
 
                -- PD & Notes
                p.pd,
                p.pd_type,
                p.notes,
                p.created_at,
 
                -- Doctor name
                d.name AS doctor_name,
 
                -- Formatted date
                DATE_FORMAT(p.prescription_date, '%M %d, %Y') AS formatted_date
 
            FROM prescriptions p
            LEFT JOIN doctors d ON p.doctor_id = d.id
 
            WHERE p.patient_id  = ?
              AND p.clinic_id   = ?
 
            ORDER BY p.prescription_date DESC, p.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$patientId, $clinicId]);
        $prescriptions = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
        echo json_encode([
            'success'       => true,
            'prescriptions' => $prescriptions,
            'total'         => count($prescriptions)
        ]);
 
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
 
 
if ($action === 'get_appointments') {
    // ✅ No permission check - view is already checked at top
    $patientId = (int)($_GET['patient_id'] ?? 0);
    
    if (!$patientId) {
        echo json_encode(['success' => false, 'message' => 'Missing patient_id']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.ref_no,
                a.appointment_date,
                a.appointment_time,
                a.service_type,
                a.appointment_type,
                a.status,
                a.payment_status,
                a.payment_type,
                a.total_amount,
                a.downpayment_amount,
                a.balance_amount,
                a.notes,
                a.contact_number,
                a.cancellation_reason,
                a.created_at,
 
                -- Product / Service name
                COALESCE(pr.name, a.service_type, 'Check-up') AS product_name,
 
                -- Doctor name (from doctors table)
                d.name AS doctor_name,
 
                -- Formatted date
                DATE_FORMAT(a.appointment_date, '%M %d, %Y') AS formatted_date
 
            FROM appointments a
            LEFT JOIN products pr ON a.product_id  = pr.id
            LEFT JOIN doctors  d  ON a.doctor_id   = d.id
 
            WHERE a.patient_id = ?
              AND a.clinic_id  = ?
 
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
            LIMIT 30
        ");
        $stmt->execute([$patientId, $clinicId]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
        // Normalize status labels for the frontend badge
        $statusMap = [
            'paid'        => 'completed',
            'confirmed'   => 'waiting',
            'in_progress' => 'in_progress',
            'completed'   => 'completed',
            'cancelled'   => 'cancelled',
            'pending'     => 'waiting',
            'no_show'     => 'cancelled',
        ];
 
        foreach ($appointments as &$appt) {
            // Map raw DB status → frontend status key
            $rawStatus = strtolower($appt['status'] ?? '');
            $appt['status_normalized'] = $statusMap[$rawStatus] ?? $rawStatus;
 
            // Format time nicely: 13:30:00 → 1:30 PM
            if (!empty($appt['appointment_time'])) {
                $appt['appointment_time_formatted'] = date(
                    'g:i A',
                    strtotime($appt['appointment_time'])
                );
            } else {
                $appt['appointment_time_formatted'] = '—';
            }
 
            // Format currency
            if ($appt['total_amount'] !== null) {
                $appt['total_amount_formatted'] = '₱ ' . number_format($appt['total_amount'], 2);
            }
        }
        unset($appt);
 
        echo json_encode([
            'success'      => true,
            'appointments' => $appointments,
            'total'        => count($appointments)
        ]);
 
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'complete_consultation') {
    if (!canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    $patientId = (int)($data['patient_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        // ✅ STEP 1: Get appointment details with price
        $stmt = $pdo->prepare("
            SELECT a.*, 
                   COALESCE(pr.name, srv.name, a.service_type, 'Consultation') as item_name,
                   COALESCE(pr.price, srv.price, 0) as item_price,
                   a.patient_id,
                   a.user_id,
                   a.product_id,
                   a.item_id,
                   a.item_type,
                   a.total_amount as appointment_total,
                   a.downpayment_amount,
                   a.balance_amount,
                   a.payment_type as appointment_payment_type
            FROM appointments a
            LEFT JOIN products pr ON a.product_id = pr.id
            LEFT JOIN services srv ON a.item_id = srv.id AND a.item_type = 'service'
            WHERE a.id = ? AND a.clinic_id = ?
        ");
        $stmt->execute([$appointmentId, $clinicId]);
        $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$appointment) {
            throw new Exception('Appointment not found');
        }
        
        // ✅ STEP 2: GET EXISTING ONLINE PAYMENTS FROM payments TABLE
        $paymentsStmt = $pdo->prepare("
            SELECT id, amount, payment_method, payment_type, payment_status, 
                   reference_number, created_at
            FROM payments 
            WHERE appointment_id = ? AND payment_status = 'paid'
            ORDER BY created_at ASC
        ");
        $paymentsStmt->execute([$appointmentId]);
        $existingPayments = $paymentsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $totalPaidOnline = 0;
        $downpaymentPaid = 0;
        $isFullyPaidOnline = false;
        
        foreach ($existingPayments as $payment) {
            $totalPaidOnline += floatval($payment['amount']);
            if ($payment['payment_type'] === 'downpayment' || strpos($payment['payment_type'], 'down') !== false) {
                $downpaymentPaid += floatval($payment['amount']);
            }
        }
        
        // ── Determine patient ID for sales ─────────────────────────────
        $finalPatientId = null;
        $walkInName = null;
        $walkInContact = null;
        $walkInEmail = null;
        
        if (!empty($appointment['patient_id'])) {
            $finalPatientId = (int)$appointment['patient_id'];
        } elseif (!empty($appointment['user_id'])) {
            // Check if patient exists
            $checkStmt = $pdo->prepare("SELECT id FROM patients WHERE clinic_id = ? AND user_id = ? LIMIT 1");
            $checkStmt->execute([$clinicId, $appointment['user_id']]);
            $existingPatient = $checkStmt->fetch();
            
            if ($existingPatient) {
                $finalPatientId = (int)$existingPatient['id'];
            } else {
                // Create patient from user
                $userStmt = $pdo->prepare("SELECT first_name, last_name, contact, email, address FROM users WHERE id = ?");
                $userStmt->execute([$appointment['user_id']]);
                $user = $userStmt->fetch();
                
                if ($user) {
                    $patientCode = 'PAT' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
                    $insertStmt = $pdo->prepare("
                        INSERT INTO patients 
                            (clinic_id, user_id, patient_code, first_name, last_name, 
                             phone, email, address, patient_type, status, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Regular', 'Active', NOW())
                    ");
                    $insertStmt->execute([
                        $clinicId, $appointment['user_id'], $patientCode,
                        $user['first_name'], $user['last_name'],
                        $user['contact'] ?? null, $user['email'] ?? null, $user['address'] ?? null
                    ]);
                    $finalPatientId = (int)$pdo->lastInsertId();
                    
                    // Update appointment with patient_id
                    $pdo->prepare("UPDATE appointments SET patient_id = ? WHERE id = ?")
                        ->execute([$finalPatientId, $appointmentId]);
                }
            }
        }
        
        if (!$finalPatientId) {
            // Walk-in patient
            if (!empty($appointment['user_id'])) {
                $userStmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as name, contact, email FROM users WHERE id = ?");
                $userStmt->execute([$appointment['user_id']]);
                $user = $userStmt->fetch();
                $walkInName = $user['name'] ?? 'Walk-in Patient';
                $walkInContact = $user['contact'] ?? null;
                $walkInEmail = $user['email'] ?? null;
            } else {
                $walkInName = 'Walk-in Patient';
            }
        }
        
        // ── Calculate total amount ─────────────────────────────────────
        $totalAmount = floatval($appointment['item_price']);
        if ($totalAmount <= 0) {
            $totalAmount = floatval($appointment['appointment_total'] ?? 500.00);
        }
        if ($totalAmount <= 0) {
            $totalAmount = 500.00; // Default consultation fee
        }
        
        // ✅ STEP 3: Calculate remaining balance after online payments
        $remainingBalance = $totalAmount - $totalPaidOnline;
        $isFullyPaid = $remainingBalance <= 0;
        
        // ── Build items array ──────────────────────────────────────────
        $resolvedItemId = $appointment['product_id'] ?? $appointment['item_id'] ?? null;
        $resolvedItemType = !empty($appointment['product_id']) ? 'product'
                          : ($appointment['item_type'] ?? 'service');
        $resolvedItemName = $appointment['item_name'] ?? $appointment['service_type'] ?? 'Consultation';
        
        $items = [[
            'id' => $resolvedItemId,
            'name' => $resolvedItemName,
            'price' => $totalAmount,
            'type' => $resolvedItemType,
            'quantity' => 1,
        ]];
        
        // ── Update appointment to appropriate status ────────────────────
        if ($isFullyPaid) {
            // ✅ FULLY PAID ONLINE - COMPLETED AGAD
            $appointmentStatus = 'completed';
            $paymentStatus = 'paid';
            $saleStatus = 'Paid';
            $saleAmountPaid = $totalAmount;
        } else if ($totalPaidOnline > 0) {
            // May downpayment/partial payment online - waiting for balance
            $appointmentStatus = 'waiting_payment';
            $paymentStatus = 'partial';
            $saleStatus = 'Partial';
            $saleAmountPaid = $totalPaidOnline;
        } else {
            // No payment yet - waiting for payment
            $appointmentStatus = 'waiting_payment';
            $paymentStatus = 'pending';
            $saleStatus = 'pending_payment';
            $saleAmountPaid = 0;
        }
        
        // Update appointment status
        $pdo->prepare("
            UPDATE appointments SET 
                status = ?,
                payment_status = ?,
                consultation_completed_at = NOW(),
                total_amount = ?,
                amount_paid = ?,
                updated_at = NOW() 
            WHERE id = ? AND clinic_id = ?
        ")->execute([
            $appointmentStatus, 
            $paymentStatus, 
            $totalAmount, 
            $totalPaidOnline,
            $appointmentId, 
            $clinicId
        ]);
        
        // ── Create bill in sales table with existing payment info ────────
        $billNumber = 'BILL-' . date('Ymd') . '-' . str_pad($appointmentId, 5, '0', STR_PAD_LEFT);
        $itemsJson = json_encode($items);
        
        if ($finalPatientId) {
            $stmt = $pdo->prepare("
                INSERT INTO sales 
                    (clinic_id, sale_date, patient_id, appointment_id, items, 
                     subtotal, total_amount, amount_paid, status, created_by, created_at)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $clinicId,
                $finalPatientId,
                $appointmentId,
                $itemsJson,
                $totalAmount,
                $totalAmount,
                $saleAmountPaid,
                $saleStatus,
                $userId
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO sales 
                    (clinic_id, sale_date, walk_in_name, walk_in_contact, walk_in_email, appointment_id,
                     items, subtotal, total_amount, amount_paid, status, created_by, created_at)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $clinicId,
                $walkInName,
                $walkInContact,
                $walkInEmail,
                $appointmentId,
                $itemsJson,
                $totalAmount,
                $totalAmount,
                $saleAmountPaid,
                $saleStatus,
                $userId
            ]);
        }
        
        $saleId = (int)$pdo->lastInsertId();
        
        // ── Insert sale items ──────────────────────────────────────────
        foreach ($items as $item) {
            $itemId = $item['id'];
            $itemType = $item['type'];
            
            $pdo->prepare("
                INSERT INTO sale_items 
                    (sale_id, clinic_id, item_id, item_type, item_name, quantity, unit_price, total_price)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([
                $saleId,
                $clinicId,
                $itemId,
                $itemType,
                $item['name'],
                $item['quantity'],
                $item['price'],
                $item['price'] * $item['quantity']
            ]);
        }
        
        // ✅ STEP 4: If fully paid online, update inventory stock
        if ($isFullyPaid) {
            foreach ($items as $item) {
                if ($item['type'] === 'product' && $item['id']) {
                    $updateStock = $pdo->prepare("
                        UPDATE inventory 
                        SET stock = stock - 1,
                            updated_at = NOW()
                        WHERE id = ? AND clinic_id = ? AND stock > 0
                    ");
                    $updateStock->execute([$item['id'], $clinicId]);
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'sale_id' => $saleId,
            'bill_number' => $billNumber,
            'total_amount' => $totalAmount,
            'amount_paid' => $saleAmountPaid,
            'remaining_balance' => $remainingBalance,
            'status' => $saleStatus,
            'appointment_status' => $appointmentStatus,
            'fully_paid' => $isFullyPaid,
            'online_payments' => [
                'total' => $totalPaidOnline,
                'count' => count($existingPayments),
                'payments' => $existingPayments
            ],
            'message' => $isFullyPaid 
                ? 'Consultation completed. Appointment is FULLY PAID and COMPLETED.' 
                : ($totalPaidOnline > 0 
                    ? 'Consultation completed. Bill created with existing payment. Remaining balance: ₱' . number_format($remainingBalance, 2)
                    : 'Consultation completed. Bill created. Waiting for payment.')
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('complete_consultation error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============= START CONSULTATION =============
if ($action === 'start_consultation') {
    if (!canEditDashboard()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit;
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    $appointmentId = (int)($data['appointment_id'] ?? 0);
    
    if (!$appointmentId) {
        echo json_encode(['success' => false, 'message' => 'Missing appointment ID']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $pdo->prepare("
            UPDATE appointments SET 
                status = 'in_progress', 
                consultation_started_at = NOW(),
                updated_at = NOW() 
            WHERE id = ? AND clinic_id = ? AND status = 'arrived'
        ")->execute([$appointmentId, $clinicId]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Consultation started']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}


echo json_encode(['success' => false, 'message' => 'Invalid action']);
?>