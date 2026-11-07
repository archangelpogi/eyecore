<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ PHPMailer setup
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

$baseDir = dirname(__DIR__);
require $baseDir . '/PHPMailer/PHPMailer.php';
require $baseDir . '/PHPMailer/SMTP.php';
require $baseDir . '/PHPMailer/Exception.php';

RBACHelper::init($pdo);

if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$clinic_id = $_SESSION['clinic_id'];
$user_id = $_SESSION['user_id'];

class DecisionSupportPermission {
    private static $module = 'decision-support';
    public static function can($action) {
        $map = ['view' => self::$module.'_view', 'create' => self::$module.'_create'];
        return RBACHelper::hasPermission($map[$action] ?? '');
    }
    public static function check($action) {
        if (!self::can($action)) {
            http_response_code(403);
            echo json_encode(['error' => 'Permission denied']);
            exit;
        }
    }
}

function makeMailer(): PHPMailer {
    $m = new PHPMailer(true);
    $m->isSMTP();
    $m->Host       = 'smtp.gmail.com';
    $m->SMTPAuth   = true;
    $m->Username   = 'angelloricanmendoza27@gmail.com';
    $m->Password   = 'tkyv vypr pxvm pfse';
    $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $m->Port       = 587;
    $m->CharSet    = 'UTF-8';
    return $m;
}

$action = $_GET['action'] ?? '';

function getDashboardStats($pdo, $clinicId) {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM decision_support_logs WHERE clinic_id = ?");
    $stmt->execute([$clinicId]);
    $stats['total_analyzed'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT patient_id) FROM decision_support_logs WHERE clinic_id = ? AND surgery_recommended = 1");
    $stmt->execute([$clinicId]);
    $stats['surgery_recs'] = (int)$stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM decision_support_logs WHERE clinic_id = ? AND surgery_recommended = 0");
    $stmt->execute([$clinicId]);
    $stats['referrals'] = (int)$stmt->fetchColumn();
    return $stats;
}

