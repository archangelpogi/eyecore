<?php
include '../includes/config.php';
include '../includes/theme.php';

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$exam_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$exam_id) {
    header('Location: my-examinations.php');
    exit();
}

// Get examination details with patient verification
$exam_query = mysqli_query($conn, "
    SELECT o.*, 
           c.name as clinic_name,
           c.address,
           c.contact as clinic_contact,
           c.clinic_email as clinic_email
    FROM optical_records o
    LEFT JOIN clinics c ON o.clinic_id = c.id
    WHERE o.id = $exam_id 
      AND o.patient_id = (
          SELECT id FROM patients WHERE email = '{$_SESSION['email']}' LIMIT 1
      )
");

$exam = mysqli_fetch_assoc($exam_query);

if (!$exam) {
    header('Location: my-examinations.php');
    exit();
}

// Get user info for navbar
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Get unread notifications count
$unread_count = getUnreadNotificationCount($user_id);
$recent_notifications = getRecentNotifications($user_id);

// Helper function timeAgo
if (!function_exists('timeAgo')) {
    function timeAgo($timestamp) {
        $time_ago = strtotime($timestamp);
        $current_time = time();
        $time_difference = $current_time - $time_ago;
        $seconds = $time_difference;
        
        $minutes = round($seconds / 60);
        $hours = round($seconds / 3600);
        $days = round($seconds / 86400);
        $weeks = round($seconds / 604800);
        $months = round($seconds / 2629440);
        $years = round($seconds / 31553280);
        
        if ($seconds <= 60) {
            return "Just Now";
        } else if ($minutes <= 60) {
            return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
        } else if ($hours <= 24) {
            return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
        } else if ($days <= 7) {
            return ($days == 1) ? "yesterday" : "$days days ago";
        } else if ($weeks <= 4.3) {
            return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
        } else if ($months <= 12) {
            return ($months == 1) ? "1 month ago" : "$months months ago";
        } else {
            return ($years == 1) ? "1 year ago" : "$years years ago";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Examination Details - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <style>
        /* Your existing CSS styles */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #00B761;
            --primary-dark: #00994D;
            --primary-light: #E3FCE9;
            --bg-primary: #F5F7FA;
            --bg-secondary: #FFFFFF;
            --text-primary: #1A1A1A;
            --text-secondary: #6B7280;
            --border-color: #E5E7EB;
        }
        
        .theme-dark {
            --primary: #00E676;
            --bg-primary: #0F0F0F;
            --bg-secondary: #1A1A1A;
            --text-primary: #FFFFFF;
            --text-secondary: #B0B0B0;
            --border-color: #2D2D2D;
        }
        
        body {
            background: var(--bg-primary);
            color: var(--text-primary);
            transition: all 0.3s;
        }
        
        .navbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: var(--bg-secondary);
            padding: 12px 40px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            border-bottom: 1px solid var(--border-color);
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
        }
        
        .nav-links {
            display: flex;
            gap: 8px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: 999px;
            transition: all 0.2s;
        }
        
        .nav-link:hover, .nav-link.active {
            color: var(--primary);
            background: var(--bg-primary);
        }
        
        .main-content {
            max-width: 1000px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        
        .back-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--bg-secondary);
            color: var(--primary);
            text-decoration: none;
            border-radius: 30px;
            margin-bottom: 30px;
            border: 1px solid var(--primary);
            transition: all 0.2s;
        }
        
        .back-btn:hover {
            background: var(--primary);
            color: white;
        }
        
        .exam-card {
            background: var(--bg-secondary);
            border-radius: 24px;
            overflow: hidden;
            box-shadow: 0 8px 20px rgba(0,0,0,0.06);
            border: 1px solid var(--border-color);
        }
        
        .exam-header {
            background: linear-gradient(135deg, #00B761 0%, #00A86B 100%);
            color: white;
            padding: 30px;
        }
        
        .exam-header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        
        .clinic-info {
            display: flex;
            gap: 20px;
            margin-top: 15px;
            font-size: 14px;
            opacity: 0.9;
        }
        
        .exam-body {
            padding: 30px;
        }
        
        .eye-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .eye-section {
            background: var(--bg-primary);
            padding: 25px;
            border-radius: 20px;
        }
        
        .eye-title {
            font-size: 18px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .prescription-row {
            display: flex;
            justify-content: space-between;
            padding: 12px 0;
            border-bottom: 1px dashed var(--border-color);
        }
        
        .prescription-label {
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .prescription-value {
            font-weight: 600;
            color: var(--text-primary);
            font-family: monospace;
            font-size: 16px;
        }
        
        .pd-section {
            background: var(--bg-primary);
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .notes-section {
            background: var(--bg-primary);
            padding: 20px;
            border-radius: 20px;
            margin-bottom: 30px;
            border-left: 4px solid var(--primary);
        }
        
        .optometrist-section {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 20px;
            background: var(--bg-primary);
            border-radius: 20px;
            margin-bottom: 30px;
        }
        
        .btn-download {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 15px 30px;
            background: var(--primary);
            color: white;
            text-decoration: none;
            border-radius: 40px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px -10px var(--primary);
        }
        
        @media (max-width: 768px) {
            .eye-grid {
                grid-template-columns: 1fr;
            }
            
            .exam-header {
                padding: 20px;
            }
            
            .exam-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <!-- Simple Navbar -->
    <div class="navbar">
        <div class="logo">
            <i class="fas fa-eye"></i>
            <span>eyecore</span>
        </div>
        <div class="nav-links">
            <a href="dashboard.php" class="nav-link"><i class="fas fa-home"></i> Home</a>
            <a href="my-appointments.php" class="nav-link"><i class="fas fa-calendar-check"></i> Appointments</a>
            <a href="my-examinations.php" class="nav-link active"><i class="fas fa-file-prescription"></i> Records</a>
            <a href="../auth/user_logout.php" class="nav-link"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>
    
    <div class="main-content">
        <a href="my-examinations.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Records
        </a>
        
        <div class="exam-card">
            <div class="exam-header">
                <h1><i class="fas fa-eye"></i> Eye Examination Report</h1>
                <div class="clinic-info">
                    <span><i class="fas fa-clinic-medical"></i> <?php echo htmlspecialchars($exam['clinic_name'] ?? 'Clinic'); ?></span>
                    <span><i class="far fa-calendar"></i> <?php echo date('F d, Y', strtotime($exam['examination_date'])); ?></span>
                </div>
                <?php if ($exam['record_code']): ?>
                <div class="clinic-info">
                    <span><i class="fas fa-hashtag"></i> Record Code: <?php echo $exam['record_code']; ?></span>
                </div>
                <?php endif; ?>
            </div>
            
            <div class="exam-body">
                <div class="eye-grid">
                    <!-- Right Eye -->
                    <div class="eye-section">
                        <div class="eye-title">
                            <i class="fas fa-eye"></i> Right Eye (OD)
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Sphere (SPH):</span>
                            <span class="prescription-value"><?php echo $exam['od_sph'] ?? '-'; ?></span>
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Cylinder (CYL):</span>
                            <span class="prescription-value"><?php echo $exam['od_cyl'] ?? '-'; ?></span>
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Axis:</span>
                            <span class="prescription-value"><?php echo $exam['od_axis'] ?? '-'; ?>°</span>
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Add:</span>
                            <span class="prescription-value"><?php echo $exam['od_add'] ?? '-'; ?></span>
                        </div>
                        <?php if ($exam['od_va']): ?>
                        <div class="prescription-row">
                            <span class="prescription-label">Visual Acuity:</span>
                            <span class="prescription-value"><?php echo htmlspecialchars($exam['od_va']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Left Eye -->
                    <div class="eye-section">
                        <div class="eye-title">
                            <i class="fas fa-eye"></i> Left Eye (OS)
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Sphere (SPH):</span>
                            <span class="prescription-value"><?php echo $exam['os_sph'] ?? '-'; ?></span>
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Cylinder (CYL):</span>
                            <span class="prescription-value"><?php echo $exam['os_cyl'] ?? '-'; ?></span>
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Axis:</span>
                            <span class="prescription-value"><?php echo $exam['os_axis'] ?? '-'; ?>°</span>
                        </div>
                        <div class="prescription-row">
                            <span class="prescription-label">Add:</span>
                            <span class="prescription-value"><?php echo $exam['os_add'] ?? '-'; ?></span>
                        </div>
                        <?php if ($exam['os_va']): ?>
                        <div class="prescription-row">
                            <span class="prescription-label">Visual Acuity:</span>
                            <span class="prescription-value"><?php echo htmlspecialchars($exam['os_va']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- PD Section -->
                <?php if ($exam['pd']): ?>
                <div class="pd-section">
                    <div>
                        <i class="fas fa-ruler" style="color: var(--primary); font-size: 24px;"></i>
                        <strong>Pupillary Distance (PD)</strong>
                    </div>
                    <div style="font-size: 24px; font-weight: bold;"><?php echo $exam['pd']; ?> mm</div>
                </div>
                <?php endif; ?>
                
                <!-- Notes -->
                <?php if ($exam['notes']): ?>
                <div class="notes-section">
                    <i class="fas fa-sticky-note" style="color: var(--primary);"></i>
                    <strong>Clinical Notes:</strong>
                    <p style="margin-top: 10px; line-height: 1.6;"><?php echo nl2br(htmlspecialchars($exam['notes'])); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Optometrist -->
                <?php if ($exam['optometrist']): ?>
                <div class="optometrist-section">
                    <i class="fas fa-user-md" style="color: var(--primary); font-size: 24px;"></i>
                    <div>
                        <strong>Examined by:</strong><br>
                        Dr. <?php echo htmlspecialchars($exam['optometrist']); ?>
                    </div>
                </div>
                <?php endif; ?>
                

            </div>
        </div>
    </div>
</body>
</html>