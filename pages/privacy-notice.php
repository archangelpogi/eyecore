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

// Get latest consent version
$latest_consent_version = 'v1.0';
$latest_consent_date = date('F j, Y');
$cv_query = mysqli_query($conn, "SELECT * FROM consent_versions ORDER BY effective_date DESC LIMIT 1");
if ($cv_query && mysqli_num_rows($cv_query) > 0) {
    $cv_row = mysqli_fetch_assoc($cv_query);
    $latest_consent_version = $cv_row['version'];
    $latest_consent_date = date('F j, Y', strtotime($cv_row['effective_date']));
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Notice - Eyecore</title>
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
        .main-content{max-width:900px;margin:0 auto;padding:40px 20px 80px;}
        @media(max-width:768px){.main-content{padding:20px 16px 60px;}}

        /* HEADER */
        .page-header{background:var(--primary-gradient);border-radius:var(--radius-lg);padding:40px 30px;margin-bottom:30px;color:white;position:relative;overflow:hidden;box-shadow:var(--shadow-md);}
        .page-header::before{content:'';position:absolute;top:-50%;right:-50%;width:200%;height:200%;background:radial-gradient(circle,rgba(255,255,255,0.1) 0%,transparent 70%);animation:rotate 20s linear infinite;}
        @keyframes rotate{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}
        .page-header-content{position:relative;z-index:1;}
        .page-badge{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.2);backdrop-filter:blur(5px);padding:6px 14px;border-radius:var(--radius-full);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;margin-bottom:12px;}
        .page-header h1{font-size:32px;font-weight:700;margin-bottom:8px;color:white;}
        .page-header p{font-size:14px;opacity:0.95;line-height:1.6;color:white;max-width:600px;}
        .page-meta{display:flex;gap:20px;margin-top:15px;flex-wrap:wrap;font-size:12px;opacity:0.9;}
        .page-meta span{display:flex;align-items:center;gap:6px;color:white;}

        /* SECTIONS */
        .notice-section{background:var(--bg-secondary);border:1px solid var(--border-light);border-radius:var(--radius-lg);padding:28px;margin-bottom:20px;box-shadow:var(--shadow-sm);}
        .notice-section h2{font-size:18px;font-weight:700;color:var(--text-primary);margin-bottom:14px;display:flex;align-items:center;gap:10px;padding-bottom:12px;border-bottom:2px solid var(--border-light);}
        .notice-section h2 i{color:var(--primary);font-size:18px;}
        .notice-section h3{font-size:15px;font-weight:700;color:var(--text-primary);margin-top:18px;margin-bottom:8px;}
        .notice-section p{font-size:14px;color:var(--text-secondary);line-height:1.8;margin-bottom:12px;}
        .notice-section ul,.notice-section ol{margin:10px 0 14px 24px;}
        .notice-section li{font-size:14px;color:var(--text-secondary);line-height:1.8;margin-bottom:8px;}
        .notice-section strong{color:var(--text-primary);font-weight:600;}
        .notice-section a{color:var(--primary);font-weight:600;text-decoration:none;}
        .notice-section a:hover{text-decoration:underline;}

        /* HIGHLIGHT BOXES */
        .highlight-box{background:var(--primary-light);border-left:4px solid var(--primary);border-radius:0 var(--radius-md) var(--radius-md) 0;padding:16px 20px;margin:16px 0;}
        .highlight-box p{color:var(--text-primary);margin-bottom:8px;font-size:13px;}
        .highlight-box p:last-child{margin-bottom:0;}
        .highlight-box strong{color:var(--primary-dark);}

        .warning-box{background:#fff8e6;border-left:4px solid var(--warning);border-radius:0 var(--radius-md) var(--radius-md) 0;padding:16px 20px;margin:16px 0;}
        .theme-dark .warning-box{background:#3a2d1a;}
        .warning-box p{color:var(--text-primary);margin-bottom:8px;font-size:13px;}
        .warning-box p:last-child{margin-bottom:0;}

        .danger-box{background:#fff0f0;border-left:4px solid var(--danger);border-radius:0 var(--radius-md) var(--radius-md) 0;padding:16px 20px;margin:16px 0;}
        .theme-dark .danger-box{background:rgba(255,68,68,0.12);}
        .danger-box p{color:var(--text-primary);margin-bottom:8px;font-size:13px;}
        .danger-box p:last-child{margin-bottom:0;}

        /* RIGHTS GRID */
        .rights-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin:16px 0;}
        .right-card{background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:18px;transition:all 0.2s;}
        .right-card:hover{border-color:var(--primary);transform:translateY(-2px);box-shadow:var(--shadow-sm);}
        .right-card .icon{width:40px;height:40px;background:var(--primary-light);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--primary);font-size:18px;margin-bottom:12px;}
        .right-card h4{font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:6px;}
        .right-card p{font-size:12px;color:var(--text-secondary);line-height:1.6;margin:0;}

        /* DPO CARD */
        .dpo-card{background:var(--bg-primary);border:2px solid var(--primary);border-radius:var(--radius-lg);padding:24px;margin:16px 0;}
        .dpo-card h3{color:var(--primary);margin-bottom:16px;display:flex;align-items:center;gap:10px;font-size:16px;}
        .dpo-info{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin-top:14px;}
        .dpo-info-item{background:var(--bg-secondary);padding:14px;border-radius:var(--radius-md);border:1px solid var(--border-light);}
        .dpo-info-item .label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.06em;color:var(--text-muted);margin-bottom:6px;}
        .dpo-info-item .value{font-size:13px;color:var(--text-primary);font-weight:600;}

        /* TABLE */
        .data-table{width:100%;border-collapse:collapse;margin:16px 0;font-size:13px;}
        .data-table th{background:var(--bg-primary);color:var(--text-primary);padding:12px;text-align:left;font-weight:700;border-bottom:2px solid var(--border-color);}
        .data-table td{padding:12px;border-bottom:1px solid var(--border-light);color:var(--text-secondary);line-height:1.6;vertical-align:top;}
        .data-table tr:last-child td{border-bottom:none;}
        .data-table strong{color:var(--text-primary);}

        /* FOOTER ACTIONS */
        .footer-actions{display:flex;gap:12px;justify-content:center;margin-top:40px;flex-wrap:wrap;}
        .btn-act{display:inline-flex;align-items:center;gap:8px;padding:12px 28px;border-radius:var(--radius-full);font-size:14px;font-weight:600;cursor:pointer;border:none;transition:all 0.2s;text-decoration:none;font-family:inherit;}
        .btn-green-act{background:var(--primary-gradient);color:white;}
        .btn-green-act:hover{transform:translateY(-2px);box-shadow:var(--shadow-md);}
        .btn-outline-act{background:var(--bg-secondary);color:var(--text-secondary);border:1px solid var(--border-color);}
        .btn-outline-act:hover{color:var(--primary);border-color:var(--primary);background:var(--primary-light);}

        /* TABLE OF CONTENTS */
        .toc{background:var(--bg-primary);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:20px;margin-bottom:24px;}
        .toc h3{font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:12px;display:flex;align-items:center;gap:8px;}
        .toc h3 i{color:var(--primary);}
        .toc ol{margin:0 0 0 20px;}
        .toc li{margin-bottom:8px;}
        .toc a{color:var(--text-secondary);text-decoration:none;font-size:13px;transition:color 0.2s;}
        .toc a:hover{color:var(--primary);}

        /* RESPONSIVE */
        @media(max-width:768px){
            .page-header{padding:28px 20px;}
            .page-header h1{font-size:24px;}
            .notice-section{padding:20px;}
            .notice-section h2{font-size:16px;}
            .data-table{font-size:12px;}
            .data-table th,.data-table td{padding:8px;}
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<div class="navbar">
    <div class="nav-left">
        <a href="dashboard.php" class="logo"><i class="fas fa-eye"></i><span>eyecore</span></a>
    </div>
    <div style="display:flex;gap:10px;align-items:center;">
        <a href="my-examinations.php" class="btn-back"><i class="fas fa-arrow-left"></i> Back</a>
        <button class="btn-theme" onclick="toggleTheme()" id="themeToggle"><i class="fas fa-moon"></i></button>
    </div>
</div>

<!-- MAIN -->
<div class="main-content">

    <!-- HEADER -->
    <div class="page-header">
        <div class="page-header-content">
            <div class="page-badge"><i class="fas fa-shield-alt"></i> RA 10173 Compliance</div>
            <h1>Privacy Notice</h1>
            <p>Learn how we collect, use, store, and protect your personal and medical information. This notice is in compliance with the <strong>Data Privacy Act of 2012 (RA 10173)</strong>.</p>
            <div class="page-meta">
                <span><i class="fas fa-file-alt"></i> Version <?=htmlspecialchars($latest_consent_version)?></span>
                <span><i class="fas fa-calendar-alt"></i> Effective: <?=htmlspecialchars($latest_consent_date)?></span>
                <span><i class="fas fa-clock"></i> Last Updated: <?=date('F j, Y')?></span>
            </div>
        </div>
    </div>

    <!-- TABLE OF CONTENTS -->
    <div class="toc">
        <h3><i class="fas fa-list"></i> Table of Contents</h3>
        <ol>
            <li><a href="#intro">Introduction</a></li>
            <li><a href="#collect">What We Collect</a></li>
            <li><a href="#purpose">Purpose of Processing</a></li>
            <li><a href="#dpa">Data Processing Agreement</a></li>
            <li><a href="#sharing">Data Sharing & Disclosure</a></li>
            <li><a href="#retention">Retention Period</a></li>
            <li><a href="#rights">Your Rights</a></li>
            <li><a href="#security">Security Measures</a></li>
            <li><a href="#dpo">Data Protection Officer</a></li>
            <li><a href="#changes">Changes to This Notice</a></li>
        </ol>
    </div>

    <!-- 1. INTRODUCTION -->
    <div class="notice-section" id="intro">
        <h2><i class="fas fa-info-circle"></i> 1. Introduction</h2>
        <p><strong>EYECORE</strong> is committed to protecting your privacy and personal data. This Privacy Notice explains how we handle your information when you use our platform, book appointments, and access your medical records.</p>
        <div class="highlight-box">
            <p><strong>In simple terms:</strong> Your medical records are owned by the clinic. EYECORE is a secure platform that helps you and the clinic access and manage your records. We do not share your data with others without your consent.</p>
        </div>
    </div>

    <!-- 2. WHAT WE COLLECT -->
    <div class="notice-section" id="collect">
        <h2><i class="fas fa-database"></i> 2. What We Collect</h2>
        <p>We collect the following information for your healthcare needs:</p>

        <h3>Personal Information</h3>
        <ul>
            <li>Full name (first name, last name)</li>
            <li>Email address</li>
            <li>Contact number</li>
            <li>Address (personal and delivery)</li>
            <li>Date of birth and age</li>
            <li>Profile picture (optional)</li>
        </ul>

        <h3>Medical Information</h3>
        <ul>
            <li>Eye examination results (SPH, CYL, AXIS, ADD, VA, PD)</li>
            <li>Prescriptions and eyeglass/contact lens measurements</li>
            <li>Clinical notes from your optometrist</li>
            <li>Appointment history and clinic visits</li>
            <li>PWD/Senior Citizen verification (if applicable)</li>
        </ul>

        <h3>Technical Information</h3>
        <ul>
            <li>IP address and device information</li>
            <li>Browser and operating system</li>
            <li>Access logs (when and how you accessed your records)</li>
            <li>Consent history and agreed version</li>
        </ul>

        <div class="warning-box">
            <p><strong>Note:</strong> We do not collect payment information such as credit card numbers. All transactions are processed directly at the clinic.</p>
        </div>
    </div>

    <!-- 3. PURPOSE -->
    <div class="notice-section" id="purpose">
        <h2><i class="fas fa-bullseye"></i> 3. Purpose of Processing</h2>
        <p>We use your information for the following purposes:</p>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:30%;">Purpose</th>
                    <th style="width:35%;">Data Used</th>
                    <th style="width:35%;">Legal Basis</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Medical Treatment</strong></td>
                    <td>Medical records, exam results, prescriptions</td>
                    <td>Consent (RA 10173, Sec. 12)</td>
                </tr>
                <tr>
                    <td><strong>Appointment Management</strong></td>
                    <td>Name, contact, appointment history</td>
                    <td>Contractual necessity</td>
                </tr>
                <tr>
                    <td><strong>Account Management</strong></td>
                    <td>Email, password, profile info</td>
                    <td>Contractual necessity</td>
                </tr>
                <tr>
                    <td><strong>Legal Compliance</strong></td>
                    <td>Medical records (10-year retention)</td>
                    <td>Legal obligation (DOH guidelines)</td>
                </tr>
                <tr>
                    <td><strong>Audit & Security</strong></td>
                    <td>Access logs, IP addresses</td>
                    <td>Legitimate interest</td>
                </tr>
                <tr>
                    <td><strong>Notifications</strong></td>
                    <td>Email, contact number</td>
                    <td>Consent</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- 4. DATA PROCESSING AGREEMENT -->
    <div class="notice-section" id="dpa">
        <h2><i class="fas fa-file-contract"></i> 4. Data Processing Agreement (DPA)</h2>
        <p>Under RA 10173, each party has a clear role in processing your data:</p>

        <div class="highlight-box">
            <p><strong>Personal Information Controller (PIC):</strong> The <strong>Clinic</strong> owns and controls your medical records. They are responsible for maintaining the confidentiality and security of your data.</p>
        </div>

        <div class="highlight-box">
            <p><strong>Personal Information Processor (PIP):</strong> <strong>EYECORE</strong> is a secure platform that processes your data <em>on behalf of the clinic</em>. We do not own your records — we only help the clinic and you access them.</p>
        </div>

        <h3>Scope of Processing</h3>
        <ul>
            <li>Storing your medical records on secure servers</li>
            <li>Sending notifications about appointments</li>
            <li>Displaying your records to you and clinic staff</li>
            <li>Maintaining audit logs for security</li>
            <li>Complying with legal retention requirements (10 years)</li>
        </ul>

        <h3>Sub-Processors</h3>
        <p>We may use third-party service providers for:</p>
        <ul>
            <li>Cloud hosting (secure servers in the Philippines)</li>
            <li>Email delivery (for notifications)</li>
            <li>SMS delivery (if applicable)</li>
        </ul>
        <p>All sub-processors have Data Processing Agreements with us and comply with RA 10173.</p>
    </div>

    <!-- 5. DATA SHARING -->
    <div class="notice-section" id="sharing">
        <h2><i class="fas fa-share-alt"></i> 5. Data Sharing & Disclosure</h2>
        <p>We do not share your personal and medical information with others without your consent, except in the following situations:</p>

        <h3>When We Share Data</h3>
        <ul>
            <li><strong>With Your Clinic:</strong> Clinic staff have access to your medical records for treatment purposes.</li>
            <li><strong>With Another Clinic (if referred):</strong> If you are referred to another clinic, they may access your records <em>with your consent</em>.</li>
            <li><strong>With Government Agencies:</strong> If required by law (e.g., DOH, NPC, court).</li>
            <li><strong>In Emergency Situations:</strong> If there is an immediate threat to your life or health.</li>
            <li><strong>With Sub-Processors:</strong> For hosting, email, and SMS delivery — all have DPAs with us.</li>
        </ul>

        <div class="danger-box">
            <p><strong>We do NOT:</strong></p>
            <ul style="margin-top:8px;">
                <li>Sell your data to third parties.</li>
                <li>Use your data for advertising.</li>
                <li>Share your data on social media.</li>
                <li>Share your data with other patients.</li>
            </ul>
        </div>
    </div>

    <!-- 6. RETENTION -->
    <div class="notice-section" id="retention">
        <h2><i class="fas fa-clock"></i> 6. Retention Period</h2>
        <p>We store your medical records in accordance with legal requirements:</p>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:40%;">Data Type</th>
                    <th style="width:30%;">Retention Period</th>
                    <th style="width:30%;">Reason</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Medical Records</strong></td>
                    <td>10 years</td>
                    <td>DOH guidelines, medical regulations</td>
                </tr>
                <tr>
                    <td><strong>Appointment History</strong></td>
                    <td>10 years</td>
                    <td>Medical records retention</td>
                </tr>
                <tr>
                    <td><strong>Consent History</strong></td>
                    <td>10 years</td>
                    <td>Proof of consent (RA 10173)</td>
                </tr>
                <tr>
                    <td><strong>Access Logs</strong></td>
                    <td>5 years</td>
                    <td>Security audit</td>
                </tr>
                <tr>
                    <td><strong>Account Information</strong></td>
                    <td>Until you deactivate</td>
                    <td>Account management</td>
                </tr>
            </tbody>
        </table>

        <div class="highlight-box">
            <p><strong>Right to Erasure:</strong> You may request deletion of your data. However, medical records must be retained for <strong>10 years</strong> as required by law. After 10 years, your records may be permanently deleted.</p>
        </div>
    </div>

    <!-- 7. RIGHTS -->
    <div class="notice-section" id="rights">
        <h2><i class="fas fa-user-shield"></i> 7. Your Rights</h2>
        <p>Under RA 10173, you have the following rights:</p>

        <div class="rights-grid">
            <div class="right-card">
                <div class="icon"><i class="fas fa-info-circle"></i></div>
                <h4>Right to be Informed</h4>
                <p>You have the right to know what data is collected, why, and how it is used.</p>
            </div>
            <div class="right-card">
                <div class="icon"><i class="fas fa-eye"></i></div>
                <h4>Right to Access</h4>
                <p>You have the right to view your medical records anytime through the platform.</p>
            </div>
            <div class="right-card">
                <div class="icon"><i class="fas fa-edit"></i></div>
                <h4>Right to Correct</h4>
                <p>You have the right to correct inaccurate information in your records.</p>
            </div>
            <div class="right-card">
                <div class="icon"><i class="fas fa-hand-paper"></i></div>
                <h4>Right to Object</h4>
                <p>You have the right to object to the processing of your data in certain situations.</p>
            </div>
            <div class="right-card">
                <div class="icon"><i class="fas fa-trash"></i></div>
                <h4>Right to Erasure</h4>
                <p>You have the right to request deletion of your data (subject to 10-year retention).</p>
            </div>
            <div class="right-card">
                <div class="icon"><i class="fas fa-times-circle"></i></div>
                <h4>Right to Withdraw</h4>
                <p>You have the right to withdraw your consent at any time.</p>
            </div>
            <div class="right-card">
                <div class="icon"><i class="fas fa-download"></i></div>
                <h4>Right to Portability</h4>
                <p>You have the right to export your data in JSON format.</p>
            </div>
            <div class="right-card">
                <div class="icon"><i class="fas fa-gavel"></i></div>
                <h4>Right to Damages</h4>
                <p>You have the right to seek damages if there is a violation of your data privacy.</p>
            </div>
        </div>

        <h3>How to Exercise Your Rights</h3>
        <p>To exercise your rights, go to the <a href="my-examinations.php">My Examination History</a> page and use the following buttons:</p>
        <ul>
            <li><strong>Export</strong> — to download your data</li>
            <li><strong>Request Deletion</strong> — to request erasure</li>
            <li><strong>Withdraw Consent</strong> — to withdraw consent</li>
            <li><strong>Access Log</strong> — to view who accessed your records</li>
        </ul>
    </div>

    <!-- 8. SECURITY -->
    <div class="notice-section" id="security">
        <h2><i class="fas fa-lock"></i> 8. Security Measures</h2>
        <p>We use industry-standard security measures to protect your data:</p>

        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:40%;">Security Measure</th>
                    <th style="width:60%;">Description</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Encryption</strong></td>
                    <td>AES-256 encryption for data and HTTPS/TLS for transmission</td>
                </tr>
                <tr>
                    <td><strong>Access Control</strong></td>
                    <td>Role-based access — clinic staff, patients, and admins have different access levels</td>
                </tr>
                <tr>
                    <td><strong>Audit Logging</strong></td>
                    <td>All access to medical records is logged (who, when, where)</td>
                </tr>
                <tr>
                    <td><strong>Authentication</strong></td>
                    <td>Password hashing and secure login sessions</td>
                </tr>
                <tr>
                    <td><strong>Backup</strong></td>
                    <td>Regular backups on secure servers</td>
                </tr>
                <tr>
                    <td><strong>Breach Response</strong></td>
                    <td>72-hour notification to NPC and affected patients in case of a breach</td>
                </tr>
            </tbody>
        </table>

        <div class="highlight-box">
            <p><strong>Data Breach:</strong> If there is a data breach affecting your records, we will notify you and the National Privacy Commission (NPC) within 72 hours.</p>
        </div>
    </div>

    <!-- 9. DPO -->
    <div class="notice-section" id="dpo">
        <h2><i class="fas fa-user-tie"></i> 9. Data Protection Officer (DPO)</h2>
        <p>For questions, complaints, or concerns about your data privacy, contact our Data Protection Officer:</p>

        <div class="dpo-card">
            <h3><i class="fas fa-user-shield"></i> Data Protection Officer</h3>
            <div class="dpo-info">
                <div class="dpo-info-item">
                    <div class="label">Email</div>
                    <div class="value">dpo@eyecore.ph</div>
                </div>
                <div class="dpo-info-item">
                    <div class="label">Contact Number</div>
                    <div class="value">+63 XXX XXX XXXX</div>
                </div>
                <div class="dpo-info-item">
                    <div class="label">Office Hours</div>
                    <div class="value">Mon–Fri, 9AM–5PM</div>
                </div>
                <div class="dpo-info-item">
                    <div class="label">Response Time</div>
                    <div class="value">Within 15 working days</div>
                </div>
            </div>
            <p style="margin-top:16px;font-size:13px;color:var(--text-secondary);">
                If you are not satisfied with our response, you may file a complaint with the <strong>National Privacy Commission (NPC)</strong>:
            </p>
            <ul style="margin-top:8px;">
                <li>Website: <a href="https://www.privacy.gov.ph" target="_blank">www.privacy.gov.ph</a></li>
                <li>Email: info@privacy.gov.ph</li>
                <li>Contact: (02) 8234-2222</li>
            </ul>
        </div>
    </div>

    <!-- 10. CHANGES -->
    <div class="notice-section" id="changes">
        <h2><i class="fas fa-sync-alt"></i> 10. Changes to This Notice</h2>
        <p>We may update this Privacy Notice from time to time. When we update it:</p>
        <ul>
            <li>We will notify you via email and platform notification.</li>
            <li>We will request your consent again if there are significant changes.</li>
            <li>We will display the new version and effective date.</li>
            <li>The old version will remain in our consent history.</li>
        </ul>

        <div class="warning-box">
            <p><strong>Current Version:</strong> <?=htmlspecialchars($latest_consent_version)?></p>
            <p><strong>Effective Date:</strong> <?=htmlspecialchars($latest_consent_date)?></p>
            <p><strong>Previous Versions:</strong> Available on your <a href="consent-history.php">Consent History</a> page.</p>
        </div>
    </div>

    <!-- FOOTER ACTIONS -->
    <div class="footer-actions">
        <a href="consent-history.php" class="btn-act btn-outline-act"><i class="fas fa-history"></i> View My Consent History</a>
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