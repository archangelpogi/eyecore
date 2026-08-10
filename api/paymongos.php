<?php
/**
 * PayMongo Refund API Integration with Auto Retry
 */

require_once __DIR__ . '/../config/db.php';

class PayMongoRefund {
    private $pdo;
    private $secretKey;
    private $apiUrl;
    private $maxRetries = 5;
    private $retryDelay = 3; // seconds
    private $baseUrl;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->secretKey = "sk_test_qcZwF33CQGUk9owjBgRtGFbS";
        $this->apiUrl = "https://api.paymongo.com/v1/refunds";
        $this->baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
    
    /**
     * Get valid PayMongo refund reason
     */
    private function getValidReason($reason) {
        $validReasons = [
            'duplicate',
            'fraudulent', 
            'requested_by_customer',
            'bank_return',
            'cancelled_recurring_billing',
            'recurring_billing_stopped',
            'others'
        ];
        
        if (in_array($reason, $validReasons)) {
            return $reason;
        }
        
        $reasonMap = [
            'customer requested refund' => 'requested_by_customer',
            'customer requested' => 'requested_by_customer',
            'duplicate' => 'duplicate',
            'fraud' => 'fraudulent',
            'fraudulent' => 'fraudulent',
            'bank return' => 'bank_return',
            'bank_return' => 'bank_return',
            'cancelled' => 'cancelled_recurring_billing',
            'recurring' => 'recurring_billing_stopped',
            'others' => 'others',
            'other' => 'others'
        ];
        
        $lowerReason = strtolower(trim($reason));
        return $reasonMap[$lowerReason] ?? 'requested_by_customer';
    }
    
    /**
     * Get actual payment ID from checkout session
     */
    private function getPaymentIdFromCheckout($checkoutSessionId) {
        try {
            $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($this->secretKey . ':')
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode !== 200) {
                return null;
            }
            
            $data = json_decode($response, true);
            
            // Get payment intent ID
            $paymentIntentId = $data['data']['attributes']['payment_intent']['id'] ?? null;
            
            if ($paymentIntentId) {
                // Get actual payment ID from payment intent
                $ch2 = curl_init("https://api.paymongo.com/v1/payment_intents/{$paymentIntentId}");
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_HTTPHEADER, [
                    'Authorization: Basic ' . base64_encode($this->secretKey . ':')
                ]);
                $response2 = curl_exec($ch2);
                $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
                curl_close($ch2);
                
                if ($httpCode2 === 200) {
                    $data2 = json_decode($response2, true);
                    $payments = $data2['data']['attributes']['payments'] ?? [];
                    if (!empty($payments)) {
                        return $payments[0]['id'] ?? null;
                    }
                }
                
                // Fallback: use payment intent ID
                return $paymentIntentId;
            }
            
