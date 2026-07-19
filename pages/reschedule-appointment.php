<?php
ob_start();

include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    ob_end_clean();
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$appointment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// ============================================
// HANDLE RESCHEDULE SUBMIT
// ============================================
if (isset($_POST['reschedule_appointment'])) {
    ob_end_clean();
    header('Content-Type: application/json');

    $reschedule_id   = (int)$_POST['appointment_id'];
    $new_date        = mysqli_real_escape_string($conn, $_POST['new_date'] ?? '');
    $new_time        = mysqli_real_escape_string($conn, $_POST['new_time'] ?? '');
    $new_doctor_id   = isset($_POST['doctor_id']) && $_POST['doctor_id'] !== '' ? (int)$_POST['doctor_id'] : null;
    $reschedule_note = mysqli_real_escape_string($conn, $_POST['reschedule_note'] ?? '');

    // Validate
    if (empty($new_date) || empty($new_time)) {
        echo json_encode(['success' => false, 'message' => 'Please select a new date and time.']);
        exit();
    }

    $new_datetime = new DateTime($new_date . ' ' . $new_time);
    $now = new DateTime();

    if ($new_datetime <= $now) {
        echo json_encode(['success' => false, 'message' => 'Please choose a future date and time.']);
        exit();
    }

    // Check appointment ownership & eligibility
    $check = mysqli_query($conn, "
        SELECT * FROM appointments
        WHERE id = $reschedule_id AND user_id = $user_id
        AND status IN ('pending', 'confirmed', 'paid')
    ");

    if (mysqli_num_rows($check) === 0) {
        echo json_encode(['success' => false, 'message' => 'Appointment not found or cannot be rescheduled.']);
        exit();
    }

    $appt = mysqli_fetch_assoc($check);

    // Check if new slot conflicts with existing confirmed appointments for this clinic
    $conflict = mysqli_query($conn, "
        SELECT id FROM appointments
        WHERE clinic_id = {$appt['clinic_id']}
        AND appointment_date = '$new_date'
        AND appointment_time = '$new_time'
        AND status IN ('pending', 'confirmed', 'paid')
        AND id != $reschedule_id
    ");

    if (mysqli_num_rows($conflict) > 0) {
        echo json_encode(['success' => false, 'message' => 'That time slot is already taken. Please choose another.']);
        exit();
    }

    $doctor_sql = $new_doctor_id ? "doctor_id = $new_doctor_id," : "";
    $notes_update = !empty($reschedule_note) ? "notes = '$reschedule_note'," : "";

    $update = mysqli_query($conn, "
        UPDATE appointments
        SET appointment_date = '$new_date',
            appointment_time = '$new_time',
            $doctor_sql
            $notes_update
            updated_at = NOW()
        WHERE id = $reschedule_id AND user_id = $user_id
    ");

    if ($update) {
        echo json_encode(['success' => true, 'message' => 'Appointment rescheduled successfully!']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit();
}

// ============================================
// FETCH APPOINTMENT
// ============================================
$query = mysqli_query($conn, "
    SELECT a.*,
           a.downpayment_amount,
           c.clinic_name, c.address, c.contact, c.hours, c.city,
           c.logo, c.clinic_image, c.cover_photo,
           c.reschedule_policy, c.penalty_amount,
           u.first_name, u.last_name,
           p.name as product_name, p.category as product_category,
           d.id as doctor_id, d.name as doctor_name, d.specialty as doctor_specialty
    FROM appointments a
    JOIN clinics c ON a.clinic_id = c.id
    JOIN users u ON a.user_id = u.id
    LEFT JOIN products p ON a.product_id = p.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    WHERE a.id = $appointment_id AND a.user_id = $user_id
    AND a.status IN ('pending', 'confirmed', 'paid')
");

$appointment = mysqli_fetch_assoc($query);

if (!$appointment) {
    header('Location: my-appointments.php');
    exit();
}

// Fetch available doctors for this clinic
$doctors_query = mysqli_query($conn, "
    SELECT id, name, specialty FROM doctors
    WHERE clinic_id = {$appointment['clinic_id']}
    ORDER BY name ASC
");
$doctors = [];
while ($doc = mysqli_fetch_assoc($doctors_query)) {
    $doctors[] = $doc;
}

// Get available time slots (you can customize these)
$time_slots = [
    '08:00:00' => '8:00 AM',
    '08:30:00' => '8:30 AM',
    '09:00:00' => '9:00 AM',
    '09:30:00' => '9:30 AM',
    '10:00:00' => '10:00 AM',
    '10:30:00' => '10:30 AM',
    '11:00:00' => '11:00 AM',
    '11:30:00' => '11:30 AM',
    '13:00:00' => '1:00 PM',
    '13:30:00' => '1:30 PM',
    '14:00:00' => '2:00 PM',
    '14:30:00' => '2:30 PM',
    '15:00:00' => '3:00 PM',
    '15:30:00' => '3:30 PM',
    '16:00:00' => '4:00 PM',
    '16:30:00' => '4:30 PM',
];

// Get booked slots for this clinic (next 60 days) - ITO LANG ANG BINAGO KO
$booked_slots = [];
$booked_query = mysqli_query($conn, "
    SELECT appointment_date, appointment_time FROM appointments
    WHERE clinic_id = {$appointment['clinic_id']}
    AND status IN ('pending','confirmed','paid')
    AND id != $appointment_id
    AND appointment_date >= CURDATE()
    AND appointment_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
");
while ($bs = mysqli_fetch_assoc($booked_query)) {
    $booked_slots[$bs['appointment_date']][] = $bs['appointment_time'];
}

$current_date = new DateTime($appointment['appointment_date']);
$formatted_current_date = $current_date->format('F j, Y');
$formatted_current_time = isset($appointment['appointment_time'])
    ? date('g:i A', strtotime($appointment['appointment_time']))
    : $current_date->format('g:i A');

if (!function_exists('getThemeClass')) {
    function getThemeClass() {
        return isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'theme-dark' : '';
    }
}

if (!function_exists('getClinicImg')) {
    function getClinicImg($c) {
        if (!empty($c['cover_photo']))  return '/eyecore/assets/images/clinic-covers/' . $c['cover_photo'];
        if (!empty($c['clinic_image'])) return '/eyecore/assets/images/clinic-images/' . $c['clinic_image'];
        if (!empty($c['logo']))         return '/eyecore/assets/images/clinic-logos/' . $c['logo'];
        return null;
    }
}

$unread_count = 0;
if (function_exists('getUnreadNotificationCount')) {
    $unread_count = getUnreadNotificationCount($user_id);
}
$recent_notifications = [];
if (function_exists('getRecentNotifications')) {
    $recent_notifications = getRecentNotifications($user_id);
}

$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

$pending_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id AND status = 'pending'");
$pending = mysqli_fetch_assoc($pending_q)['total'] ?? 0;

// Variables required by navbar.php
$points_query = mysqli_query($conn, "SELECT SUM(points) as total_points FROM user_rewards WHERE user_id = $user_id");
$points_row = mysqli_fetch_assoc($points_query);
$total_points = $points_row['total_points'] ?: 0;

$bookings_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM appointments WHERE user_id = $user_id");
$bookings_row = mysqli_fetch_assoc($bookings_query);
$total_bookings = $bookings_row['total'] ?: 0;

$reservation_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM reservations WHERE user_id = $user_id AND status IN ('pending', 'confirmed')");
$reservation_row = mysqli_fetch_assoc($reservation_query);
$reservation_count = $reservation_row['total'] ?? 0;

$sale_count_query = mysqli_query($conn, "SELECT COUNT(*) as total FROM products WHERE is_on_sale = 1 AND sale_end >= CURDATE()");
$sale_count = mysqli_fetch_assoc($sale_count_query)['total'] ?? 0;

include '../includes/navbar.php';
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Reschedule Appointment - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s;
        }

        :root {
            --primary: #00B761;
            --primary-dark: #00874A;
            --primary-light: #E3FCE9;
            --primary-gradient: linear-gradient(135deg,#00B761,#00A86B);
            --secondary: #FF8C42;
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --border-color: #E5E7EB;
            --border-light: #F3F4F6;
            --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,0.08);
            --radius-sm: 10px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-full: 999px;
            --danger: #EF4444;
            --warning: #F59E0B;
        }

        .theme-dark {
            --primary: #00E676;
            --primary-dark: #00C853;
            --primary-light: #0D2818;
            --bg-primary: #0D0D0D;
            --bg-secondary: #161616;
            --text-primary: #F9FAFB;
            --text-secondary: #9CA3AF;
            --text-muted: #6B7280;
            --border-color: #2A2A2A;
            --border-light: #222222;
        }

        .main-content {
            max-width: 860px;
            margin: 0 auto;
            padding: 28px 40px;
        }

        @media (max-width: 768px) {
            .main-content { padding: 18px 16px 100px; }
        }

        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 24px;
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }

        .page-title h1 { font-size: 24px; font-weight: 700; }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .back-btn:hover { background: var(--primary); color: white; border-color: var(--primary); }

        .current-banner {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 20px 25px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 18px;
            flex-wrap: wrap;
        }

        .current-banner .clinic-avatar {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-md);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 24px;
            overflow: hidden;
            flex-shrink: 0;
        }

        .current-banner .clinic-avatar img {
            width: 100%; height: 100%; object-fit: cover;
        }

        .current-banner-info { flex: 1; min-width: 0; }

        .current-banner-info h3 {
            font-size: 17px;
            font-weight: 700;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .current-banner-meta {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .current-banner-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .current-banner-meta i { color: var(--primary); font-size: 12px; }

        .current-banner-badge {
            padding: 6px 16px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            background: #FFF3E0;
            color: #F57C00;
            white-space: nowrap;
        }

        .policy-card {
            background: #FFFBEB;
            border: 1px solid #FDE68A;
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 25px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .theme-dark .policy-card {
            background: #1C1600;
            border-color: #3D2E00;
        }

        .policy-card i {
            color: #F59E0B;
            font-size: 18px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .policy-card div { flex: 1; }

        .policy-card strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #92400E;
            margin-bottom: 4px;
        }

        .theme-dark .policy-card strong { color: #FCD34D; }

        .policy-card p {
            font-size: 13px;
            color: #78350F;
            line-height: 1.5;
        }

        .theme-dark .policy-card p { color: #A16207; }

        .form-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-lg);
            padding: 28px;
            margin-bottom: 25px;
            box-shadow: var(--shadow-sm);
        }

        .form-card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border-light);
        }

        .form-card-header i {
            width: 40px;
            height: 40px;
            background: var(--primary-light);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 17px;
            flex-shrink: 0;
        }

        .form-card-header h2 { font-size: 17px; font-weight: 700; }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-group label span.required { color: var(--danger); margin-left: 3px; }

        .form-control {
            width: 100%;
            padding: 13px 16px;
            background: var(--bg-primary);
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            font-family: inherit;
            font-size: 15px;
            color: var(--text-primary);
            transition: all 0.2s;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,183,97,0.12);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 90px;
        }

        .date-hint {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        .time-slots-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 10px;
            margin-top: 4px;
        }

        .time-slot {
            padding: 11px 8px;
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            text-align: center;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            background: var(--bg-primary);
            color: var(--text-primary);
            user-select: none;
        }

        .time-slot:hover:not(.booked) {
            border-color: var(--primary);
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        .time-slot.selected {
            background: var(--primary-gradient);
            border-color: var(--primary);
            color: white;
        }

        .time-slot.booked {
            background: var(--border-light);
            color: var(--text-muted);
            border-color: var(--border-light);
            cursor: not-allowed;
            text-decoration: line-through;
            font-weight: 400;
        }

        .slots-legend {
            display: flex;
            gap: 16px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        .legend-dot {
            width: 12px;
            height: 12px;
            border-radius: 3px;
            border: 1.5px solid var(--border-color);
            background: var(--bg-primary);
        }

        .legend-dot.available { border-color: var(--border-color); }
        .legend-dot.selected-d { background: var(--primary); border-color: var(--primary); }
        .legend-dot.booked-d { background: var(--border-light); border-color: var(--border-light); }

        .doctor-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 12px;
        }

        .doctor-card {
            padding: 14px;
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.2s;
            background: var(--bg-primary);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .doctor-card:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .doctor-card.selected {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .doctor-avatar {
            width: 40px;
            height: 40px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 16px;
            flex-shrink: 0;
        }

        .doctor-card-info { flex: 1; min-width: 0; }

        .doctor-card-name {
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .doctor-card-spec {
            font-size: 11px;
            color: var(--text-muted);
        }

        .summary-card {
            background: var(--primary-light);
            border: 1.5px solid var(--primary);
            border-radius: var(--radius-lg);
            padding: 22px 25px;
            margin-bottom: 25px;
        }

        .summary-card h3 {
            font-size: 15px;
            font-weight: 700;
            color: var(--primary-dark);
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,183,97,0.15);
            font-size: 14px;
        }

        .summary-row:last-child { border-bottom: none; }

        .summary-row .label { color: var(--text-secondary); }
        .summary-row .value { font-weight: 600; color: var(--text-primary); }
        .summary-row .value.highlight { color: var(--primary-dark); font-size: 15px; }

        .action-bar {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .btn {
            padding: 13px 28px;
            border-radius: var(--radius-full);
            font-size: 15px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            transition: all 0.3s;
            border: none;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0,183,97,0.3);
        }

        .btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--bg-secondary);
            border: 1.5px solid var(--border-color);
            color: var(--text-secondary);
        }

        .btn-secondary:hover { background: var(--danger); color: white; border-color: var(--danger); }

        .steps {
            display: flex;
            align-items: center;
            gap: 0;
            margin-bottom: 28px;
        }

        .step {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            position: relative;
        }

        .step-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            font-weight: 700;
            border: 2px solid var(--border-color);
            background: var(--bg-secondary);
            color: var(--text-muted);
            transition: all 0.3s;
            flex-shrink: 0;
            z-index: 1;
        }

        .step.active .step-circle {
            background: var(--primary-gradient);
            border-color: var(--primary);
            color: white;
        }

        .step.done .step-circle {
            background: var(--primary-light);
            border-color: var(--primary);
            color: var(--primary);
        }

        .step-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            white-space: nowrap;
        }

        .step.active .step-label { color: var(--primary-dark); }
        .step.done .step-label { color: var(--primary); }

        .step-line {
            flex: 1;
            height: 2px;
            background: var(--border-color);
            margin: 0 8px;
        }

        .step-line.done { background: var(--primary); }

        @media (max-width: 480px) {
            .step-label { display: none; }
            .steps { gap: 4px; }
        }
    </style>
</head>
<body>
<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <i class="fas fa-calendar-alt"></i>
            <h1>Reschedule Appointment</h1>
        </div>
        <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Details
        </a>
    </div>

    <div class="steps">
        <div class="step active" id="step-indicator-1">
            <div class="step-circle">1</div>
            <span class="step-label">Choose Date</span>
        </div>
        <div class="step-line" id="line-1-2"></div>
        <div class="step" id="step-indicator-2">
            <div class="step-circle">2</div>
            <span class="step-label">Choose Time</span>
        </div>
        <div class="step-line" id="line-2-3"></div>
        <div class="step" id="step-indicator-3">
            <div class="step-circle">3</div>
            <span class="step-label">Confirm</span>
        </div>
    </div>

    <div class="current-banner">
        <?php
        $clinic_img = getClinicImg($appointment);
        if ($clinic_img): ?>
            <div class="clinic-avatar">
                <img src="<?php echo htmlspecialchars($clinic_img); ?>"
                     alt="<?php echo htmlspecialchars($appointment['clinic_name']); ?>"
                     onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-eye\'></i>'">
            </div>
        <?php else: ?>
            <div class="clinic-avatar"><i class="fas fa-eye"></i></div>
        <?php endif; ?>

        <div class="current-banner-info">
            <h3><?php echo htmlspecialchars($appointment['clinic_name']); ?></h3>
            <div class="current-banner-meta">
                <span><i class="fas fa-calendar-day"></i> <?php echo $formatted_current_date; ?></span>
                <span><i class="fas fa-clock"></i> <?php echo $formatted_current_time; ?></span>
                <?php if (!empty($appointment['doctor_name'])): ?>
                <span><i class="fas fa-user-md"></i> Dr. <?php echo htmlspecialchars($appointment['doctor_name']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="current-banner-badge">Current Schedule</div>
    </div>

    <?php if (!empty($appointment['reschedule_policy'])): ?>
    <div class="policy-card">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Reschedule Policy</strong>
            <p><?php echo htmlspecialchars($appointment['reschedule_policy']); ?></p>
        </div>
    </div>
    <?php else: ?>
    <div class="policy-card">
        <i class="fas fa-info-circle"></i>
        <div>
            <strong>Reschedule Policy</strong>
            <p>You may reschedule your appointment at least 24 hours before the scheduled time. Please contact the clinic directly for same-day reschedule requests.</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-card">
        <div class="form-card-header">
            <i class="fas fa-calendar-day"></i>
            <h2>Select New Date</h2>
        </div>
        <div class="form-group">
            <label>New Appointment Date <span class="required">*</span></label>
            <input type="date"
                   id="new_date"
                   class="form-control"
                   min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                   max="<?php echo date('Y-m-d', strtotime('+60 days')); ?>"
                   value=""
                   onchange="onDateChange(this.value)">
            <div class="date-hint">
                <i class="fas fa-info-circle"></i>
                You can book up to 60 days in advance. Same-day reschedule not allowed.
            </div>
        </div>
    </div>

    <div class="form-card" id="timeSlotsCard" style="display:none;">
        <div class="form-card-header">
            <i class="fas fa-clock"></i>
            <h2>Select New Time</h2>
        </div>
        <div id="timeSlotsWrapper">
            <div class="time-slots-grid">
                <div class="slot-loading"><i class="fas fa-spinner fa-spin"></i> Loading available slots...</div>
            </div>
        </div>
        <div class="slots-legend">
            <div class="legend-item"><div class="legend-dot available"></div> Available</div>
            <div class="legend-item"><div class="legend-dot selected-d"></div> Selected</div>
            <div class="legend-item"><div class="legend-dot booked-d"></div> Taken</div>
        </div>
        <input type="hidden" id="selected_time" value="">
    </div>

    <?php if (!empty($doctors)): ?>
    <div class="form-card" id="doctorCard" style="display:none;">
        <div class="form-card-header">
            <i class="fas fa-user-md"></i>
            <h2>Select Doctor <span style="font-size:13px; font-weight:400; color:var(--text-muted);">(optional)</span></h2>
        </div>
        <div class="doctor-grid">
            <?php foreach ($doctors as $doc): ?>
            <div class="doctor-card <?php echo ($appointment['doctor_id'] == $doc['id']) ? 'selected' : ''; ?>"
                 data-id="<?php echo $doc['id']; ?>"
                 onclick="selectDoctor(this, <?php echo $doc['id']; ?>)">
                <div class="doctor-avatar"><i class="fas fa-user-md"></i></div>
                <div class="doctor-card-info">
                    <div class="doctor-card-name">Dr. <?php echo htmlspecialchars($doc['name']); ?></div>
                    <div class="doctor-card-spec"><?php echo htmlspecialchars($doc['specialty'] ?? ''); ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <input type="hidden" id="selected_doctor" value="<?php echo $appointment['doctor_id'] ?? ''; ?>">
    </div>
    <?php endif; ?>

    <div class="form-card" id="noteCard" style="display:none;">
        <div class="form-card-header">
            <i class="fas fa-sticky-note"></i>
            <h2>Reason for Rescheduling <span style="font-size:13px; font-weight:400; color:var(--text-muted);">(optional)</span></h2>
        </div>
        <div class="form-group" style="margin-bottom:0;">
            <textarea id="reschedule_note" class="form-control" rows="3"
                      placeholder="e.g. Conflict with work schedule, medical reason, etc."></textarea>
        </div>
    </div>

    <div class="summary-card" id="summaryCard" style="display:none;">
        <h3><i class="fas fa-calendar-check"></i> New Schedule Summary</h3>
        <div class="summary-row">
            <span class="label">Clinic</span>
            <span class="value"><?php echo htmlspecialchars($appointment['clinic_name']); ?></span>
        </div>
        <div class="summary-row">
            <span class="label">New Date</span>
            <span class="value highlight" id="summary-date">—</span>
        </div>
        <div class="summary-row">
            <span class="label">New Time</span>
            <span class="value highlight" id="summary-time">—</span>
        </div>
        <?php if (!empty($doctors)): ?>
        <div class="summary-row">
            <span class="label">Doctor</span>
            <span class="value" id="summary-doctor">—</span>
        </div>
        <?php endif; ?>
        <?php if (!empty($appointment['product_name'])): ?>
        <div class="summary-row">
            <span class="label">Service / Product</span>
            <span class="value"><?php echo htmlspecialchars($appointment['product_name']); ?></span>
        </div>
        <?php endif; ?>
        <?php if ($appointment['downpayment_amount'] > 0): ?>
        <div class="summary-row">
            <span class="label">Downpayment</span>
            <span class="value" style="color:var(--primary-dark);">₱<?php echo number_format($appointment['downpayment_amount'], 2); ?> (already paid)</span>
        </div>
        <?php endif; ?>
    </div>

    <script>
        const bookedSlots = <?php echo json_encode($booked_slots); ?>;
        const currentAppointmentTime = "<?php echo $appointment['appointment_time'] ?? ''; ?>";
        const currentAppointmentDate = "<?php echo $appointment['appointment_date']; ?>";
        const appointmentId = <?php echo $appointment_id; ?>;

        const ALL_SLOTS = <?php echo json_encode($time_slots); ?>;

        const doctorNames = {};
        <?php foreach ($doctors as $doc): ?>
        doctorNames[<?php echo $doc['id']; ?>] = "Dr. <?php echo addslashes($doc['name']); ?>";
        <?php endforeach; ?>

        let selectedDate = '';
        let selectedTime = '';
        let selectedDoctorId = '<?php echo $appointment['doctor_id'] ?? ''; ?>';
        let selectedDoctorName = '<?php echo !empty($appointment['doctor_name']) ? 'Dr. ' . addslashes($appointment['doctor_name']) : 'Not specified'; ?>';

        function onDateChange(date) {
            selectedDate = date;
            selectedTime = '';
            document.getElementById('selected_time').value = '';

            document.getElementById('step-indicator-1').classList.add('done');
            document.getElementById('step-indicator-2').classList.add('active');
            document.getElementById('line-1-2').classList.add('done');

            renderTimeSlots(date);
            document.getElementById('timeSlotsCard').style.display = '';
            <?php if (!empty($doctors)): ?>
            document.getElementById('doctorCard').style.display = '';
            <?php endif; ?>
            document.getElementById('noteCard').style.display = '';
            document.getElementById('summaryCard').style.display = 'none';
            updateSummaryDate(date);
        }

        function renderTimeSlots(date) {
            const wrapper = document.getElementById('timeSlotsWrapper');
            const booked = bookedSlots[date] || [];

            let html = '<div class="time-slots-grid">';
            let hasSlots = false;

            for (const [timeVal, timeLabel] of Object.entries(ALL_SLOTS)) {
                hasSlots = true;
                const isBooked = booked.includes(timeVal);

                let cls = 'time-slot';
                if (isBooked) cls += ' booked';

                const onclick = isBooked ? '' : `onclick="selectTime('${timeVal}', '${timeLabel}', this)"`;
                html += `<div class="${cls}" data-time="${timeVal}" ${onclick}>${timeLabel}</div>`;
            }

            if (!hasSlots) {
                html += '<div class="no-slots"><i class="fas fa-calendar-times"></i>No available slots for this date.</div>';
            }

            html += '</div>';
            wrapper.innerHTML = html;
        }

        function selectTime(timeVal, timeLabel, el) {
            document.querySelectorAll('.time-slot').forEach(s => s.classList.remove('selected'));
            el.classList.add('selected');
            selectedTime = timeVal;
            document.getElementById('selected_time').value = timeVal;

            document.getElementById('step-indicator-2').classList.add('done');
            document.getElementById('step-indicator-3').classList.add('active');
            document.getElementById('line-2-3').classList.add('done');

            document.getElementById('summary-time').textContent = timeLabel;
            document.getElementById('summaryCard').style.display = '';

            updateSubmitBtn();
        }

        function updateSummaryDate(dateStr) {
            const d = new Date(dateStr + 'T00:00:00');
            const opts = { year: 'numeric', month: 'long', day: 'numeric', weekday: 'long' };
            document.getElementById('summary-date').textContent = d.toLocaleDateString('en-US', opts);
        }

        function selectDoctor(el, docId) {
            document.querySelectorAll('.doctor-card').forEach(c => c.classList.remove('selected'));
            el.classList.add('selected');
            selectedDoctorId = docId;
            selectedDoctorName = doctorNames[docId] || 'Not specified';
            document.getElementById('selected_doctor').value = docId;
            const summaryDoc = document.getElementById('summary-doctor');
            if (summaryDoc) summaryDoc.textContent = selectedDoctorName;
        }

        function updateSubmitBtn() {
            const btn = document.getElementById('submitBtn');
            if (!btn) return;
            btn.disabled = !(selectedDate && selectedTime);
        }

        async function submitReschedule() {
            if (!selectedDate || !selectedTime) {
                Swal.fire({ icon: 'warning', title: 'Incomplete', text: 'Please select both a date and time slot.', confirmButtonColor: '#00B761' });
                return;
            }

            const note = document.getElementById('reschedule_note').value;

            const result = await Swal.fire({
                title: 'Confirm Reschedule?',
                html: `<div style="text-align:left;">
                    <p style="margin-bottom:12px;">Your appointment will be moved to:</p>
                    <div style="background:var(--primary-light,#E3FCE9);padding:14px;border-radius:12px;text-align:center;margin-bottom:12px;">
                        <div style="font-size:17px;font-weight:700;color:#00874A;" id="swal-date-display"></div>
                        <div style="font-size:15px;color:#00B761;" id="swal-time-display"></div>
                    </div>
                    <p style="font-size:13px;color:#6B7280;">Your original schedule will be replaced. This action cannot be undone.</p>
                </div>`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#00B761',
                cancelButtonColor: '#6B7280',
                confirmButtonText: '<i class="fas fa-calendar-check"></i> Confirm Reschedule',
                cancelButtonText: 'Cancel',
                didOpen: () => {
                    const d = new Date(selectedDate + 'T00:00:00');
                    document.getElementById('swal-date-display').textContent = d.toLocaleDateString('en-US', { weekday:'long', year:'numeric', month:'long', day:'numeric' });
                    document.getElementById('swal-time-display').textContent = document.getElementById('summary-time').textContent;
                }
            });

            if (!result.isConfirmed) return;

            Swal.fire({ title: 'Rescheduling...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

            const formData = new URLSearchParams();
            formData.append('reschedule_appointment', '1');
            formData.append('appointment_id', appointmentId);
            formData.append('new_date', selectedDate);
            formData.append('new_time', selectedTime);
            <?php if (!empty($doctors)): ?>
            formData.append('doctor_id', document.getElementById('selected_doctor').value || '');
            <?php endif; ?>
            formData.append('reschedule_note', note);

            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: formData.toString()
                });
                const data = await response.json();

                if (data.success) {
                    await Swal.fire({
                        icon: 'success',
                        title: 'Rescheduled!',
                        text: data.message,
                        confirmButtonColor: '#00B761'
                    });
                    window.location.href = 'appointment-details.php?id=' + appointmentId;
                } else {
                    await Swal.fire({
                        icon: 'error',
                        title: 'Oops!',
                        text: data.message,
                        confirmButtonColor: '#EF4444'
                    });
                }
            } catch (err) {
                await Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'Something went wrong. Please try again.',
                    confirmButtonColor: '#EF4444'
                });
            }
        }

        window.addEventListener('DOMContentLoaded', () => {
            const summaryDoc = document.getElementById('summary-doctor');
            if (summaryDoc) summaryDoc.textContent = selectedDoctorName;
        });
    </script>

    <div class="action-bar">
        <a href="appointment-details.php?id=<?php echo $appointment_id; ?>" class="btn btn-secondary">
            <i class="fas fa-times"></i> Cancel
        </a>
        <button id="submitBtn" class="btn btn-primary" onclick="submitReschedule()" disabled>
            <i class="fas fa-calendar-check"></i> Confirm Reschedule
        </button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
    .theme-dark .swal2-popup { background: #1E1E1E; color: #F9FAFB; }
    .theme-dark .swal2-title { color: #F9FAFB; }
    .theme-dark .swal2-html-container { color: #9CA3AF; }
</style>
</body>
</html>