<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
include __DIR__ . '/../config/db.php';

// ── PER-DAY DECLINE RULE ──────────────────────────
// A declined document can only be re-uploaded ONCE PER CALENDAR DAY.
// No more fixed attempt limits — documents can be re-uploaded as long as
// they were not declined today (or admin re-opened them).

// Increase PHP limits for multiple file uploads
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '60M');
ini_set('max_execution_time', 300);
ini_set('max_input_time', 300);
ini_set('memory_limit', '256M');

error_reporting(E_ALL);
ini_set('display_errors', 1);

$userId = $_SESSION['user_id'] ?? 0;
if (!$userId) {
    header("Location: ../admin/login.php");
    exit;
}

// Fetch clinic id
$stmt = $pdo->prepare("SELECT clinic_id FROM users WHERE id = ?");
$stmt->execute([$userId]);
$clinicId = $stmt->fetchColumn();

if (!$clinicId) {
    $_SESSION['swal'] = ['icon' => 'error', 'title' => 'Error', 'text' => 'Clinic not found'];
    header("Location: ../views/clinic_pending.php");
    exit;
}

error_log("=== UPLOAD START ===");
error_log("Clinic ID: $clinicId");

// Get documents from form
$uploadedDocs = isset($_POST['owner_name']) ? array_keys($_POST['owner_name']) : [];

if (empty($uploadedDocs)) {
    $_SESSION['swal'] = ['icon' => 'error', 'title' => 'No Data', 'text' => 'No documents to upload'];
    header("Location: ../views/clinic_pending.php");
    exit;
}

// Create upload directory
$uploadDir = __DIR__ . '/../uploads/clinic_' . $clinicId . '/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$isResubmission = false;
$uploadedCount = 0;
$updatedCount = 0;
$errors = [];
$waitTomorrowErrors = [];