            return null;
        } catch (Exception $e) {
            error_log("Failed to get payment ID: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Process refund with automatic retry
     */
    public function processRefundWithRetry($refundId, $paymongoCheckoutId, $amount, $reason = 'Customer requested refund') {
        $attempt = 1;
        $lastError = null;
        $result = null;
        
        // ✅ First, get the actual payment ID from checkout session
        $paymentId = $this->getPaymentIdFromCheckout($paymongoCheckoutId);
        
        if (!$paymentId) {
            return [
                'success' => false,
                'message' => 'Could not retrieve valid payment ID from checkout session. Please process refund manually via PayMongo dashboard.',
                'requires_manual' => true,
                'checkout_id' => $paymongoCheckoutId
            ];
        }
        
        while ($attempt <= $this->maxRetries) {
            // Update attempt count
            $this->updateRefundAttempt($refundId, $attempt);
            
            // Try to process refund with actual payment ID
            $result = $this->processRefund($refundId, $paymentId, $amount, $reason);
            
            if ($result['success']) {
                // ✅ SUCCESS - Update and return
                $this->updateRefundStatus($refundId, 'completed', $result['refund_id'] ?? null, null, $amount);
                $this->updateAppointmentStatus($refundId, 'refunded');
                $this->sendRefundNotification($refundId, $amount);
                
                return [
                    'success' => true,
                    'message' => "Refund processed successfully after {$attempt} attempt(s)!",
                    'refund_id' => $result['refund_id'] ?? null,
                    'amount' => $amount,
                    'attempts' => $attempt
                ];
            }
            
            // ❌ FAILED - Store error and retry
            $lastError = $result['message'] ?? 'Unknown error';
            $this->updateRefundStatus($refundId, 'failed', null, "Attempt {$attempt}: " . $lastError);
            
            if ($attempt < $this->maxRetries) {
                // Wait before retry
                sleep($this->retryDelay);
            }
            
            $attempt++;
        }
        
        // ❌ ALL RETRIES FAILED - Mark for manual intervention
        $this->updateRefundStatus(
            $refundId, 
            'failed', 
            null, 
            "All {$this->maxRetries} attempts failed. Last error: " . $lastError . ". Payment ID used: " . $paymentId
        );
        
        // Send notification to clinic admin
        $this->notifyAdminForManualRefund($refundId, $paymentId);
        
        return [
            'success' => false,
            'message' => "Refund failed after {$this->maxRetries} attempts. Manual intervention required.",
            'error' => $lastError,
            'requires_manual' => true,
            'attempts' => $attempt - 1,
            'payment_id_used' => $paymentId
        ];
    }
    
    /**
     * Single refund attempt (called by processRefundWithRetry)
     */
    private function processRefund($refundId, $paymentId, $amount, $reason = 'Customer requested refund') {
        try {
            // Get refund request details
            $stmt = $this->pdo->prepare("
                SELECT rr.*, a.total_amount, a.id as appointment_id, a.user_id
                FROM refund_requests rr
                JOIN appointments a ON rr.appointment_id = a.id
                WHERE rr.id = ?
            ");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$refund) {
                return ['success' => false, 'message' => 'Refund request not found'];
            }
            
            if (!$paymentId) {
                return ['success' => false, 'message' => 'No valid payment ID found'];
            }
            
            $validReason = $this->getValidReason($reason);
            
            $refundData = [
                'data' => [
                    'attributes' => [
                        'payment_id' => $paymentId,
                        'amount' => intval($amount * 100),
                        'reason' => $validReason,
                        'notes' => [
                            'refund_request_id' => $refundId,
                            'appointment_id' => $refund['appointment_id'],
                            'customer_reason' => $refund['reason'] ?? 'Customer requested'
                        ]
                    ]
                ]
            ];
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($refundData));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "Content-Type: application/json",
                "Authorization: Basic " . base64_encode($this->secretKey . ":")
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return ['success' => false, 'message' => 'CURL Error: ' . $curlError];
            }
            
            $result = json_decode($response, true);
            
            if (isset($result['errors'])) {
                $errorMsg = $result['errors'][0]['detail'] ?? 'Unknown PayMongo error';
                return ['success' => false, 'message' => 'PayMongo Error: ' . $errorMsg];
            }
            
            if (isset($result['data']['id'])) {
                return [
                    'success' => true,
                    'refund_id' => $result['data']['id'],
                    'amount' => $result['data']['attributes']['amount'] / 100,
                    'status' => $result['data']['attributes']['status'] ?? 'completed'
                ];
            }
            
            return ['success' => false, 'message' => 'Unexpected PayMongo response'];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
        }
    }
    
/**
 * Create checkout session for refund (for clinic admin)
 * ✅ This creates a BRAND NEW checkout session, NOT using old one
 */
