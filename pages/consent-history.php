<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
include '../includes/config.php';
include '../includes/theme.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$user_query = mysqli_query($conn, "SELECT * FROM users WHERE id = $user_id");
$user = mysqli_fetch_assoc($user_query);

// Get patient_id
$patient_id = 0;
$email_escaped = mysqli_real_escape_string($conn, $user['email']);

$p1 = mysqli_query($conn, "SELECT patient_id FROM appointments WHERE user_id = $user_id AND patient_id > 0 LIMIT 1");
if (mysqli_num_rows($p1) > 0) {
    $patient_id = mysqli_fetch_assoc($p1)['patient_id'];
}
if ($patient_id == 0) {
    $p2 = mysqli_query($conn, "SELECT id FROM patients WHERE email = '$email_escaped' LIMIT 1");
    if (mysqli_num_rows($p2) > 0) $patient_id = mysqli_fetch_assoc($p2)['id'];
}
if ($patient_id == 0) {
    $p3 = mysqli_query($conn, "SELECT id FROM patients WHERE user_id = $user_id LIMIT 1");
    if (mysqli_num_rows($p3) > 0) $patient_id = mysqli_fetch_assoc($p3)['id'];
}

// Get consent history
$consent_history = [];
if ($patient_id > 0) {
    $ch_query = mysqli_query($conn, "
        SELECT * FROM patient_consent_history 
        WHERE patient_id = $patient_id 
        ORDER BY consent_date DESC
    ");
    while ($row = mysqli_fetch_assoc($ch_query)) {
        $consent_history[] = $row;
    }
}

// Get DPA history
$dpa_history = [];
if ($patient_id > 0) {
    $dpa_query = mysqli_query($conn, "
        SELECT d.*, u.fullname as accepted_by_name
        FROM dpa_acceptance_log d
        LEFT JOIN users u ON d.user_id = u.id
        WHERE d.patient_id = $patient_id
        ORDER BY d.accepted_at DESC
    ");
    while ($row = mysqli_fetch_assoc($dpa_query)) {
        $dpa_history[] = $row;
    }
}

// Get consent versions
$consent_versions = [];
$cv_query = mysqli_query($conn, "SELECT * FROM consent_versions ORDER BY effective_date DESC");
while ($row = mysqli_fetch_assoc($cv_query)) {
    $consent_versions[] = $row;
}

// Get patient info for minor status
$patient_info = null;
if ($patient_id > 0) {
    $pi_query = mysqli_query($conn, "SELECT is_minor, guardian_name, guardian_relationship, guardian_contact FROM patients WHERE id = $patient_id LIMIT 1");
    if (mysqli_num_rows($pi_query) > 0) {
        $patient_info = mysqli_fetch_assoc($pi_query);
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Consent History - Eyecore</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        *{margin:0;padding:0;box-sizing:border-box;font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;}
        html,body{margin:0!important;padding:0!important;width:100%;background:var(--bg-primary);}
        body{min-height:100vh;transition:background-color 0.3s,color 0.3s;}
        h1,h2,h3,h4,h5,h6,p{margin:0;}
        :root{
            --primary:#00B761;--primary-dark:#00994D;--primary-light:#E3FCE9;
            --primary-gradient:linear-gradient(135deg,#00B761 0%,#00A86B 100%);
            --bg-primary:#F5F7FA;--bg-secondary:#FFFFFF;
            --text-primary:#1A1A1A;--text-secondary:#6B7280;--text-muted:#9CA3AF;
            --border-color:#E5E7EB;--border-light:#F3F4F6;
            --shadow-sm:0 2px 8px rgba(0,0,0,0.04);--shadow-md:0 8px 20px rgba(0,0,0,0.06);
            --shadow-lg:0 20px 40px rgba(0,0,0,0.08);
            --radius-sm:12px;--radius-md:16px;--radius-lg:24px;--radius-full:999px;
            --danger:#FF4444;--warning:#FF8C42;--info:#17A2B8;--success:#00B761;
        }
        .theme-dark{
            --primary:#00E676;--primary-dark:#00C853;--primary-light:#1E3A2E;
            --bg-primary:#0F0F0F;--bg-secondary:#1A1A1A;
            --text-primary:#FFFFFF;--text-secondary:#B0B0B0;--text-muted:#6B7280;
            --border-color:#2D2D2D;--border-light:#262626;
            --shadow-sm:0 2px 8px rgba(0,0,0,0.2);--shadow-md:0 8px 20px rgba(0,0,0,0.3);
            --shadow-lg:0 20px 40px rgba(0,0,0,0.4);
        }

        /* NAVBAR */
        .navbar{display:flex;justify-content:space-between;align-items:center;background:var(--bg-secondary);padding:12px 40px;box-shadow:var(--shadow-sm);position:sticky;top:0;z-index:100;border-bottom:1px solid var(--border-light);}
        @media(max-width:768px){.navbar{padding:12px 20px;}}
        .nav-left{display:flex;align-items:center;gap:30px;}
        .logo{display:flex;align-items:center;gap:10px;font-size:22px;font-weight:700;color:var(--primary);text-decoration:none;}
        .logo i{font-size:26px;}
        .btn-back{display:inline-flex;align-items:center;gap:8px;padding:8px 18px;background:var(--bg-primary);color:var(--text-secondary);text-decoration:none;border-radius:var(--radius-full);font-size:13px;font-weight:600;transition:all 0.2s;border:1px solid var(--border-color);}
        .btn-back:hover{background:var(--primary);color:white;border-color:var(--primary);}
        .btn-theme{width:40px;height:40px;background:var(--bg-primary);border:none;border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--text-secondary);font-size:16px;transition:all 0.2s;}
        .btn-theme:hover{background:var(--primary);color:white;}

        /* MAIN */
        .main-content{max-width:1000px;margin:0 auto;padding:40px 20px 80px;}
        @media(max-width:768px){.main-content{padding:20px 16px 60px;}}

        /* HEADER */
        .page-header{background:var(--primary-gradient);border-radius:var(--radius-lg);padding:40px 30px;margin-bottom:30px;color:white;position:relative;overflow:hidden;box-shadow:var(--shadow-md);}
        .page-header::before{content:'';position:absolute;top:-50%;right:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(255,255,255,0.1) 0%,transparent 70%);animation:rotate 20s linear infinite;}
        @keyframes rotate{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
        .page-header-content{position:relative;z-index:1;}
        .page-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.2);backdrop-filter:blur(5px);padding:6px 14px;border-radius:var(--radius-full);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:12px;}
        .page-header h1{font-size:32px;font-weight:700;margin-bottom:8px;color:white;}
        .page-header p{font-size:14px;opacity:0.95;line-height:1.6;color:white;max-width:600px;}

        /* SUMMARY CARDS */
        .summary-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:30px;}
        .summary-card{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:20px;box-shadow:var(--shadow-sm);}
        .summary-card .icon{width:44px;height:44px;background:var(--primary-light);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:20px;margin-bottom:12px;}
        .summary-card .label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:6px;}
        .summary-card .value{font-size:16px;font-weight:700;color:var(--text-primary);}
        .summary-card .sub{font-size:12px;color:var(--text-secondary);margin-top:4px;}

        /* SECTION */
        .section-card{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius-lg);padding:28px;margin-bottom:24px;box-shadow:var(--shadow-sm);}
        .section-card h2{font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:20px;display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:2px solid var(--border-light);}
        .section-card h2 i{color:var(--primary);font-size:18px;}

        /* TIMELINE */
        .timeline{position:relative;padding-left:30px;}
        .timeline::before{content:'';position:absolute;left:8px;top:0;bottom:0;width:2px;background:var(--border-light);}
        .timeline-item{position:relative;padding-bottom:24px;}
        .timeline-item:last-child{padding-bottom:0;}
        .timeline-dot{position:absolute;left:-30px;top:4px;width:18px;height:18px;border-radius:50%;background:var(--bg-secondary);border:3px solid var(--primary);display:flex;align-items:center;justify-content:center;}
        .timeline-dot.approved{border-color:var(--success);background:var(--success);}
        .timeline-dot.withdrawn{border-color:var(--danger);background:var(--danger);}
        .timeline-dot.rejected{border-color:var(--warning);background:var(--warning);}
        .timeline-dot.pending{border-color:var(--info);background:var(--info);}
        .timeline-content{background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:16px;}
        .timeline-header{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:10px;}
        .timeline-title{font-size:14px;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;}
        .status-badge{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;border-radius:var(--radius-full);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;}
        .status-badge.approved{background:var(--primary-light);color:var(--primary);}
        .status-badge.withdrawn{background:#fff0f0;color:var(--danger);}
        .theme-dark .status-badge.withdrawn{background:rgba(255,68,68,0.15);}
        .status-badge.rejected{background:#fff8e6;color:var(--warning);}
        .theme-dark .status-badge.rejected{background:rgba(255,140,66,0.15);}
        .status-badge.pending{background:#e3f6f9;color:var(--info);}
        .theme-dark .status-badge.pending{background:rgba(23,162,184,0.15);}
        .timeline-meta{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;margin-top:12px;padding-top:12px;border-top:1px dashed var(--border-color);}
        .meta-item .label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:3px;}
        .meta-item .value{font-size:12px;color:var(--text-primary);font-weight:600;word-break:break-word;}

        /* VERSION CARDS */
        .version-card{background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:18px;margin-bottom:12px;display:flex;gap:16px;align-items:flex-start;}
        .version-card.current{border-color:var(--primary);border-width:2px;}
        .version-icon{width:44px;height:44px;background:var(--primary-light);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;flex-shrink:0;}
        .version-body{flex:1;}
        .version-header{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:6px;}
        .version-title{font-size:14px;font-weight:700;color:var(--text-primary);}
        .version-date{font-size:11px;color:var(--text-muted);}
        .version-desc{font-size:12px;color:var(--text-secondary);line-height:1.6;}
        .current-badge{background:var(--primary);color:white;font-size:10px;font-weight:700;padding:3px 10px;border-radius:var(--radius-full);text-transform:uppercase;letter-spacing:0.05em;}

        /* MINOR CARD */
        .minor-card{background:var(--bg-primary);border:1px solid var(--warning);border-radius:var(--radius-md);padding:18px;margin-top:16px;}
        .minor-card h3{font-size:13px;font-weight:700;color:var(--warning);margin-bottom:12px;display:flex;align-items:center;gap:8px;}
        .minor-info{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;}

        /* EMPTY */
        .empty-state{text-align:center;padding:60px 20px;color:var(--text-muted);}
        .empty-state i{font-size:60px;opacity:0.3;display:block;margin-bottom:16px;}
        .empty-state h3{font-size:16px;color:var(--text-primary);margin-bottom:6px;}
        .empty-state p{font-size:13px;color:var(--text-secondary);}

        /* FOOTER */
        .footer-actions{display:flex;gap:12px;justify-content:center;margin-top:40px;flex-wrap:wrap;}
        .btn-act{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:var(--radius-full);font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all 0.2s;text-decoration:none;font-family:inherit;}
        .btn-green-act{background:var(--primary-gradient);color:white;}
        .btn-green-act:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
        .btn-outline-act{background:var(--bg-secondary);color:var(--text-secondary);border:1px solid var(--border-color);}
        .btn-outline-act:hover{color:var(--primary);border-color:var(--primary);background:var(--primary-light);}

        @media(max-width:768px){
            .page-header{padding:28px 20px;}
            .page-header h1{font-size:24px;}
            .section-card{padding:20px;}
            .section-card h2{font-size:16px;}
            .timeline-meta{grid-template-columns:1fr 1fr;}
        }
    </style>
</head>
<body>

<div class="navbar">
    <div class="nav-left">
        <a href="dashboard.php" class="logo"><i class="fas fa-eye"></i><span>eyecore</span></a>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="my-examinations.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
        <button class="btn-theme" onclick="toggleTheme()" id="themeToggle"><i class="fas fa-moon"></i></button>
    </div>
</div>

<div class="main-content">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-header-content">
            <div class="page-badge"><i class="fas fa-history"></i> Consent Records</div>
            <h1>My Consent History</h1>
            <p>View all your consent records, including the versions you agreed to, when you agreed, and the status of your consent.</p>
        </div>
    </div>

    <!-- SUMMARY -->
    <div class="summary-grid">
        <div class="summary-card">
            <div class="icon"><i class="fas fa-file-signature"></i></div>
            <div class="label">Total Consents</div>
            <div class="value"><?=count($consent_history)?></div>
            <div class="sub">records</div>
        </div>
        <div class="summary-card">
            <div class="icon"><i class="fas fa-check-circle"></i></div>
            <div class="label">Current Status</div>
            <div class="value">
                <?php
                $current_status = 'None';
                if (!empty($consent_history)) {
                    $latest = $consent_history[0];
                    $current_status = $latest['consent_status'] ?? 'approved';
                }
                echo ucfirst($current_status);
                ?>
            </div>
            <div class="sub">latest consent</div>
        </div>
        <div class="summary-card">
            <div class="icon"><i class="fas fa-file-contract"></i></div>
            <div class="label">DPA Accepted</div>
            <div class="value"><?=count($dpa_history)?></div>
            <div class="sub">times</div>
        </div>
        <div class="summary-card">
            <div class="icon"><i class="fas fa-code-branch"></i></div>
            <div class="label">Latest Version</div>
            <div class="value"><?=!empty($consent_versions) ? htmlspecialchars($consent_versions[0]['version']) : 'v1.0'?></div>
            <div class="sub">active version</div>
        </div>
    </div>

    <!-- CONSENT TIMELINE -->
    <div class="section-card">
        <h2><i class="fas fa-stream"></i> Consent Timeline</h2>

        <?php if (count($consent_history) > 0): ?>
        <div class="timeline">
            <?php foreach ($consent_history as $c): 
                $status = $c['consent_status'] ?? 'approved';
                $method = $c['consent_method'] ?? 'digital';
                $version = $c['consent_version'] ?? 'v1.0';
                $date = $c['consent_date'] ?? null;
                $ip = $c['consent_ip'] ?? 'N/A';
                
                $status_label = 'Approved';
                $status_icon = 'fa-check';
                if ($status == 'withdrawn') { $status_label = 'Withdrawn'; $status_icon = 'fa-times'; }
                elseif ($status == 'rejected') { $status_label = 'Rejected'; $status_icon = 'fa-ban'; }
                elseif ($status == 'pending') { $status_label = 'Pending'; $status_icon = 'fa-clock'; }
            ?>
            <div class="timeline-item">
                <div class="timeline-dot <?=htmlspecialchars($status)?>"></div>
                <div class="timeline-content">
                    <div class="timeline-header">
                        <div class="timeline-title">
                            <i class="fas fa-shield-alt" style="color:var(--primary);"></i>
                            Data Privacy Consent &mdash; Version <?=htmlspecialchars($version)?>
                        </div>
                        <span class="status-badge <?=htmlspecialchars($status)?>">
                            <i class="fas <?=$status_icon?>"></i> <?=htmlspecialchars($status_label)?>
                        </span>
                    </div>
                    <div class="timeline-meta">
                        <div class="meta-item">
                            <div class="label">Date & Time</div>
                            <div class="value"><?=$date ? date('F j, Y \a\t g:i A', strtotime($date)) : 'N/A'?></div>
                        </div>
                        <div class="meta-item">
                            <div class="label">Method</div>
                            <div class="value"><?=htmlspecialchars(ucfirst(str_replace('_', ' ', $method)))?></div>
                        </div>
                        <div class="meta-item">
                            <div class="label">IP Address</div>
                            <div class="value"><?=htmlspecialchars($ip)?></div>
                        </div>
                        <?php if (!empty($c['verification_details'])): ?>
                        <div class="meta-item">
                            <div class="label">Details</div>
                            <div class="value"><?=htmlspecialchars($c['verification_details'])?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-file-signature"></i>
            <h3>No Consent Records Yet</h3>
            <p>You don't have any consent records yet. Your consent will be recorded here once you give permission.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- DPA HISTORY -->
    <div class="section-card">
        <h2><i class="fas fa-file-contract"></i> Data Processing Agreement (DPA) History</h2>

        <?php if (count($dpa_history) > 0): ?>
        <?php foreach ($dpa_history as $d): ?>
        <div class="version-card">
            <div class="version-icon"><i class="fas fa-file-contract"></i></div>
            <div class="version-body">
                <div class="version-header">
                    <div class="version-title">DPA Version <?=htmlspecialchars($d['dpa_version'] ?? 'v1.0')?></div>
                    <div class="version-date">
                        <i class="fas fa-calendar-alt"></i>
                        <?=date('F j, Y \a\t g:i A', strtotime($d['accepted_at']))?>
                    </div>
                </div>
                <div class="version-desc">
                    <strong>Accepted by:</strong> <?=htmlspecialchars($d['accepted_by_name'] ?? 'You')?><br>
                    <strong>IP Address:</strong> <?=htmlspecialchars($d['ip_address'] ?? 'N/A')?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-file-contract"></i>
            <h3>No DPA Records</h3>
            <p>No DPA acceptance records yet. These will be recorded once you give consent.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- CONSENT VERSIONS -->
    <div class="section-card">
        <h2><i class="fas fa-code-branch"></i> Consent Versions</h2>

        <?php if (count($consent_versions) > 0): ?>
        <?php foreach ($consent_versions as $idx => $v): ?>
        <div class="version-card <?=$idx === 0 ? 'current' : ''?>">
            <div class="version-icon"><i class="fas fa-file-alt"></i></div>
            <div class="version-body">
                <div class="version-header">
                    <div class="version-title"><?=htmlspecialchars($v['title'] ?? 'Data Privacy Consent')?></div>
                    <?php if ($idx === 0): ?>
                        <span class="current-badge">Current</span>
                    <?php endif; ?>
                </div>
                <div class="version-desc">
                    <strong>Version:</strong> <?=htmlspecialchars($v['version'])?><br>
                    <strong>Effective Date:</strong> <?=date('F j, Y', strtotime($v['effective_date']))?><br>
                    <?php if (!empty($v['content'])): ?>
                        <strong>Description:</strong> <?=htmlspecialchars(substr($v['content'], 0, 300))?><?=strlen($v['content']) > 300 ? '...' : ''?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-file-alt"></i>
            <h3>No Versions Found</h3>
            <p>No consent versions found in the database.</p>
        </div>
        <?php endif; ?>
    </div>

    <!-- MINOR INFO -->
    <?php if ($patient_info && !empty($patient_info['is_minor'])): ?>
    <div class="section-card">
        <h2><i class="fas fa-child"></i> Guardian Information</h2>
        <div class="minor-card">
            <h3><i class="fas fa-user-shield"></i> Minor Patient &mdash; Consent via Guardian</h3>
            <div class="minor-info">
                <div class="meta-item">
                    <div class="label">Guardian Name</div>
                    <div class="value"><?=htmlspecialchars($patient_info['guardian_name'] ?? 'N/A')?></div>
                </div>
                <div class="meta-item">
                    <div class="label">Relationship</div>
                    <div class="value"><?=htmlspecialchars($patient_info['guardian_relationship'] ?? 'N/A')?></div>
                </div>
                <div class="meta-item">
                    <div class="label">Contact</div>
                    <div class="value"><?=htmlspecialchars($patient_info['guardian_contact'] ?? 'N/A')?></div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- FOOTER ACTIONS -->
    <div class="footer-actions">
        <a href="privacy-notice.php" class="btn-act btn-outline-act"><i class="fas fa-file-contract"></i> View Privacy Notice</a>
        <a href="my-examinations.php" class="btn-act btn-green-act"><i class="fas fa-arrow-left"></i> Back to My Records</a>
    </div>

</div>

<script>
function toggleTheme() {
    const html = document.documentElement;
    const icon = document.querySelector('#themeToggle i');
    if (html.classList.contains('theme-dark')) {
        html.classList.remove('theme-dark');
        localStorage.setItem('theme', 'light');
        if (icon) icon.className = 'fas fa-moon';
    } else {
        html.classList.add('theme-dark');
        localStorage.setItem('theme', 'dark');
        if (icon) icon.className = 'fas fa-sun';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const saved = localStorage.getItem('theme') || 'light';
    const icon = document.querySelector('#themeToggle i');
    if (saved === 'dark') {
        document.documentElement.classList.add('theme-dark');
        if (icon) icon.className = 'fas fa-sun';
    } else {
        document.documentElement.classList.remove('theme-dark');
        if (icon) icon.className = 'fas fa-moon';
    }
});
</script>
</body>
</html>