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

// ── MAX ATTEMPTS CONFIG ──────────────────────────────
$MAX_ATTEMPTS = 3;

$allApproved = true;
$showUploadForm = false;
$docsToShow = [];
$docsToUpload = [];
$maxAttemptsDocs = [];   // ← NEW: track docs that permanently hit max attempts

foreach ($requiredDocs as $doc) {
    $docRow = array_filter($documents, fn($d) => $d['document_type'] === $doc);
    $docRow = $docRow ? array_values($docRow)[0] : null;
    $canUpload = false;
    $status = $docRow['status'] ?? 'Not Uploaded';

    // ── GET ATTEMPT DATA ──────────────────────────────
    $attempts = $docRow['submission_attempts'] ?? 0;
    $maxReached = $docRow['max_attempts_reached'] ?? false;

    if (!$docRow) {
        // New document — always can upload
        $canUpload = true;
        $showUploadForm = true;
        $docsToUpload[] = $doc;
    }
    elseif ($docRow['status'] === 'Rejected' && !$maxReached && $attempts < $MAX_ATTEMPTS) {
        // Rejected but still has attempts left
        $canUpload = true;
        $showUploadForm = true;
        $docsToUpload[] = $doc;
    }
    elseif ($docRow['status'] === 'Rejected' && ($maxReached || $attempts >= $MAX_ATTEMPTS)) {
        // Rejected and max attempts reached — DO NOT include in upload list
        $canUpload = false;
        $maxAttemptsDocs[] = $doc;   // ← NEW: flag for "contact support" state
    }

    if ($docRow && $docRow['status'] !== 'Approved') $allApproved = false;
    $docsToShow[$doc] = [
        'row' => $docRow,
        'can_upload' => $canUpload,
        'status' => $status,
        'attempts' => $attempts,
        'max_reached' => $maxReached
    ];
}

