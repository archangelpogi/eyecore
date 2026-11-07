<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
include __DIR__ . '/../config/db.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require '../PHPMailer/PHPMailer.php';
require '../PHPMailer/SMTP.php';
require '../PHPMailer/Exception.php';

/* ───────────────────────── helpers ───────────────────────── */

class UserFacingException extends Exception {}

function alertAndRedirect(string $message, string $type = 'success', string $redirect = '../auth/register.php'): void {
    if (ob_get_length()) ob_clean();
    $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
    $msg = json_encode($message,  $flags);
    $typ = json_encode($type,     $flags);
    $red = json_encode($redirect, $flags);
    echo "<!DOCTYPE html><html><head><meta charset='UTF-8'>
    <script src='https://cdn.jsdelivr.net/npm/sweetalert2@11'></script>
    </head><body>
    <script>
    Swal.fire({icon:$typ,title:'',text:$msg}).then(()=>{ window.location=$red; });
    </script></body></html>";
    exit;
}

function pointInPolygon(float $lat, float $lng, array $poly): bool {
    $inside = false;
    $n = count($poly);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$yi, $xi] = $poly[$i];
        [$yj, $xj] = $poly[$j];
        if ((($yi > $lat) !== ($yj > $lat)) &&
            ($lng < ($xj - $xi) * ($lat - $yi) / ($yj - $yi) + $xi)) {
            $inside = !$inside;
        }
    }
    return $inside;
}

// ── Ibinabalik natin sa hardcoded SMTP (walang mail.php) ──
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

function validateLogo(array $file): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new UserFacingException('Clinic logo is required.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new UserFacingException('Clinic logo must be 2 MB or smaller.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new UserFacingException('Invalid file upload.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) {
        throw new UserFacingException('Clinic logo must be a JPG or PNG image.');
    }
    if (@getimagesize($file['tmp_name']) === false) {
        throw new UserFacingException('Clinic logo is not a valid image.');
    }
    return $allowed[$mime];
}

/* ───────────────────────── request checks ───────────────────────── */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    alertAndRedirect('Invalid request.', 'error');
}

