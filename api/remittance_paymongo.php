<?php
/**
 * ════════════════════════════════════════════════════════════════
 * api/remittance_paymongo.php
 * COD Remittance via PayMongo — Rider online remittance
 * ════════════════════════════════════════════════════════════════
 */

if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// ✅ PayMongo configuration (reuse existing)
$secret_key = "sk_test_qcZwF33CQGUk9owjBgRtGFbS";
$base_url = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ════════════════════════════════════════════════════════════════
// CREATE REMITTANCE CHECKOUT
// ════════════════════════════════════════════════════════════════
if ($action === 'create_remittance_checkout') {
    
    $riderId  = (int)($_SESSION['rider_id'] ?? 0);
    $userId   = (int)($_SESSION['user_id'] ?? 0);
    $clinicId = (int)($_SESSION['clinic_id'] ?? 0);
    
    if (!$riderId || !$userId) {
        echo json_encode(['success' => false, 'error' => 'Rider not authenticated']);
        exit;
    }
    
    // ✅ Get rider info + wallet
    $stmt = $pdo->prepare("
        SELECT name, email, phone, wallet_balance, wallet_limit, wallet_status
        FROM riders
        WHERE id = ? AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$riderId]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$rider) {
        echo json_encode(['success' => false, 'error' => 'Rider not found']);
        exit;
    }
    
    $balance = (float)$rider['wallet_balance'];
    
    if ($balance <= 0) {
        echo json_encode(['success' => false, 'error' => 'Walang balance na ire-remit']);
        exit;
    }
    
    // ✅ Idempotency: Check kung may pending online remittance na
    $checkStmt = $pdo->prepare("
        SELECT id, remittance_code
        FROM cod_remittances
        WHERE rider_id = ?
          AND method = 'online'
          AND status = 'pending'
          AND paymongo_checkout_id IS NOT NULL
        ORDER BY id DESC
        LIMIT 1
    ");
    $checkStmt->execute([$riderId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // ✅ May existing pending — check kung valid pa ang checkout
        $verifyCh = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$existing['remittance_code']}");
        // Actually, use checkout_id from DB para tama
        $existingCheckoutStmt = $pdo->prepare("
            SELECT paymongo_checkout_id FROM cod_remittances WHERE id = ?
        ");
        $existingCheckoutStmt->execute([$existing['id']]);
        $existingCheckoutId = $existingCheckoutStmt->fetchColumn();
        
        echo json_encode([
            'success' => false,
            'error' => 'May pending remittance ka na. Tapusin o hintayin ang verification.',
            'existing_code' => $existing['remittance_code']
        ]);
        exit;
    }
    
    // ✅ Generate remittance code
    $remittanceCode = 'RMT-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    
    // ✅ Create cod_remittances record (pending)
    $insertStmt = $pdo->prepare("
        INSERT INTO cod_remittances 
            (rider_id, clinic_id, remittance_code, amount, method, status, submitted_at)
        VALUES (?, ?, ?, ?, 'online', 'pending', NOW())
    ");
    $insertStmt->execute([$riderId, $clinicId, $remittanceCode, $balance]);
    
    $remittanceId = (int)$pdo->lastInsertId();
    
    // ✅ Success/cancel URLs
    $successUrl = $base_url . "/views/rider_dashboard.php?remit=success&rid={$remittanceId}";
    $cancelUrl  = $base_url . "/views/rider_dashboard.php?remit=cancelled&rid={$remittanceId}";
    
    // ✅ Line items
    $lineItems = [[
        'currency'    => 'PHP',
        'amount'      => (int)round($balance * 100),
        'name'        => 'COD Remittance',
        'description' => 'Remittance Code: ' . $remittanceCode,
        'quantity'    => 1,
    ]];
    
    // ✅ Build payload
    $payload = [
        'data' => [
            'attributes' => [
                'send_email_receipt'   => true,
                'show_description'     => true,
                'show_line_items'      => true,
                'description'          => 'COD Remittance — ' . $rider['name'],
                'line_items'           => $lineItems,
                'payment_method_types' => ['gcash', 'paymaya', 'card'],
                'success_url'          => $successUrl,
                'cancel_url'           => $cancelUrl,
                'billing'              => [
                    'name'  => $rider['name'] ?? '',
                    'email' => $rider['email'] ?? '',
                    'phone' => $rider['phone'] ?? '',
                ],
                'metadata' => [
                    'remittance_id'   => $remittanceId,
                    'remittance_code' => $remittanceCode,
                    'rider_id'        => $riderId,
                    'clinic_id'       => $clinicId,
                    'type'            => 'cod_remittance',
                ]
            ]
        ]
    ];
    
    // ✅ PayMongo API call
    $ch = curl_init('https://api.paymongo.com/v1/checkout_sessions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Basic ' . base64_encode($secret_key . ':')
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        // ✅ Mark as failed
        $pdo->prepare("UPDATE cod_remittances SET status = 'failed' WHERE id = ?")
            ->execute([$remittanceId]);
        
        echo json_encode(['success' => false, 'error' => 'CURL error: ' . $curlError]);
        exit;
    }
    
    $data = json_decode($response, true);
    
    if ($httpCode === 200 || $httpCode === 201) {
        $checkoutUrl = $data['data']['attributes']['checkout_url'] ?? null;
        $checkoutId  = $data['data']['id'] ?? null;
        
        if (!$checkoutUrl || !$checkoutId) {
            $pdo->prepare("UPDATE cod_remittances SET status = 'failed' WHERE id = ?")
                ->execute([$remittanceId]);
            
            echo json_encode(['success' => false, 'error' => 'PayMongo returned invalid checkout data']);
            exit;
        }
        
        // ✅ Save checkout info
        $pdo->prepare("
            UPDATE cod_remittances 
            SET paymongo_checkout_id = ?,
                paymongo_status = 'pending'
            WHERE id = ?
        ")->execute([$checkoutId, $remittanceId]);
        
        echo json_encode([
            'success'         => true,
            'checkout_url'    => $checkoutUrl,
            'checkout_id'     => $checkoutId,
            'remittance_id'   => $remittanceId,
            'remittance_code' => $remittanceCode,
            'amount'          => $balance
        ]);
        
    } else {
        // ✅ Mark as failed
        $pdo->prepare("UPDATE cod_remittances SET status = 'failed' WHERE id = ?")
            ->execute([$remittanceId]);
        
        $errors = $data['errors'] ?? [];
        $errorMsg = !empty($errors) ? $errors[0]['detail'] : 'PayMongo API error (HTTP ' . $httpCode . ')';
        
        echo json_encode(['success' => false, 'error' => $errorMsg]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════
// VERIFY REMITTANCE CHECKOUT (called by success page fallback)
// ════════════════════════════════════════════════════════════════
if ($action === 'verify_remittance_checkout') {
    
    $remittanceId = (int)($_POST['remittance_id'] ?? 0);
    
    if (!$remittanceId) {
        echo json_encode(['success' => false, 'error' => 'Missing remittance_id']);
        exit;
    }
    
    // ✅ Get remittance + checkout id
    $stmt = $pdo->prepare("
        SELECT id, rider_id, clinic_id, amount, status, paymongo_checkout_id, paymongo_status
        FROM cod_remittances
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$remittanceId]);
    $remittance = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$remittance) {
        echo json_encode(['success' => false, 'error' => 'Remittance not found']);
        exit;
    }
    
    // ✅ Kung completed na — ibalik ang result
    if ($remittance['status'] === 'completed') {
        echo json_encode([
            'success' => true,
            'status' => 'completed',
            'message' => 'Remittance already verified'
        ]);
        exit;
    }
    
    if (empty($remittance['paymongo_checkout_id'])) {
        echo json_encode(['success' => false, 'error' => 'No PayMongo checkout ID']);
        exit;
    }
    
    // ✅ Check PayMongo status
    $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$remittance['paymongo_checkout_id']}");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . base64_encode($secret_key . ':')
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'PayMongo API error']);
        exit;
    }
    
    $data = json_decode($response, true);
    
    $pmStatus = $data['data']['attributes']['payment_intent']['attributes']['status']
             ?? $data['data']['attributes']['status']
             ?? 'unknown';
    
    if ($pmStatus === 'paid' || $pmStatus === 'succeeded') {
        // ✅ Process the remittance
        $result = processOnlineRemittance($pdo, $remittanceId);
        
        if ($result['ok']) {
            echo json_encode([
                'success' => true,
                'status' => 'completed',
                'message' => 'Remittance verified and wallet cleared'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => $result['reason'] ?? 'Failed to process remittance'
            ]);
        }
    } else {
        echo json_encode([
            'success' => true,
            'status' => $pmStatus,
            'message' => 'Payment still pending or not completed'
        ]);
    }
    exit;
}

