<?php
// Disable error display for AJAX to prevent JSON corruption
if (isset($_POST['action'])) {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    if (isset($_POST['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Please login first']);
        exit();
    }
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$exam_id) {
    die('Invalid examination ID.');
}

// Get the optical record with user permission check
$query = mysqli_query($conn, "
    SELECT o.*, 
           c.name as clinic_name, 
           c.address as clinic_address, 
           c.contact as clinic_contact,
           c.clinic_email,
           p.first_name, 
           p.last_name, 
           p.age, 
           p.gender,
           a.user_id as appointment_user_id
    FROM optical_records o
    LEFT JOIN clinics c ON o.clinic_id = c.id
    LEFT JOIN patients p ON o.patient_id = p.id
    LEFT JOIN appointments a ON o.appointment_id = a.id
    WHERE o.id = $exam_id
");

$exam = mysqli_fetch_assoc($query);

if (!$exam) {
    die('Record not found.');
}

// Permission check - simplified for now
$has_access = false;

// Check if user owns the appointment
if ($exam['appointment_user_id'] == $user_id) {
    $has_access = true;
}

// If no access yet, check via patients table
if (!$has_access && $exam['patient_id'] > 0) {
    $check_patient = mysqli_query($conn, "
        SELECT id FROM patients 
        WHERE id = {$exam['patient_id']} 
        AND (user_id = $user_id OR email = '{$_SESSION['user_email']}')
        LIMIT 1
    ");
    if (mysqli_num_rows($check_patient) > 0) {
        $has_access = true;
    }
}

if (!$has_access) {
    die('You do not have permission to download this prescription.');
}

// Set PDF headers
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="prescription_' . $exam['id'] . '.pdf"');

// Prepare variables
$clinic_name = htmlspecialchars($exam['clinic_name'] ?? 'EYECORE Optical Clinic');
$clinic_address = htmlspecialchars($exam['clinic_address'] ?? '');
$clinic_contact = htmlspecialchars($exam['clinic_contact'] ?? '');
$clinic_email = htmlspecialchars($exam['clinic_email'] ?? 'info@eyecore.com');

$patient_name = htmlspecialchars(($exam['first_name'] ?? '') . ' ' . ($exam['last_name'] ?? ''));
$patient_age = $exam['age'] ?? '—';
$patient_gender = $exam['gender'] ?? '—';
$exam_date = date('F d, Y', strtotime($exam['examination_date']));
$record_code = htmlspecialchars($exam['record_code'] ?? 'REC-' . $exam['id']);
$optometrist = htmlspecialchars($exam['optometrist'] ?? 'EYECORE Optometrist');

$od_sph = $exam['od_sph'] ?? '—';
$od_cyl = $exam['od_cyl'] ?? '—';
$od_axis = !empty($exam['od_axis']) ? $exam['od_axis'] . '°' : '—';
$od_add = $exam['od_add'] ?? '—';
$od_va = $exam['od_va'] ?? '—';

$os_sph = $exam['os_sph'] ?? '—';
$os_cyl = $exam['os_cyl'] ?? '—';
$os_axis = !empty($exam['os_axis']) ? $exam['os_axis'] . '°' : '—';
$os_add = $exam['os_add'] ?? '—';
$os_va = $exam['os_va'] ?? '—';

$pd = $exam['pd'] ?? '—';
$notes = nl2br(htmlspecialchars($exam['notes'] ?? ''));
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Prescription - <?php echo $record_code; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: white; padding: 40px; }
        .prescription-container { max-width: 800px; margin: 0 auto; background: white; border: 1px solid #ddd; }
        .header { background: #00B761; color: white; padding: 30px; text-align: center; }
        .header h1 { font-size: 28px; margin-bottom: 5px; }
        .header p { font-size: 12px; opacity: 0.9; }
        .content { padding: 30px; }
        .info-row { display: flex; margin-bottom: 10px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .info-label { width: 130px; font-weight: bold; color: #555; }
        .info-value { flex: 1; color: #333; }
        .rx-table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        .rx-table th { background: #f5f5f5; padding: 12px; text-align: center; border: 1px solid #ddd; }
        .rx-table td { padding: 10px; text-align: center; border: 1px solid #ddd; }
        .rx-table td:first-child { font-weight: bold; background: #f9f9f9; }
        .pd-box { background: #f5f5f5; padding: 15px; text-align: center; margin: 20px 0; border-radius: 8px; }
        .pd-box span { font-size: 24px; font-weight: bold; color: #00B761; }
        .notes-box { background: #fef9e6; padding: 15px; margin: 20px 0; border-left: 4px solid #ffc107; }
        .footer { background: #f5f5f5; padding: 20px; text-align: center; font-size: 11px; color: #777; }
        @media print { body { padding: 0; } .prescription-container { box-shadow: none; border: none; } .header { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
    </style>
</head>
<body>
    <div class="prescription-container">
        <div class="header">
            <h1><?php echo $clinic_name; ?></h1>
            <p><?php echo $clinic_address; ?></p>
            <p>📞 <?php echo $clinic_contact; ?> | ✉️ <?php echo $clinic_email; ?></p>
        </div>
        
        <div class="content">
            <div class="info-row">
                <div class="info-label">Patient Name:</div>
                <div class="info-value"><?php echo $patient_name; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Age / Gender:</div>
                <div class="info-value"><?php echo $patient_age; ?> yrs / <?php echo $patient_gender; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Examination Date:</div>
                <div class="info-value"><?php echo $exam_date; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Record #:</div>
                <div class="info-value"><?php echo $record_code; ?></div>
            </div>
            <div class="info-row">
                <div class="info-label">Optometrist:</div>
                <div class="info-value">Dr. <?php echo $optometrist; ?></div>
            </div>
            
            <h3 style="margin: 25px 0 15px 0; color: #00B761;">EYE PRESCRIPTION</h3>
            
            <table class="rx-table">
                <thead>
                    <tr><th></th><th>SPH</th><th>CYL</th><th>AXIS</th><th>ADD</th><th>VA</th></tr>
                </thead>
                <tbody>
                    <tr><td><strong>RIGHT (OD)</strong></td><td><?php echo $od_sph; ?></td><td><?php echo $od_cyl; ?></td><td><?php echo $od_axis; ?></td><td><?php echo $od_add; ?></td><td><?php echo $od_va; ?></td></tr>
                    <tr><td><strong>LEFT (OS)</strong></td><td><?php echo $os_sph; ?></td><td><?php echo $os_cyl; ?></td><td><?php echo $os_axis; ?></td><td><?php echo $os_add; ?></td><td><?php echo $os_va; ?></td></tr>
                </tbody>
            </table>
            
            <div class="pd-box">
                <strong>PUPILLARY DISTANCE (PD)</strong><br>
                <span><?php echo $pd; ?> mm</span>
            </div>
            
            <?php if (!empty($notes)): ?>
            <div class="notes-box">
                <strong>📋 CLINICAL NOTES:</strong><br>
                <?php echo $notes; ?>
            </div>
            <?php endif; ?>
            
            <div style="margin-top: 30px; text-align: center;">
                <div style="border-top: 1px dashed #ccc; margin: 20px 0;"></div>
                <p style="font-size: 11px; color: #999;">
                    This prescription is valid for 1 year from the date of examination.<br>
                    Generated on <?php echo date('F d, Y'); ?>
                </p>
            </div>
        </div>
        
        <div class="footer">
            <p>© <?php echo date('Y'); ?> <?php echo $clinic_name; ?> - All Rights Reserved</p>
        </div>
    </div>
    
    <script>
        window.onload = function() {
            window.print();
        };
    </script>
</body>
</html>