<?php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// ============================================
// RBAC PERMISSION HELPER FUNCTIONS - ILIPAT SA ITAAS!
// ============================================
function canViewPatients() { return RBACHelper::hasPermission('patients_view'); }
function canCreatePatients() { return RBACHelper::hasPermission('patients_create'); }
function canEditPatients() { return RBACHelper::hasPermission('patients_edit'); }
function canDeletePatients() { return RBACHelper::hasPermission('patients_delete'); }
function canApprovePatients() { return RBACHelper::hasPermission('patients_approve'); }
function canRejectPatients() { return RBACHelper::hasPermission('patients_reject'); }
function canExportPatients() { return RBACHelper::hasPermission('patients_view'); }
function canUpgradePatients() { return RBACHelper::hasPermission('patients_create'); }
function canVerifyPatients() { return RBACHelper::hasPermission('patients_approve'); }

// ✅ Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// ✅ RBAC: Check base view permission first
if (!canViewPatients()) {
    echo json_encode(['error' => 'Access denied: You do not have permission to view patient records']);
    exit();
}

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

// ============================================
// ENCRYPTION CLASS
// ============================================
class DataEncryption {
    private static $method = 'AES-256-CBC';
    private static $key = null;
    
    public static function getKey($pdo, $clinicId) {
        if (self::$key !== null) return self::$key;
        
        $stmt = $pdo->prepare("SELECT key_value FROM encryption_keys WHERE clinic_id = ? AND key_name = 'patient_data'");
        $stmt->execute([$clinicId]);
        $keyData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($keyData) {
            self::$key = base64_decode($keyData['key_value']);
        } else {
            self::$key = openssl_random_pseudo_bytes(32);
            $encodedKey = base64_encode(self::$key);
            
            $stmt = $pdo->prepare("INSERT INTO encryption_keys (clinic_id, key_name, key_value) VALUES (?, 'patient_data', ?)");
            $stmt->execute([$clinicId, $encodedKey]);
        }
        
        return self::$key;
    }
    
    public static function encrypt($data, $pdo, $clinicId) {
        if (empty($data)) return $data;
        
        $key = self::getKey($pdo, $clinicId);
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::$method));
        $encrypted = openssl_encrypt($data, self::$method, $key, 0, $iv);
        return base64_encode($encrypted . '::' . $iv);
    }
    
    public static function decrypt($data, $pdo, $clinicId) {
        if (empty($data) || strpos($data, '::') === false) return $data;
        
        $key = self::getKey($pdo, $clinicId);
        $parts = explode('::', base64_decode($data));
        if (count($parts) !== 2) return $data;
        
        return openssl_decrypt($parts[0], self::$method, $key, 0, $parts[1]);
    }
}

// ============================================
// AUDIT LOG CLASS
// ============================================
class AuditLogger {
    private $pdo;
    private $clinicId;
    private $userId;
    private $userName;
    private $userRole;
    private $ipAddress;
    private $userAgent;
    
    public function __construct($pdo, $clinicId) {
        $this->pdo = $pdo;
        $this->clinicId = $clinicId;
        $this->userId = $_SESSION['user_id'] ?? null;
        $this->userName = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
        $this->userRole = $_SESSION['role'] ?? 'Unknown';
        $this->ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
    }
    
    public function log($patientId, $action, $fieldName = null, $oldValue = null, $newValue = null) {
        try {
            if (is_string($oldValue) && strlen($oldValue) > 500) $oldValue = substr($oldValue, 0, 500) . '...';
            if (is_string($newValue) && strlen($newValue) > 500) $newValue = substr($newValue, 0, 500) . '...';
            
            $stmt = $this->pdo->prepare("
                INSERT INTO patient_audit_logs 
                (clinic_id, user_id, user_name, user_role, patient_id, action_type, 
                 field_name, old_value, new_value, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $this->clinicId,
                $this->userId,
                $this->userName,
                $this->userRole,
                $patientId,
                $action,
                $fieldName,
                $oldValue,
                $newValue,
                $this->ipAddress,
                $this->userAgent
            ]);
            
            return true;
        } catch (Exception $e) {
            error_log("Audit log failed: " . $e->getMessage());
            return false;
        }
    }
    
    public function logView($patientId) { return $this->log($patientId, 'VIEW'); }
    public function logCreate($patientId, $data) { return $this->log($patientId, 'CREATE', null, null, json_encode($data)); }
    public function logUpdate($patientId, $field, $old, $new) { return $this->log($patientId, 'UPDATE', $field, $old, $new); }
    public function logDelete($patientId) { return $this->log($patientId, 'DELETE'); }
    public function logArchive($patientId) { return $this->log($patientId, 'ARCHIVE'); }
    public function logRestore($patientId) { return $this->log($patientId, 'RESTORE'); }
    public function logExport($patientId) { return $this->log($patientId, 'EXPORT'); }
}

$clinicId = $_SESSION['clinic_id'];
$userId = $_SESSION['user_id'];
$userRole = $_SESSION['role'];

// ✅ Unified action resolver — checks GET, POST, and JSON body
$jsonBody = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $_GET['action'] 
       ?? $_POST['action'] 
       ?? $jsonBody['action'] 
       ?? '';

// Initialize audit logger
$auditLogger = new AuditLogger($pdo, $clinicId);

// Get client IP for consent
$clientIp = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

// ============================================
// OTP FUNCTIONS
// ============================================
function sendOTP($email, $name, $otp) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = '🔐 Your OTP Code - Eyecore';
        $mail->Body = "
            <div style='font-family:Arial; max-width:400px; margin:0 auto;'>
                <h2 style='color:#3b82f6;'>Email Verification</h2>
                <p>Hello <strong>$name</strong>,</p>
                <p>Your OTP code is:</p>
                <div style='background:#3b82f6; color:white; font-size:32px; padding:15px; text-align:center; border-radius:8px;'>
                    <strong>$otp</strong>
                </div>
                <p style='color:#666; margin-top:20px;'>Valid for 5 minutes only.</p>
                <hr>
                <p style='font-size:12px; color:#999;'>
                    This is an automated message. Please do not reply.
                </p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("OTP Error: " . $e->getMessage());
        return false;
    }
}

function sendWelcome($email, $name) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = '🎉 Welcome to Eyecore!';
        $mail->Body = "
            <div style='font-family:Arial; max-width:400px; margin:0 auto;'>
                <h2 style='color:#10b981;'>Welcome $name!</h2>
                <p>Your account has been successfully verified.</p>
                <p>You can now login to your patient portal.</p>
                <hr>
                <p style='font-size:12px; color:#999;'>
                    Your personal data is protected under RA 10173 (Data Privacy Act).
                </p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        return false;
    }
}