$stats = ['uploaded' => 0, 'pending' => 0, 'missing' => 0];
foreach ($docsToShow as $doc => $data) {
    if ($data['status'] === 'Approved') $stats['uploaded']++;
    elseif ($data['status'] === 'Pending') $stats['pending']++;
    else $stats['missing']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Clinic Approval — Eyecore</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<style>
:root {
    --teal:        #0d9488;
    --teal-d:      #0f766e;
    --teal-l:      #ccfbf1;
    --teal-mid:    #5eead4;
    --ink:         #0d1b2a;
    --slate:       #64748b;
    --muted:       #94a3b8;
    --surface:     #f1f5f9;
    --white:       #ffffff;
    --border:      #e2e8f0;
    --danger:      #ef4444;
    --danger-l:    #fee2e2;
    --warning:     #f59e0b;
    --warning-l:   #fef3c7;
    --success:     #10b981;
    --success-l:   #d1fae5;
    --r:           12px;
    --r-lg:        20px;
    --shadow:      0 4px 20px rgba(13,27,42,.07);
    --shadow-lg:   0 8px 40px rgba(13,27,42,.11);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Outfit', sans-serif; background: var(--surface); color: var(--ink); font-size: 14px; min-height: 100vh; }

/* NAV */
.nav {
    background: var(--white); border-bottom: 1px solid var(--border);
    height: 58px; padding: 0 28px;
    display: flex; align-items: center; justify-content: space-between;
    position: sticky; top: 0; z-index: 200;
}
.nav-brand { font-family: 'Playfair Display', serif; font-size: 19px; color: var(--teal-d); display: flex; align-items: center; gap: 9px; }
.nav-brand-dot { width: 30px; height: 30px; background: var(--teal); border-radius: 8px; display: grid; place-items: center; color: white; font-size: 13px; }
.nav-r { display: flex; align-items: center; gap: 10px; }
.clinic-tag { background: var(--teal-l); color: var(--teal-d); border-radius: 99px; padding: 4px 13px; font-size: 12.5px; font-weight: 500; }
.btn-out { background: none; border: 1px solid var(--border); color: var(--slate); padding: 5px 13px; border-radius: 8px; cursor: pointer; font-family: inherit; font-size: 13px; text-decoration: none; display: flex; align-items: center; gap: 6px; transition: .15s; }
.btn-out:hover { border-color: var(--danger); color: var(--danger); }

/* LAYOUT */
.page { display: grid; grid-template-columns: 260px 1fr; min-height: calc(100vh - 58px); }

/* SIDEBAR */
.sb {
    background: var(--white); border-right: 1px solid var(--border);
    padding: 24px 16px; display: flex; flex-direction: column; gap: 4px;
    position: sticky; top: 58px; height: calc(100vh - 58px); overflow-y: auto;
}
.sb-ring-card {
    background: linear-gradient(145deg, var(--teal-d), var(--teal));
    border-radius: var(--r-lg); padding: 18px; margin-bottom: 18px;
    display: flex; gap: 14px; align-items: center; color: white;
}
.ring svg { transform: rotate(-90deg); }
.ring-bg { fill: none; stroke: rgba(255,255,255,.2); stroke-width: 5; }
.ring-fill { fill: none; stroke: white; stroke-width: 5; stroke-linecap: round; stroke-dasharray: 100; transition: stroke-dashoffset .7s ease; }
.ring-info strong { font-size: 24px; font-family: 'Playfair Display', serif; display: block; }
.ring-info span { font-size: 11px; opacity: .8; }
.sb-lbl { font-size: 10px; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: var(--muted); padding: 0 6px 8px; }
.sbi {
    display: flex; align-items: center; gap: 9px; padding: 8px 9px;
    border-radius: 10px; cursor: pointer; transition: .15s;
    border: 1.5px solid transparent;
}
.sbi:hover { background: var(--surface); }
.sbi.active { background: var(--teal-l); border-color: var(--teal-mid); }
.sbi-icon { width: 30px; height: 30px; border-radius: 7px; display: grid; place-items: center; font-size: 12px; flex-shrink: 0; }
.sbi.todo  .sbi-icon { background: #fef3c7; color: var(--warning); }
.sbi.rej   .sbi-icon { background: var(--danger-l); color: var(--danger); }
.sbi.maxed .sbi-icon { background: #450a0a; color: #fecaca; }
.sbi.pend  .sbi-icon { background: var(--warning-l); color: var(--warning); }
.sbi.ok    .sbi-icon { background: var(--success-l); color: var(--success); }
.sbi-name { font-size: 12.5px; font-weight: 500; flex: 1; line-height: 1.3; }
.sbadge { font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 99px; }
.sbadge-ok  { background: var(--success-l); color: #065f46; }
.sbadge-w   { background: var(--warning-l); color: #92400e; }
.sbadge-err { background: var(--danger-l); color: #991b1b; }
.sbadge-maxed { background: var(--danger); color: #ffffff; }
.sbadge-n   { background: var(--surface); color: var(--muted); border: 1px solid var(--border); }

/* MAIN */
.main { padding: 28px 36px; }

/* OVERVIEW TABLE */
.card { background: var(--white); border-radius: var(--r-lg); box-shadow: var(--shadow); overflow: hidden; margin-bottom: 28px; }
.card-head { padding: 16px 22px; border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.card-head h2 { font-size: 14.5px; font-weight: 600; }
.stats-row { display: flex; gap: 7px; }
.st { display: flex; align-items: center; gap: 5px; font-size: 11.5px; font-weight: 600; padding: 3px 9px; border-radius: 99px; }
.st-g { background: var(--success-l); color: #065f46; }
.st-y { background: var(--warning-l); color: #92400e; }
.st-r { background: var(--danger-l); color: #991b1b; }
table { width: 100%; border-collapse: collapse; }
th { font-size: 10.5px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--muted); padding: 9px 16px; background: var(--surface); text-align: left; }
td { padding: 11px 16px; font-size: 13px; border-bottom: 1px solid var(--border); }
tr:last-child td { border-bottom: none; }
tr:hover td { background: #fafbfc; }
.pill { display: inline-block; font-size: 10.5px; font-weight: 700; padding: 3px 9px; border-radius: 99px; }
.p-ok  { background: var(--success-l); color: #065f46; }
.p-w   { background: var(--warning-l); color: #92400e; }
.p-err { background: var(--danger-l); color: #991b1b; }
.p-maxed { background: var(--danger); color: #ffffff; }
.p-n   { background: var(--surface); color: var(--muted); border: 1px solid var(--border); }
.btn-view { display: inline-flex; align-items: center; gap: 4px; font-size: 11.5px; font-weight: 500; color: var(--teal-d); border: 1px solid var(--teal-mid); background: var(--teal-l); padding: 3px 9px; border-radius: 6px; text-decoration: none; transition: .15s; cursor: pointer; font-family: inherit; }
.btn-view:hover { background: var(--teal-mid); }

/* WIZARD */
.wizard { background: var(--white); border-radius: var(--r-lg); box-shadow: var(--shadow); overflow: hidden; }

/* step strip */
.steps-strip {
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 14px 22px; display: flex; align-items: center; gap: 0;
    overflow-x: auto; scrollbar-width: none;
}
.steps-strip::-webkit-scrollbar { display: none; }
.ws { display: flex; align-items: center; gap: 7px; padding: 5px 9px; border-radius: 9px; cursor: pointer; transition: .15s; white-space: nowrap; flex-shrink: 0; }
.ws:hover { background: var(--border); }
.ws.active { background: var(--teal-l); }
.ws-n { width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center; font-size: 11px; font-weight: 700; background: var(--border); color: var(--slate); transition: .2s; }
.ws.active .ws-n { background: var(--teal); color: white; }
.ws.done .ws-n { background: var(--success); color: white; }
.ws-label { font-size: 12px; font-weight: 500; color: var(--slate); }
.ws.active .ws-label { color: var(--teal-d); }
.ws-sep { color: var(--border); padding: 0 2px; font-size: 16px; flex-shrink: 0; }

/* panel */
.wbody { padding: 30px 32px; }
.panel { display: none; animation: up .22s ease; }
.panel.active { display: block; }
@keyframes up { from { opacity:0; transform:translateY(7px); } to { opacity:1; transform:translateY(0); } }

.ph { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 26px; }
.ph-title { font-family: 'Playfair Display', serif; font-size: 25px; color: var(--ink); }
.ph-sub { color: var(--muted); font-size: 13px; margin-top: 3px; }
.ph-ctr { font-size: 11.5px; font-weight: 600; color: var(--muted); background: var(--surface); border: 1px solid var(--border); padding: 5px 13px; border-radius: 99px; white-space: nowrap; margin-top: 3px; }

.rej-alert {
    background: #fff5f5; border: 1px solid #fecaca; border-radius: var(--r);
    padding: 12px 15px; margin-bottom: 22px; display: flex; gap: 10px;
    font-size: 13px; color: #991b1b; align-items: flex-start;
}
.rej-alert .attempt-tag {
    display: inline-block; margin-top: 6px; font-size: 11px; font-weight: 700;
    background: #fecaca; color: #7f1d1d; padding: 2px 8px; border-radius: 99px;
}

/* ── UPLOAD BOX ── */
.upload-box {
    border: 2px dashed var(--border);
    border-radius: var(--r-lg); padding: 36px 24px;
    text-align: center; cursor: pointer; transition: .2s;
    position: relative; background: var(--surface);
    margin-bottom: 22px;
}
.upload-box:hover, .upload-box.drag { border-color: var(--teal); background: var(--teal-l); }
.upload-box input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
.ub-icon { font-size: 36px; color: var(--teal-mid); margin-bottom: 10px; }
.ub-title { font-size: 15px; font-weight: 600; color: var(--ink); margin-bottom: 4px; }
.ub-sub { font-size: 12.5px; color: var(--muted); }
.ub-hint { font-size: 11px; color: var(--muted); margin-top: 6px; }

/* AI reading state */
.ai-state {
    display: none; flex-direction: column; align-items: center; gap: 10px;
    padding: 28px 24px; background: linear-gradient(135deg, var(--teal-l), #e0f2fe);
    border-radius: var(--r-lg); margin-bottom: 22px; border: 1px solid var(--teal-mid);
}
.ai-state.visible { display: flex; }
.ai-spinner {
    width: 44px; height: 44px; border-radius: 50%;
    border: 3px solid var(--teal-l); border-top-color: var(--teal);
    animation: spin .7s linear infinite;
}
@keyframes spin { to { transform: rotate(360deg); } }
.ai-state-title { font-weight: 600; color: var(--teal-d); font-size: 14px; }
.ai-state-sub { font-size: 12px; color: var(--slate); }

/* file preview strip */
.file-preview {
    display: none; align-items: center; gap: 10px;
    background: var(--teal-l); border: 1px solid var(--teal-mid);
    border-radius: var(--r); padding: 10px 14px; margin-bottom: 14px;
}
.file-preview.visible { display: flex; }
.fp-icon { font-size: 20px; color: var(--teal); }
.fp-name { font-size: 13px; font-weight: 600; color: var(--teal-d); flex: 1; }
.fp-change { font-size: 11.5px; color: var(--teal-d); cursor: pointer; text-decoration: underline; opacity: .7; }
.fp-change:hover { opacity: 1; }

/* ── DOCUMENT PREVIEW ── */
.doc-preview-wrap {
    display: none;
    border-radius: var(--r-lg);
    overflow: hidden;
    margin-bottom: 22px;
    border: 1.5px solid var(--border);
    background: #1e293b;
    position: relative;
}
.doc-preview-wrap.visible { display: block; }
.doc-preview-img {
    width: 100%;
    max-height: 400px;
    object-fit: contain;
    display: block;
    background: #0f172a;
}
.doc-preview-pdf {
    width: 100%;
    height: 400px;
    border: none;
    display: block;
}
.doc-preview-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    background: var(--white);
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--slate);
}
.doc-preview-bar strong {
    color: var(--ink);
    flex: 1;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 60%;
}
.doc-preview-size {
    font-size: 11px;
    color: var(--muted);
    white-space: nowrap;
}
.doc-preview-open {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11.5px;
    font-weight: 600;
    color: var(--teal-d);
    text-decoration: none;
    background: var(--teal-l);
    border: 1px solid var(--teal-mid);
    padding: 3px 10px;
    border-radius: 6px;
    transition: .15s;
    white-space: nowrap;
}
.doc-preview-open:hover { background: var(--teal-mid); }

/* filled fields */
.fields-row {
    display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px;
    margin-bottom: 6px;
}
.fg { display: flex; flex-direction: column; gap: 5px; }
.fl { font-size: 11.5px; font-weight: 700; color: var(--ink); letter-spacing: .02em; display: flex; align-items: center; gap: 5px; }
.fl .ai-tag { font-size: 10px; font-weight: 700; background: linear-gradient(90deg,var(--teal),#3b82f6); color: white; border-radius: 4px; padding: 1px 5px; letter-spacing: .04em; }
.fl .req { color: var(--danger); }
.fi {
    padding: 10px 12px; border: 1.5px solid var(--border); border-radius: var(--r);
    font-family: inherit; font-size: 13px; color: var(--ink); background: var(--white);
    outline: none; transition: .15s;
}
.fi:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(13,148,136,.1); }
.fi.ai-filled { border-color: var(--teal-mid); background: var(--teal-l); color: var(--teal-d); font-weight: 500; }
.fi-hint { font-size: 10.5px; color: var(--muted); margin-top: 2px; }

/* terms */
.terms-wrap {
    background: var(--surface); border: 1.5px solid var(--border);
    border-radius: var(--r); padding: 15px; display: flex; gap: 11px;
    align-items: flex-start; margin-bottom: 16px;
}
.terms-wrap input[type="checkbox"] { margin-top: 2px; accent-color: var(--teal); cursor: pointer; flex-shrink: 0; width: 15px; height: 15px; }
.terms-wrap label { font-size: 12.5px; color: var(--slate); line-height: 1.6; cursor: pointer; }
.warn-bar {
    background: #fffbeb; border: 1px solid #fde68a;
    border-radius: var(--r); padding: 10px 14px;
    display: flex; gap: 8px; font-size: 12px; color: #92400e;
}

/* nav footer */
.wnav {
    display: flex; align-items: center; justify-content: space-between;
    padding: 18px 32px; border-top: 1px solid var(--border);
    background: var(--surface);
}
.btn { display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px; border-radius: var(--r); font-family: inherit; font-size: 13px; font-weight: 600; cursor: pointer; border: none; transition: .2s; }
.btn-back { background: var(--white); color: var(--slate); border: 1.5px solid var(--border); }
.btn-back:hover { border-color: var(--slate); color: var(--ink); }
.btn-fwd { background: var(--teal); color: white; }
.btn-fwd:hover { background: var(--teal-d); }
.btn-send { background: linear-gradient(135deg, var(--teal-d), var(--teal)); color: white; box-shadow: 0 4px 14px rgba(13,148,136,.28); }
.btn-send:hover { box-shadow: 0 6px 22px rgba(13,148,136,.38); transform: translateY(-1px); }
.btn-send:disabled { opacity: .5; cursor: not-allowed; transform: none; }
.step-ctr { font-size: 12px; color: var(--muted); }

/* states */
.state { padding: 70px 32px; text-align: center; }
.state-ico { width: 76px; height: 76px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 22px; font-size: 30px; }
.state-ico.warn { background: var(--warning-l); color: var(--warning); }
.state-ico.ok { background: var(--success-l); color: var(--success); }
.state-ico.err { background: var(--danger-l); color: var(--danger); }
.state-title { font-family: 'Playfair Display', serif; font-size: 27px; margin-bottom: 10px; }
.state-body { color: var(--muted); font-size: 13.5px; max-width: 440px; margin: 0 auto 22px; line-height: 1.7; }
.state-badge { display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 500; padding: 9px 18px; border-radius: 99px; }
.sb-info { background: #e0f2fe; color: #075985; }
.sb-ok   { background: var(--success-l); color: #065f46; }
.sb-err  { background: var(--danger-l); color: #991b1b; }
.maxed-doc-list {
    display: inline-flex; flex-wrap: wrap; gap: 6px; justify-content: center;
    margin: 10px 0 18px;
}
.maxed-doc-chip {
    background: var(--danger-l); color: #991b1b; font-weight: 700; font-size: 12px;
    padding: 5px 12px; border-radius: 99px; border: 1px solid #fecaca;
}
.support-email {
    display: inline-flex; align-items: center; gap: 7px; font-size: 13.5px; font-weight: 600;
    color: var(--teal-d); background: var(--teal-l); border: 1px solid var(--teal-mid);
    padding: 8px 16px; border-radius: 99px; text-decoration: none; margin-top: 4px;
}
.support-email:hover { background: var(--teal-mid); }

/* ── DOCUMENT VIEW MODAL ── */
.doc-modal-overlay {
    display: none;
    position: fixed; inset: 0; z-index: 1000;
    background: rgba(13,27,42,.62);
    backdrop-filter: blur(2px);
    align-items: center; justify-content: center;
    padding: 24px;
}
.doc-modal-overlay.visible { display: flex; animation: fadeIn .15s ease; }
@keyframes fadeIn { from{opacity:0;} to{opacity:1;} }
.doc-modal {
    background: var(--white);
    border-radius: var(--r-lg);
    box-shadow: var(--shadow-lg);
    width: 100%; max-width: 780px;
    max-height: 88vh;
    display: flex; flex-direction: column;
    overflow: hidden;
    animation: modalUp .2s ease;
}
@keyframes modalUp { from{opacity:0; transform:translateY(14px) scale(.98);} to{opacity:1; transform:translateY(0) scale(1);} }
.doc-modal-header {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 18px; border-bottom: 1px solid var(--border);
    flex-shrink: 0;
}
.doc-modal-header i.fa-file-lines { color: var(--teal); }
.doc-modal-header strong { flex: 1; font-size: 14.5px; color: var(--ink); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.doc-modal-close {
    background: var(--surface); border: 1px solid var(--border); color: var(--slate);
    width: 30px; height: 30px; border-radius: 8px; cursor: pointer;
    display: grid; place-items: center; font-size: 15px; flex-shrink: 0; transition: .15s;
}
.doc-modal-close:hover { background: var(--danger-l); color: var(--danger); border-color: var(--danger); }
.doc-modal-body {
    flex: 1; overflow: auto; background: #1e293b;
    display: flex; align-items: center; justify-content: center;
    min-height: 300px;
}
.doc-modal-img { width: 100%; max-height: 74vh; object-fit: contain; display: block; }
.doc-modal-pdf { width: 100%; height: 74vh; border: none; display: block; }
.doc-modal-footer {
    padding: 10px 18px; border-top: 1px solid var(--border);
    display: flex; justify-content: flex-end; flex-shrink: 0;
}

@media (max-width: 860px) {
    .page { grid-template-columns: 1fr; }
    .sb   { display: none; }
    .main { padding: 16px; }
    .fields-row { grid-template-columns: 1fr; }
    .wbody { padding: 20px; }
    .wnav  { padding: 14px 20px; }
    .doc-modal { max-width: 100%; max-height: 92vh; }
}
</style>
</head>
<body>

<nav class="nav">
    <div class="nav-brand">
        <div class="nav-brand-dot"><i class="fas fa-eye"></i></div>
        Eyecore
    </div>
    <div class="nav-r">
        <div class="clinic-tag"><i class="fas fa-hospital-user" style="margin-right:5px;"></i><?= htmlspecialchars($user['clinic_name']) ?></div>
        <a href="../admin/logout.php" class="btn-out"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</nav>

<div class="page">

<!-- SIDEBAR -->
<aside class="sb">
    <?php
    $pct = round(($stats['uploaded'] / count($requiredDocs)) * 100);
    $r   = 16; $circ = 2 * M_PI * $r;
    $off = $circ * (1 - $pct / 100);
    $iconMap = ['Business Permit'=>'fa-building','DTI'=>'fa-briefcase','SEC'=>'fa-landmark','BIR'=>'fa-receipt','Optometrist License'=>'fa-user-md','Barangay Clearance'=>'fa-id-card','Health Clearance'=>'fa-heart-pulse','Insurance'=>'fa-shield-halved','Other Documents'=>'fa-folder-open'];
    ?>
    <div class="sb-ring-card">
        <div class="ring">
            <svg width="54" height="54" viewBox="0 0 36 36">
                <circle class="ring-bg" cx="18" cy="18" r="<?= $r ?>"/>
                <circle class="ring-fill" cx="18" cy="18" r="<?= $r ?>" style="stroke-dashoffset:<?= $off ?>"/>
            </svg>
        </div>
        <div class="ring-info">
            <strong><?= $pct ?>%</strong>
            <span>Documents approved</span>
        </div>
    </div>

    <div class="sb-lbl">Document Status</div>
    <?php foreach ($docsToShow as $doc => $data):
        $s = $data['status'];
        $isMaxed = ($s === 'Rejected' && ($data['max_reached'] || $data['attempts'] >= $MAX_ATTEMPTS));
        $cls = 'todo'; $bc = 'sbadge-n'; $bt = 'Upload'; $ic = 'fa-arrow-up-from-bracket';
        if ($s==='Approved'){$cls='ok';$bc='sbadge-ok';$bt='✓ Done';$ic='fa-circle-check';}
        elseif($s==='Pending'){$cls='pend';$bc='sbadge-w';$bt='Review';$ic='fa-clock';}
        elseif($isMaxed){$cls='maxed';$bc='sbadge-maxed';$bt='⚠ Max Reached';$ic='fa-headset';}
        elseif($s==='Rejected'){$cls='rej';$bc='sbadge-err';$bt='Rejected';$ic='fa-triangle-exclamation';}
        $slug = preg_replace('/[^a-z0-9]/','_',strtolower($doc));
    ?>
    <div class="sbi <?= $cls ?>" data-slug="<?= $slug ?>" onclick="jumpTo('<?= $slug ?>')">
        <div class="sbi-icon"><i class="fas <?= $isMaxed ? 'fa-headset' : ($iconMap[$doc]??'fa-file') ?>"></i></div>
        <span class="sbi-name"><?= $doc ?></span>
        <span class="sbadge <?= $bc ?>"><?= $bt ?></span>
    </div>
    <?php endforeach; ?>
</aside>

<!-- MAIN -->
<main class="main">

<?php if (isset($_SESSION['swal'])): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded',()=>{
    Swal.fire({icon:'<?= $_SESSION['swal']['icon'] ?>',title:'<?= $_SESSION['swal']['title'] ?>',html:'<?= nl2br($_SESSION['swal']['text']) ?>',confirmButtonColor:'#0d9488'});
});
</script>
<?php unset($_SESSION['swal']); endif; ?>

<!-- OVERVIEW TABLE -->
<div class="card">
    <div class="card-head">
        <h2><i class="fas fa-file-contract" style="color:var(--teal);margin-right:7px;"></i>Document Overview</h2>
        <div class="stats-row">
            <div class="st st-g"><i class="fas fa-circle-check"></i> <?= $stats['uploaded'] ?> Approved</div>
            <div class="st st-y"><i class="fas fa-clock"></i> <?= $stats['pending'] ?> Pending</div>
            <div class="st st-r"><i class="fas fa-circle-xmark"></i> <?= $stats['missing'] ?> Missing</div>
        </div>
    </div>
    <div style="overflow-x:auto;">
        <table>
            <thead><tr><th>Document</th><th>Status</th><th>Owner</th><th>Released</th><th>Expires</th><th>Remarks</th><th>File</th></tr></thead>
            <tbody>
            <?php foreach ($docsToShow as $doc => $data):
                $row = $data['row']; $s = $data['status'];
                $isMaxed = ($s === 'Rejected' && ($data['max_reached'] || $data['attempts'] >= $MAX_ATTEMPTS));
                $pc = 'p-n';
                if($s==='Approved')$pc='p-ok';
                elseif($isMaxed)$pc='p-maxed';
                elseif($s==='Pending')$pc='p-w';
                elseif($s==='Rejected')$pc='p-err';
            ?>
            <tr>
                <td style="font-weight:600;"><?= $doc ?></td>
                <td><span class="pill <?= $pc ?>"><?= $isMaxed ? 'Max Reached' : $s ?></span></td>
                <td style="color:var(--slate);"><?= $row['owner_name']??'—' ?></td>
                <td style="color:var(--slate);"><?= $row['released_at']??'—' ?></td>
                <td style="color:var(--slate);"><?= $row['expires_at']??'—' ?></td>
                <td>
                    <?php if($isMaxed): ?>
                    <span style="color:var(--danger);font-size:12px;font-weight:700;">
                        <i class="fas fa-headset"></i> Max attempts reached — contact support
                    </span>
                    <?php elseif($row && $row['status']==='Rejected' && $row['rejection_reason']): ?>
                    <span style="color:var(--danger);font-size:12px;"><i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars(substr($row['rejection_reason'],0,35)) ?>…</span>
                    <?php else: ?><span style="color:var(--muted);">—</span><?php endif; ?>
                </td>
                <td>
                    <?php if($row && $row['file_path']): ?>
                    <button type="button" class="btn-view" onclick="openDocModal('../<?= htmlspecialchars($row['file_path']) ?>','<?= htmlspecialchars($doc, ENT_QUOTES) ?>')"><i class="fas fa-eye"></i> View</button>
                    <?php else: ?><span style="color:var(--muted);font-size:12px;">No file</span><?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- WIZARD / STATE -->
<?php if ($showUploadForm): ?>
<div class="wizard">

    <!-- Steps strip -->
    <div class="steps-strip" id="stepsStrip">
        <?php foreach ($docsToUpload as $i => $doc):
            $slug = preg_replace('/[^a-z0-9]/','_',strtolower($doc));
        ?>
        <?php if($i>0): ?><span class="ws-sep">›</span><?php endif; ?>
        <div class="ws <?= $i===0?'active':'' ?>" id="wstab-<?= $slug ?>" data-idx="<?= $i ?>" onclick="goTo(<?= $i ?>)">
            <div class="ws-n"><?= $i+1 ?></div>
            <span class="ws-label"><?= $doc ?></span>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($maxAttemptsDocs)): ?>
    <div style="padding:14px 22px 0;">
        <div class="rej-alert" style="align-items:center;">
            <i class="fas fa-headset" style="flex-shrink:0;color:var(--danger);"></i>
            <div>
                <strong>Note:</strong> <?= implode(', ', $maxAttemptsDocs) ?>
                <?= count($maxAttemptsDocs) > 1 ? 'have' : 'has' ?> reached the maximum of <?= $MAX_ATTEMPTS ?> attempts
                and can no longer be re-uploaded here. Please contact <strong>support@eyecore.com</strong> for those document(s).
            </div>
        </div>
    </div>
    <?php endif; ?>

    <form id="docForm" action="/api/upload_documents.php" method="POST" enctype="multipart/form-data" onsubmit="return false;">
        <input type="hidden" name="clinic_id" value="<?= $clinicId ?>">

        <div class="wbody">
        <?php foreach ($docsToUpload as $i => $doc):
            $row  = $docsToShow[$doc]['row'];
            $attempts = $docsToShow[$doc]['attempts'];
            $slug = preg_replace('/[^a-z0-9]/','_',strtolower($doc));
        ?>
        <div class="panel <?= $i===0?'active':'' ?>" id="panel-<?= $slug ?>" data-idx="<?= $i ?>">

            <div class="ph">
                <div>
                    <div class="ph-title"><?= $doc ?></div>
                    <div class="ph-sub"><?= ($row && $row['status']==='Rejected') ? 'Re-upload this rejected document' : 'Upload your document — AI will fill the details for you' ?></div>
                </div>
                <div class="ph-ctr"><?= $i+1 ?> of <?= count($docsToUpload) ?></div>
            </div>

            <?php if ($row && $row['status']==='Rejected'): ?>
            <div class="rej-alert">
                <i class="fas fa-circle-exclamation" style="flex-shrink:0;margin-top:2px;color:var(--danger);"></i>
                <div>
                    <strong>Rejected:</strong> <?= htmlspecialchars($row['rejection_reason']??'Please re-upload a valid document.') ?>
                    <br>
                    <span class="attempt-tag">Attempt <?= $attempts ?> of <?= $MAX_ATTEMPTS ?> used — <?= max(0, $MAX_ATTEMPTS - $attempts) ?> remaining</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- Upload box -->
            <div class="upload-box" id="ub-<?= $slug ?>">
                <input type="file" name="docs[<?= $doc ?>]" id="file-<?= $slug ?>"
                       accept=".jpg,.jpeg,.png,.pdf" required
                       onchange="handleFile('<?= $slug ?>','<?= addslashes($doc) ?>',this)">
                <div class="ub-icon"><i class="fas fa-cloud-arrow-up"></i></div>
                <div class="ub-title">Upload Document</div>
                <div class="ub-sub">Click to browse or drag & drop your file</div>
                <div class="ub-hint">JPG, PNG, PDF · Max 5MB</div>
            </div>

            <!-- AI reading animation -->
            <div class="ai-state" id="ai-<?= $slug ?>">
                <div class="ai-spinner"></div>
                <div class="ai-state-title">AI is reading your document…</div>
                <div class="ai-state-sub">Extracting owner name, release date, and expiry date</div>
            </div>

            <!-- File preview strip -->
            <div class="file-preview" id="fp-<?= $slug ?>">
                <i class="fas fa-file-lines fp-icon"></i>
                <span class="fp-name" id="fp-name-<?= $slug ?>"></span>
                <span class="fp-change" onclick="resetFile('<?= $slug ?>')">Change file</span>
            </div>

            <!-- ── DOCUMENT PREVIEW ── -->
            <div class="doc-preview-wrap" id="prev-<?= $slug ?>">
                <div class="doc-preview-bar">
                    <i class="fas fa-eye" style="color:var(--teal);flex-shrink:0;"></i>
                    <strong id="prev-name-<?= $slug ?>"></strong>
                    <span class="doc-preview-size" id="prev-size-<?= $slug ?>"></span>
                    <a href="#" class="doc-preview-open" onclick="event.preventDefault(); openDocModalFromSlug('<?= $slug ?>');">
                        <i class="fas fa-expand"></i> Full view
                    </a>
                </div>
            </div>
            <!-- ── END DOCUMENT PREVIEW ── -->

            <!-- Auto-filled fields -->
            <div class="fields-row" id="fields-<?= $slug ?>" style="display:none;">
                <div class="fg">
                    <label class="fl">
                        Owner Name <span class="ai-tag">AI</span> <span class="req">*</span>
                    </label>
                    <input type="text" name="owner_name[<?= $doc ?>]" id="own-<?= $slug ?>"
                           class="fi" placeholder="e.g. Juan dela Cruz"
                           value="<?= htmlspecialchars($row['owner_name']??'') ?>" required>
                    <div class="fi-hint">Verify or edit if incorrect</div>
                </div>
                <div class="fg">
                    <label class="fl">
                        Release Date <span class="ai-tag">AI</span> <span class="req">*</span>
                    </label>
                    <input type="date" name="released_at[<?= $doc ?>]" id="rel-<?= $slug ?>"
                           class="fi" value="<?= $row['released_at']??'' ?>" required>
                    <div class="fi-hint">Verify or edit if incorrect</div>
                </div>
                <div class="fg">
                    <label class="fl">
                        Expiry Date <span class="ai-tag">AI</span> <span class="req">*</span>
                    </label>
                    <input type="date" name="expires_at[<?= $doc ?>]" id="exp-<?= $slug ?>"
                           class="fi" value="<?= $row['expires_at']??'' ?>" required>
                    <div class="fi-hint">Verify or edit if incorrect</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Terms (last step only) -->
        <div id="termsWrap" style="display:none; margin-top:4px;">
            <div class="terms-wrap">
                <input type="checkbox" id="terms">
                <label for="terms">
                    I certify that all uploaded documents are <strong>valid and not expired</strong>.
                    I understand that submitting false or expired documents may result in clinic suspension or termination of services.
                </label>
            </div>
            <div class="warn-bar">
                <i class="fas fa-triangle-exclamation" style="flex-shrink:0;margin-top:1px;"></i>
                Documents with <strong style="margin:0 3px;">Approved</strong> or
                <strong style="margin:0 3px;">Pending</strong> status cannot be re-uploaded unless <strong style="margin:0 3px;">Rejected</strong>.
                Each document allows a maximum of <strong style="margin:0 3px;"><?= $MAX_ATTEMPTS ?></strong> submission attempts.
            </div>
        </div>
        </div><!-- /wbody -->

        <div class="wnav">
            <button type="button" class="btn btn-back" id="btnBack" onclick="prevStep()">
                <i class="fas fa-arrow-left"></i> Back
            </button>
            <span class="step-ctr" id="stepCtr">Step 1 of <?= count($docsToUpload) ?></span>
            <button type="button" class="btn btn-fwd btn-send" id="btnNext" onclick="nextStep()">
                Next <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    </form>
</div>

<?php elseif (!empty($maxAttemptsDocs)): ?>
<!-- ── NEW STATE: ALL REMAINING DOCS ARE PERMANENTLY BLOCKED ── -->
<div class="wizard">
    <div class="state">
        <div class="state-ico err"><i class="fas fa-headset"></i></div>
        <div class="state-title">Contact Support Required</div>
        <div class="state-body">
            The following document(s) have reached the maximum of <?= $MAX_ATTEMPTS ?> submission attempts
            and can no longer be re-uploaded through this portal.
        </div>
        <div class="maxed-doc-list">
            <?php foreach ($maxAttemptsDocs as $md): ?>
                <span class="maxed-doc-chip"><i class="fas fa-file-circle-xmark"></i> <?= htmlspecialchars($md) ?></span>
            <?php endforeach; ?>
        </div>
        <br>
        <a href="mailto:support@eyecore.com" class="support-email">
            <i class="fas fa-envelope"></i> support@eyecore.com
        </a>
    </div>
</div>

<?php elseif (!$allApproved): ?>
<div class="wizard">
    <div class="state">
        <div class="state-ico warn"><i class="fas fa-clock"></i></div>
        <div class="state-title">Documents Under Review</div>
        <div class="state-body">All documents have been submitted and are currently being reviewed. You will be notified once the process is complete.</div>
        <div class="state-badge sb-info"><i class="fas fa-info-circle"></i> Please wait — no uploads needed at this time</div>
    </div>
</div>
<?php else: ?>
<div class="wizard">
    <div class="state">
        <div class="state-ico ok"><i class="fas fa-circle-check"></i></div>
        <div class="state-title">All Documents Approved!</div>
        <div class="state-body">Your clinic documents have been fully reviewed and approved. No further action is required.</div>
        <div class="state-badge sb-ok"><i class="fas fa-thumbs-up"></i> Your clinic is ready for activation</div>
    </div>
</div>
<?php endif; ?>

</main>
</div>

<!-- ── DOCUMENT VIEW MODAL ── -->
<div class="doc-modal-overlay" id="docModalOverlay" onclick="if(event.target===this) closeDocModal()">
    <div class="doc-modal">
        <div class="doc-modal-header">
            <i class="fas fa-file-lines"></i>
            <strong id="docModalTitle">Document</strong>
            <button type="button" class="doc-modal-close" onclick="closeDocModal()"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="doc-modal-body" id="docModalBody"></div>
        <div class="doc-modal-footer">
            <a href="#" id="docModalOpenNew" target="_blank" class="doc-preview-open"><i class="fas fa-up-right-from-square"></i> Open in new tab</a>
        </div>
    </div>
</div>
<!-- ── END DOCUMENT VIEW MODAL ── -->

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<!-- Tesseract.js for Client-Side OCR -->
<script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
<script>
// ── CONFIG ────────────────────────────────────────
const DOCS      = <?= json_encode(array_values($docsToUpload)) ?>;
const TOTAL     = DOCS.length;
let   current   = 0;
const fileData  = {}; // slug -> {base64, mimeType, fileName}

function slug(doc){ return doc.toLowerCase().replace(/[^a-z0-9]/g,'_'); }

// ── DOCUMENT VIEW MODAL ────────────────────────────
function isPdfUrl(url){
    return url.split('?')[0].toLowerCase().endsWith('.pdf');
}
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
        embed.src  = url;
        embed.type = 'application/pdf';
        embed.className = 'doc-modal-pdf';
        body.appendChild(embed);
    } else {
        const img = document.createElement('img');
        img.src = url;
        img.alt = title || 'Document preview';
        img.className = 'doc-modal-img';
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
document.addEventListener('keydown', e=>{
    if(e.key === 'Escape') closeDocModal();
});

// ── NAVIGATION ────────────────────────────────────
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
    document.querySelector(`.sbi[data-slug="${sl}"]`)?.classList.add('active');
    document.querySelectorAll('.sbi').forEach(el=>{ if(el.dataset.slug!==sl) el.classList.remove('active'); });
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
function jumpTo(sl){ const idx=DOCS.findIndex(d=>slug(d)===sl); if(idx!==-1) goTo(idx); }

// ── FILE HANDLE + AI OCR ──────────────────────────
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
    } catch(e){
        console.warn('AI extraction failed:', e);
    }

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
    console.log('Calling OCR API for:', docName);
    try {
        const res = await fetch('/api/ocr_extract.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ base64, mimeType, docName })
        });
        if (!res.ok) {
            console.warn('OCR API returned:', res.status);
            return { owner_name: null, release_date: null, expiry_date: null };
        }
        const data = await res.json();
        console.log('OCR Result:', data);
        return data;
    } catch (err) {
        console.error('OCR error:', err);
        return { owner_name: null, release_date: null, expiry_date: null };
    }
}
function normalizeDateClient(dateStr) {
    if (!dateStr) return null;
    const match = dateStr.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
    if (match) {
        return `${match[3]}-${match[2].padStart(2,'0')}-${match[1].padStart(2,'0')}`;
    }
    return null;
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
}

// Drag visual
document.querySelectorAll('.upload-box').forEach(z=>{
    z.addEventListener('dragover', e=>{ e.preventDefault(); z.classList.add('drag'); });
    z.addEventListener('dragleave', ()=> z.classList.remove('drag'));
    z.addEventListener('drop',      ()=> z.classList.remove('drag'));
});

// ── SUBMIT ────────────────────────────────────────
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

// Legacy alias
function validateForm(){ return doSubmit(); }

// Init
document.addEventListener('DOMContentLoaded',()=>{ if(TOTAL>0) goTo(0); });
</script>
</body>
</html>