// ════════════════════════════════════════════════════════════════
// HELPER: PROCESS ONLINE REMITTANCE
// ════════════════════════════════════════════════════════════════
function processOnlineRemittance(PDO $pdo, int $remittanceId): array
{
    try {
        // ✅ Get remittance + rider info
        $stmt = $pdo->prepare("
            SELECT cr.*, r.name AS rider_name, r.user_id AS rider_user_id,
                   r.wallet_balance, r.wallet_limit
            FROM cod_remittances cr
            JOIN riders r ON cr.rider_id = r.id
            WHERE cr.id = ?
            LIMIT 1
        ");
        $stmt->execute([$remittanceId]);
        $rem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$rem) {
            return ['ok' => false, 'reason' => 'Remittance not found'];
        }
        
        // ✅ Idempotency: Kung completed na, huwag i-process ulit
        if ($rem['status'] === 'completed') {
            return ['ok' => true, 'reason' => 'Already processed'];
        }
        
        $riderId = (int)$rem['rider_id'];
        $amount  = (float)$rem['amount'];
        $oldBalance = (float)$rem['wallet_balance'];
        $limit = (float)$rem['wallet_limit'];
        if ($limit <= 0) $limit = 5000;
        
        $newBalance = max(0, $oldBalance - $amount);
        
        // ✅ Recompute wallet status
        $newStatus = 'normal';
        if ($newBalance >= $limit * 1.6) {
            $newStatus = 'blocked_all';
        } elseif ($newBalance >= $limit) {
            $newStatus = 'blocked_cod';
        } elseif ($newBalance >= $limit * 0.8) {
            $newStatus = 'warning';
        }
        
        $pdo->beginTransaction();
        
        try {
            // ✅ Update remittance
            $pdo->prepare("
                UPDATE cod_remittances
                SET status = 'completed',
                    refund_status = 'completed',
                    completed_at = NOW(),
                    paymongo_status = 'paid'
                WHERE id = ?
            ")->execute([$remittanceId]);
            
            // ✅ Update rider wallet
            $pdo->prepare("
                UPDATE riders
                SET wallet_balance = ?,
                    wallet_status = ?,
                    last_remit_at = NOW()
                WHERE id = ?
            ")->execute([$newBalance, $newStatus, $riderId]);
            
            // ✅ Insert wallet transaction (audit)
            $pdo->prepare("
                INSERT INTO rider_wallet_transactions 
                    (rider_id, reservation_id, remittance_id, type, amount, balance_after, notes, created_at)
                VALUES (?, NULL, ?, 'remit_online', ?, ?, ?, NOW())
            ")->execute([
                $riderId,
                $remittanceId,
                -$amount,             // negative = bawas
                $newBalance,
                'Online remittance — ' . $rem['remittance_code']
            ]);
            
            $pdo->commit();
            
            // ✅ Notify rider
            if (!empty($rem['rider_user_id'])) {
                $title = '✅ COD Remittance Complete';
                $message = "Your online remittance of ₱" . number_format($amount, 2) . 
                           " has been confirmed. New balance: ₱" . number_format($newBalance, 2) . ".";
                
                $pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                    VALUES (?, ?, ?, 'wallet', 'rider_dashboard.php', 0, NOW())
                ")->execute([$rem['rider_user_id'], $title, $message]);
            }
            
            return ['ok' => true, 'new_balance' => $newBalance, 'status' => $newStatus];
            
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log("processOnlineRemittance transaction failed: " . $e->getMessage());
            return ['ok' => false, 'reason' => 'Transaction failed: ' . $e->getMessage()];
        }
        
    } catch (Exception $e) {
        error_log("processOnlineRemittance error: " . $e->getMessage());
        return ['ok' => false, 'reason' => 'Error: ' . $e->getMessage()];
    }
}

// ════════════════════════════════════════════════════════════════
// UNKNOWN ACTION
// ════════════════════════════════════════════════════════════════
echo json_encode(['success' => false, 'error' => 'Unknown action']);