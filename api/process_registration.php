<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
include __DIR__ . '/../config/db.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';
require '../PHPMailer/Exception.php';

function alertAndRedirect($message, $type = 'success', $redirect = '../auth/register.php') {
    if (ob_get_length()) ob_clean();
    echo "<!DOCTYPE html><html><head>
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    </head><body>
    <script>
    Swal.fire({icon:'$type',title:'',text:'$message'})
        .then(()=>{ window.location='$redirect'; });
    </script></body></html>";
    exit;
}

// ── CSRF check ──
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
    alertAndRedirect('Invalid request. Please try again.', 'error');
}

// ── OTP must be verified ──
$email = filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL);

if (
    empty($_SESSION['otp_email_verified']) ||
    $_SESSION['otp_email_verified'] !== $email
) {
    alertAndRedirect('Email not verified. Please complete OTP verification.', 'error');
}

// ── Sanitize inputs ──
$first_name       = trim($_POST['first_name']);
$last_name        = trim($_POST['last_name']);
$password         = $_POST['password'];
$clinic_name      = trim($_POST['clinic_name']);
$clinic_email     = filter_var($_POST['clinic_email'], FILTER_SANITIZE_EMAIL);
$branch           = trim($_POST['branch'] ?? '');
$unit_floor       = trim($_POST['unit_floor'] ?? '');
$building_name    = trim($_POST['building_name'] ?? '');
$street_address   = trim($_POST['street_address'] ?? '');
$landmark         = trim($_POST['landmark'] ?? '');
$barangay         = trim($_POST['barangay'] ?? '');
$city             = trim($_POST['city'] ?? '');
$province         = 'Cavite'; // hard-coded server-side — never trust the hidden field alone
$zip_code         = trim($_POST['zip_code'] ?? '');
$contact          = trim($_POST['contact']);
$clinic_type      = $_POST['clinic_type'] ?? 'standalone';
$hospital_name    = trim($_POST['hospital_name'] ?? '');
$hospital_address = trim($_POST['hospital_address'] ?? '');
$offers_eye_surgery = ($_POST['offers_eye_surgery'] ?? 'no') === 'yes' ? 1 : 0;
$ip_address       = $_SERVER['REMOTE_ADDR'];

// Coordinates
$latitude  = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? floatval($_POST['latitude'])  : null;
$longitude = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? floatval($_POST['longitude']) : null;

// ── Server-side Cavite whitelist (defense in depth — the client JS check can be bypassed) ──
$cavite_cities = [
    'cavite city','bacoor','imus','dasmariñas','dasmarinas','tagaytay','general trias',
    'kawit','tanza','trece martires','naic','silang','amadeo','alfonso','carmona',
    'gen. mariano alvarez','magallanes','maragondon','mendez','ternate'
];
if (empty($street_address) || empty($barangay) || empty($city)) {
    alertAndRedirect('Please provide a complete address (street, barangay, and city).', 'error');
}
if (!preg_match('/^\d{4}$/', $zip_code)) {
    alertAndRedirect('Please provide a valid 4-digit ZIP code.', 'error');
}
if (!in_array(mb_strtolower($city), $cavite_cities, true)) {
    alertAndRedirect('Registration is limited to clinics located within Cavite province.', 'error');
}
// Cross-check the pinned coordinates really fall inside Cavite's bounding box.
// Cavite province roughly spans lat 14.05–14.52, lng 120.60–121.10.
if ($latitude === null || $longitude === null) {
    alertAndRedirect('Please use the location picker to confirm your clinic is within Cavite.', 'error');
}
if ($latitude < 14.00 || $latitude > 14.60 || $longitude < 120.55 || $longitude > 121.15) {
    alertAndRedirect('The pinned location is outside Cavite province. Please adjust the map pin.', 'error');
}

// Combined string kept for legacy display/email use
$address_parts = array_filter([
    $unit_floor, $building_name, $street_address, "Brgy. {$barangay}", $city, $province, $zip_code
]);
$address = implode(', ', $address_parts);

// ── Check duplicate email ──
$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->rowCount() > 0) {
    alertAndRedirect('Email already exists! Please use another email.', 'error');
}

