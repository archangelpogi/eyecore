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

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$clinic_id = $_SESSION['clinic_id'];
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'User';
$user_name = $_SESSION['name'] ?? 'Unknown User';

// ✅ RBAC Permission Helper Class for Decision Support
class DecisionSupportPermission {
    private static $module = 'decision-support';
    
    public static function can($action) {
        $permissionMap = [
            'view' => self::$module . '_view',
            'create' => self::$module . '_create',
            'edit' => self::$module . '_edit',
            'delete' => self::$module . '_delete',
            'approve' => self::$module . '_approve',
            'reject' => self::$module . '_reject'
        ];
        
        $permission = $permissionMap[$action] ?? self::$module . '_' . $action;
        return RBACHelper::hasPermission($permission);
    }
    
    public static function check($action, $exitOnFail = true) {
        if (!self::can($action)) {
            if ($exitOnFail) {
                http_response_code(403);
                echo json_encode(['error' => 'Permission denied: Cannot ' . $action . ' decision support data']);
                exit;
            }
            return false;
        }
        return true;
    }
}

// ✅ Additional check for optometrist role (for backward compatibility)
$isOptometrist = ($user_role === 'Optometrist' || $user_role === 'ClinicAdmin');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ================== GET REAL STATS ==================
function getDashboardStats($pdo, $clinicId, $userId) {
    $stats = [];
    
    // Total patients analyzed (patients with exam_results)
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT patient_id) as count 
        FROM exam_results 
        WHERE clinic_id = ?
    ");
    $stmt->execute([$clinicId]);
    $stats['total_analyzed'] = (int)$stmt->fetchColumn();
    
    // Surgery recommendations (from exam_results with diagnosis containing surgery-related terms)
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
    
    // Total referrals made
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as count 
        FROM surgery_referrals 
        WHERE clinic_id = ? AND from_doctor = ?
    ");
    $stmt->execute([$clinicId, $userId]);
    $stats['referrals'] = (int)$stmt->fetchColumn();
    
    // High risk cases (age >= 60 OR specific conditions)
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