foreach ($uploadedDocs as $docType) {
    try {
        // 1. Get form data
        $ownerName = trim($_POST['owner_name'][$docType] ?? '');
        $releasedAt = $_POST['released_at'][$docType] ?? '';
        $expiresAt = $_POST['expires_at'][$docType] ?? '';
        
        // KUNIN ANG OCR WARNING MULA SA HIDDEN INPUT
        $ocr_warning = trim($_POST['ocr_warning'] ?? '');
        $ocr_flag = !empty($ocr_warning) ? $ocr_warning : null;

        // 2. Validation
        if (empty($ownerName)) throw new Exception("Owner name is required");
        if (empty($releasedAt) || empty($expiresAt)) throw new Exception("Release and expiry dates are required");
        if ($expiresAt < $releasedAt) throw new Exception("Expiry date cannot be before release date");

        if (!isset($_FILES['docs']['name'][$docType])) throw new Exception("No file uploaded");

        $fileError = $_FILES['docs']['error'][$docType];
        if ($fileError !== UPLOAD_ERR_OK) {
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE => "File exceeds upload limit",
                UPLOAD_ERR_FORM_SIZE => "File exceeds form limit",
                UPLOAD_ERR_PARTIAL => "File was only partially uploaded",
                UPLOAD_ERR_NO_FILE => "No file was uploaded",
                UPLOAD_ERR_NO_TMP_DIR => "Missing temporary folder",
                UPLOAD_ERR_CANT_WRITE => "Failed to write file to disk",
                UPLOAD_ERR_EXTENSION => "File upload stopped by extension"
            ];
            throw new Exception($errorMessages[$fileError] ?? "Upload error code: $fileError");
        }

        // 3. Process file
        $originalName = $_FILES['docs']['name'][$docType];
        $tmpName = $_FILES['docs']['tmp_name'][$docType];
        $fileSize = $_FILES['docs']['size'][$docType];
        if ($fileSize > 5 * 1024 * 1024) throw new Exception("File exceeds 5MB limit");

        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) throw new Exception("Invalid file type. Allowed: jpg, png, pdf");

        $safeDocName = preg_replace('/[^A-Za-z0-9]/', '_', $docType);
        $microtime = str_replace('.', '', microtime(true));
        $random = rand(10000, 99999);
        $filename = "{$safeDocName}_{$clinicId}_{$microtime}_{$random}.{$ext}";
        $fullPath = $uploadDir . $filename;

        if (!move_uploaded_file($tmpName, $fullPath)) throw new Exception("Failed to save uploaded file");

        $filePath = 'uploads/clinic_' . $clinicId . '/' . $filename;

        // ════════════════════════════════════════════════════════
        // 4. Check existing document
        // ════════════════════════════════════════════════════════
        $checkStmt = $pdo->prepare("SELECT id, file_path, status, submission_attempts, reviewed_at 
                                    FROM clinic_documents 
                                    WHERE clinic_id = ? AND document_type = ?");
        $checkStmt->execute([$clinicId, $docType]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

        $isResub = false;
        $notificationType = '';
        $notificationMessage = '';

        if ($existing) {
            $currentAttempts = (int)($existing['submission_attempts'] ?? 0);
            $currentStatus   = $existing['status'] ?? '';
            $today           = date('Y-m-d');

            // ── PER-DAY DECLINE RULE ──────────────────────
            // Block only if the document was DECLINED TODAY.
            if ($currentStatus === 'Rejected') {
                $reviewedAt    = $existing['reviewed_at'] ?? null;
                $declinedToday = $reviewedAt && (date('Y-m-d', strtotime($reviewedAt)) === $today);

                if ($declinedToday) {
                    if (file_exists($fullPath)) unlink($fullPath);
                    $waitTomorrowErrors[] = "$docType: Declined today — please try again tomorrow.";
                    error_log("BLOCKED: Clinic $clinicId - $docType - Declined today");
                    continue;
                }
            }

            // Block if already Pending or Approved
            if ($currentStatus === 'Pending' || $currentStatus === 'Approved') {
                if (file_exists($fullPath)) unlink($fullPath);
                throw new Exception("Document is already {$currentStatus}. Cannot update.");
            }

            // Increment attempts (except if Approved, but we never reach here)
            $newAttempts = $currentAttempts + 1;
        } else {
            // New document: first attempt
            $newAttempts = 1;
        }

        // No more "max attempts reached" — replaced by per-day rule
        $maxReached = 0;

        // ── SAVE TO DATABASE ─────────────────────────────
        if ($existing) {
            // Existing document (Rejected or Awaiting Re-upload) → Resubmission

            // Remove old file kung meron
            if ($existing['file_path']) {
                $oldFile = __DIR__ . '/../' . $existing['file_path'];
                if (file_exists($oldFile)) unlink($oldFile);
            }

            $stmt = $pdo->prepare("UPDATE clinic_documents SET 
                                     owner_name = ?,
                                     released_at = ?,
                                     expires_at = ?,
                                     file_path = ?,
                                     ocr_flag = ?,
                                     status = 'Pending',
                                     submission_attempts = ?,
                                     max_attempts_reached = ?,
                                     rejection_reason = NULL,
                                     remarks = NULL,
                                     reviewed_at = NULL,
                                     uploaded_at = NOW(),
                                     is_resubmitted = 1
                                     WHERE id = ?");

            if ($stmt->execute([$ownerName, $releasedAt, $expiresAt, $filePath, $ocr_flag, 
                               $newAttempts, $maxReached, $existing['id']])) {
                $updatedCount++;
                $isResub = true;
                $isResubmission = true;
                $notificationType = 'document_resubmit';
                $notificationMessage = "You have resubmitted the document '$docType' (Submission #$newAttempts). It is now pending review.";
                error_log("Updated (Resubmission): $docType - ID: {$existing['id']} - Submission: $newAttempts");
            } else {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Database update failed: " . ($errorInfo[2] ?? 'Unknown error'));
            }

        } else {
            // New submission
            $stmt = $pdo->prepare("INSERT INTO clinic_documents 
                (clinic_id, document_type, owner_name, released_at, expires_at, 
                 file_path, ocr_flag, status, submission_attempts, max_attempts_reached, uploaded_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, NOW())");

            if ($stmt->execute([$clinicId, $docType, $ownerName, $releasedAt, $expiresAt, 
                               $filePath, $ocr_flag, $newAttempts, $maxReached])) {
                $uploadedCount++;
                $notificationType = 'document_new';
                $notificationMessage = "You have submitted a new document '$docType' (Submission #$newAttempts). It is now pending review.";
                $newId = $pdo->lastInsertId();
                error_log("Inserted (New): $docType - ID: $newId - Submission: $newAttempts");
            } else {
                $errorInfo = $stmt->errorInfo();
                throw new Exception("Database insert failed: " . ($errorInfo[2] ?? 'Unknown error'));
            }
        }

        // ── SEND NOTIFICATION ────────────────────────────
        if ($notificationType && $notificationMessage) {
            $stmtNotif = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read, created_at) 
                                        VALUES (?, ?, ?, ?, 0, NOW())");
            $title = ($isResub ? "Document Resubmitted: $docType" : "New Document Submitted: $docType");
            $stmtNotif->execute([$userId, $title, $notificationMessage, $notificationType]);
        }

    } catch (Exception $e) {
        $errors[] = "$docType: " . $e->getMessage();
        error_log("Error for $docType: " . $e->getMessage());
        continue;
    }
}

// ── UPDATE CLINIC STATUS ───────────────────────────
if ($isResubmission) {
    $stmtClinic = $pdo->prepare("UPDATE clinics SET status = 'Reapplying', updated_at = NOW() WHERE id = ?");
    $stmtClinic->execute([$clinicId]);
}

// ── FINAL RESPONSE ──────────────────────────────────
error_log("=== UPLOAD COMPLETE ===");
error_log("Uploaded: $uploadedCount, Updated: $updatedCount, Errors: " . count($errors));

// Build the session message
$finalMessage = "Successfully processed " . ($uploadedCount + $updatedCount) . " document(s).\n" .
                "• New: $uploadedCount\n" .
                "• Updated: $updatedCount";

if (!empty($waitTomorrowErrors)) {
    $finalMessage .= "\n\n⏳ Please wait until tomorrow:\n• " . implode("\n• ", $waitTomorrowErrors);
}

if (!empty($errors)) {
    $_SESSION['swal'] = [
        'icon' => 'error',
        'title' => 'Upload Issues',
        'text' => $finalMessage . "\n\nErrors:\n" . 
                  implode("\n", array_slice($errors, 0, 5)) . 
                  (count($errors) > 5 ? "\n...and " . (count($errors) - 5) . " more errors" : '')
    ];
} elseif ($uploadedCount > 0 || $updatedCount > 0) {
    $_SESSION['swal'] = [
        'icon' => 'success',
        'title' => 'Success!',
        'text' => $finalMessage
    ];
} elseif (!empty($waitTomorrowErrors)) {
    // Lahat ng in-attempt ay declined today
    $_SESSION['swal'] = [
        'icon' => 'warning',
        'title' => 'Please Try Again Tomorrow',
        'text' => implode("\n", $waitTomorrowErrors)
    ];
} else {
    $_SESSION['swal'] = [
        'icon' => 'info',
        'title' => 'No Changes',
        'text' => 'No documents were uploaded or updated.'
    ];
}

header("Location: ../views/clinic_pending.php");
exit;