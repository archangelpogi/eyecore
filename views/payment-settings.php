<?php
// /admin/api/payment-settings.php

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../config/db.php';

// Check access
$allowed_roles = ['ClinicAdmin', 'Finance'];
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], $allowed_roles)) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$clinic_id = $_SESSION['clinic_id'];
$user_id = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $type = $input['type'] ?? '';
        $data = $input['data'] ?? [];
        
        if ($type === 'save_payment_settings') {
            // Validate
            if (!in_array($data['payment_policy'], 
                ['full_payment', 'downpayment_30', 'downpayment_custom', 'no_payment', 'pay_on_site'])) {
                echo json_encode(['error' => 'Invalid payment policy']);
                exit;
            }
            
            // Update clinic
            $stmt = $pdo->prepare("
                UPDATE clinics SET 
                    payment_policy = ?,
                    downpayment_percentage = ?,
                    payment_method_online = ?,
                    payment_method_onsite = ?,
                    updated_at = NOW()
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $data['payment_policy'],
                $data['downpayment_percentage'] ?? 30,
                $data['payment_method_online'],
                $data['payment_method_onsite'],
                $clinic_id
            ]);
            
            if ($result) {
                // Log audit
                $pdo->prepare("
                    INSERT INTO audit_logs 
                    (user_id, clinic_id, action, table_name, record_id, new_values, created_at)
                    VALUES (?, ?, 'UPDATE', 'payment_settings', ?, ?, NOW())
                ")->execute([
                    $user_id,
                    $clinic_id,
                    $clinic_id,
                    json_encode($data)
                ]);
                
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Failed to save']);
            }
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>