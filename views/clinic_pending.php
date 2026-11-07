<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
include __DIR__ . '/../config/db.php';

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) { header("Location: ../admin/login.php"); exit; }

$stmt = $pdo->prepare("SELECT u.*, c.* FROM users u JOIN clinics c ON u.clinic_id = c.id WHERE u.id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
$clinicId = $user['clinic_id'];

$stmt = $pdo->prepare("SELECT * FROM clinic_documents WHERE clinic_id = ?");
$stmt->execute([$clinicId]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

$requiredDocs = ['Business Permit','DTI','SEC','BIR','Optometrist License','Barangay Clearance','Health Clearance','Insurance','Other Documents'];

$today = date('Y-m-d');

$allApproved = true;
$showUploadForm = false;
$docsToShow = [];
$docsToUpload = [];
$waitTomorrowDocs = [];

foreach ($requiredDocs as $doc) {
    $docRow = array_filter($documents, fn($d) => $d['document_type'] === $doc);
    $docRow = $docRow ? array_values($docRow)[0] : null;
    $canUpload = false;
    $status = $docRow['status'] ?? 'Not Uploaded';

    $attempts     = $docRow['submission_attempts'] ?? 0;
    $reviewedAt   = $docRow['reviewed_at'] ?? null;
    $declinedToday = false;

    if ($status === 'Rejected' && $reviewedAt) {
        $declinedToday = (date('Y-m-d', strtotime($reviewedAt)) === $today);
    }

    if (!$docRow) {
        $canUpload = true; $showUploadForm = true; $docsToUpload[] = $doc;
    }
    elseif ($status === 'Awaiting Re-upload') {
        $canUpload = true; $showUploadForm = true; $docsToUpload[] = $doc;
    }
    elseif ($status === 'Rejected' && !$declinedToday) {
        $canUpload = true; $showUploadForm = true; $docsToUpload[] = $doc;
    }
    elseif ($status === 'Rejected' && $declinedToday) {
        $canUpload = false; $waitTomorrowDocs[] = $doc;
    }

    if ($docRow && $docRow['status'] !== 'Approved') $allApproved = false;
    $docsToShow[$doc] = [
        'row'               => $docRow,
        'can_upload'        => $canUpload,
        'status'            => $status,
        'attempts'          => $attempts,
        'declined_today'    => $declinedToday,
        'awaiting_reupload' => ($status === 'Awaiting Re-upload')
    ];
}

$stats = ['uploaded' => 0, 'pending' => 0, 'missing' => 0];
foreach ($docsToShow as $doc => $data) {
    if ($data['status'] === 'Approved') $stats['uploaded']++;
    elseif ($data['status'] === 'Pending') $stats['pending']++;
    else $stats['missing']++;
}
$pct = round(($stats['uploaded'] / count($requiredDocs)) * 100);

// ── IDENTITY VERIFICATION STATE ──
$idStmt = $pdo->prepare("SELECT * FROM clinic_identity_verification WHERE clinic_id = ?");
$idStmt->execute([$clinicId]);
$identity = $idStmt->fetch(PDO::FETCH_ASSOC);

$idStatus   = $identity['status'] ?? 'Not Submitted';
$idVerified = $idStatus === 'Approved';

$idDeclinedToday = false;
if ($idStatus === 'Rejected' && !empty($identity['reviewed_at'])) {
    $idDeclinedToday = (date('Y-m-d', strtotime($identity['reviewed_at'])) === $today);
}

$idTypeOptions = [
    'Philippine Passport', "Driver's License", 'UMID', 'PhilHealth ID', 'SSS ID',
    'PRC ID', 'Postal ID', "Voter's ID", 'National ID (PhilSys)', 'Barangay ID',
];

$docDesc = [
    'Business Permit' => 'Upload your current mayor’s / business permit.',
    'DTI' => 'Upload your DTI business name registration.',
    'SEC' => 'Upload your SEC registration certificate.',
    'BIR' => 'Upload your BIR certificate of registration.',
    'Optometrist License' => 'Upload the optometrist’s PRC license.',
    'Barangay Clearance' => 'Upload your current barangay clearance.',
    'Health Clearance' => 'Upload your health clearance certificate.',
    'Insurance' => 'Upload your clinic insurance document.',
    'Other Documents' => 'Upload any additional supporting document.',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clinic Approval — Eyecore</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
:root{--teal:#0d9488;--teal-d:#0f766e;--teal-l:#e6f7f5;--bg:#f6f8fa;--card:#fff;--ink:#0f172a;--sub:#64748b;--mut:#94a3b8;--bd:#e5e9ef;--ok:#047857;--ok-l:#d1fae5;--wa:#92400e;--wa-l:#fef3c7;--er:#b91c1c;--er-l:#fee2e2;--or:#c2410c;--or-l:#ffedd5;--ne:#475569;--ne-l:#eef1f5;--r:12px;--sh:0 1px 2px rgba(15,23,42,.04),0 4px 16px rgba(15,23,42,.05)}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--ink);font:14px/1.5 'Outfit',system-ui,sans-serif;min-height:100vh}
.nav{background:var(--card);border-bottom:1px solid var(--bd);height:56px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50}
.brand{display:flex;align-items:center;gap:10px;font-weight:700;font-size:17px}
.logo{width:28px;height:28px;border-radius:8px;background:var(--teal);color:#fff;display:grid;place-items:center;font-size:13px}
.nr{display:flex;align-items:center;gap:12px;color:var(--sub);font-size:13px}.nr b{color:var(--ink);font-weight:500}
.btn{display:inline-flex;align-items:center;gap:7px;font:inherit;font-weight:600;font-size:13px;border-radius:10px;padding:9px 16px;cursor:pointer;border:1px solid var(--bd);background:var(--card);color:var(--ink);text-decoration:none;transition:.15s}
.btn:hover{border-color:var(--teal)}
.btn-fwd,.btn-send{background:var(--teal);border-color:var(--teal);color:#fff}.btn-fwd:hover,.btn-send:hover{background:var(--teal-d)}
.btn-sm{padding:5px 11px;font-size:12px}
.wrap{max-width:980px;margin:0 auto;padding:28px 20px 56px;display:flex;flex-direction:column;gap:20px}
h1{font-size:22px;font-weight:700}.lead{color:var(--sub);margin-top:2px}
.card{background:var(--card);border:1px solid var(--bd);border-radius:var(--r);box-shadow:var(--sh)}
.ch{padding:18px 22px 0;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
.ch h2{font-size:15px;font-weight:600}.ch p{color:var(--sub);font-size:12.5px;margin-top:1px}
.cb{padding:18px 22px 22px}
.bar{height:10px;border-radius:99px;background:var(--ne-l);overflow:hidden}.bar i{display:block;height:100%;background:var(--teal);border-radius:99px;transition:width .6s}
.pg-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}.pg-top strong{font-size:26px;font-weight:700}.pg-top span{color:var(--sub)}
.legend{display:flex;gap:18px;margin-top:12px;color:var(--sub);font-size:12.5px;flex-wrap:wrap}.legend b{color:var(--ink)}
.dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}
.badge{display:inline-block;font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:99px;white-space:nowrap}
.b-ok{background:var(--ok-l);color:var(--ok)}.b-wa{background:var(--wa-l);color:var(--wa)}.b-er{background:var(--er-l);color:var(--er)}.b-or{background:var(--or-l);color:var(--or)}.b-ne{background:var(--ne-l);color:var(--ne)}
.alert{display:flex;gap:10px;padding:11px 14px;border-radius:10px;font-size:13px;align-items:flex-start}
.a-er{background:var(--er-l);color:var(--er)}.a-or{background:var(--or-l);color:var(--or)}.a-wa{background:var(--wa-l);color:var(--wa)}.a-in{background:var(--teal-l);color:var(--teal-d)}.a-ok{background:var(--ok-l);color:var(--ok)}
.alert i{margin-top:3px}
.steps{display:flex;align-items:center;gap:8px;margin:18px 0 22px}
.st{display:flex;align-items:center;gap:8px;cursor:pointer;color:var(--mut);font-weight:500}
.st .n{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;font-size:12px;font-weight:700;background:var(--ne-l);color:var(--sub)}
.st.active{color:var(--ink);font-weight:600}.st.active .n{background:var(--teal);color:#fff}
.st.done{color:var(--sub)}.st.done .n{background:var(--ok-l);color:var(--ok)}
.ln{flex:1;height:1px;background:var(--bd);max-width:70px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.fl{display:block;font-size:12px;font-weight:600;margin-bottom:6px}
.req{color:var(--er)}.ai-tag{font-size:10px;font-weight:700;background:var(--teal-l);color:var(--teal-d);padding:1px 6px;border-radius:4px;margin-left:4px}
.fi{width:100%;font:inherit;padding:10px 12px;border:1px solid var(--bd);border-radius:10px;background:var(--card);color:var(--ink)}
.fi:focus{outline:2px solid var(--teal);outline-offset:-1px}.fi.ai-filled{background:var(--teal-l);border-color:var(--teal)}
.fi-hint{font-size:11.5px;color:var(--mut);margin-top:5px}
.ph{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:6px}
.ph-title{font-size:18px;font-weight:700}.ph-sub{color:var(--sub);font-size:13px}
.ph-ctr{font-size:11.5px;font-weight:600;color:var(--sub);background:var(--ne-l);padding:3px 10px;border-radius:99px;white-space:nowrap}
.panel{display:none}.panel.active{display:block}
.idv-capture-box{border:1px solid var(--bd);border-radius:var(--r);padding:16px;display:grid;grid-template-columns:240px 1fr;gap:20px;align-items:center}
.idv-preview{aspect-ratio:4/3;border-radius:10px;background:var(--ne-l);overflow:hidden;display:grid;place-items:center;color:var(--mut)}
.idv-preview video,.idv-preview img{width:100%;height:100%;object-fit:cover}
.idv-empty{display:flex;flex-direction:column;align-items:center;gap:6px;font-size:12.5px}.idv-empty i{font-size:26px;opacity:.5}
.idv-capture-actions{display:flex;gap:8px;flex-wrap:wrap}
.idv-btn{display:inline-flex;align-items:center;gap:6px;font:inherit;font-weight:600;font-size:12.5px;padding:8px 14px;border-radius:10px;cursor:pointer;border:1px solid var(--bd);background:var(--card);color:var(--ink)}
.idv-btn:hover{border-color:var(--teal)}
.idv-btn-cam,.idv-btn-shot{background:var(--teal);border-color:var(--teal);color:#fff}
.idv-btn-retake{background:var(--er-l);border-color:transparent;color:var(--er)}
.foot,.wnav{display:flex;justify-content:space-between;align-items:center;margin-top:22px;padding-top:16px;border-top:1px solid var(--bd)}
.step-ctr{color:var(--sub);font-size:12.5px}
.tw{overflow-x:auto;margin-top:14px}
table{width:100%;border-collapse:collapse;min-width:680px}
th{text-align:left;font-size:11.5px;font-weight:600;color:var(--mut);padding:10px 22px;border-bottom:1px solid var(--bd);text-transform:uppercase;letter-spacing:.04em}
td{padding:13px 22px;border-bottom:1px solid var(--bd);font-size:13px;color:var(--sub)}
tr:last-child td{border-bottom:0}td.dn{color:var(--ink);font-weight:600}tbody tr:hover td{background:var(--bg)}
tr.go{cursor:pointer}
.upload-box{position:relative;border:1.5px dashed var(--bd);border-radius:var(--r);padding:34px 20px;text-align:center;background:var(--bg);transition:.15s;margin-top:16px}
.upload-box:hover,.upload-box.drag{border-color:var(--teal);background:var(--teal-l)}
.upload-box input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.ub-icon{width:40px;height:40px;border-radius:50%;background:var(--teal-l);color:var(--teal);display:grid;place-items:center;margin:0 auto 6px;font-size:16px}
.ub-title{font-size:14.5px;font-weight:600}.ub-sub,.ub-hint{color:var(--mut);font-size:12.5px}
.ai-state{display:none;flex-direction:column;align-items:center;gap:8px;padding:26px;margin-top:16px;border:1px solid var(--bd);border-radius:var(--r);background:var(--teal-l)}
.ai-state.visible{display:flex}
.ai-spinner{width:34px;height:34px;border-radius:50%;border:3px solid var(--bd);border-top-color:var(--teal);animation:spin .7s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.ai-state-title{font-weight:600;color:var(--teal-d)}.ai-state-sub{font-size:12px;color:var(--sub)}
.file-preview{display:none;align-items:center;gap:12px;border:1px solid var(--bd);border-radius:10px;padding:10px 14px;margin-top:16px}
.file-preview.visible{display:flex}.fp-icon{color:var(--teal);font-size:18px}.fp-name{flex:1;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fp-change{font-size:12px;color:var(--teal-d);cursor:pointer;text-decoration:underline}
.doc-preview-wrap{display:none;margin-top:8px}.doc-preview-wrap.visible{display:block}
.doc-preview-bar{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--sub)}
.doc-preview-bar strong{flex:1;color:var(--ink);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.doc-preview-open{font-size:12px;font-weight:600;color:var(--teal-d);text-decoration:none}
.fields-row{margin-top:18px}
.tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:18px}
.tab{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:500;padding:6px 12px;border-radius:99px;border:1px solid var(--bd);background:var(--card);color:var(--sub);cursor:pointer}
.tab.active{background:var(--teal);border-color:var(--teal);color:#fff}
.tab.done:not(.active)::after{content:"✓";color:var(--ok)}
.terms-wrap{display:flex;gap:10px;font-size:12.5px;color:var(--sub);margin-top:18px;padding:14px;background:var(--bg);border-radius:10px}
.terms-wrap input{accent-color:var(--teal);margin-top:3px}.terms-wrap label{cursor:pointer}
.hint-bar{font-size:12px;color:var(--mut);margin-top:10px}
.stack{display:flex;flex-direction:column;gap:10px}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}.chip{background:var(--er-l);color:var(--er);font-weight:600;font-size:12px;padding:3px 10px;border-radius:99px}
.support-email{color:var(--teal-d);font-weight:600;text-decoration:none}
.result-card{display:none;margin-top:18px;padding:16px;border-radius:10px;background:var(--ok-l);color:var(--ok)}
.result-card.visible{display:block}
.result-header{font-weight:700;margin-bottom:10px}
.result-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:10px}
.result-ocr{background:var(--card);border-radius:8px;padding:10px;font:12px/1.5 monospace;color:var(--sub);max-height:100px;overflow:auto}
.text-success{color:var(--ok)}.text-warning{color:var(--wa)}
.doc-modal-overlay{display:none;position:fixed;inset:0;z-index:1000;background:rgba(15,23,42,.6);align-items:center;justify-content:center;padding:24px}
.doc-modal-overlay.visible{display:flex}
.doc-modal{background:var(--card);border-radius:var(--r);width:100%;max-width:780px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden}
.doc-modal-header,.doc-modal-footer{display:flex;align-items:center;gap:10px;padding:12px 18px}
.doc-modal-header{border-bottom:1px solid var(--bd)}.doc-modal-header strong{flex:1}
.doc-modal-footer{border-top:1px solid var(--bd);justify-content:flex-end}
.doc-modal-close{background:var(--ne-l);border:0;width:30px;height:30px;border-radius:8px;cursor:pointer;color:var(--sub)}
.doc-modal-body{flex:1;overflow:auto;background:#1e293b;display:flex;align-items:center;justify-content:center;min-height:300px}
.doc-modal-img{width:100%;max-height:74vh;object-fit:contain}.doc-modal-pdf{width:100%;height:74vh;border:0}
@media(max-width:720px){.grid2,.grid3,.idv-capture-box,.result-stats{grid-template-columns:1fr}.steps .lbl{display:none}.nav{padding:0 14px}.nr b{display:none}}
</style>
</head>
<body>

<nav class="nav">
    <div class="brand"><div class="logo"><i class="fas fa-eye"></i></div>Eyecore</div>
    <div class="nr"><b><?= htmlspecialchars($user['clinic_name']) ?></b><a href="../admin/logout.php" class="btn btn-sm"><i class="fas fa-sign-out-alt"></i> Logout</a></div>
</nav>

<main class="wrap">

<?php if (isset($_SESSION['swal'])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $_SESSION['swal']['icon'] ?>',title:'<?= $_SESSION['swal']['title'] ?>',html:'<?= nl2br($_SESSION['swal']['text']) ?>',confirmButtonColor:'#0d9488'});
});
</script>
<?php unset($_SESSION['swal']); endif; ?>

<div><h1>Clinic Registration</h1><p class="lead">Complete identity verification and submit your required documents to activate your clinic.</p></div>

<!-- 1. REGISTRATION PROGRESS -->
<section class="card"><div class="cb">
    <div class="pg-top"><div><strong><?= $pct ?>%</strong> <span>of documents approved</span></div><span><?= $stats['uploaded'] ?> of <?= count($requiredDocs) ?> documents</span></div>
    <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
    <div class="legend">
        <span><i class="dot" style="background:var(--ok)"></i><b><?= $stats['uploaded'] ?></b> Approved</span>
        <span><i class="dot" style="background:#f59e0b"></i><b><?= $stats['pending'] ?></b> Pending review</span>
        <span><i class="dot" style="background:#ef4444"></i><b><?= $stats['missing'] ?></b> Missing or declined</span>
    </div>
</div></section>

<!-- 2. IDENTITY VERIFICATION -->
<section class="card idv-card">
    <div class="ch">
        <div><h2>Identity &amp; Face Verification</h2><p>Required before your clinic can be approved.</p></div>
        <?php if ($idVerified): ?><span class="badge b-ok">Verified</span>
        <?php elseif ($idDeclinedToday): ?><span class="badge b-er">Rejected</span>
        <?php elseif ($idStatus === 'Pending'): ?><span class="badge b-wa">Under review</span>
        <?php elseif ($idStatus === 'Rejected'): ?><span class="badge b-er">Rejected</span>
        <?php else: ?><span class="badge b-wa">Required</span><?php endif; ?>
    </div>
    <div class="cb">

    <?php if ($idVerified): ?>
        <div class="alert a-ok" style="margin-top:14px"><i class="fas fa-shield-halved"></i>
            <div><b>Identity verified.</b> <?= htmlspecialchars($identity['id_type']) ?> — verified on <?= date('M j, Y', strtotime($identity['reviewed_at'])) ?></div></div>

    <?php elseif ($idDeclinedToday): ?>
        <div class="alert a-er" style="margin-top:14px"><i class="fas fa-hourglass-half"></i>
            <div><b>Please try again tomorrow.</b> Your identity verification was declined today (<?= date('M j, Y', strtotime($identity['reviewed_at'])) ?>). You can re-upload your ID and selfie once per calendar day.</div></div>

    <?php elseif ($idStatus === 'Pending'): ?>
        <div class="alert a-wa" style="margin-top:14px"><i class="fas fa-hourglass-half"></i>
            <div><b>Submitted — under review.</b> Your <em><?= htmlspecialchars($identity['id_type']) ?></em> and selfie are with the admin team. You’ll be notified once verified.</div></div>

    <?php else: ?>
        <?php if ($idStatus === 'Rejected'): ?>
        <div class="alert a-er" style="margin-top:14px"><i class="fas fa-circle-exclamation"></i>
            <div><b>Rejected:</b> <?= htmlspecialchars($identity['rejection_reason'] ?: 'Please resubmit clearer photos.') ?></div></div>
        <?php endif; ?>

        <form id="identityForm" enctype="multipart/form-data">
            <div class="steps">
                <div class="st active" id="idv-tab-1" onclick="idvGoTo(0)"><span class="n">1</span><span class="lbl">ID Details</span></div><span class="ln"></span>
                <div class="st" id="idv-tab-2" onclick="idvGoTo(1)"><span class="n">2</span><span class="lbl">ID Photo</span></div><span class="ln"></span>
                <div class="st" id="idv-tab-3" onclick="idvGoTo(2)"><span class="n">3</span><span class="lbl">Selfie</span></div>
            </div>

            <div class="panel active" id="idv-panel-1">
                <div class="grid2">
                    <div>
                        <label class="fl">ID Type <span class="req">*</span></label>
                        <select id="idTypeSelect" name="id_type" class="fi" required>
                            <option value="">— Select an ID type —</option>
                            <?php foreach ($idTypeOptions as $opt): ?>
                                <option value="<?= htmlspecialchars($opt) ?>" <?= ($identity['id_type'] ?? '') === $opt ? 'selected' : '' ?>><?= htmlspecialchars($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="fl">ID Number <span class="req">*</span></label>
                        <input type="text" id="idNumber" name="id_number" class="fi" placeholder="Select ID type first" maxlength="20" value="<?= htmlspecialchars($identity['id_number'] ?? '') ?>" required>
                    </div>
                </div>
            </div>

            <div class="panel" id="idv-panel-2">
                <div class="ph"><div><div class="ph-title">Upload ID Photo</div><div class="ph-sub" id="idTypeHint">Select an ID type first</div></div></div>
                <div class="idv-capture-box" style="margin-top:14px">
                    <div class="idv-preview" id="idPreviewBox">
                        <div class="idv-empty" id="idEmptyState"><i class="fas fa-id-badge"></i><span>No ID photo yet</span></div>
                        <img id="idShot" alt="ID preview" style="display:none;">
                        <canvas id="idCanvas" style="display:none;"></canvas>
                        <video id="idVideo" autoplay playsinline muted style="display:none;"></video>
                    </div>
                    <div class="idv-capture-actions">
                        <button type="button" class="idv-btn idv-btn-cam" id="btnStartIdCam" onclick="startIdCam()"><i class="fas fa-video"></i> Use Camera</button>
                        <button type="button" class="idv-btn idv-btn-shot" id="btnSnapId" onclick="captureId()" style="display:none;"><i class="fas fa-camera"></i> Capture ID</button>
                        <button type="button" class="idv-btn" onclick="document.getElementById('idFile').click()"><i class="fas fa-upload"></i> Upload Photo</button>
                        <button type="button" class="idv-btn idv-btn-retake" id="btnRetakeId" onclick="retakeId()" style="display:none;"><i class="fas fa-rotate-left"></i> Retake</button>
                    </div>
                    <input type="file" id="idFile" name="id_image" accept="image/*" style="display:none;" onchange="handleIdUpload(this)">
                </div>
            </div>

            <div class="panel" id="idv-panel-3">
                <div class="ph"><div><div class="ph-title">Take a Selfie</div><div class="ph-sub">Look straight at the camera in a well-lit area.</div></div></div>
                <div class="idv-capture-box" style="margin-top:14px">
                    <div class="idv-preview" id="selfiePreviewBox">
                        <video id="selfieVideo" autoplay playsinline muted></video>
                        <img id="selfieShot" alt="Selfie preview" style="display:none;">
                        <canvas id="selfieCanvas" style="display:none;"></canvas>
                    </div>
                    <div class="idv-capture-actions">
                        <button type="button" class="idv-btn idv-btn-cam" id="btnStartCam" onclick="startSelfieCam()"><i class="fas fa-video"></i> Start Camera</button>
                        <button type="button" class="idv-btn idv-btn-shot" id="btnTakeShot" onclick="captureSelfie()" style="display:none;"><i class="fas fa-camera"></i> Take Photo</button>
                        <button type="button" class="idv-btn idv-btn-retake" id="btnRetakeSelfie" onclick="retakeSelfie()" style="display:none;"><i class="fas fa-rotate-left"></i> Retake</button>
                    </div>
                    <input type="file" id="selfieFile" name="selfie" accept="image/*" capture="user" style="display:none;">
                </div>
            </div>

            <div class="wnav">
                <button type="button" class="btn" id="idvBtnBack" onclick="idvPrevStep()" style="visibility:hidden;"><i class="fas fa-arrow-left"></i> Previous</button>
                <span class="step-ctr" id="idvStepCtr">Step 1 of 3</span>
                <button type="button" class="btn btn-fwd btn-send" id="idvBtnNext" onclick="idvNextStep()">Next <i class="fas fa-arrow-right"></i></button>
            </div>
        </form>

        <div class="result-card" id="idvResultCard">
            <div class="result-header"><i class="fas fa-circle-check"></i> Submitted for review</div>
            <div class="result-stats">
                <div>Face match: <span id="resFaceMatch" style="font-weight:600">—</span></div>
                <div>Name: <span id="resNameMatch" style="font-weight:600">—</span></div>
                <div>ID number: <span id="resIdMatch" style="font-weight:600">—</span></div>
            </div>
            <div class="result-ocr" id="resOcrText"></div>
        </div>
    <?php endif; ?>
    </div>
</section>

<!-- 3. DOCUMENT OVERVIEW -->
<section class="card">
    <div class="ch"><div><h2>Required Documents</h2><p>Reference list of every document and its current status.</p></div></div>
    <div class="tw">
        <table>
            <thead><tr><th>Document</th><th>Status</th><th>Owner</th><th>Released</th><th>Expires</th><th>Remarks</th><th>File</th></tr></thead>
            <tbody>
<?php foreach ($docsToShow as $doc => $data):
    $row = $data['row']; $s = $data['status'];
    $declinedToday = $data['declined_today'] ?? false;
    $pc = 'b-ne'; $label = $s;
    if ($s === 'Approved') $pc = 'b-ok';
    elseif ($s === 'Pending') $pc = 'b-wa';
    elseif ($s === 'Awaiting Re-upload') $pc = 'b-or';
    elseif ($s === 'Rejected') { $pc = 'b-er'; }
    $slugRow = preg_replace('/[^a-z0-9]/','_',strtolower($doc));
?>
<tr <?= $data['can_upload'] ? 'class="go" onclick="jumpTo(\''.$slugRow.'\')"' : '' ?>>
    <td class="dn"><?= $doc ?></td>
    <td><span class="badge <?= $pc ?>"><?= htmlspecialchars($label) ?></span></td>
    <td><?= $row['owner_name']??'—' ?></td>
    <td><?= $row['released_at']??'—' ?></td>
    <td><?= $row['expires_at']??'—' ?></td>
    <td>
        <?php if($s==='Awaiting Re-upload'): ?>
            <span style="color:var(--or);font-weight:600;">Waiting for your re-upload</span>
        <?php elseif($row && $row['status']==='Rejected' && $declinedToday): ?>
            <span style="color:var(--er);">Re-upload available tomorrow</span>
        <?php elseif($row && $row['status']==='Rejected' && $row['rejection_reason']): ?>
            <span style="color:var(--er);"><?= htmlspecialchars(substr($row['rejection_reason'],0,35)) ?>…</span>
        <?php elseif($row && $row['status']==='Rejected'): ?>
            <span style="color:var(--er);">Declined</span>
        <?php else: ?>—<?php endif; ?>
    </td>
    <td>
        <?php if($row && $row['file_path']): ?>
        <button type="button" class="btn btn-sm" onclick="event.stopPropagation();openDocModal('../<?= htmlspecialchars($row['file_path']) ?>','<?= htmlspecialchars($doc, ENT_QUOTES) ?>')"><i class="fas fa-eye"></i> View</button>
        <?php else: ?><span style="color:var(--mut);font-size:12px;">No file</span><?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- 4. DOCUMENT SUBMISSION / 5. STATUS -->
<?php if ($showUploadForm): ?>
<section class="card">
    <div class="ch"><div><h2>Submit Documents</h2><p><?= count($docsToUpload) ?> document(s) need your attention. Our AI reads each file and fills in the details.</p></div></div>
    <div class="cb">

    <?php if (!empty($waitTomorrowDocs)): ?>
        <div class="alert a-er" style="margin:6px 0 18px"><i class="fas fa-hourglass-half"></i>
            <div><b><?= implode(', ', $waitTomorrowDocs) ?></b> <?= count($waitTomorrowDocs) > 1 ? 'were' : 'was' ?> declined today. You can re-upload <?= count($waitTomorrowDocs) > 1 ? 'them' : 'it' ?> <b>tomorrow</b> — one submission per document per day.</div></div>
    <?php endif; ?>

    <div class="tabs" id="stepsStrip">
        <?php foreach ($docsToUpload as $i => $doc): $slug = preg_replace('/[^a-z0-9]/','_',strtolower($doc)); ?>
        <button type="button" class="tab ws <?= $i===0?'active':'' ?>" id="wstab-<?= $slug ?>" data-idx="<?= $i ?>" onclick="goTo(<?= $i ?>)"><?= $doc ?></button>
        <?php endforeach; ?>
    </div>

    <form id="docForm" action="/api/upload_documents.php" method="POST" enctype="multipart/form-data" onsubmit="return false;">
        <input type="hidden" name="clinic_id" value="<?= $clinicId ?>">
        <input type="hidden" name="ocr_warning" id="ocr_warning_hidden" value="">

        <?php foreach ($docsToUpload as $i => $doc):
            $row  = $docsToShow[$doc]['row'];
            $attempts = $docsToShow[$doc]['attempts'];
            $slug = preg_replace('/[^a-z0-9]/','_',strtolower($doc));
        ?>
        <div class="panel <?= $i===0?'active':'' ?>" id="panel-<?= $slug ?>" data-idx="<?= $i ?>">
            <div class="ph">
                <div>
                    <div class="ph-title"><?= $doc ?></div>
                    <div class="ph-sub"><?= ($row && $row['status']==='Rejected') ? 'Re-upload this rejected document.' : ($docDesc[$doc] ?? 'Upload your document.') ?></div>
                </div>
                <div class="ph-ctr">Document <?= $i+1 ?> of <?= count($docsToUpload) ?></div>
            </div>

            <?php if ($row && $row['status']==='Awaiting Re-upload'): ?>
            <div class="alert a-or" style="margin-top:14px"><i class="fas fa-arrow-up-from-bracket"></i>
                <div><b>Re-upload requested.</b> The administrator re-opened this document. Upload a corrected copy to continue the review.</div></div>
            <?php elseif ($row && $row['status']==='Rejected'): ?>
            <div class="alert a-er" style="margin-top:14px"><i class="fas fa-circle-exclamation"></i>
                <div><b>Declined:</b> <?= htmlspecialchars($row['rejection_reason']??'Please re-upload a valid document.') ?>
                <br><small>Submission #<?= $attempts ?> declined — you may re-upload one corrected copy per day.</small></div></div>
            <?php endif; ?>

            <div class="upload-box" id="ub-<?= $slug ?>">
                <input type="file" name="docs[<?= $doc ?>]" id="file-<?= $slug ?>" accept=".jpg,.jpeg,.png,.pdf" required onchange="handleFile('<?= $slug ?>','<?= addslashes($doc) ?>',this)">
                <div class="ub-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                <div class="ub-title">Upload document</div>
                <div class="ub-sub">Click to browse or drag &amp; drop your file</div>
                <div class="ub-hint">JPG, PNG, PDF · Max 5MB</div>
            </div>

            <div class="ai-state" id="ai-<?= $slug ?>">
                <div class="ai-spinner"></div>
                <div class="ai-state-title">AI is reading your document…</div>
                <div class="ai-state-sub">Extracting owner name, release date, and expiry date</div>
            </div>

            <div class="file-preview" id="fp-<?= $slug ?>">
                <i class="fas fa-file-lines fp-icon"></i>
                <span class="fp-name" id="fp-name-<?= $slug ?>"></span>
                <span class="fp-change" onclick="resetFile('<?= $slug ?>')">Change file</span>
            </div>

            <div class="doc-preview-wrap" id="prev-<?= $slug ?>">
                <div class="doc-preview-bar">
                    <strong id="prev-name-<?= $slug ?>"></strong>
                    <span id="prev-size-<?= $slug ?>"></span>
                    <a href="#" class="doc-preview-open" onclick="event.preventDefault(); openDocModalFromSlug('<?= $slug ?>');"><i class="fas fa-expand"></i> Full view</a>
                </div>
            </div>

            <div class="fields-row grid3" id="fields-<?= $slug ?>" style="display:none;">
                <div>
                    <label class="fl">Owner Name <span class="ai-tag">AI</span> <span class="req">*</span></label>
                    <input type="text" name="owner_name[<?= $doc ?>]" id="own-<?= $slug ?>" class="fi" placeholder="e.g. Juan dela Cruz" value="<?= htmlspecialchars($row['owner_name']??'') ?>" required>
                    <div class="fi-hint">Verify or edit if incorrect</div>
                </div>
                <div>
                    <label class="fl">Release Date <span class="ai-tag">AI</span> <span class="req">*</span></label>
                    <input type="date" name="released_at[<?= $doc ?>]" id="rel-<?= $slug ?>" class="fi" value="<?= $row['released_at']??'' ?>" required>
                    <div class="fi-hint">Verify or edit if incorrect</div>
                </div>
                <div>
                    <label class="fl">Expiry Date <span class="ai-tag">AI</span> <span class="req">*</span></label>
                    <input type="date" name="expires_at[<?= $doc ?>]" id="exp-<?= $slug ?>" class="fi" value="<?= $row['expires_at']??'' ?>" required>
                    <div class="fi-hint">Verify or edit if incorrect</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div id="termsWrap" style="display:none;">
            <div class="terms-wrap">
                <input type="checkbox" id="terms">
                <label for="terms">I certify that all uploaded documents are <strong>valid and not expired</strong>. I understand that submitting false or expired documents may result in clinic suspension or termination of services.</label>
            </div>
            <div class="hint-bar">Approved or pending documents can’t be re-uploaded unless declined. A declined document may be re-uploaded once per calendar day.</div>
        </div>

        <div class="wnav">
            <button type="button" class="btn" id="btnBack" onclick="prevStep()"><i class="fas fa-arrow-left"></i> Back</button>
            <span class="step-ctr" id="stepCtr">Step 1 of <?= count($docsToUpload) ?></span>
            <button type="button" class="btn btn-fwd btn-send" id="btnNext" onclick="nextStep()">Next <i class="fas fa-arrow-right"></i></button>
        </div>
    </form>
    </div>
</section>

<?php elseif (!empty($waitTomorrowDocs)): ?>
<section class="card">
    <div class="ch"><div><h2>Review Status</h2></div></div>
    <div class="cb">
        <div class="alert a-er"><i class="fas fa-hourglass-half"></i>
            <div><b>Please try again tomorrow.</b> The following document(s) were declined today. Each document can only be re-uploaded once per calendar day.
            <div class="chips"><?php foreach ($waitTomorrowDocs as $wd): ?><span class="chip"><?= htmlspecialchars($wd) ?></span><?php endforeach; ?></div></div></div>
        <p style="margin-top:12px;color:var(--sub)">Need help? <a href="mailto:support@eyecore.com" class="support-email"><i class="fas fa-envelope"></i> support@eyecore.com</a></p>
    </div>
</section>

<?php elseif (!$allApproved): ?>
<section class="card">
    <div class="ch"><div><h2>Review Status</h2></div></div>
    <div class="cb"><div class="alert a-wa"><i class="fas fa-clock"></i>
        <div><b>Documents under review.</b> All documents have been submitted and are being reviewed. You’ll be notified once the process is complete. No uploads needed at this time.</div></div></div>
</section>

<?php else: ?>
<section class="card">
    <div class="ch"><div><h2>Review Status</h2></div></div>
    <div class="cb"><div class="alert a-ok"><i class="fas fa-circle-check"></i>
        <div><b>All documents approved.</b> No further action is required. Your clinic is ready for activation.</div></div></div>
</section>
<?php endif; ?>

</main>

<!-- DOCUMENT VIEW MODAL -->
<div class="doc-modal-overlay" id="docModalOverlay" onclick="if(event.target===this) closeDocModal()">
    <div class="doc-modal">
        <div class="doc-modal-header">
            <i class="fas fa-file-lines" style="color:var(--teal)"></i>
            <strong id="docModalTitle">Document</strong>
            <button type="button" class="doc-modal-close" onclick="closeDocModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="doc-modal-body" id="docModalBody"></div>
        <div class="doc-modal-footer">
            <a href="#" id="docModalOpenNew" target="_blank" class="doc-preview-open"><i class="fas fa-up-right-from-square"></i> Open in new tab</a>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
/* ═══ DOCUMENTS JS (unchanged) ═══ */
const DOCS      = <?= json_encode(array_values($docsToUpload)) ?>;
const TOTAL     = DOCS.length;
let   current   = 0;
const fileData  = {};

function slug(doc){ return doc.toLowerCase().replace(/[^a-z0-9]/g,'_'); }
function isPdfUrl(url){ return url.split('?')[0].toLowerCase().endsWith('.pdf'); }
function openDocModal(url, title){
    const overlay = document.getElementById('docModalOverlay');
    const body    = document.getElementById('docModalBody');
    const titleEl = document.getElementById('docModalTitle');
    const openNew = document.getElementById('docModalOpenNew');
    titleEl.textContent = title || 'Document';
    openNew.href = url;
    body.innerHTML = '';
    if (isPdfUrl(url)) {
        const embed = document.createElement('embed');
        embed.src = url; embed.type = 'application/pdf'; embed.className = 'doc-modal-pdf';
        body.appendChild(embed);
    } else {
        const img = document.createElement('img');
        img.src = url; img.alt = title || 'Document preview'; img.className = 'doc-modal-img';
        body.appendChild(img);
    }
    overlay.classList.add('visible');
    document.body.style.overflow = 'hidden';
}
function openDocModalFromSlug(sl){
    const fd = fileData[sl];
    if(!fd) return;
    const url = URL.createObjectURL(new Blob([Uint8Array.from(atob(fd.base64), c=>c.charCodeAt(0))], {type: fd.mimeType}));
    openDocModal(url, fd.fileName);
}
function closeDocModal(){
    document.getElementById('docModalOverlay').classList.remove('visible');
    document.getElementById('docModalBody').innerHTML = '';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e=>{ if(e.key === 'Escape') closeDocModal(); });

function goTo(idx){
    if(idx<0||idx>=TOTAL) return;
    DOCS.forEach((d,i)=>{
        const sl = slug(d);
        document.getElementById('panel-'+sl)?.classList.toggle('active', i===idx);
        document.getElementById('wstab-'+sl)?.classList.toggle('active', i===idx);
        if(i<idx) document.getElementById('wstab-'+sl)?.classList.add('done');
    });
    current = idx;
    const sl = slug(DOCS[idx]);
    document.getElementById('termsWrap').style.display = idx===TOTAL-1 ? 'block':'none';
    const btnBack = document.getElementById('btnBack');
    const btnNext = document.getElementById('btnNext');
    btnBack.style.visibility = idx===0 ? 'hidden':'visible';
    if(idx===TOTAL-1){
        btnNext.className = 'btn btn-fwd btn-send';
        btnNext.innerHTML = '<i class="fas fa-paper-plane" style="margin-right:6px;"></i>Submit '+TOTAL+' Document(s)';
        btnNext.onclick = doSubmit;
    } else {
        btnNext.className = 'btn btn-fwd';
        btnNext.innerHTML = 'Next <i class="fas fa-arrow-right"></i>';
        btnNext.onclick = nextStep;
    }
    document.getElementById('stepCtr').textContent = 'Step '+(idx+1)+' of '+TOTAL;
    document.getElementById('wstab-'+sl)?.scrollIntoView({behavior:'smooth',block:'nearest',inline:'center'});
}

function nextStep(){
    const doc = DOCS[current], sl = slug(doc);
    const ownV = document.getElementById('own-'+sl)?.value.trim();
    const relV = document.getElementById('rel-'+sl)?.value;
    const expV = document.getElementById('exp-'+sl)?.value;
    const hasFile = !!fileData[sl];
    let errs = [];
    if(!hasFile) errs.push('Please upload a document file first');
    if(!ownV)   errs.push('Owner name is required');
    if(!relV)   errs.push('Release date is required');
    if(!expV)   errs.push('Expiry date is required');
    if(relV && expV){
        if(new Date(expV)<new Date(relV)) errs.push('Expiry date cannot be before release date');
        const today=new Date(); today.setHours(0,0,0,0);
        if(new Date(expV)<today) errs.push('Document is already expired');
    }
    if(errs.length){
        Swal.fire({icon:'warning',title:'Complete this step',html:errs.map(e=>`• ${e}`).join('<br>'),confirmButtonColor:'#0d9488'});
        return;
    }
    goTo(current+1);
}
function prevStep(){ goTo(current-1); }
function jumpTo(sl){ const idx=DOCS.findIndex(d=>slug(d)===sl); if(idx!==-1){ goTo(idx); document.getElementById('docForm')?.scrollIntoView({behavior:'smooth',block:'start'}); } }

async function handleFile(sl, docName, input){
    if(!input.files.length) return;
    const file = input.files[0];

    document.getElementById('prev-name-'+sl).textContent = file.name;
    document.getElementById('prev-size-'+sl).textContent = (file.size/1024).toFixed(1)+' KB';
    document.getElementById('prev-'+sl).classList.add('visible');

    document.getElementById('fp-name-'+sl).textContent = file.name;
    document.getElementById('fp-'+sl).classList.add('visible');
    document.getElementById('ub-'+sl).style.display = 'none';
    document.getElementById('ai-'+sl).classList.add('visible');
    document.getElementById('fields-'+sl).style.display = 'none';

    const base64 = await toBase64(file);
    const mime   = file.type || 'image/jpeg';
    fileData[sl] = { base64: base64.split(',')[1], mimeType: mime, fileName: file.name };

    try {
        const extracted = await extractWithClaude(base64.split(',')[1], mime, docName);
        fillField('own-'+sl, extracted.owner_name);
        fillDateField('rel-'+sl, extracted.release_date);
        fillDateField('exp-'+sl, extracted.expiry_date);
    } catch(e){ console.warn('AI extraction failed:', e); }

    document.getElementById('ai-'+sl).classList.remove('visible');
    document.getElementById('fields-'+sl).style.display = 'grid';
}

function fillField(id, val){
    const el = document.getElementById(id);
    if(!el) return;
    if(val){ el.value = val; el.classList.add('ai-filled'); }
    else { el.classList.remove('ai-filled'); }
}
function fillDateField(id, val){
    const el = document.getElementById(id);
    if(!el) return;
    if(val){
        const d = parseDate(val);
        if(d){ el.value = d; el.classList.add('ai-filled'); }
    }
}
function parseDate(str){
    if(!str) return null;
    if(/^\d{4}-\d{2}-\d{2}$/.test(str)) return str;
    const d = new Date(str);
    if(!isNaN(d)){
        const y = d.getFullYear();
        const m = String(d.getMonth()+1).padStart(2,'0');
        const dy= String(d.getDate()).padStart(2,'0');
        return `${y}-${m}-${dy}`;
    }
    return null;
}

async function extractWithClaude(base64, mimeType, docName) {
    try {
        const res = await fetch('/api/ocr_extract.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ base64, mimeType, docName })
        });
        if (!res.ok) return { owner_name: null, release_date: null, expiry_date: null };
        let data;
        try { data = JSON.parse(await res.text()); } catch (e) { return { owner_name: null, release_date: null, expiry_date: null }; }

        if (data.warning === 'blurry') {
            Swal.fire({ 
                icon: 'warning', 
                title: 'Document is Blurry', 
                text: 'We could not read the text clearly. Please try uploading a clearer photo or double-check the details.', 
                confirmButtonColor: '#0d9488' 
            });
            document.getElementById('ocr_warning_hidden').value = 'Blurry Document';
        } else if (data.warning === 'address_mismatch') {
            Swal.fire({ 
                icon: 'info', 
                title: 'Address Notice', 
                text: 'The detected address seems outside Cavite. You can continue, but please verify the address on your document.', 
                confirmButtonColor: '#0d9488' 
            });
            document.getElementById('ocr_warning_hidden').value = 'Address Outside Cavite';
        } else {
            document.getElementById('ocr_warning_hidden').value = '';
        }
        return data;
    } catch (err) { return { owner_name: null, release_date: null, expiry_date: null }; }
}
function toBase64(file){
    return new Promise((res,rej)=>{
        const r = new FileReader();
        r.onload  = ()=> res(r.result);
        r.onerror = ()=> rej(new Error('read failed'));
        r.readAsDataURL(file);
    });
}
function resetFile(sl){
    const fileInput = document.querySelector(`#panel-${sl} input[type="file"]`);
    if(fileInput) fileInput.value = '';
    delete fileData[sl];
    document.getElementById('fp-'+sl).classList.remove('visible');
    document.getElementById('ub-'+sl).style.display = '';
    document.getElementById('fields-'+sl).style.display = 'none';
    document.getElementById('ai-'+sl).classList.remove('visible');
    document.getElementById('prev-'+sl)?.classList.remove('visible');
    ['own-','rel-','exp-'].forEach(p=>document.getElementById(p+sl)?.classList.remove('ai-filled'));
    document.getElementById('ocr_warning_hidden').value = '';
}

document.querySelectorAll('.upload-box').forEach(z=>{
    z.addEventListener('dragover', e=>{ e.preventDefault(); z.classList.add('drag'); });
    z.addEventListener('dragleave', ()=> z.classList.remove('drag'));
    z.addEventListener('drop',      ()=> z.classList.remove('drag'));
});

function doSubmit(){
    let allErrs = [];
    DOCS.forEach(doc=>{
        const sl = slug(doc);
        if(!fileData[sl]) allErrs.push(`${doc} – No file uploaded`);
        const ownV = document.getElementById('own-'+sl)?.value.trim();
        const relV = document.getElementById('rel-'+sl)?.value;
        const expV = document.getElementById('exp-'+sl)?.value;
        if(!ownV) allErrs.push(`${doc} – Owner name missing`);
        if(!relV) allErrs.push(`${doc} – Release date missing`);
        if(!expV) allErrs.push(`${doc} – Expiry date missing`);
        if(relV&&expV){
            if(new Date(expV)<new Date(relV)) allErrs.push(`${doc} – Expiry before release date`);
            const t=new Date();t.setHours(0,0,0,0);
            if(new Date(expV)<t) allErrs.push(`${doc} – Document already expired`);
        }
    });
    if(!document.getElementById('terms').checked) allErrs.push('You must agree to the terms & conditions');
    if(allErrs.length){
        Swal.fire({icon:'error',title:'Please fix these issues',html:allErrs.map(e=>`• ${e}`).join('<br>'),confirmButtonColor:'#0d9488'});
        return;
    }
    Swal.fire({
        title:'Submit Documents?',
        html:`Submitting <strong>${TOTAL} document(s)</strong>. Please confirm all details are correct.`,
        icon:'question', showCancelButton:true,
        confirmButtonColor:'#0d9488', cancelButtonColor:'#6c757d',
        confirmButtonText:'<i class="fas fa-paper-plane"></i> Submit Now',
        cancelButtonText:'Cancel', reverseButtons:true
    }).then(r=>{
        if(r.isConfirmed){
            Swal.fire({title:'Uploading…',html:'Please wait.',allowOutsideClick:false,didOpen:()=>Swal.showLoading()});
            document.getElementById('docForm').submit();
        }
    });
}

document.addEventListener('DOMContentLoaded',()=>{ if(TOTAL>0) goTo(0); });

/* ═══ IDENTITY VERIFICATION JS (unchanged) ═══ */
let idvCurrentStep = 0;
const idvTotalSteps = 3;
let selfieStream = null, idStream = null, selfieData = null, idData = null, isOcrRunning = false;

const ID_TYPE_HINTS = {
    'Philippine Passport':'Photo page with MRZ at bottom',
    "Driver's License":"Front side with photo & license no.",
    'UMID':'Front side (photo + CRN)',
    'PhilHealth ID':'Front side with PhilHealth number',
    'SSS ID':'Front side with SS number',
    'PRC ID':'Front side with PRC license number',
    'Postal ID':'Front side with photo & ID number',
    "Voter's ID":'Front side with voter info',
    'National ID (PhilSys)':'Front side with PhilSys number & QR',
    'Barangay ID':'Front side with photo & barangay',
};

const ID_FORMATS = {
    'Philippine Passport':   { max: 9,  pattern: /^[A-Z0-9]{8,9}$/, mask: 'P1234567A', hint: 'Format: Letter + 7 Numbers + Letter (e.g. P1234567A)' },
    "Driver's License":      { max: 13, pattern: /^[A-Z0-9\-]{10,13}$/, mask: 'N01-23-456789', hint: 'Format: N01-XX-XXXXXX' },
    'UMID':                  { max: 12, pattern: /^\d{4}-\d{7}-\d{1}$/, mask: '0000-0000000-0', hint: 'Format: 4 digits - 7 digits - 1 digit' },
    'PhilHealth ID':         { max: 14, pattern: /^\d{2}-\d{9}-\d{1}$/, mask: '00-000000000-0', hint: 'Format: 2 digits - 9 digits - 1 digit' },
    'SSS ID':                { max: 12, pattern: /^\d{2}-\d{7}-\d{1}$/, mask: '00-0000000-0', hint: 'Format: 2 digits - 7 digits - 1 digit' },
    'PRC ID':                { max: 7,  pattern: /^\d{7}$/, mask: '0000000', hint: 'Format: 7 digits' },
    'Postal ID':             { max: 15, pattern: /^[A-Z0-9\-]{10,15}$/, mask: 'PHL-0000-000', hint: 'Format: Letters and numbers' },
    "Voter's ID":            { max: 22, pattern: /^[A-Z0-9\-]{10,22}$/, mask: '0000-0000A-0000', hint: 'Format: Alphanumeric' },
    'National ID (PhilSys)': { max: 16, pattern: /^\d{4}-\d{4}-\d{4}-\d{4}$/, mask: '0000-0000-0000-0000', hint: 'Format: 16 digits (XXXX-XXXX-XXXX-XXXX)' },
    'Barangay ID':           { max: 20, pattern: /^[A-Z0-9\-]{5,20}$/, mask: 'BRGY-0000', hint: 'Format: Alphanumeric' },
};

document.getElementById('idTypeSelect')?.addEventListener('change', e => {
    const hint = document.getElementById('idTypeHint');
    const numInput = document.getElementById('idNumber');
    const type = e.target.value;
    if (type && ID_FORMATS[type]) {
        hint.textContent = 'Capture or upload the ' + (ID_TYPE_HINTS[type] || 'front side of this ID');
        numInput.placeholder = ID_FORMATS[type].mask;
        numInput.maxLength = ID_FORMATS[type].max;
        numInput.value = '';
    } else {
        hint.textContent = 'Select an ID type first';
        numInput.placeholder = 'Select ID type first';
        numInput.maxLength = 20;
    }
});

function idvGoTo(step) {
    idvCurrentStep = step;
    for (let i = 0; i < idvTotalSteps; i++) {
        document.getElementById(`idv-panel-${i+1}`).classList.toggle('active', i === step);
        document.getElementById(`idv-tab-${i+1}`).classList.toggle('active', i === step);
        document.getElementById(`idv-tab-${i+1}`).classList.toggle('done', i < step);
    }
    document.getElementById('idvBtnBack').style.visibility = step === 0 ? 'hidden' : 'visible';
    document.getElementById('idvStepCtr').textContent = `Step ${step + 1} of ${idvTotalSteps}`;
    const btnNext = document.getElementById('idvBtnNext');
    if (step === idvTotalSteps - 1) {
        btnNext.innerHTML = '<i class="fas fa-paper-plane"></i> Submit Identity';
        btnNext.onclick = submitIdentity;
    } else {
        btnNext.innerHTML = 'Next <i class="fas fa-arrow-right"></i>';
        btnNext.onclick = idvNextStep;
    }
}

function idvNextStep() {
    if (idvCurrentStep === 0) {
        const idType = document.getElementById('idTypeSelect').value;
        const idNum  = document.getElementById('idNumber').value.trim();
        if (!idType || !idNum) {
            Swal.fire({icon:'warning', title:'Incomplete', text:'Please fill in ID Type and ID Number.', confirmButtonColor:'#0d9488'});
            return;
        }
        const format = ID_FORMATS[idType];
        if (format && !format.pattern.test(idNum)) {
            Swal.fire({
                icon: 'warning', title: 'Invalid ID Format',
                html: `The ID Number for <b>${idType}</b> does not match the required format.<br><br><b>${format.hint}</b>`,
                confirmButtonColor: '#0d9488'
            });
            return;
        }
    }
    if (idvCurrentStep === 1) {
        if (!idData) {
            Swal.fire({icon:'warning', title:'Incomplete', text:'Please upload or capture your ID photo.', confirmButtonColor:'#0d9488'});
            return;
        }
    }
    if (idvCurrentStep < idvTotalSteps - 1) idvGoTo(idvCurrentStep + 1);
}
function idvPrevStep() { if (idvCurrentStep > 0) idvGoTo(idvCurrentStep - 1); }

async function startSelfieCam() {
    try {
        selfieStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: 640, height: 480 }, audio: false });
        const v = document.getElementById('selfieVideo');
        v.srcObject = selfieStream;
        v.style.display = 'block';
        document.getElementById('selfieShot').style.display = 'none';
        document.getElementById('btnStartCam').style.display = 'none';
        document.getElementById('btnTakeShot').style.display = '';
        document.getElementById('btnRetakeSelfie').style.display = 'none';
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Camera unavailable', text: 'Please allow camera access.', confirmButtonColor: '#0d9488' });
    }
}
function captureSelfie() {
    const video  = document.getElementById('selfieVideo');
    const canvas = document.getElementById('selfieCanvas');
    canvas.width  = video.videoWidth  || 640;
    canvas.height = video.videoHeight || 480;
    canvas.getContext('2d').drawImage(video, 0, 0);
    canvas.toBlob(blob => {
        const reader = new FileReader();
        reader.onload = e => {
            selfieData = { blob, dataUrl: e.target.result };
            document.getElementById('selfieShot').src = e.target.result;
            document.getElementById('selfieShot').style.display = 'block';
            video.style.display = 'none';
            stopSelfieCam();
            document.getElementById('btnStartCam').style.display = 'none';
            document.getElementById('btnTakeShot').style.display = 'none';
            document.getElementById('btnRetakeSelfie').style.display = '';
        };
        reader.readAsDataURL(blob);
    }, 'image/jpeg', 0.9);
}
function retakeSelfie() {
    selfieData = null;
    document.getElementById('selfieShot').style.display = 'none';
    document.getElementById('btnRetakeSelfie').style.display = 'none';
    document.getElementById('btnStartCam').style.display = '';
    startSelfieCam();
}
function stopSelfieCam() { if (selfieStream) { selfieStream.getTracks().forEach(t => t.stop()); selfieStream = null; } }

async function startIdCam() {
    try {
        idStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment', width: 1280, height: 720 }, audio: false });
        const v = document.getElementById('idVideo');
        v.srcObject = idStream;
        v.style.display = 'block';
        document.getElementById('idEmptyState').style.display = 'none';
        document.getElementById('idShot').style.display = 'none';
        document.getElementById('btnStartIdCam').style.display = 'none';
        document.getElementById('btnSnapId').style.display = '';
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Camera unavailable', text: 'Use Upload Photo.', confirmButtonColor: '#0d9488' });
    }
}
function captureId() {
    const video  = document.getElementById('idVideo');
    const canvas = document.getElementById('idCanvas');
    canvas.width  = video.videoWidth  || 1280;
    canvas.height = video.videoHeight || 720;
    canvas.getContext('2d').drawImage(video, 0, 0);
    canvas.toBlob(blob => {
        const reader = new FileReader();
        reader.onload = e => {
            idData = { blob, dataUrl: e.target.result };
            document.getElementById('idShot').src = e.target.result;
            document.getElementById('idShot').style.display = 'block';
            video.style.display = 'none';
            stopIdCam();
            document.getElementById('btnStartIdCam').style.display = 'none';
            document.getElementById('btnSnapId').style.display = 'none';
            document.getElementById('btnRetakeId').style.display = '';
        };
        reader.readAsDataURL(blob);
    }, 'image/jpeg', 0.92);
}
function handleIdUpload(input) {
    if (!input.files || !input.files.length) return;
    const file = input.files[0];
    if (file.size > 8 * 1024 * 1024) {
        Swal.fire({ icon:'error', title:'File too large', text:'Max 8MB.', confirmButtonColor:'#0d9488' });
        input.value = ''; return;
    }
    const reader = new FileReader();
    reader.onload = e => {
        idData = { blob: file, dataUrl: e.target.result };
        document.getElementById('idShot').src = e.target.result;
        document.getElementById('idShot').style.display = 'block';
        document.getElementById('idEmptyState').style.display = 'none';
        document.getElementById('btnStartIdCam').style.display = 'none';
        document.getElementById('btnRetakeId').style.display = '';
    };
    reader.readAsDataURL(file);
}
function retakeId() {
    idData = null;
    document.getElementById('idShot').style.display = 'none';
    document.getElementById('idEmptyState').style.display = '';
    document.getElementById('btnStartIdCam').style.display = '';
    document.getElementById('btnRetakeId').style.display = 'none';
    document.getElementById('idFile').value = '';
}
function stopIdCam() { if (idStream) { idStream.getTracks().forEach(t => t.stop()); idStream = null; } }

async function submitIdentity() {
    const idType = document.getElementById('idTypeSelect').value;
    const idNumber = document.getElementById('idNumber').value.trim();

    if (!selfieData) {
        Swal.fire({ icon: 'warning', title: 'Incomplete', text: 'Take or upload a live selfie.', confirmButtonColor: '#0d9488' });
        idvGoTo(2);
        return;
    }
    if (isOcrRunning) return;
    isOcrRunning = true;

    Swal.fire({ title: 'Analyzing ID...', html: 'Scanning text to verify if it matches your selection.', allowOutsideClick: false, didOpen: () => Swal.showLoading() });

    try {
        const fd = new FormData();
        fd.append('id_type', idType);
        fd.append('id_number', idNumber);
        fd.append('selfie',   selfieData.blob, 'selfie.jpg');
        fd.append('id_image', idData.blob,     'id_image.jpg');

        const res = await fetch('/api/submit_identity_verification.php', { method: 'POST', body: fd });
        const text = await res.text();
        let data;
        try { data = JSON.parse(text); }
        catch (e) { throw new Error('Bad server response: ' + text.slice(0, 200)); }
        if (!data.ok) throw new Error(data.error || 'Submission failed');

        Swal.close();

        const resCard = document.getElementById('idvResultCard');
        if (resCard) {
            document.getElementById('resFaceMatch').innerText = data.matches.face_match ? 'Yes' : 'No';

            if (data.matches.name_match) {
                document.getElementById('resNameMatch').innerText = 'Matched';
                document.getElementById('resNameMatch').className = 'text-success';
            } else {
                document.getElementById('resNameMatch').innerText = 'Manual Check';
                document.getElementById('resNameMatch').className = 'text-warning';
            }
            if (data.matches.id_type_match) {
                document.getElementById('resIdMatch').innerText = 'Matched';
                document.getElementById('resIdMatch').className = 'text-success';
            } else {
                document.getElementById('resIdMatch').innerText = 'Manual Check';
                document.getElementById('resIdMatch').className = 'text-warning';
            }
            const ocrDisplay = data.matches.ocr_raw_text ? data.matches.ocr_raw_text.replace(/\n/g, '<br>') : 'No text detected.';
            document.getElementById('resOcrText').innerHTML = ocrDisplay;

            resCard.classList.add('visible');
            resCard.scrollIntoView({ behavior: 'smooth', block: 'center' });

            const btnNext = document.getElementById('idvBtnNext');
            btnNext.innerHTML = 'Done';
            btnNext.onclick = () => location.reload();
            document.getElementById('idvBtnBack').style.display = 'none';
        }
    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Error', text: err.message, confirmButtonColor: '#0d9488' });
    } finally {
        isOcrRunning = false;
    }
}

window.addEventListener('beforeunload', () => { stopSelfieCam(); stopIdCam(); });
</script>
</body>
</html>