function sendConsentEmail($email, $name, $consentLink) {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore');
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Subject = '📋 Data Privacy Consent - Eyecore';
        $mail->Body = "
            <div style='font-family:Arial; max-width:500px; margin:0 auto; padding:20px; border:1px solid #e2e8f0; border-radius:10px;'>
                <h2 style='color:#0d9488;'>Data Privacy Consent</h2>
                <p>Hello <strong>" . htmlspecialchars($name) . "</strong>,</p>
                <p>Under the <strong>Data Privacy Act of 2012 (RA 10173)</strong>, we need your consent to store and access your medical records.</p>
                
                <div style='background:#f0fdfa; padding:15px; border-radius:8px; margin:15px 0;'>
                    <p><strong>What this means:</strong></p>
                    <ul>
                        <li>Your personal information will be collected for medical purposes</li>
                        <li>Your data will be kept confidential and secure</li>
                        <li>Only authorized clinic personnel can access your records</li>
                        <li>You have the right to access, correct, or request deletion of your data</li>
                    </ul>
                </div>
                
                <div style='text-align:center; margin:25px 0;'>
                    <a href='" . $consentLink . "' style='background:#0d9488; color:white; padding:12px 30px; text-decoration:none; border-radius:8px; display:inline-block;'>
                        Give My Consent
                    </a>
                </div>
                
                <p style='color:#666; font-size:12px;'>This link will expire in 7 days. If you have questions, please contact the clinic directly.</p>
                <hr>
                <p style='font-size:11px; color:#999;'>This is an automated message. Please do not reply.</p>
            </div>
        ";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Consent email error: " . $e->getMessage());
        return false;
    }
}