$postedToken = $_POST['csrf_token'] ?? '';
if (!is_string($postedToken) || empty($_SESSION['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $postedToken)) {
    alertAndRedirect('Invalid request. Please try again.', 'error');
}

$email = trim($_POST['email'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    alertAndRedirect('Please enter a valid email address.', 'error');
}
if (empty($_SESSION['otp_email_verified']) ||
    strcasecmp($_SESSION['otp_email_verified'], $email) !== 0) {
    alertAndRedirect('Email not verified. Please complete OTP verification.', 'error');
}

/* ───────────────────────── inputs ───────────────────────── */

$first_name    = trim($_POST['first_name'] ?? '');
$last_name     = trim($_POST['last_name'] ?? '');
$password      = $_POST['password'] ?? '';
$confirm       = $_POST['confirm_password'] ?? '';
$clinic_name   = trim($_POST['clinic_name'] ?? '');
$clinic_email  = trim($_POST['clinic_email'] ?? '');
$branch        = trim($_POST['branch'] ?? '');
$unit_floor    = trim($_POST['unit_floor'] ?? '');
$building_name = trim($_POST['building_name'] ?? '');
$street_address= trim($_POST['street_address'] ?? '');
$landmark      = trim($_POST['landmark'] ?? '');
$barangay      = trim($_POST['barangay'] ?? '');
$city          = trim($_POST['city'] ?? '');
$province      = 'Cavite';
$zip_code      = trim($_POST['zip_code'] ?? '');
$ip_address    = $_SERVER['REMOTE_ADDR'];

if ($first_name === '' || $last_name === '' || $clinic_name === '') {
    alertAndRedirect('Please complete all required fields.', 'error');
}
if (mb_strlen($first_name) > 50 || mb_strlen($last_name) > 50 || mb_strlen($clinic_name) > 100) {
    alertAndRedirect('One or more fields are too long.', 'error');
}
if (!filter_var($clinic_email, FILTER_VALIDATE_EMAIL)) {
    alertAndRedirect('Please enter a valid clinic email address.', 'error');
}

if ($password !== $confirm) {
    alertAndRedirect('Passwords do not match.', 'error');
}
if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[@$!%*?&])[A-Za-z\d@$!%*?&]{8,}$/', $password)) {
    alertAndRedirect('Password needs 8+ characters with uppercase, lowercase, a number and a special character (@$!%*?&).', 'error');
}

$clinic_type = $_POST['clinic_type'] ?? '';
if (!in_array($clinic_type, ['standalone', 'hospital_based'], true)) {
    alertAndRedirect('Please select a valid clinic type.', 'error');
}
$hospital_name      = trim($_POST['hospital_name'] ?? '');
$hospital_type      = trim($_POST['hospital_type'] ?? '');
$offers_eye_surgery = 0; 

if ($clinic_type === 'hospital_based' && $hospital_name === '') {
    alertAndRedirect('Hospital name is required for hospital-based clinics.', 'error');
}
if ($clinic_type === 'standalone') {
    $hospital_name = '';
    $hospital_type = '';
}

/* ───────────────────────── contact number ───────────────────────── */

$contact_raw    = trim($_POST['contact'] ?? '');
$contact_digits = preg_replace('/[\s\-\(\)]/', '', $contact_raw);

$isMobilePH   = preg_match('/^09\d{9}$/', $contact_digits);
$isIntlPH     = preg_match('/^\+639\d{9}$/', $contact_digits);
$isLandlinePH = preg_match('/^0[2-9]\d{8,9}$/', $contact_digits);

if (!$isMobilePH && !$isIntlPH && !$isLandlinePH) {
    alertAndRedirect('Please enter a valid Philippine contact number (e.g. 09XX-XXX-XXXX or (046) XXX-XXXX).', 'error');
}

if ($isMobilePH) {
    $contact = substr($contact_digits, 0, 4) . '-' . substr($contact_digits, 4, 3) . '-' . substr($contact_digits, 7, 4);
} elseif ($isIntlPH) {
    $contact = '+63 ' . substr($contact_digits, 3, 3) . ' ' . substr($contact_digits, 6, 3) . ' ' . substr($contact_digits, 9, 4);
} else {
    if (strlen($contact_digits) === 10) {
        $contact = '(' . substr($contact_digits, 0, 3) . ') ' . substr($contact_digits, 3, 3) . '-' . substr($contact_digits, 6, 4);
    } else {
        $contact = '(' . substr($contact_digits, 0, 3) . ') ' . substr($contact_digits, 3, 4) . '-' . substr($contact_digits, 7, 4);
    }
}

/* ───────────────────────── location ───────────────────────── */

$cavite_cities = [
    'cavite city','bacoor','imus','dasmariñas','dasmarinas','tagaytay','general trias',
    'kawit','tanza','trece martires','naic','silang','amadeo','alfonso','carmona',
    'gen. mariano alvarez','magallanes','maragondon','mendez','ternate'
];
if ($street_address === '' || $barangay === '' || $city === '') {
    alertAndRedirect('Please provide a complete address (street, barangay, and city).', 'error');
}
if (!preg_match('/^\d{4}$/', $zip_code)) {
    alertAndRedirect('Please provide a valid 4-digit ZIP code.', 'error');
}
if (!in_array(mb_strtolower($city), $cavite_cities, true)) {
    alertAndRedirect('Registration is limited to clinics located within Cavite province.', 'error');
}

$latitude  = (isset($_POST['latitude'])  && $_POST['latitude']  !== '') ? (float)$_POST['latitude']  : null;
$longitude = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? (float)$_POST['longitude'] : null;
if ($latitude === null || $longitude === null) {
    alertAndRedirect('Please use the location picker to confirm your clinic is within Cavite.', 'error');
}

$caviteLand = [
    [14.495,120.905],[14.465,120.970],[14.400,121.000],[14.330,121.060],
    [14.250,121.070],[14.170,121.030],[14.100,121.000],[14.080,120.930],
    [14.040,120.850],[14.070,120.780],[14.150,120.700],[14.200,120.620],
    [14.270,120.620],[14.300,120.720],[14.340,120.770],[14.390,120.835],
    [14.420,120.850],[14.460,120.885]
];
if (!pointInPolygon($latitude, $longitude, $caviteLand)) {
    alertAndRedirect('The pinned location is not on land within Cavite. Please adjust the map pin.', 'error');
}

$address = implode(', ', array_filter([
    $unit_floor, $building_name, $street_address, "Brgy. {$barangay}", $city, $province, $zip_code
]));

/* ───────────────────────── pre-flight checks ───────────────────────── */

try {
    $logoExt = validateLogo($_FILES['clinic_logo'] ?? []);
} catch (UserFacingException $e) {
    alertAndRedirect($e->getMessage(), 'error');
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    alertAndRedirect('Email already exists! Please use another email.', 'error');
}

$hashedPassword = password_hash($password, PASSWORD_DEFAULT);
$uploadDir = null;

/* ───────────────────────── save ───────────────────────── */

try {
    $pdo->beginTransaction();

    $clinic_code = 'CLINIC' . strtoupper(bin2hex(random_bytes(5)));
    $stmt = $pdo->prepare("
        INSERT INTO clinics
        (clinic_code, clinic_name, clinic_email, branch, address, unit_floor, building_name,
         street_address, landmark, barangay, city, province, postal_code, contact, clinic_type,
         hospital_name, hospital_type, latitude, longitude, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending')
    ");
    $stmt->execute([
        $clinic_code, $clinic_name, $clinic_email, $branch, $address, $unit_floor ?: null, $building_name ?: null,
        $street_address, $landmark ?: null, $barangay, $city, $province, $zip_code, $contact, $clinic_type,
        $hospital_name ?: null, $hospital_type ?: null, $latitude, $longitude
    ]);
    $clinic_id = (int)$pdo->lastInsertId();

    $uploadDir = __DIR__ . '/../uploads/clinic_' . $clinic_id . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException('Could not create upload directory.');
    }
    $logoName = 'clinic_logo_' . bin2hex(random_bytes(8)) . '.' . $logoExt;
    if (!move_uploaded_file($_FILES['clinic_logo']['tmp_name'], $uploadDir . $logoName)) {
        throw new RuntimeException('Could not save the uploaded logo.');
    }
    $logoPath = 'clinic_' . $clinic_id . '/' . $logoName;
    $pdo->prepare("UPDATE clinics SET clinic_logo = ? WHERE id = ?")->execute([$logoPath, $clinic_id]);

    $user_code = 'USR' . strtoupper(bin2hex(random_bytes(5)));
    $stmt = $pdo->prepare("
        INSERT INTO users
        (clinic_id, user_code, first_name, last_name, email, password, role, status, email_verified_at)
        VALUES (?, ?, ?, ?, ?, ?, 'ClinicAdmin', 'Active', NOW())
    ");
    $stmt->execute([$clinic_id, $user_code, $first_name, $last_name, $email, $hashedPassword]);
    $user_id = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO activity_logs (clinic_id, user_id, action, module, details, ip_address)
        VALUES (?, ?, 'REGISTER', 'Registration', ?, ?)
    ")->execute([$clinic_id, $user_id, 'Clinic registration completed with GPS coordinates', $ip_address]);

    $pdo->commit();

    session_regenerate_id(true);
    $_SESSION['user_id']     = $user_id;
    $_SESSION['clinic_id']   = $clinic_id;
    $_SESSION['role']        = 'ClinicAdmin';
    $_SESSION['first_name']  = $first_name;
    $_SESSION['last_name']   = $last_name;
    $_SESSION['clinic_name'] = $clinic_name;
    $_SESSION['clinic_logo'] = $logoPath;
    unset($_SESSION['otp_email_verified'], $_SESSION['csrf_token']);

    // ── DITO ANG FIX: Tinanggal ang $cfg at ginamit ang hardcoded SMTP ──
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // Admin notification
    try {
        $m = makeMailer();
        $m->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore');
        $m->addAddress('admin@eyecore.com', 'Admin');
        $m->isHTML(true);
        $m->Subject = 'New Clinic Registration — ' . $clinic_name;
        $m->Body = "
            <p>New clinic <b>{$h($clinic_name)}</b> has registered. Please review and approve.</p>
            <p><b>Address:</b> {$h($address)}</p>
            <p><b>Contact:</b> {$h($contact)}</p>
            <p><b>GPS Coordinates:</b> {$h($latitude)}, {$h($longitude)}</p>
            <p><b>Admin Email:</b> {$h($email)}</p>
        ";
        $m->send();
    } catch (MailException $e) {
        error_log('Admin notification email failed: ' . $e->getMessage());
    }

    // Welcome email
    try {
        $m = makeMailer();
        $m->setFrom('no-reply@eyecore.com', 'Eyecore Registration');
        $m->addAddress($email, $first_name . ' ' . $last_name);
        $m->isHTML(true);
        $m->Subject = 'Registration Submitted — ' . $clinic_name;
        $m->Body = "
            <div style='font-family:Arial,sans-serif;max-width:520px;'>
                <h3>Thank you, {$h($first_name)}!</h3>
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
        $m->send();
    } catch (MailException $e) {
        error_log('Welcome email failed: ' . $e->getMessage());
    }

    header('Location: ../views/clinic_pending.php');
    exit;

} catch (\Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();

    if ($uploadDir && is_dir($uploadDir)) {
        foreach (glob($uploadDir . '*') ?: [] as $f) @unlink($f);
        @rmdir($uploadDir);
    }

    error_log('Registration failed: ' . $e->getMessage());

    $userMsg = ($e instanceof UserFacingException)
        ? $e->getMessage()
        : 'Registration failed due to a server error. Please try again later.';
    alertAndRedirect($userMsg, 'error');
}