<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// Dagdag ito dito:
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

if (!isset($_SESSION['clinic_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$clinic_id = $_SESSION['clinic_id'];

$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true) ?? [];
}
$action = $_GET['action'] ?? $_POST['action'] ?? $input['action'] ?? '';

function sendWarrantyEmail($claim, $status, $scheduleDate, $rejectionReason, $resolution) {


    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'angelloricanmendoza27@gmail.com';
        $mail->Password   = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = 'tls';
        $mail->Port       = 587;

        $mail->setFrom('angelloricanmendoza27@gmail.com', 'EyeCore Clinic');
        $mail->addAddress($claim['email'], $claim['first_name'] . ' ' . $claim['last_name']);

        if ($status === 'approved') {
            $formattedDate = $scheduleDate 
                ? date('F j, Y', strtotime($scheduleDate)) 
                : 'To be confirmed';

            $resolutionLabel = match($resolution) {
                'repair'       => 'Repair',
                'replacement'  => 'Replacement',
                'store_credit' => 'Store Credit',
                default        => 'To be discussed'
            };

            $mail->Subject = '✅ Warranty Claim Approved — ' . $claim['claim_number'];
            $mail->isHTML(true);
            $mail->Body = "
                <h2>Your Warranty Claim Has Been Approved!</h2>
                <p>Dear {$claim['first_name']},</p>
                <p>Great news! Your warranty claim <strong>{$claim['claim_number']}</strong> for <strong>{$claim['product_name']}</strong> has been <strong style='color:green;'>approved</strong>.</p>
                <table style='border-collapse:collapse; width:100%; max-width:400px;'>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Offer Type</strong></td><td style='padding:8px; border:1px solid #ddd;'>{$resolutionLabel}</td></tr>
                    <tr><td style='padding:8px; border:1px solid #ddd;'><strong>Visit Date</strong></td><td style='padding:8px; border:1px solid #ddd;'>{$formattedDate}</td></tr>
                </table>
                <p>Please visit the clinic on the scheduled date. Bring a valid ID and your proof of purchase.</p>
                <p>Thank you for trusting EyeCore!</p>
            ";
        } else {
            $mail->Subject = '❌ Warranty Claim Update — ' . $claim['claim_number'];
            $mail->isHTML(true);
            $mail->Body = "
                <h2>Warranty Claim Status Update</h2>
                <p>Dear {$claim['first_name']},</p>
                <p>We're sorry to inform you that your warranty claim <strong>{$claim['claim_number']}</strong> for <strong>{$claim['product_name']}</strong> has been <strong style='color:red;'>rejected</strong>.</p>
                <p><strong>Reason:</strong> {$rejectionReason}</p>
                <p>If you have questions, please contact us at the clinic.</p>
                <p>Thank you for understanding.</p>
            ";
        }

        $mail->send();
    } catch (Exception $e) {
        // Log error but don't fail the whole request
        error_log('Warranty email error: ' . $e->getMessage());
    }
}

switch($action) {
    case 'get_claims':
        $search   = $_GET['search'] ?? '';
        $status   = $_GET['status'] ?? '';
        $fromDate = $_GET['from'] ?? '';
        $toDate   = $_GET['to'] ?? '';

        $sql = "
            SELECT wc.*, u.first_name, u.last_name, u.email, u.contact,
                   p.name as product_name
            FROM warranty_claims wc
            JOIN users u ON wc.user_id = u.id
            LEFT JOIN products p ON wc.product_id = p.id
            WHERE wc.clinic_id = ?
        ";
        $params = [$clinic_id];

        if ($search) {
            $sql .= " AND (wc.claim_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR p.name LIKE ?)";
            $s = "%$search%";
            array_push($params, $s, $s, $s, $s);
        }
        if ($status) {
            $sql .= " AND wc.status = ?";
            $params[] = $status;
        }
        if ($fromDate) {
            $sql .= " AND DATE(wc.created_at) >= ?";
            $params[] = $fromDate;
        }
        if ($toDate) {
            $sql .= " AND DATE(wc.created_at) <= ?";
            $params[] = $toDate;
        }

        $sql .= " ORDER BY wc.created_at DESC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $claims = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $claims]);
        break;

    case 'get_claim':
        $id = (int)($_GET['id'] ?? 0);

        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Invalid claim ID']);
            break;
        }

        $stmt = $pdo->prepare("
            SELECT wc.*,
                   u.first_name, u.last_name, u.email, u.contact,
                   p.name as product_name,
                   r.reservation_code
            FROM warranty_claims wc
            JOIN users u ON wc.user_id = u.id
            LEFT JOIN products p ON wc.product_id = p.id
            LEFT JOIN reservations r ON wc.reservation_id = r.id
            WHERE wc.id = ? AND wc.clinic_id = ?
        ");
        $stmt->execute([$id, $clinic_id]);
        $claim = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$claim) {
            echo json_encode(['success' => false, 'message' => 'Claim not found']);
            break;
        }

        echo json_encode(['success' => true, 'data' => $claim]);
        break;

case 'update_claim':
    $claimId         = $input['claim_id'] ?? null;
    $newStatus       = $input['status'] ?? null;
    $resolution      = $input['resolution'] ?? null;
    $scheduleDate    = $input['schedule_date'] ?? null;
    $resolutionNotes = $input['resolution_notes'] ?? null;
    $rejectionReason = $input['rejection_reason'] ?? null;
    $userId          = $input['user_id'] ?? null;

    if (!$claimId || !$newStatus) {
        echo json_encode(['success' => false, 'message' => 'Missing required fields']);
        exit;
    }

    try {
        // Get claim info for email notification
        $stmtGet = $pdo->prepare("
            SELECT wc.*, u.email, u.first_name, u.last_name, p.name as product_name
            FROM warranty_claims wc
            JOIN users u ON wc.user_id = u.id
            LEFT JOIN products p ON wc.product_id = p.id
            WHERE wc.id = ? AND wc.clinic_id = ?
        ");
        $stmtGet->execute([$claimId, $clinic_id]);
        $claim = $stmtGet->fetch(PDO::FETCH_ASSOC);

        if (!$claim) {
            echo json_encode(['success' => false, 'message' => 'Claim not found']);
            exit;
        }

        // Update the claim
        $stmtUpdate = $pdo->prepare("
            UPDATE warranty_claims
            SET status           = ?,
                resolution_type  = ?,
                schedule_date    = ?,
                resolution_notes = ?,
                rejection_reason = ?,
                reviewed_at      = NOW()
            WHERE id = ? AND clinic_id = ?
        ");
        $stmtUpdate->execute([
            $newStatus,
            $resolution,
            $scheduleDate,
            $resolutionNotes,
            $rejectionReason,
            $claimId,
            $clinic_id
        ]);

        // Send email notification
        if (in_array($newStatus, ['approved', 'rejected']) && !empty($claim['email'])) {
            sendWarrantyEmail($claim, $newStatus, $scheduleDate, $rejectionReason, $resolution);
        }

        echo json_encode(['success' => true, 'message' => 'Claim updated successfully']);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;

    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>