// ================== GET PATIENTS ==================
if ($action === 'get_patients') {
    // ✅ Check view permission
    DecisionSupportPermission::check('view');
    
    try {
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
        $stmt->execute([$clinic_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ================== GET SURGICAL CENTERS ==================
if ($action === 'get_surgical_centers') {
    // ✅ Check view permission
    DecisionSupportPermission::check('view');
    
    try {
        $stmt = $pdo->prepare("
            SELECT id, clinic_name, clinic_type, city, offers_eye_surgery
            FROM clinics 
            WHERE status = 'Active'
            ORDER BY offers_eye_surgery DESC, clinic_name
        ");
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ================== GET PATIENT DATA ==================
if ($action === 'get_patient_data') {
    // ✅ Check view permission
    DecisionSupportPermission::check('view');
    
    try {
        $patientId = $_GET['patient_id'] ?? 0;
        
        // Get patient info
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$patientId, $clinic_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get latest exam results
        $stmt = $pdo->prepare("
            SELECT * FROM exam_results 
            WHERE patient_id = ? 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$patientId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get latest prescription
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
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ================== ANALYZE PATIENT ==================
if ($action === 'analyze') {
    // ✅ Check create permission (analyzing is a create action)
    DecisionSupportPermission::check('create');
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $patientId = $data['patient_id'] ?? 0;
        
        // Get patient data for analysis
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$patientId, $clinic_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get latest exam
        $stmt = $pdo->prepare("SELECT * FROM exam_results WHERE patient_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$patientId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get latest prescription for high myopia check
        $stmt = $pdo->prepare("SELECT sph_l, sph_r FROM prescriptions WHERE patient_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$patientId]);
        $rx = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // AI LOGIC - Based on REAL patient data
        $riskLevel = 'low';
        $riskMessage = 'Patient appears to be low risk. Regular monitoring recommended.';
        $surgeryRecommended = false;
        $surgeryType = '';
        $surgeryReason = '';
        
        $diagnoses = [];
        $treatments = [];
        
        // Check from exam results first
        if ($exam) {
            // Parse diagnosis from exam
            $diagnosis = strtolower($exam['diagnosis'] ?? '');
            
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
        
        // Check age-based risks
        if ($patient && $patient['age'] >= 60 && empty($diagnoses)) {
            $riskLevel = 'medium';
            $riskMessage = 'Senior patient. Increased risk for age-related eye conditions.';
            $diagnoses[] = [
                'name' => 'Age-related risk',
                'reason' => 'Patient age ≥ 60 years',
                'confidence' => 'medium'
            ];
        }
        
        // Check high myopia from prescription
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
        
        // Default treatments based on risk
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
        
        // If no diagnoses found
        if (empty($diagnoses)) {
            $diagnoses[] = [
                'name' => 'Normal Examination',
                'reason' => 'No significant findings detected',
                'confidence' => 'low'
            ];
        }
        
        // Log the analysis
        $stmt = $pdo->prepare("
            INSERT INTO decision_support_logs 
            (clinic_id, patient_id, doctor_id, risk_level, recommendations, surgery_recommended) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $clinic_id,
            $patientId,
            $user_id,
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
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Analysis failed: ' . $e->getMessage()]);
    }
    exit;
}

// ================== NEARBY CLINICS ==================
if ($action === 'nearby_clinics') {
    // ✅ Check view permission
    DecisionSupportPermission::check('view');
    
    try {
        $city = $_GET['city'] ?? '';
        
        $sql = "SELECT id, clinic_name, city, clinic_type FROM clinics WHERE offers_eye_surgery = 1 AND status = 'Active'";
        $params = [];
        
        if (!empty($city)) {
            // Extract city from address (simplified)
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
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ================== REFER PATIENT ==================
if ($action === 'refer') {
    // ✅ Check create permission (referring is a create action)
    DecisionSupportPermission::check('create');
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (empty($data['patient_id']) || empty($data['clinic_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Patient ID and Clinic ID are required']);
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO surgery_referrals 
            (clinic_id, patient_id, from_doctor, to_clinic, surgery_type, priority, notes) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $success = $stmt->execute([
            $clinic_id,
            $data['patient_id'],
            $user_id,
            $data['clinic_id'],
            $data['surgery_type'] ?? 'General',
            $data['priority'] ?? 'Routine',
            $data['notes'] ?? ''
        ]);
        
        if ($success) {
            // Update log if exists
            $stmt = $pdo->prepare("
                UPDATE decision_support_logs 
                SET referral_made = TRUE, referral_to = ? 
                WHERE patient_id = ? AND clinic_id = ?
                ORDER BY id DESC LIMIT 1
            ");
            $stmt->execute([$data['clinic_id'], $data['patient_id'], $clinic_id]);
            
            echo json_encode(['success' => true, 'message' => 'Referral sent successfully']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to create referral']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Referral failed: ' . $e->getMessage()]);
    }
    exit;
}

// ================== GET DASHBOARD STATS ==================
if ($action === 'get_stats') {
    // ✅ Check view permission
    DecisionSupportPermission::check('view');
    
    try {
        echo json_encode(getDashboardStats($pdo, $clinic_id, $user_id));
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ================== GET ANALYSIS HISTORY ==================
if ($action === 'get_history') {
    // ✅ Check view permission
    DecisionSupportPermission::check('view');
    
    try {
        $patientId = $_GET['patient_id'] ?? 0;
        $limit = (int)($_GET['limit'] ?? 20);
        
        $stmt = $pdo->prepare("
            SELECT l.*, 
                   CONCAT(u.first_name, ' ', u.last_name) as doctor_name
            FROM decision_support_logs l
            LEFT JOIN users u ON l.doctor_id = u.id
            WHERE l.clinic_id = ? 
            " . ($patientId ? "AND l.patient_id = ?" : "") . "
            ORDER BY l.created_at DESC
            LIMIT ?
        ");
        
        if ($patientId) {
            $stmt->execute([$clinic_id, $patientId, $limit]);
        } else {
            $stmt->execute([$clinic_id, $limit]);
        }
        
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Parse JSON recommendations
        foreach ($history as &$h) {
            $h['recommendations'] = json_decode($h['recommendations'] ?? '[]', true);
        }
        
        echo json_encode(['success' => true, 'data' => $history]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ================== GET REFERRALS ==================
if ($action === 'get_referrals') {
    // ✅ Check view permission
    DecisionSupportPermission::check('view');
    
    try {
        $stmt = $pdo->prepare("
            SELECT r.*,
                   CONCAT(p.first_name, ' ', p.last_name) as patient_name,
                   c.clinic_name as to_clinic_name,
                   CONCAT(d.first_name, ' ', d.last_name) as doctor_name
            FROM surgery_referrals r
            LEFT JOIN patients p ON r.patient_id = p.id
            LEFT JOIN clinics c ON r.to_clinic = c.id
            LEFT JOIN users d ON r.from_doctor = d.id
            WHERE r.clinic_id = ?
            ORDER BY r.created_at DESC
        ");
        $stmt->execute([$clinic_id]);
        
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ================== GET PERMISSIONS ==================
if ($action === 'get_permissions') {
    echo json_encode([
        'success' => true,
        'data' => [
            'role' => $user_role,
            'permissions' => [
                'can_view' => DecisionSupportPermission::can('view'),
                'can_create' => DecisionSupportPermission::can('create'),
                'can_edit' => DecisionSupportPermission::can('edit'),
                'can_delete' => DecisionSupportPermission::can('delete'),
                'can_approve' => DecisionSupportPermission::can('approve'),
                'can_reject' => DecisionSupportPermission::can('reject')
            ]
        ]
    ]);
    exit;
}

// Default response for invalid actions
http_response_code(400);
echo json_encode(['error' => 'Invalid action: ' . $action]);
exit;