$hashedPassword = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo->beginTransaction();

    // Validate hospital
    if ($clinic_type === 'hospital_based' && empty($hospital_name)) {
        throw new Exception('Hospital name is required for hospital-based clinics.');
    }

    // ── Insert Clinic ──
    $clinic_code = 'CLINIC' . time();
    $stmt = $pdo->prepare("
        INSERT INTO clinics
        (clinic_code, clinic_name, clinic_email, branch, address, unit_floor, building_name,
         street_address, landmark, barangay, city, province, postal_code, contact, clinic_type,
         hospital_name, hospital_address, offers_eye_surgery, latitude, longitude, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
    ");
    $stmt->execute([
        $clinic_code, $clinic_name, $clinic_email, $branch, $address, $unit_floor ?: null, $building_name ?: null,
        $street_address, $landmark ?: null, $barangay, $city, $province, $zip_code, $contact, $clinic_type,
        $hospital_name ?: null, $hospital_address ?: null, $offers_eye_surgery, $latitude, $longitude
    ]);
    $clinic_id = $pdo->lastInsertId();

    // ── Upload Clinic Logo ──
    $uploadDir = '../uploads/clinic_' . $clinic_id . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

    if (!isset($_FILES['clinic_logo']) || $_FILES['clinic_logo']['error'] != 0) {
        throw new Exception('Clinic logo is required.');
    }
    $ext           = pathinfo($_FILES['clinic_logo']['name'], PATHINFO_EXTENSION);
    $logoName      = 'clinic_logo_' . time() . '.' . $ext;
    move_uploaded_file($_FILES['clinic_logo']['tmp_name'], $uploadDir . $logoName);
    $logoPath      = 'clinic_' . $clinic_id . '/' . $logoName;

    $pdo->prepare("UPDATE clinics SET clinic_logo = ? WHERE id = ?")->execute([$logoPath, $clinic_id]);

    // ── Insert ClinicAdmin User (Active immediately — email already verified) ──
    $user_code = 'USR' . time();
    $stmt = $pdo->prepare("
        INSERT INTO users
        (clinic_id, user_code, first_name, last_name, email, password, role, status, email_verified_at)
        VALUES (?, ?, ?, ?, ?, ?, 'ClinicAdmin', 'Active', NOW())
    ");
    $stmt->execute([$clinic_id, $user_code, $first_name, $last_name, $email, $hashedPassword]);
    $user_id = $pdo->lastInsertId();

    // ── Activity Log ──
    $details = 'Clinic registration completed' . ($latitude && $longitude ? ' with GPS coordinates' : '');
    $pdo->prepare("
        INSERT INTO activity_logs (clinic_id, user_id, action, module, details, ip_address)
        VALUES (?, ?, 'REGISTER', 'Registration', ?, ?)
    ")->execute([$clinic_id, $user_id, $details, $ip_address]);

    $pdo->commit();

    // ── Auto-login: set session ──
    $_SESSION['user_id']     = $user_id;
    $_SESSION['clinic_id']   = $clinic_id;
    $_SESSION['role']        = 'ClinicAdmin';
    $_SESSION['first_name']  = $first_name;
    $_SESSION['last_name']   = $last_name;
    $_SESSION['clinic_name'] = $clinic_name;
    $_SESSION['clinic_logo'] = $logoPath;

    // Clean up OTP session
    unset($_SESSION['otp_email_verified'], $_SESSION['csrf_token']);

    // ── Send admin notification email ──
    try {
        $adminMail = new PHPMailer(true);
        $adminMail->isSMTP();
        $adminMail->Host       = 'smtp.gmail.com';
        $adminMail->SMTPAuth   = true;
        $adminMail->Username   = 'angelloricanmendoza27@gmail.com';
        $adminMail->Password   = 'tkyv vypr pxvm pfse';
        $adminMail->SMTPSecure = 'tls';
        $adminMail->Port       = 587;
        $adminMail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore');
        $adminMail->addAddress('admin@eyecore.com', 'Admin');
        $adminMail->isHTML(true);
        $adminMail->Subject = 'New Clinic Registration — ' . $clinic_name;
        $locInfo = $latitude && $longitude
            ? "<p><b>GPS Coordinates:</b> {$latitude}, {$longitude}</p>"
            : "<p><b>Location:</b> No coordinates captured</p>";
        $adminMail->Body = "
            <p>New clinic <b>{$clinic_name}</b> has registered. Please review and approve.</p>
            <p><b>Address:</b> {$address}</p>
            {$locInfo}
            <p><b>Admin Email:</b> {$email}</p>
        ";
        $adminMail->send();
    } catch (Exception $e) {
        error_log('Admin notification email failed: ' . $e->getMessage());
    }

    // ── Send welcome + instructions email to clinic admin ──
    try {
        $userMail = new PHPMailer(true);
        $userMail->isSMTP();
        $userMail->Host       = 'smtp.gmail.com';
        $userMail->SMTPAuth   = true;
        $userMail->Username   = 'angelloricanmendoza27@gmail.com';
        $userMail->Password   = 'tkyv vypr pxvm pfse';
        $userMail->SMTPSecure = 'tls';
        $userMail->Port       = 587;
        $userMail->setFrom('no-reply@eyecore.com', 'Eyecore Registration');
        $userMail->addAddress($email, $first_name . ' ' . $last_name);
        $userMail->isHTML(true);
        $userMail->Subject = '✅ Registration Submitted — ' . $clinic_name;
        $userMail->Body = "
            <div style='font-family:Arial,sans-serif;max-width:520px;'>
                <h3>Thank you, {$first_name}!</h3>
                <p>Your clinic registration is <b>pending approval</b>.</p>
                <p>Please submit the following documents to complete your verification:</p>
                <ul>
                    <li>BIR TIN / Tax Documents</li>
                    <li>Mayor's Permit / Business Permit</li>
                    <li>DTI / SEC Registration</li>
                    <li>Optometrist License</li>
                    <li>Barangay Clearance</li>
                    <li>Health Clearance</li>
                    <li>Insurance / Liability Coverage</li>
                </ul>
                <p>Log in to your Eyecore dashboard to upload your documents.</p>
                <p>— Eyecore Management System</p>
            </div>
        ";
        $userMail->send();
    } catch (Exception $e) {
        error_log('Welcome email failed: ' . $e->getMessage());
    }

    // ── Redirect to clinic_pending.php ──
    header('Location: ../views/clinic_pending.php');
    exit;

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    try {
        $pdo->prepare("
            INSERT INTO activity_logs (clinic_id, user_id, action, module, details, ip_address)
            VALUES (?, ?, 'ERROR', 'Registration', ?, ?)
        ")->execute([
            $clinic_id ?? null,
            $user_id   ?? null,
            'Registration failed: ' . $e->getMessage(),
            $ip_address
        ]);
    } catch (\Throwable $logErr) {
        error_log('Log error: ' . $logErr->getMessage());
    }

    error_log('Registration failed: ' . $e->getMessage());
    alertAndRedirect('Registration failed: ' . $e->getMessage(), 'error');
}