public function createRefundCheckout($refundId, $appointmentId, $amount, $reason = 'Refund request') {
    try {
        // Get refund details
        $stmt = $this->pdo->prepare("
            SELECT rr.*, a.*, c.name as clinic_name
            FROM refund_requests rr
            JOIN appointments a ON rr.appointment_id = a.id
            JOIN clinics c ON a.clinic_id = c.id
            WHERE rr.id = ?
        ");
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$refund) {
            return ['success' => false, 'message' => 'Refund request not found'];
        }
        
        // Generate reference number
        $refNo = 'REF-' . strtoupper(uniqid());
        
        // ✅ CLINIC ADMIN ONLY - use views/refund-redirect.php
        $successUrl = $this->baseUrl . "/views/refund-redirect.php?ref_no={$refNo}&appointment_id={$appointmentId}&type=refund&status=success";
        
        // Cancel URL - back to clinic appointments
        $cancelUrl = $this->baseUrl . "/views/main.php?view=appointments&tab=refunds";
        
        // Prepare checkout payload - BRAND NEW SESSION
        $checkoutData = [
            'data' => [
                'attributes' => [
                    'line_items' => [
                        [
                            'currency' => 'PHP',
                            'amount' => intval($amount * 100),
                            'name' => 'Refund - ' . $refund['clinic_name'],
                            'description' => 'Refund for appointment #' . $appointmentId . ' - ' . $reason,
                            'quantity' => 1
                        ]
                    ],
                    'payment_method_types' => ['gcash', 'paymaya', 'card'],
                    'success_url' => $successUrl,
                    'cancel_url' => $cancelUrl,
                    'description' => 'Refund processing for appointment #' . $appointmentId,
                    'send_email_receipt' => true,
                    'show_description' => true,
                    'show_line_items' => true,
                    'reference_number' => $refNo,
                    'metadata' => [
                        'refund_id' => $refundId,
                        'appointment_id' => $appointmentId,
                        'type' => 'refund'
                    ]
                ]
            ]
        ];
        
        // Send to PayMongo to create NEW checkout session
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.paymongo.com/v1/checkout_sessions");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($checkoutData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Basic " . base64_encode($this->secretKey . ":")
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            return ['success' => false, 'message' => 'CURL Error: ' . $curlError];
        }
        
        $result = json_decode($response, true);
        
        if (isset($result['errors'])) {
            $errorMsg = $result['errors'][0]['detail'] ?? 'Unknown PayMongo error';
            return ['success' => false, 'message' => 'PayMongo Error: ' . $errorMsg];
        }
        
        if (isset($result['data']['id'])) {
            $checkoutId = $result['data']['id'];
            $checkoutUrl = $result['data']['attributes']['checkout_url'] ?? null;
            
            return [
                'success' => true,
                'checkout_url' => $checkoutUrl,
                'checkout_id' => $checkoutId,
                'ref_no' => $refNo
            ];
        }
        
        return ['success' => false, 'message' => 'Unexpected PayMongo response'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Exception: ' . $e->getMessage()];
    }
}
    
    private function updateRefundAttempt($refundId, $attempt) {
        try {
            $check = $this->pdo->query("SHOW COLUMNS FROM refund_requests LIKE 'refund_attempts'");
            if ($check->rowCount() > 0) {
                $stmt = $this->pdo->prepare("
                    UPDATE refund_requests 
                    SET refund_attempts = ?
                    WHERE id = ?
                ");
                $stmt->execute([$attempt, $refundId]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function updateRefundStatus($refundId, $status, $paymongoRefundId = null, $errorMsg = null, $amount = null) {
        try {
            $sql = "UPDATE refund_requests SET 
                    refund_status = ?";
            $params = [$status];
            
            if ($paymongoRefundId) {
                $sql .= ", paymongo_refund_id = ?";
                $params[] = $paymongoRefundId;
            }
            
            if ($errorMsg) {
                $sql .= ", error_message = ?";
                $params[] = $errorMsg;
            }
            
            if ($amount) {
                $sql .= ", refund_amount_actual = ?";
                $params[] = $amount;
            }
            
            if ($status === 'completed') {
                $sql .= ", refund_date = NOW()";
            }
            
            $sql .= " WHERE id = ?";
            $params[] = $refundId;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            return true;
        } catch (Exception $e) {
            error_log("Failed to update refund status: " . $e->getMessage());
            return false;
        }
    }
    
    private function updateAppointmentStatus($refundId, $status) {
        try {
            $stmt = $this->pdo->prepare("SELECT appointment_id FROM refund_requests WHERE id = ?");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($refund) {
                $stmt = $this->pdo->prepare("
                    UPDATE appointments 
                    SET status = ?, 
                        refund_status = 'completed'
                    WHERE id = ?
                ");
                $stmt->execute([$status, $refund['appointment_id']]);
            }
            return true;
        } catch (Exception $e) {
            error_log("Failed to update appointment status: " . $e->getMessage());
            return false;
        }
    }
    
    private function sendRefundNotification($refundId, $amount) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rr.*, u.id as user_id, u.fullname
                FROM refund_requests rr
                JOIN users u ON rr.user_id = u.id
                WHERE rr.id = ?
            ");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($refund) {
                $title = "✅ Refund Processed Successfully";
                $message = "Your refund of ₱" . number_format($amount, 2) . " has been processed. Please allow 3-5 business days for the amount to reflect in your account.";
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                    VALUES (?, ?, ?, 'refund', '/profile.php?tab=appointments', 0, NOW())
                ");
                $stmt->execute([$refund['user_id'], $title, $message]);
            }
            return true;
        } catch (Exception $e) {
            error_log("Failed to send notification: " . $e->getMessage());
            return false;
        }
    }
    
    private function notifyAdminForManualRefund($refundId, $paymentId = null) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rr.clinic_id, u.id as admin_id
                FROM refund_requests rr
                JOIN users u ON u.clinic_id = rr.clinic_id AND u.role = 'ClinicAdmin'
                WHERE rr.id = ?
                LIMIT 1
            ");
            $stmt->execute([$refundId]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($admin) {
                $title = "⚠️ Refund Needs Manual Intervention";
                $message = "Refund request #{$refundId} failed after multiple attempts. ";
                if ($paymentId) {
                    $message .= "Payment ID: {$paymentId}. ";
                }
                $message .= "Please process manually via PayMongo dashboard.";
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                    VALUES (?, ?, ?, 'refund_alert', '/pages/admin/appointment-scheduling.php#tabRefunds', 0, NOW())
                ");
                $stmt->execute([$admin['admin_id'], $title, $message]);
            }
            return true;
        } catch (Exception $e) {
            error_log("Failed to notify admin: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get checkout URL from checkout session ID
     */
    public function getCheckoutUrl($checkoutSessionId) {
        try {
            $ch = curl_init("https://api.paymongo.com/v1/checkout_sessions/{$checkoutSessionId}");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Basic ' . base64_encode($this->secretKey . ':')
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return $data['data']['attributes']['checkout_url'] ?? null;
            }
            
            return null;
        } catch (Exception $e) {
            error_log("Failed to get checkout URL: " . $e->getMessage());
            return null;
        }
    }
}
?>