// ============================================
// VERIFY HANDLER - UNA DAPAT ITO!
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'verify' || $_GET['action'] === 'verify')) {
    if (!canVerifyPatients() && !canEditPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
 
    $data = $jsonBody;
 
    if (empty($data['email']) || empty($data['otp'])) {
        echo json_encode(['success' => false, 'message' => 'Email and OTP are required']);
        exit();
    }
 
    $pending = $_SESSION['pending_patient'] ?? null;
 
    // ── If there's a pending patient (new registration) ──────
    if ($pending && strtolower($pending['email']) === strtolower($data['email'])) {
 
        // Check OTP match
        if ((string)$pending['otp'] !== (string)$data['otp']) {
            echo json_encode(['success' => false, 'message' => 'Incorrect OTP code. Please check and try again.', 'can_resend' => false]);
            exit();
        }
 
        // Check expiry
        if (time() > $pending['otp_expires']) {
            echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new code.', 'can_resend' => true]);
            exit();
        }
 
        // ── All good → INSERT patient + user ─────────────────
        try {
            $pdo->beginTransaction();
 
            // Insert patient
            $patientStmt = $pdo->prepare("
                INSERT INTO patients (
                    clinic_id, patient_code, first_name, last_name, age, gender,
                    phone, email, address, patient_type, remarks, status,
                    consent_date, consent_ip, consent_version, data_privacy_accepted,
                    consent_method, consent_recorded_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active',
                    NOW(), ?, 'v1.0', 1, 'digital', ?
                )
            ");
            $patientStmt->execute([
                $pending['clinic_id'],
                $pending['patient_code'],
                $pending['first_name'],
                $pending['last_name'],
                $pending['age'],
                $pending['gender'],
                $pending['phone'],
                $pending['email'],
                $pending['address'],
                $pending['patient_type'],
                $pending['remarks'],
                $pending['consent_ip'],
                $pending['recorded_by']
            ]);
            $patientId = $pdo->lastInsertId();
 
            // Insert user account (already active — OTP verified)
            $userCode = 'USR' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $pdo->prepare("
                INSERT INTO users (
                    clinic_id, user_code, first_name, last_name, email,
                    password, role, status
                ) VALUES (?, ?, ?, ?, ?, ?, 'Patient', 'Active')
            ")->execute([
                $pending['clinic_id'],
                $userCode,
                $pending['first_name'],
                $pending['last_name'],
                $pending['email'],
                $pending['password']
            ]);
 
            // Consent history
            $pdo->prepare("
                INSERT INTO patient_consent_history
                (patient_id, consent_version, consent_date, consent_ip, consent_method, recorded_by)
                VALUES (?, 'v1.0', NOW(), ?, 'digital', ?)
            ")->execute([$patientId, $pending['consent_ip'], $pending['recorded_by']]);
 
            $auditLogger->logCreate($patientId, ['source' => 'otp_verified', 'email' => $pending['email']]);
 
            $pdo->commit();
 
            // Clear session
            unset($_SESSION['pending_patient']);
 
            // Send welcome email
            sendWelcome($pending['email'], $pending['first_name'] . ' ' . $pending['last_name']);
 
            echo json_encode([
                'success'    => true,
                'message'    => 'Account verified successfully! Patient has been registered.',
                'patient_id' => $patientId
            ]);
 
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Registration failed: ' . $e->getMessage()]);
        }
        exit();
    }
 
    // ── Existing user re-verification (upgrade flow) ─────────
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND role = 'Patient'");
    $stmt->execute([$data['email']]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
 
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'No account found for this email.', 'can_resend' => false]);
        exit();
    }
    if ($user['status'] === 'Active') {
        echo json_encode(['success' => true, 'message' => 'Account is already verified. You can now log in.']);
        exit();
    }
    if ($user['otp_code'] !== $data['otp']) {
        echo json_encode(['success' => false, 'message' => 'Incorrect OTP code.', 'can_resend' => false]);
        exit();
    }
    
    $expCheckStmt = $pdo->prepare("SELECT NOW() > otp_expires AS is_expired FROM users WHERE id = ?");
    $expCheckStmt->execute([$user['id']]);
    $expiredRow = $expCheckStmt->fetch(PDO::FETCH_ASSOC);

    if ($expiredRow['is_expired']) {
        echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.', 'can_resend' => true]);
        exit();
    }
 
    try {
        $pdo->prepare("
            UPDATE users SET status = 'Active', otp_code = NULL, otp_expires = NULL, updated_at = NOW()
            WHERE id = ?
        ")->execute([$user['id']]);
 
        $auditLogger->log(null, 'OTP_VERIFIED', 'account_status', 'Pending', 'Active');
        sendWelcome($data['email'], $user['first_name'] . ' ' . $user['last_name']);
 
        echo json_encode(['success' => true, 'message' => 'Account verified successfully!']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Verification failed. Please try again.']);
    }
    exit();
}

// ============================================
// RESEND HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'resend' || $_GET['action'] === 'resend')) {
    $data    = $jsonBody;
    $email   = $data['email'] ?? '';
    $pending = $_SESSION['pending_patient'] ?? null;
 
    // New registration resend
    if ($pending && strtolower($pending['email']) === strtolower($email)) {
        $newOtp = rand(100000, 999999);
        $_SESSION['pending_patient']['otp']         = $newOtp;
        $_SESSION['pending_patient']['otp_expires']  = time() + 300;
 
        $name = $pending['first_name'] . ' ' . $pending['last_name'];
        sendOTP($email, $name, $newOtp);
 
        echo json_encode(['success' => true, 'message' => 'New OTP sent!']);
        exit();
    }
 
    // Upgrade resend (existing user)
    $newOtp = rand(100000, 999999);
    $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires = DATE_ADD(NOW(), INTERVAL 5 MINUTE) WHERE email = ?")
        ->execute([$newOtp, $email]);
 
    $user = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $user->execute([$email]);
    $user = $user->fetch();
    if ($user) sendOTP($email, $user['first_name'] . ' ' . $user['last_name'], $newOtp);
 
    echo json_encode(['success' => true, 'message' => 'OTP resent']);
    exit();
}

// ============================================
// UPGRADE HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'upgrade' || $_GET['action'] === 'upgrade')) {
    if (!canUpgradePatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot upgrade patient accounts']);
        exit();
    }
    
    $data = $jsonBody;
    
    if (empty($data['patient_id']) || empty($data['email']) || empty($data['password'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        $patientStmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
        $patientStmt->execute([$data['patient_id'], $clinicId]);
        $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            throw new Exception('Patient not found');
        }
        
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$data['email']]);
        if ($checkStmt->fetch()) {
            throw new Exception('Email already exists');
        }
        
        if (!$patient['data_privacy_accepted']) {
            throw new Exception('Patient must accept Data Privacy consent first');
        }
        
        $otp = rand(100000, 999999);
        $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
        $userCode = 'USR' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        $userStmt = $pdo->prepare("
            INSERT INTO users (
                clinic_id, user_code, first_name, last_name, email, 
                password, role, otp_code, otp_expires, status
            ) VALUES (?, ?, ?, ?, ?, ?, 'Patient', ?, DATE_ADD(NOW(), INTERVAL 5 MINUTE), 'Pending')
        ");
        
        $userStmt->execute([
            $clinicId,
            $userCode,
            $patient['first_name'],
            $patient['last_name'],
            $data['email'],
            $hashedPassword,
            $otp
        ]);
        
        if ($patient['email'] !== $data['email']) {
            $updateStmt = $pdo->prepare("UPDATE patients SET email = ? WHERE id = ?");
            $updateStmt->execute([$data['email'], $data['patient_id']]);
        }
        
        $auditLogger->log($data['patient_id'], 'UPDATE', 'account_upgraded', 'walk-in', 'with_account');
        
        $pdo->commit();
        
        sendOTP($data['email'], $patient['first_name'] . ' ' . $patient['last_name'], $otp);
        
        echo json_encode(['success' => true, 'message' => 'Account created. OTP sent to email.']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// CREATE PATIENT HANDLER (WALANG ACTION)
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($action) && empty($_GET['action'])) {
    if (!canCreatePatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot add patients']);
        exit();
    }
 
    $data = $jsonBody;
 
    if (empty($data['first_name']) || empty($data['last_name']) || empty($data['phone'])) {
        echo json_encode(['success' => false, 'message' => 'Required fields missing']);
        exit();
    }
 
    $consentGiven = filter_var($data['consent_given'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if (!$consentGiven) {
        echo json_encode(['success' => false, 'message' => 'Data Privacy consent is required']);
        exit();
    }
 
    $accountType = $data['account_type'] ?? 'without';
    $age = isset($data['age']) ? (int)$data['age'] : 0;
 
    if ($accountType === 'with' && $age > 0 && $age < 18) {
        echo json_encode(['success' => false, 'message' => 'Patients must be at least 18 years old to create an account.']);
        exit();
    }
 
    try {
        $patientCode = 'PAT' . date('Ymd') . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
 
        if ($accountType !== 'with') {
            $pdo->beginTransaction();
 
            $patientStmt = $pdo->prepare("
                INSERT INTO patients (
                    clinic_id, patient_code, first_name, last_name, age, gender,
                    phone, email, address, patient_type, remarks, status,
                    consent_date, consent_ip, consent_version, data_privacy_accepted,
                    consent_method, consent_recorded_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active',
                    NOW(), ?, 'v1.0', 1, 'digital', ?
                )
            ");
            $patientStmt->execute([
                $clinicId, $patientCode, $data['first_name'], $data['last_name'],
                $data['age'] ?? null, $data['gender'] ?? 'Male', $data['phone'],
                $data['email'] ?? null, $data['address'] ?? '', $data['patient_type'] ?? 'Regular',
                $data['remarks'] ?? '', $clientIp, $userId
            ]);
            $patientId = $pdo->lastInsertId();
 
            $pdo->prepare("
                INSERT INTO patient_consent_history
                (patient_id, consent_version, consent_date, consent_ip, consent_method, recorded_by)
                VALUES (?, 'v1.0', NOW(), ?, 'digital', ?)
            ")->execute([$patientId, $clientIp, $userId]);
 
            $auditLogger->logCreate($patientId, $data);
            $pdo->commit();
 
            echo json_encode(['success' => true, 'message' => 'Walk-in patient added successfully.', 'patient_id' => $patientId, 'otp_required' => false]);
            exit();
        }
 
        if (empty($data['email'])) {
            echo json_encode(['success' => false, 'message' => 'Email is required for account creation']);
            exit();
        }
 
        $checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $checkStmt->execute([$data['email']]);
        if ($checkStmt->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Email already exists. Please use a different email.']);
            exit();
        }
 
        $otp = rand(100000, 999999);
 
        $_SESSION['pending_patient'] = [
            'clinic_id' => $clinicId, 'patient_code' => $patientCode,
            'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
            'age' => $data['age'] ?? null, 'gender' => $data['gender'] ?? 'Male',
            'phone' => $data['phone'], 'email' => $data['email'],
            'address' => $data['address'] ?? '', 'patient_type' => $data['patient_type'] ?? 'Regular',
            'remarks' => $data['remarks'] ?? '', 'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'consent_ip' => $clientIp, 'recorded_by' => $userId,
            'otp' => $otp, 'otp_expires' => time() + 300, 'created_at' => time()
        ];
 
        $fullName = $data['first_name'] . ' ' . $data['last_name'];
        sendOTP($data['email'], $fullName, $otp);
 
        echo json_encode(['success' => true, 'message' => 'OTP sent to ' . $data['email'] . '. Please verify to complete registration.', 'otp_required' => true, 'email' => $data['email']]);
 
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// GET: Fetch patients for DataTables
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'fetch') {
    try {
        $draw = isset($_GET['draw']) ? (int)$_GET['draw'] : 1;
        $start = isset($_GET['start']) ? (int)$_GET['start'] : 0;
        $length = isset($_GET['length']) ? (int)$_GET['length'] : 10;
        
        $status = $_GET['status'] ?? '';
        $gender = $_GET['gender'] ?? '';
        $search = isset($_GET['search']['value']) ? $_GET['search']['value'] : '';
        
        $where = "WHERE p.clinic_id = ?";
        $params = [$clinicId];
        
        if (!empty($status)) {
            $where .= " AND p.status = ?";
            $params[] = $status;
        }
        
        if (!empty($gender)) {
            $where .= " AND p.gender = ?";
            $params[] = $gender;
        }
        
        if (!empty($search)) {
            $where .= " AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.patient_code LIKE ? OR p.phone LIKE ? OR p.email LIKE ?)";
            $searchTerm = "%$search%";
            $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
        }
        
        $totalStmt = $pdo->prepare("SELECT COUNT(*) FROM patients WHERE clinic_id = ?");
        $totalStmt->execute([$clinicId]);
        $totalRecords = (int)$totalStmt->fetchColumn();
        
        $filteredSql = "SELECT COUNT(*) FROM patients p $where";
        $filteredStmt = $pdo->prepare($filteredSql);
        $filteredStmt->execute($params);
        $filteredRecords = (int)$filteredStmt->fetchColumn();
        
        $orderColumn = isset($_GET['order'][0]['column']) ? (int)$_GET['order'][0]['column'] : 6;
        $orderDir = isset($_GET['order'][0]['dir']) ? $_GET['order'][0]['dir'] : 'desc';
        $columns = ['p.patient_code', 'p.first_name', 'p.age', 'p.gender', 'p.phone', 'p.status', 'p.created_at'];
        $orderBy = isset($columns[$orderColumn]) ? $columns[$orderColumn] : 'p.created_at';
        
        $dataSql = "SELECT p.*, 
                    CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END as has_account 
                    FROM patients p 
                    LEFT JOIN users u ON u.email = p.email AND u.role = 'Patient'
                    $where 
                    ORDER BY $orderBy $orderDir 
                    LIMIT ? OFFSET ?";
        
        $params[] = $length;
        $params[] = $start;
        
        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->execute($params);
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $statsStmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total,
                COALESCE(SUM(CASE WHEN p.status = 'Active' THEN 1 ELSE 0 END), 0) as active,
                COALESCE(SUM(CASE WHEN p.status = 'Archived' THEN 1 ELSE 0 END), 0) as archived,
                COALESCE(SUM(CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END), 0) as with_account,
                COALESCE(SUM(CASE WHEN p.patient_type = 'Senior' THEN 1 ELSE 0 END), 0) as senior,
                COALESCE(SUM(CASE WHEN p.patient_type = 'PWD' THEN 1 ELSE 0 END), 0) as pwd,
                COALESCE(SUM(CASE WHEN p.data_privacy_accepted = 1 THEN 1 ELSE 0 END), 0) as consent_given
            FROM patients p
            LEFT JOIN users u ON u.email = p.email AND u.role = 'Patient'
            WHERE p.clinic_id = ?
        ");
        $statsStmt->execute([$clinicId]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        $stats = [
            'total' => (int)($stats['total'] ?? 0),
            'active' => (int)($stats['active'] ?? 0),
            'archived' => (int)($stats['archived'] ?? 0),
            'with_account' => (int)($stats['with_account'] ?? 0),
            'senior' => (int)($stats['senior'] ?? 0),
            'pwd' => (int)($stats['pwd'] ?? 0),
            'consent_given' => (int)($stats['consent_given'] ?? 0)
        ];
        
        echo json_encode([
            'draw' => $draw,
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $filteredRecords,
            'data' => $data,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'draw' => isset($draw) ? $draw : 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'stats' => ['total' => 0, 'active' => 0, 'archived' => 0, 'with_account' => 0, 'senior' => 0, 'pwd' => 0]
        ]);
    }
    exit();
}

// ============================================
// RECORD CONSENT HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record_consent') {
    if (!canEditPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot record patient consent']);
        exit();
    }
    
    $patientId = (int)($_POST['patient_id'] ?? 0);
    $consentMethod = $_POST['consent_method'] ?? '';
    $patientEmail = $_POST['patient_email'] ?? '';
    
    if (!$patientId || !$consentMethod) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    $validMethods = ['physical', 'appointment', 'online'];
    if (!in_array($consentMethod, $validMethods)) {
        echo json_encode(['success' => false, 'message' => 'Invalid consent method']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        $patientStmt = $pdo->prepare("
            SELECT p.*, u.email as user_email
            FROM patients p
            LEFT JOIN users u ON u.email = p.email AND u.role = 'Patient'
            WHERE p.id = ? AND p.clinic_id = ?
        ");
        $patientStmt->execute([$patientId, $clinicId]);
        $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            throw new Exception('Patient not found');
        }
        
        $verificationDetails = '';
        $canProceed = false;
        $accessGranted = false;
        
        switch ($consentMethod) {
            case 'physical':
                $checkStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM appointments 
                    WHERE patient_id = ? AND clinic_id = ? 
                    AND appointment_date = CURDATE()
                    AND appointment_type = 'walk_in'
                ");
                $checkStmt->execute([$patientId, $clinicId]);
                if ($checkStmt->fetchColumn() > 0) {
                    $canProceed = true;
                    $accessGranted = true;
                    $verificationDetails = 'Patient physically present (walk-in today)';
                } else {
                    throw new Exception('Patient not verified as physically present today');
                }
                break;
                
            case 'appointment':
                $checkStmt = $pdo->prepare("
                    SELECT appointment_date FROM appointments 
                    WHERE patient_id = ? AND clinic_id = ? 
                    AND appointment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    AND status IN ('completed', 'paid', 'confirmed')
                    ORDER BY appointment_date DESC LIMIT 1
                ");
                $checkStmt->execute([$patientId, $clinicId]);
                $lastAppt = $checkStmt->fetch(PDO::FETCH_ASSOC);
                if ($lastAppt) {
                    $canProceed = true;
                    $accessGranted = true;
                    $verificationDetails = 'Patient had appointment on ' . date('M d, Y', strtotime($lastAppt['appointment_date']));
                } else {
                    throw new Exception('No recent appointment found');
                }
                break;
                
            case 'online':
                if (empty($patientEmail)) {
                    $patientEmail = $patient['email'] ?? $patient['user_email'] ?? null;
                    if (empty($patientEmail)) {
                        throw new Exception('Patient email not found. Please update patient record with email address first.');
                    }
                }
                $canProceed = true;
                $accessGranted = false;
                $verificationDetails = 'Online consent form sent to patient email: ' . $patientEmail;
                break;
        }
        
        if (!$canProceed) {
            throw new Exception('Cannot record consent at this time');
        }
        
        $patientName = ($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '');
        
        if ($accessGranted) {
            $stmt = $pdo->prepare("
                UPDATE patients 
                SET data_privacy_accepted = 1,
                    consent_date = NOW(),
                    consent_ip = ?,
                    consent_version = 'v1.0',
                    consent_method = ?,
                    consent_recorded_by = ?,
                    consent_verification_method = ?,
                    consent_verified_by = ?,
                    consent_verified_at = NOW(),
                    consent_status = 'approved'
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$clientIp, $consentMethod, $userId, $consentMethod, $userId, $patientId, $clinicId]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE patients 
                SET consent_date = NOW(),
                    consent_ip = ?,
                    consent_version = 'v1.0',
                    consent_method = ?,
                    consent_recorded_by = ?,
                    consent_verification_method = ?,
                    consent_verified_by = ?,
                    consent_verified_at = NOW(),
                    consent_status = 'pending',
                    consent_token_sent = 1,
                    consent_token_sent_at = NOW()
                WHERE id = ? AND clinic_id = ?
            ");
            $stmt->execute([$clientIp, $consentMethod, $userId, $consentMethod, $userId, $patientId, $clinicId]);
        }
        
        $historyStmt = $pdo->prepare("
            INSERT INTO patient_consent_history 
            (patient_id, consent_version, consent_date, consent_ip, consent_method, recorded_by, verification_method, verification_details, consent_status)
            VALUES (?, 'v1.0', NOW(), ?, ?, ?, ?, ?, ?)
        ");
        $historyStmt->execute([$patientId, $clientIp, $consentMethod, $userId, $consentMethod, $verificationDetails, $accessGranted ? 'approved' : 'pending']);
        
        $auditLogger->log($patientId, 'CONSENT_RECORDED', 'consent_status', 'none', $accessGranted ? 'approved' : 'pending');
        
        if ($consentMethod === 'online' && !empty($patientEmail)) {
            $token = bin2hex(random_bytes(32));
            $consentLink = "http://" . $_SERVER['HTTP_HOST'] . "/eyecore/consent.php?token=" . $token . "&patient=" . $patientId;
            
            $tokenStmt = $pdo->prepare("
                INSERT INTO consent_tokens (patient_id, token, expires_at)
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
            ");
            $tokenStmt->execute([$patientId, $token]);
            
            sendConsentEmail($patientEmail, $patientName, $consentLink);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => $accessGranted 
                ? 'Consent recorded successfully! You can now access patient records.'
                : 'Consent form sent to patient email. They need to click the link to give consent before records can be accessed.',
            'access_granted' => $accessGranted
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// RESEND CONSENT HANDLER
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_consent') {
    if (!canEditPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot resend consent email']);
        exit();
    }
    
    $patientId = (int)($_POST['patient_id'] ?? 0);
    $patientEmail = $_POST['patient_email'] ?? '';
    
    if (!$patientId || !$patientEmail) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit();
    }
    
    try {
        $patientStmt = $pdo->prepare("SELECT first_name, last_name FROM patients WHERE id = ? AND clinic_id = ?");
        $patientStmt->execute([$patientId, $clinicId]);
        $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            throw new Exception('Patient not found');
        }
        
        $patientName = ($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '');
        
        $token = bin2hex(random_bytes(32));
        $consentLink = "http://" . $_SERVER['HTTP_HOST'] . "/eyecore/consent.php?token=" . $token . "&patient=" . $patientId;
        
        $tokenStmt = $pdo->prepare("
            REPLACE INTO consent_tokens (patient_id, token, expires_at)
            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 7 DAY))
        ");
        $tokenStmt->execute([$patientId, $token]);
        
        $updateStmt = $pdo->prepare("
            UPDATE patients SET consent_token_sent_at = NOW() WHERE id = ?
        ");
        $updateStmt->execute([$patientId]);
        
        sendConsentEmail($patientEmail, $patientName, $consentLink);
        
        echo json_encode(['success' => true, 'message' => 'Consent email resent successfully']);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// GET: View Patient
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'view' && isset($_GET['id'])) {
    $patientId = (int)$_GET['id'];
    
    $auditLogger->logView($patientId);
    
    $stmt = $pdo->prepare("
        SELECT p.*, 
               CASE WHEN u.id IS NOT NULL THEN 1 ELSE 0 END as has_account,
               u.status as account_status,
               p.consent_date, p.consent_method, p.data_privacy_accepted
        FROM patients p 
        LEFT JOIN users u ON u.email = p.email AND u.role = 'Patient'
        WHERE p.id = ? AND p.clinic_id = ?
    ");
    $stmt->execute([$patientId, $clinicId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit();
    }
    
    $typeBadge = '';
    if ($patient['patient_type'] === 'Senior') {
        $typeBadge = '<span class="badge bg-primary mt-1"><i class="bi bi-person-standing"></i> Senior</span>';
    } elseif ($patient['patient_type'] === 'PWD') {
        $typeBadge = '<span class="badge bg-warning mt-1"><i class="bi bi-heart"></i> PWD</span>';
    }
    
    $accountBadge = $patient['has_account'] ? 
        '<span class="badge bg-success"><i class="bi bi-person-badge"></i> ' . ucfirst($patient['account_status']) . '</span>' : 
        '<span class="badge bg-secondary"><i class="bi bi-person"></i> Walk-in</span>';
    
    $consentBadge = $patient['data_privacy_accepted'] ? 
        '<span class="badge bg-info"><i class="bi bi-shield-check"></i> Consent Given: ' . date('M d, Y', strtotime($patient['consent_date'])) . '</span>' : 
        '<span class="badge bg-danger"><i class="bi bi-shield-exclamation"></i> No Consent</span>';
    
    $html = '<div class="row">
        <div class="col-md-6">
            <div class="mb-3"><strong>Patient ID:</strong> <code>'.htmlspecialchars($patient['patient_code']).'</code> ' . $typeBadge . '</div>
            <div class="mb-3"><strong>Name:</strong> '.htmlspecialchars($patient['first_name'].' '.$patient['last_name']).'</div>
            <div class="mb-3"><strong>Age:</strong> '.($patient['age'] ?: 'N/A').'</div>
            <div class="mb-3"><strong>Gender:</strong> '.htmlspecialchars($patient['gender']).'</div>
            <div class="mb-3"><strong>Patient Type:</strong> '.$patient['patient_type'].'</div>
            <div class="mb-3"><strong>Data Privacy:</strong> ' . $consentBadge . '</div>
        </div>
        <div class="col-md-6">
            <div class="mb-3"><strong>Phone:</strong> '.htmlspecialchars($patient['phone']).'</div>
            <div class="mb-3"><strong>Email:</strong> '.htmlspecialchars($patient['email'] ?: 'N/A').'</div>
            <div class="mb-3"><strong>Status:</strong> <span class="badge bg-'.($patient['status']=='Active'?'success':'secondary').'">'.$patient['status'].'</span></div>
            <div class="mb-3"><strong>Account:</strong> '.$accountBadge.'</div>
            <div class="mb-3"><strong>Remarks:</strong> '.htmlspecialchars($patient['remarks'] ?: 'N/A').'</div>
        </div>
        <div class="col-12"><strong>Address:</strong> '.htmlspecialchars($patient['address'] ?: 'N/A').'</div>
    </div>';
    
    echo json_encode([
        'success' => true, 
        'html' => $html,
        'patient' => $patient
    ]);
    exit();
}

// ============================================
// GET: Edit Patient
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'edit' && isset($_GET['id'])) {
    if (!canEditPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot edit patients']);
        exit();
    }
    
    $patientId = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
    $stmt->execute([$patientId, $clinicId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit();
    }
    
    $maleSelected = $patient['gender'] == 'Male' ? 'selected' : '';
    $femaleSelected = $patient['gender'] == 'Female' ? 'selected' : '';
    $regularSelected = $patient['patient_type'] == 'Regular' ? 'selected' : '';
    $seniorSelected = $patient['patient_type'] == 'Senior' ? 'selected' : '';
    $pwdSelected = $patient['patient_type'] == 'PWD' ? 'selected' : '';
    
    $html = '<form id="editPatientForm" onsubmit="return false;">
        <input type="hidden" id="edit_patient_id" value="'.$patient['id'].'">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">First Name *</label>
                <input type="text" id="edit_first_name" class="form-control" value="'.htmlspecialchars($patient['first_name']).'" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Last Name *</label>
                <input type="text" id="edit_last_name" class="form-control" value="'.htmlspecialchars($patient['last_name']).'" required>
            </div>
            <div class="col-md-4">
                <label class="form-label">Age</label>
                <input type="number" id="edit_age" class="form-control" value="'.htmlspecialchars($patient['age']).'">
            </div>
            <div class="col-md-4">
                <label class="form-label">Gender</label>
                <select id="edit_gender" class="form-select">
                    <option value="Male" '.$maleSelected.'>Male</option>
                    <option value="Female" '.$femaleSelected.'>Female</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Phone *</label>
                <input type="tel" id="edit_phone" class="form-control" value="'.htmlspecialchars($patient['phone']).'" required>
            </div>
            <div class="col-md-6">
                <label class="form-label">Patient Type</label>
                <select id="edit_patient_type" class="form-select">
                    <option value="Regular" '.$regularSelected.'>Regular</option>
                    <option value="Senior" '.$seniorSelected.'>Senior</option>
                    <option value="PWD" '.$pwdSelected.'>PWD</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Remarks</label>
                <input type="text" id="edit_remarks" class="form-control" value="'.htmlspecialchars($patient['remarks']).'">
            </div>
            <div class="col-12">
                <label class="form-label">Address</label>
                <textarea id="edit_address" class="form-control" rows="2">'.htmlspecialchars($patient['address']).'</textarea>
            </div>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-shield-lock me-2"></i>
                    <strong>Data Privacy Notice:</strong> Email cannot be edited here. Contact support for email changes.
                </div>
            </div>
        </div>
    </form>';
    
    echo json_encode(['success' => true, 'html' => $html]);
    exit();
}

// ============================================
// PUT: Update Patient
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    if (!canEditPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot update patients']);
        exit();
    }
    
    $data = $jsonBody;
    
    if (empty($data['id'])) {
        echo json_encode(['success' => false, 'message' => 'Patient ID missing']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        $patientId = $data['id'];
        
        $oldStmt = $pdo->prepare("SELECT * FROM patients WHERE id = ? AND clinic_id = ?");
        $oldStmt->execute([$patientId, $clinicId]);
        $oldPatient = $oldStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$oldPatient) {
            throw new Exception('Patient not found');
        }
        
        $oldPhone = DataEncryption::decrypt($oldPatient['phone'], $pdo, $clinicId);
        $oldAddress = DataEncryption::decrypt($oldPatient['address'], $pdo, $clinicId);
        $oldRemarks = DataEncryption::decrypt($oldPatient['remarks'], $pdo, $clinicId);
        
        $encryptedPhone = $data['phone'] ?? '';
        $encryptedAddress = $data['address'] ?? '';
        $encryptedRemarks = $data['remarks'] ?? '';
        
        $stmt = $pdo->prepare("
            UPDATE patients SET
                first_name = ?,
                last_name = ?,
                age = ?,
                gender = ?,
                phone = ?,
                address = ?,
                patient_type = ?,
                remarks = ?,
                updated_at = NOW()
            WHERE id = ? AND clinic_id = ?
        ");
        
        $stmt->execute([
            $data['first_name'],
            $data['last_name'],
            $data['age'] ?? null,
            $data['gender'] ?? 'Male',
            $encryptedPhone,
            $encryptedAddress,
            $data['patient_type'] ?? 'Regular',
            $encryptedRemarks,
            $patientId,
            $clinicId
        ]);
        
        if ($oldPatient['first_name'] != $data['first_name']) {
            $auditLogger->logUpdate($patientId, 'first_name', $oldPatient['first_name'], $data['first_name']);
        }
        if ($oldPatient['last_name'] != $data['last_name']) {
            $auditLogger->logUpdate($patientId, 'last_name', $oldPatient['last_name'], $data['last_name']);
        }
        if ($oldPatient['age'] != $data['age']) {
            $auditLogger->logUpdate($patientId, 'age', $oldPatient['age'], $data['age']);
        }
        if ($oldPatient['gender'] != $data['gender']) {
            $auditLogger->logUpdate($patientId, 'gender', $oldPatient['gender'], $data['gender']);
        }
        if ($oldPhone != $data['phone']) {
            $auditLogger->logUpdate($patientId, 'phone', '[ENCRYPTED]', '[ENCRYPTED]');
        }
        if ($oldAddress != $data['address']) {
            $auditLogger->logUpdate($patientId, 'address', '[ENCRYPTED]', '[ENCRYPTED]');
        }
        if ($oldPatient['patient_type'] != $data['patient_type']) {
            $auditLogger->logUpdate($patientId, 'patient_type', $oldPatient['patient_type'], $data['patient_type']);
        }
        if ($oldRemarks != $data['remarks']) {
            $auditLogger->logUpdate($patientId, 'remarks', '[ENCRYPTED]', '[ENCRYPTED]');
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Patient updated successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// PATCH: Archive/Unarchive Patient
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
    if (!canEditPatients() && !canDeletePatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot change patient status']);
        exit();
    }
    
    $data = $jsonBody;
    
    if (empty($data['id']) || empty($data['status'])) {
        echo json_encode(['success' => false, 'message' => 'Missing data']);
        exit();
    }
    
    $patientId = (int)$data['id'];
    $allowedStatuses = ['Active', 'Archived'];
    
    if (!in_array($data['status'], $allowedStatuses)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        $oldStmt = $pdo->prepare("SELECT status FROM patients WHERE id = ? AND clinic_id = ?");
        $oldStmt->execute([$patientId, $clinicId]);
        $oldStatus = $oldStmt->fetchColumn();
        
        if (!$oldStatus) {
            throw new Exception('Patient not found');
        }
        
        $stmt = $pdo->prepare("UPDATE patients SET status = ?, updated_at = NOW() WHERE id = ? AND clinic_id = ?");
        $stmt->execute([$data['status'], $patientId, $clinicId]);
        
        if ($data['status'] === 'Archived') {
            $auditLogger->logArchive($patientId);
            
            $retentionStmt = $pdo->prepare("
                INSERT INTO data_retention_log (clinic_id, patient_id, retention_date, action_taken, performed_by)
                VALUES (?, ?, CURDATE(), 'ARCHIVED', ?)
            ");
            $retentionStmt->execute([$clinicId, $patientId, $userId]);
            
        } else {
            $auditLogger->logRestore($patientId);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Status updated']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
    exit();
}

// ============================================
// GET: Export Patient Data
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'export' && isset($_GET['id'])) {
    if (!canExportPatients() && !canViewPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot export patient data']);
        exit();
    }
    
    $patientId = (int)$_GET['id'];
    
    $auditLogger->logExport($patientId);
    
    $stmt = $pdo->prepare("
        SELECT p.*, 
               u.status as account_status,
               u.created_at as account_created
        FROM patients p 
        LEFT JOIN users u ON u.email = p.email AND u.role = 'Patient'
        WHERE p.id = ? AND p.clinic_id = ?
    ");
    $stmt->execute([$patientId, $clinicId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient not found']);
        exit();
    }
    
    $auditStmt = $pdo->prepare("
        SELECT action_type, user_name, created_at 
        FROM patient_audit_logs 
        WHERE patient_id = ? 
        ORDER BY created_at DESC 
        LIMIT 20
    ");
    $auditStmt->execute([$patientId]);
    $auditLogs = $auditStmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'patient' => $patient,
        'audit_logs' => $auditLogs,
        'consent' => [
            'date' => $patient['consent_date'],
            'method' => $patient['consent_method'],
            'version' => $patient['consent_version']
        ]
    ]);
    exit();
}

// ============================================
// DELETE: Delete Patient
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'DELETE' && isset($_GET['id'])) {
    if (!canDeletePatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied: You cannot delete patient records']);
        exit();
    }
    
    $patientId = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        $auditLogger->logDelete($patientId);
        
        $retentionStmt = $pdo->prepare("
            INSERT INTO data_retention_log (clinic_id, patient_id, retention_date, action_taken, performed_by)
            VALUES (?, ?, CURDATE(), 'DELETED', ?)
        ");
        $retentionStmt->execute([$clinicId, $patientId, $userId]);
        
        if (isset($_GET['anonymize']) && $_GET['anonymize'] === 'true') {
            $anonStmt = $pdo->prepare("
                UPDATE patients SET
                    first_name = '[DELETED]',
                    last_name = '[DELETED]',
                    phone = '',
                    email = NULL,
                    address = '',
                    remarks = 'Account deleted per RA 10173 right to erasure'
                WHERE id = ? AND clinic_id = ?
            ");
            $anonStmt->execute([$patientId, $clinicId]);
        } else {
            $deleteStmt = $pdo->prepare("DELETE FROM patients WHERE id = ? AND clinic_id = ?");
            $deleteStmt->execute([$patientId, $clinicId]);
        }
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Patient data deleted per Right to Erasure']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Delete failed: ' . $e->getMessage()]);
    }
    exit();
}

// ============================================
// GET: Retention Stats
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'retention_stats') {
    if (!canDeletePatients() && $_SESSION['role'] !== 'ClinicAdmin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_archived,
            SUM(CASE WHEN retention_date < DATE_SUB(CURDATE(), INTERVAL 5 YEAR) THEN 1 ELSE 0 END) as eligible_for_deletion
        FROM data_retention_log
        WHERE clinic_id = ? AND action_taken = 'ARCHIVED'
    ");
    $stmt->execute([$clinicId]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'stats' => $stats
    ]);
    exit();
}

// ============================================
// GET: Clinical Notes
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_clinical_notes' && isset($_GET['id'])) {
    $patientId = (int)$_GET['id'];
 
    $auditLogger->logView($patientId);
 
    try {
        $stmt = $pdo->prepare("
            SELECT
                cn.id,
                cn.service_date,
                DATE_FORMAT(cn.service_date, '%M %d, %Y') AS formatted_date,
                cn.chief_complaint,
                cn.va_left,
                cn.va_right,
                cn.findings,
                cn.diagnosis,
                cn.treatment_plan,
                cn.notes,
                cn.status,
                CONCAT(u.first_name, ' ', u.last_name) AS doctor_name
            FROM clinical_notes cn
            LEFT JOIN users u ON cn.optometrist_id = u.id
            WHERE cn.patient_id = ?
              AND cn.clinic_id  = ?
              AND cn.status IN ('completed', 'draft')
            ORDER BY cn.service_date DESC, cn.created_at DESC
            LIMIT 50
        ");
        $stmt->execute([$patientId, $clinicId]);
        $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
        echo json_encode([
            'success' => true,
            'notes'   => $notes,
            'total'   => count($notes)
        ]);
 
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}
 
// ============================================
// GET: Patient Prescriptions History
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_prescriptions' && isset($_GET['id'])) {
    $patientId = (int)$_GET['id'];
 
    try {
        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.prescription_date,
                DATE_FORMAT(p.prescription_date, '%M %d, %Y') AS formatted_date,
                p.expiry_date,
                p.prescription_type,
                p.sph_r, p.cyl_r, p.axis_r, p.add_r,
                p.prism_r, p.base_r,
                p.sph_l, p.cyl_l, p.axis_l, p.add_l,
                p.prism_l, p.base_l,
                p.pd, p.pd_type, p.notes,
                p.appointment_id,
                d.name AS doctor_name
            FROM prescriptions p
            LEFT JOIN doctors d ON p.doctor_id = d.id
            WHERE p.patient_id = ?
              AND p.clinic_id  = ?
            ORDER BY p.prescription_date DESC, p.created_at DESC
            LIMIT 30
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
    exit();
}
 
// ============================================
// GET: Patient Appointment History
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_appointments' && isset($_GET['id'])) {
    $patientId = (int)$_GET['id'];
 
    try {
        $stmt = $pdo->prepare("
            SELECT
                a.id,
                a.ref_no,
                a.appointment_date,
                DATE_FORMAT(a.appointment_date, '%M %d, %Y') AS formatted_date,
                a.appointment_time,
                a.service_type,
                a.appointment_type,
                a.status,
                a.payment_status,
                a.total_amount,
                a.balance_amount,
                a.notes,
                a.cancellation_reason,
                COALESCE(pr.name, a.service_type, 'Check-up') AS product_name,
                d.name AS doctor_name
            FROM appointments a
            LEFT JOIN products pr ON a.product_id = pr.id
            LEFT JOIN doctors  d  ON a.doctor_id  = d.id
            WHERE a.patient_id = ?
              AND a.clinic_id  = ?
            ORDER BY a.appointment_date DESC, a.appointment_time DESC
            LIMIT 50
        ");
        $stmt->execute([$patientId, $clinicId]);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);
 
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
            $raw = strtolower($appt['status'] ?? '');
            $appt['status_normalized'] = $statusMap[$raw] ?? $raw;
            $appt['appointment_time_formatted'] = !empty($appt['appointment_time'])
                ? date('g:i A', strtotime($appt['appointment_time']))
                : '—';
            $appt['total_amount_formatted'] = $appt['total_amount'] !== null
                ? '₱ ' . number_format($appt['total_amount'], 2)
                : null;
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
    exit();
}

// ============================================
// GET: Check Consent Eligibility
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'check_consent_eligibility' && isset($_GET['id'])) {
    if (!canEditPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $patientId = (int)$_GET['id'];
    
    $physicalStmt = $pdo->prepare("
        SELECT COUNT(*) as physical_presence
        FROM appointments 
        WHERE patient_id = ? AND clinic_id = ? 
        AND appointment_date >= DATE_SUB(NOW(), INTERVAL 1 DAY)
        AND appointment_type = 'walk_in'
        AND status IN ('completed', 'paid', 'confirmed')
    ");
    $physicalStmt->execute([$patientId, $clinicId]);
    $physicalPresence = $physicalStmt->fetchColumn() > 0;
    
    $apptStmt = $pdo->prepare("
        SELECT COUNT(*) as recent_appointments,
               MAX(appointment_date) as last_appointment
        FROM appointments 
        WHERE patient_id = ? AND clinic_id = ? 
        AND appointment_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        AND status IN ('completed', 'paid', 'confirmed')
    ");
    $apptStmt->execute([$patientId, $clinicId]);
    $apptData = $apptStmt->fetch(PDO::FETCH_ASSOC);
    $hasRecentAppointment = $apptData['recent_appointments'] > 0;
    $lastAppointment = $apptData['last_appointment'];
    
    $onlineStmt = $pdo->prepare("
        SELECT data_privacy_accepted, consent_date, consent_method, first_name, last_name
        FROM patients 
        WHERE id = ? AND clinic_id = ?
    ");
    $onlineStmt->execute([$patientId, $clinicId]);
    $patient = $onlineStmt->fetch(PDO::FETCH_ASSOC);
    $hasOnlineConsent = $patient && $patient['data_privacy_accepted'] == 1;
    $patientName = ($patient['first_name'] ?? '') . ' ' . ($patient['last_name'] ?? '');
    
    $availableMethods = [];
    $canRecord = false;
    
    if ($physicalPresence) {
        $availableMethods[] = [
            'method' => 'physical',
            'name' => 'Physical Presence',
            'icon' => 'bi-person-walking',
            'description' => 'Patient is currently in the clinic (walk-in)',
            'badge' => 'success'
        ];
        $canRecord = true;
    }
    
    if ($hasRecentAppointment) {
        $availableMethods[] = [
            'method' => 'appointment',
            'name' => 'Recent Appointment',
            'icon' => 'bi-calendar-check',
            'description' => "Patient had appointment on " . date('M d, Y', strtotime($lastAppointment)),
            'badge' => 'info'
        ];
        $canRecord = true;
    }
    
    if (!$hasOnlineConsent) {
        $availableMethods[] = [
            'method' => 'online',
            'name' => 'Send Online Consent',
            'icon' => 'bi-envelope-paper',
            'description' => 'Send consent form via email for patient to sign digitally',
            'badge' => 'primary'
        ];
        $canRecord = true;
    }
    
    if ($hasOnlineConsent) {
        $availableMethods[] = [
            'method' => 'already_given',
            'name' => 'Consent Already Given',
            'icon' => 'bi-check-circle',
            'description' => "Patient already gave consent on " . date('M d, Y', strtotime($patient['consent_date'])),
            'badge' => 'success'
        ];
    }
    
    echo json_encode([
        'success' => true,
        'can_record' => $canRecord,
        'has_consent' => $hasOnlineConsent,
        'available_methods' => $availableMethods,
        'patient_name' => trim($patientName) ?: 'Unknown Patient'
    ]);
    exit();
}

// ============================================
// GET: Get Consent Status
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_consent_status' && isset($_GET['patient_id'])) {
    if (!canViewPatients()) {
        echo json_encode(['success' => false, 'message' => 'Permission denied']);
        exit();
    }
    
    $patientId = (int)$_GET['patient_id'];
    
    $stmt = $pdo->prepare("
        SELECT data_privacy_accepted, consent_status 
        FROM patients 
        WHERE id = ? AND clinic_id = ?
    ");
    $stmt->execute([$patientId, $clinicId]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data_privacy_accepted' => $patient ? (int)$patient['data_privacy_accepted'] : 0,
        'consent_status' => $patient ? $patient['consent_status'] : null
    ]);
    exit();
}

// ============================================
// POST: Force Consent
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'force_consent') {
    if (!canEditPatients() && $_SESSION['role'] !== 'ClinicAdmin') {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $patientId = (int)($_POST['patient_id'] ?? 0);
    
    if (!$patientId) {
        echo json_encode(['success' => false, 'message' => 'Patient ID required']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        $patientStmt = $pdo->prepare("SELECT first_name, last_name FROM patients WHERE id = ? AND clinic_id = ?");
        $patientStmt->execute([$patientId, $clinicId]);
        $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$patient) {
            throw new Exception('Patient not found');
        }
        
        $stmt = $pdo->prepare("
            UPDATE patients 
            SET data_privacy_accepted = 1,
                consent_status = 'approved',
                consent_date = NOW(),
                consent_ip = ?,
                consent_version = 'v1.0',
                consent_method = 'clinic_override',
                consent_recorded_by = ?,
                consent_verified_at = NOW(),
                consent_verified_by = ?,
                consent_verification_method = 'clinic_override'
            WHERE id = ? AND clinic_id = ?
        ");
        $stmt->execute([$clientIp, $userId, $userId, $patientId, $clinicId]);
        
        $historyStmt = $pdo->prepare("
            INSERT INTO patient_consent_history 
            (patient_id, consent_version, consent_date, consent_ip, consent_method, recorded_by, verification_method, verification_details, consent_status)
            VALUES (?, 'v1.0', NOW(), ?, 'clinic_override', ?, 'clinic_override', 'Clinic staff overrode consent with verbal confirmation', 'approved')
        ");
        $historyStmt->execute([$patientId, $clientIp, $userId]);
        
        $auditLogger->log($patientId, 'FORCE_CONSENT', 'consent_status', 'rejected', 'approved');
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Consent overridden successfully. Patient records are now accessible.'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// ============================================
// GET: Get User Consent Status
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_user_consent_status') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit();
    }
    
    $userId = $_SESSION['user_id'];
    
    $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    $stmt = $pdo->prepare("
        SELECT data_privacy_accepted, consent_status 
        FROM patients 
        WHERE email = ?
    ");
    $stmt->execute([$user['email']]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data_privacy_accepted' => $patient ? (int)$patient['data_privacy_accepted'] : 0,
        'consent_status' => $patient ? $patient['consent_status'] : null
    ]);
    exit();
}

// ============================================
// POST: Reconsent
// ============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reconsent') {
    if (!isset($_SESSION['user_id'])) {
        echo json_encode(['success' => false, 'message' => 'Please login']);
        exit();
    }
    
    $userId = $_SESSION['user_id'];
    
    $userStmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit();
    }
    
    $patientStmt = $pdo->prepare("
        SELECT id, clinic_id, first_name, last_name 
        FROM patients 
        WHERE email = ?
    ");
    $patientStmt->execute([$user['email']]);
    $patient = $patientStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$patient) {
        echo json_encode(['success' => false, 'message' => 'Patient record not found']);
        exit();
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE patients 
            SET data_privacy_accepted = 1,
                consent_status = 'approved',
                consent_date = NOW(),
                consent_ip = ?,
                consent_version = 'v1.0',
                consent_method = 'online_reconsent',
                consent_recorded_by = ?,
                consent_verified_at = NOW()
            WHERE id = ? AND clinic_id = ?
        ");
        $stmt->execute([$clientIp, $userId, $patient['id'], $patient['clinic_id']]);
        
        $historyStmt = $pdo->prepare("
            INSERT INTO patient_consent_history 
            (patient_id, consent_version, consent_date, consent_ip, consent_method, recorded_by, verification_method, verification_details, consent_status)
            VALUES (?, 'v1.0', NOW(), ?, 'online_reconsent', ?, 'online', 'Patient re-consented via portal', 'approved')
        ");
        $historyStmt->execute([$patient['id'], $clientIp, $userId]);
        
        $pdo->commit();
        
        echo json_encode(['success' => true, 'message' => 'Consent recorded successfully']);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit();
}

// If no handler matched
echo json_encode(['success' => false, 'message' => 'Invalid request']);
?>