// ================== GET PATIENTS ==================
if ($action === 'get_patients') {
    DecisionSupportPermission::check('view');
    $stmt = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) as name, patient_code, age, gender FROM patients WHERE clinic_id = ? AND status = 'Active' ORDER BY first_name");
    $stmt->execute([$clinic_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ================== GET PATIENT DATA ==================
if ($action === 'get_patient_data') {
    DecisionSupportPermission::check('view');
    $patientId = $_GET['patient_id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
    $stmt->execute([$patientId, $clinic_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) { echo json_encode(['success' => false]); exit; }
    
    $userEmail = '';
    if (!empty($patient['user_id'])) {
        $stmtUser = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmtUser->execute([$patient['user_id']]);
        $userEmail = $stmtUser->fetchColumn() ?: '';
    }
    
    $stmtOpt = $pdo->prepare("SELECT * FROM optical_records WHERE patient_id = ? AND clinic_id = ? ORDER BY examination_date DESC LIMIT 1");
    $stmtOpt->execute([$patientId, $clinic_id]);
    
    $stmtRx = $pdo->prepare("SELECT * FROM prescriptions WHERE patient_id = ? AND clinic_id = ? ORDER BY prescription_date DESC LIMIT 1");
    $stmtRx->execute([$patientId, $clinic_id]);
    
    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'user_email' => $userEmail,
        'optical' => $stmtOpt->fetch(PDO::FETCH_ASSOC),
        'rx' => $stmtRx->fetch(PDO::FETCH_ASSOC)
    ]);
    exit;
}

// ================== ANALYZE PATIENT ==================
if ($action === 'analyze') {
    DecisionSupportPermission::check('create');
    $data = json_decode(file_get_contents('php://input'), true);
    $patientId = $data['patient_id'] ?? 0;
    
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
    $stmt->execute([$patientId, $clinic_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stmtOpt = $pdo->prepare("SELECT * FROM optical_records WHERE patient_id = ? AND clinic_id = ? ORDER BY examination_date DESC LIMIT 1");
    $stmtOpt->execute([$patientId, $clinic_id]);
    $optical = $stmtOpt->fetch(PDO::FETCH_ASSOC);
    
    $isEligible = false;
    $discountMsg = "";
    $discountSteps = [];
    $opticalFindings = [];
    $opticalSteps = [];
    
    $age = (int)$patient['age'];
    $type = $patient['patient_type'];
    
    // ✅ ENGLISH TRANSLATIONS PARA SA DISCOUNT STEPS
    if ($age >= 60 || $type === 'Senior') {
        $isEligible = true;
        $discountMsg = "Eligible for Senior Citizen Discount (20% & VAT Exempt).";
        $discountSteps = [
            "Remind patient to present their OSCA ID.",
            "Deduct 20% from service and VAT exempt.",
            "<strong>How to apply for an OSCA ID:</strong>",
            "1. Go to the OSCA Office in your City/Municipality.",
            "2. Bring a Birth Certificate or valid ID (proof of age 60+).",
            "3. Bring 2x2 or 1x1 ID pictures."
        ];
    } elseif ($type === 'PWD') {
        $isEligible = true;
        $discountMsg = "Eligible for PWD Discount (20% & VAT Exempt).";
        $discountSteps = [
            "Remind patient to present their PWD ID.",
            "Deduct 20% from service and VAT exempt.",
            "<strong>How to apply for a PWD ID:</strong>",
            "1. Get a Medical Certificate from your doctor.",
            "2. Go to the MSWD (Municipal Social Welfare and Development).",
            "3. Bring a valid ID, Med Cert, and 1x1/2x2 ID pictures."
        ];
    } else {
        $discountMsg = "Not eligible for automatic discount.";
        $discountSteps = ["Proceed with regular pricing."];
    }
    
    // OPTICAL ANALYSIS
    if ($optical) {
        $od_sph = floatval($optical['od_sph'] ?? 0);
        $os_sph = floatval($optical['os_sph'] ?? 0);
        $od_cyl = abs(floatval($optical['od_cyl'] ?? 0));
        $os_cyl = abs(floatval($optical['os_cyl'] ?? 0));
        $od_add = floatval($optical['od_add'] ?? 0);
        $os_add = floatval($optical['os_add'] ?? 0);
        
        if ($od_sph <= -6.00 || $os_sph <= -6.00) {
            $opticalFindings[] = "High Myopia detected (SPH ≤ -6.00).";
            $opticalSteps[] = "Recommend High-Index lenses (1.67 or 1.74) to keep lenses thin.";
            $opticalSteps[] = "Advise patient about retinal risk and regular checkups.";
        }
        if ($od_cyl >= 1.00 || $os_cyl >= 1.00) {
            $opticalFindings[] = "Significant Astigmatism detected (CYL ≥ 1.00).";
            $opticalSteps[] = "Recommend Anti-Reflective (AR) coating to reduce glare.";
            $opticalSteps[] = "Suggest a smaller frame shape for better optics.";
        }
        if ($od_add > 0 || $os_add > 0 || $age >= 40) {
            $opticalFindings[] = "Presbyopia / Reading vision need detected.";
            $opticalSteps[] = "Recommend Progressive or Bifocal lenses.";
            $opticalSteps[] = "Inform patient about adaptation period.";
        }
        if (empty($opticalFindings) && ($od_sph < 0 || $os_sph < 0)) {
            $opticalFindings[] = "Mild Myopia detected.";
            $opticalSteps[] = "Recommend Blue Light Filter (Anti-Radiation) coating.";
            $opticalSteps[] = "Advise the 20-20-20 rule for screen time.";
        }
    } else {
        $opticalFindings[] = "No recent Optical Record found.";
        $opticalSteps[] = "Advise patient to undergo a comprehensive eye exam.";
    }
    
    $stmt = $pdo->prepare("INSERT INTO decision_support_logs (clinic_id, patient_id, doctor_id, risk_level, recommendations, surgery_recommended) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $clinic_id, $patientId, $user_id, 
        $isEligible ? 'low' : 'high', 
        json_encode(['discount' => $discountMsg, 'optical' => $opticalFindings]),
        $isEligible ? 1 : 0 
    ]);
    
    echo json_encode([
        'success' => true,
        'is_eligible' => $isEligible,
        'discount_message' => $discountMsg,
        'discount_steps' => $discountSteps,
        'optical_findings' => $opticalFindings,
        'optical_steps' => $opticalSteps
    ]);
    exit;
}

// ================== SEND EMAIL ==================
if ($action === 'send_email') {
    DecisionSupportPermission::check('view');
    
    try {
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $patientId = $data['patient_id'] ?? 0;
        
        $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$patientId, $clinic_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $email = '';
        if (!empty($patient['user_id'])) {
            $stmtUser = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmtUser->execute([$patient['user_id']]);
            $email = $stmtUser->fetchColumn();
        }
        
        if (!$email) {
            echo json_encode(['success' => false, 'message' => 'Patient has no registered email.']);
            exit;
        }
        
        $stmtClinic = $pdo->prepare("SELECT clinic_name FROM clinics WHERE id = ?");
        $stmtClinic->execute([$clinic_id]);
        $clinicName = $stmtClinic->fetchColumn() ?: 'Our Clinic';
        
        $patientName = $patient['first_name'] . ' ' . $patient['last_name'];
        $isEligible = $data['is_eligible'] ?? false;
        $discountMsg = $data['discount_message'] ?? '';
        $discountSteps = $data['discount_steps'] ?? [];
        $opticalFindings = $data['optical_findings'] ?? [];
        $opticalSteps = $data['optical_steps'] ?? [];

        // BUILD DISCOUNT HTML
        $discountHtml = '';
        if ($isEligible) {
            $stepsHtml = '';
            foreach ($discountSteps as $step) {
                if (strpos($step, '<strong>') !== false) {
                    $stepsHtml .= "<div style='margin-top: 12px; margin-bottom: 6px; font-weight: bold; color: #059669;'>{$step}</div>";
                } else {
                    $stepsHtml .= "<li style='margin-bottom: 4px;'>{$step}</li>";
                }
            }
            $discountHtml = "<div style='background:#f0fdf4; padding:16px; border-left:4px solid #10b981; border-radius:6px; margin-bottom: 24px;'><h4 style='color:#065f46; margin-top:0;'>🎉 Eligible for Discount</h4><p style='color:#065f46;'>{$discountMsg}</p><div style='background:white; padding:12px; border-radius:4px;'><strong>Action Plan:</strong><ul style='margin-top: 8px; padding-left: 20px; color:#4b5563;'>{$stepsHtml}</ul></div></div>";
        } else {
            $discountHtml = "<div style='background:#f3f4f6; padding:16px; border-left:4px solid #9ca3af; border-radius:6px; margin-bottom: 24px;'><h4 style='color:#374151; margin-top:0;'>Discount Status</h4><p style='color:#4b5563; margin-bottom:0;'>{$discountMsg}</p></div>";
        }

        // BUILD OPTICAL HTML
        $findingsHtml = '';
        foreach ($opticalFindings as $finding) {
            $findingsHtml .= "<li style='margin-bottom: 4px; color:#b45309;'><strong>{$finding}</strong></li>";
        }
        $opticalStepsHtml = '';
        foreach ($opticalSteps as $step) {
            $opticalStepsHtml .= "<li style='margin-bottom: 4px;'>{$step}</li>";
        }
        $opticalHtml = "<div style='background:#eff6ff; padding:16px; border-left:4px solid #3b82f6; border-radius:6px;'><h4 style='color:#1e40af; margin-top:0;'>Optical Recommendations</h4><ul style='margin-bottom: 12px; padding-left: 20px;'>{$findingsHtml}</ul><div style='background:white; padding:12px; border-radius:4px;'><strong>Suggested Actions:</strong><ul style='margin-top: 8px; padding-left: 20px; color:#4b5563;'>{$opticalStepsHtml}</ul></div></div>";

        $body = "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; color:#111827;'><div style='text-align:center; margin-bottom: 30px;'><h2 style='color:#0d9488;'>{$clinicName}</h2><p style='color:#6b7280;'>Your Optical Summary</p></div><p>Hello <strong>{$patientName}</strong>,</p><p>Thank you for visiting our clinic. Here is the summary of your optical results and recommendations.</p><br>{$discountHtml}{$opticalHtml}<hr style='border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;'><p style='color:#9ca3af; font-size: 12px; text-align: center;'>Automated email from Eyecore System on behalf of {$clinicName}.</p></div>";

        $m = makeMailer();
        $m->setFrom('angelloricanmendoza27@gmail.com', $clinicName . ' via Eyecore');
        $m->addAddress($email, $patientName);
        $m->isHTML(true);
        $m->Subject = 'Your Optical Results & Benefits Guide - ' . $clinicName;
        $m->Body = $body;
        $m->send();

        echo json_encode(['success' => true, 'message' => 'Email sent successfully.']);
    } catch (MailException $e) {
        echo json_encode(['success' => false, 'message' => 'SMTP Error: ' . $e->getMessage()]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    }
    exit;
}

// ================== GET STATS ==================
if ($action === 'get_stats') {
    DecisionSupportPermission::check('view');
    echo json_encode(getDashboardStats($pdo, $clinic_id));
    exit;
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
exit;