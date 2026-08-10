<?php
// ✅ USE THE SAME SESSION NAME AS CLINIC ADMIN
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

require_once __DIR__ . '/../config/db.php';

// ============================================
// CLINIC REFUND REDIRECT PAGE
// ============================================

// ✅ Check if logged in as clinic user
if (!isset($_SESSION['clinic_id']) || !isset($_SESSION['user_id'])) {
    header('Location: /auth/admin_login.php');
    exit();
}

// Get parameters
$ref_no = $_GET['ref_no'] ?? '';
$appointment_id = (int)($_GET['appointment_id'] ?? 0);
$type = $_GET['type'] ?? '';
$status = $_GET['status'] ?? '';

// ✅ Fetch refund details from database
$refund = null;
$appointment = null;
$patient = null;

if ($appointment_id > 0) {
    try {
        // Get refund request
        $stmt = $pdo->prepare("
            SELECT rr.*, 
                   a.appointment_date, a.appointment_time, a.total_amount, a.downpayment_amount,
                   c.name as clinic_name,
                   u.first_name, u.last_name, u.email, u.contact
            FROM refund_requests rr
            JOIN appointments a ON rr.appointment_id = a.id
            JOIN clinics c ON a.clinic_id = c.id
            JOIN users u ON rr.user_id = u.id
            WHERE rr.appointment_id = ? AND rr.clinic_id = ?
            ORDER BY rr.id DESC
            LIMIT 1
        ");
        $stmt->execute([$appointment_id, $_SESSION['clinic_id']]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get appointment details
        if ($refund) {
            $stmt2 = $pdo->prepare("
                SELECT a.*, pr.name as product_name
                FROM appointments a
                LEFT JOIN products pr ON a.product_id = pr.id
                WHERE a.id = ?
            ");
            $stmt2->execute([$appointment_id]);
            $appointment = $stmt2->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Silently fail, proceed with available data
    }
}

// ✅ Auto-update refund status if success
$verified = false;
if ($status === 'success' && $refund) {
    try {
        // Update refund request status
        $pdo->prepare("
            UPDATE refund_requests 
            SET status = 'completed', 
                refund_status = 'completed',
                refund_date = NOW(),
                updated_at = NOW()
            WHERE appointment_id = ? AND clinic_id = ?
        ")->execute([$appointment_id, $_SESSION['clinic_id']]);
        
        // Update appointment status to refunded
        $pdo->prepare("
            UPDATE appointments 
            SET status = 'refunded', 
                refund_status = 'completed',
                updated_at = NOW()
            WHERE id = ?
        ")->execute([$appointment_id]);
        
        $verified = true;
    } catch (Exception $e) {
        // Silently fail
    }
}

// Get user info
$userRole = $_SESSION['role'] ?? 'Staff';
$clinic_name = $_SESSION['clinic_name'] ?? 'Your Clinic';

// Format amount
$refundAmount = $refund['amount'] ?? $refund['downpayment_amount'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund <?= $status === 'success' ? 'Successful' : 'Redirect' ?> - EyeCore</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --teal: #0d9488;
            --teal-dark: #0f766e;
            --teal-light: #99f6e4;
            --teal-soft: #f0fdfa;
        }
        
        body {
            background: #f5f7fb;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .card {
            border-radius: 20px;
            border: none;
            box-shadow: 0 8px 30px rgba(0,0,0,0.08);
            max-width: 480px;
            width: 100%;
            margin: 0 auto;
            overflow: hidden;
        }
        .card-header-custom {
            background: linear-gradient(135deg, var(--teal), var(--teal-dark));
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        .card-body {
            padding: 2rem;
            background: white;
        }
        .icon-circle {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 1rem;
        }
        .icon-circle.success {
            background: #d1fae5;
            color: #059669;
        }
        .icon-circle.warning {
            background: #fef3c7;
            color: #d97706;
        }
        .icon-circle.pending {
            background: #e5e7eb;
            color: #6b7280;
        }
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.6rem 0;
            border-bottom: 1px solid #f3f4f6;
        }
        .detail-row:last-child {
            border-bottom: none;
        }
        .detail-row .label {
            color: #6b7280;
            font-size: 0.85rem;
        }
        .detail-row .value {
            font-weight: 600;
            color: #111827;
            text-align: right;
        }
        .detail-row .value.teal {
            color: var(--teal);
            font-size: 1.1rem;
        }
        .badge-custom {
            background: #d1fae5;
            color: #059669;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .btn-teal {
            background: linear-gradient(135deg, var(--teal), var(--teal-dark));
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-teal:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(13, 148, 136, 0.3);
            color: white;
        }
        .btn-outline-teal {
            background: transparent;
            border: 1.5px solid var(--teal);
            color: var(--teal);
            padding: 10px 24px;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
        }
        .btn-outline-teal:hover {
            background: var(--teal);
            color: white;
        }
        .alert-custom {
            background: var(--teal-soft);
            border: 1px solid var(--teal-light);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 0.85rem;
            color: var(--teal-dark);
        }
        .alert-custom i {
            margin-right: 8px;
        }
        .ref-code {
            font-family: monospace;
            font-size: 0.8rem;
            background: #f8fafc;
            padding: 2px 8px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
    </style>
</head>
<body>
<div class="card">
    <!-- Card Header -->
    <div class="card-header-custom">
        <i class="bi bi-arrow-repeat fs-2 d-block mb-2"></i>
        <h4 class="mb-0 fw-bold">Refund Processing</h4>
        <p class="mb-0 opacity-75 small">You have been redirected from PayMongo</p>
    </div>

    <!-- Card Body -->
    <div class="card-body">
        
        <?php if ($status === 'success' && $verified): ?>
            <!-- ✅ SUCCESS -->
            <div class="text-center">
                <div class="icon-circle success mx-auto">
                    <i class="bi bi-check-circle-fill"></i>
                </div>
                <h3 class="fw-bold text-success mb-2">Refund Processed!</h3>
                <p class="text-muted small mb-4">The refund has been processed successfully on PayMongo.</p>
            </div>

            <?php if ($refund): ?>
            <div class="bg-light rounded-3 p-3 mb-4">
                <div class="detail-row">
                    <span class="label">Reference No.</span>
                    <span class="value ref-code"><?= htmlspecialchars($ref_no ?: 'N/A') ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Patient</span>
                    <span class="value"><?= htmlspecialchars(($refund['first_name'] ?? '') . ' ' . ($refund['last_name'] ?? '')) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Appointment</span>
                    <span class="value">#<?= $appointment_id ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Refund Amount</span>
                    <span class="value teal">₱<?= number_format($refundAmount, 2) ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Refund Date</span>
                    <span class="value"><?= date('M d, Y h:i A') ?></span>
                </div>
                <div class="detail-row">
                    <span class="label">Status</span>
                    <span class="value"><span class="badge-custom">✅ Completed</span></span>
                </div>
            </div>

            <div class="alert-custom mb-4">
                <i class="bi bi-envelope"></i>
                A refund confirmation has been sent to the patient's email.
            </div>
            <?php endif; ?>

        <?php elseif ($status === 'cancelled'): ?>
            <!-- ❌ CANCELLED -->
            <div class="text-center">
                <div class="icon-circle warning mx-auto">
                    <i class="bi bi-x-circle-fill"></i>
                </div>
                <h3 class="fw-bold text-warning mb-2">Refund Cancelled</h3>
                <p class="text-muted small mb-4">You cancelled the refund process. No charges were made.</p>
            </div>

            <div class="alert alert-warning py-2 small mb-4">
                <i class="bi bi-info-circle me-1"></i>
                You can retry the refund from the Refunds page.
            </div>

        <?php else: ?>
            <!-- ⏳ PENDING / REDIRECT -->
            <div class="text-center">
                <div class="icon-circle pending mx-auto">
                    <i class="bi bi-clock-fill"></i>
                </div>
                <h3 class="fw-bold mb-2">Refund Redirect</h3>
                <p class="text-muted small mb-4">
                    You are being redirected back to the clinic dashboard.
                </p>
            </div>

            <div class="alert-custom mb-4">
                <i class="bi bi-arrow-clockwise"></i>
                Please wait while we redirect you...
            </div>
        <?php endif; ?>

        <!-- Buttons -->
        <div class="d-flex gap-2 justify-content-center mt-3">
            <a href="/main.php?view=appointments" class="btn-teal">
                <i class="bi bi-calendar-check me-2"></i>Appointments
            </a>
        </div>

    </div>
</div>

<script>
    <?php if ($status !== 'success'): ?>
    setTimeout(function() {
        window.location.href = '/main.php?view=appointments';
    }, 5000);
    <?php endif; ?>
</script>
</body>
</html>