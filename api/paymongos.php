<?php
/**
 * PayMongo Refund API Integration with Auto Retry
 * ✅ FIXED: Updates sales table when refund is completed
 * ✅ ADDED: Customer information retrieval with proper fallback
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
    
    // ✅ ✅ ✅ ADD THIS: Send email to customer
    $this->sendRefundEmailToCustomer($refundId, $amount);
    
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
     * ✅ ADDED: Customer information retrieval with proper fallback
     */
    public function createRefundCheckout($refundId, $appointmentId, $amount, $reason = 'Refund request') {
        try {
            // ✅ UPDATED QUERY: Added customer info from patients and users
            $stmt = $this->pdo->prepare("
                SELECT rr.*, a.*, c.name as clinic_name,
                       -- ✅ Customer info from patients
                       p.first_name as patient_first_name,
                       p.last_name as patient_last_name,
                       p.email as patient_email,
                       p.phone as patient_phone,
                       -- ✅ Fallback from users
                       u.first_name as user_first_name,
                       u.last_name as user_last_name,
                       u.email as user_email,
                       u.contact as user_contact,
                       -- ✅ Appointment contact info
                       a.contact_number as appointment_contact
                FROM refund_requests rr
                JOIN appointments a ON rr.appointment_id = a.id
                JOIN clinics c ON a.clinic_id = c.id
                LEFT JOIN patients p ON a.patient_id = p.id
                LEFT JOIN users u ON a.user_id = u.id
                WHERE rr.id = ?
            ");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$refund) {
                return ['success' => false, 'message' => 'Refund request not found'];
            }
            
            // ✅ Get customer information
            $customerName = '';
            $customerEmail = '';
            $customerPhone = '';
            
            // Priority 1: From patients table (patient linked to appointment)
            if (!empty($refund['patient_first_name']) || !empty($refund['patient_last_name'])) {
                $customerName = trim($refund['patient_first_name'] . ' ' . $refund['patient_last_name']);
                $customerEmail = $refund['patient_email'] ?? '';
                $customerPhone = $refund['patient_phone'] ?? '';
            } 
            // Priority 2: From users table (user linked to appointment)
            elseif (!empty($refund['user_first_name']) || !empty($refund['user_last_name'])) {
                $customerName = trim($refund['user_first_name'] . ' ' . $refund['user_last_name']);
                $customerEmail = $refund['user_email'] ?? '';
                $customerPhone = $refund['user_contact'] ?? '';
            } 
            // Priority 3: Fallback - use appointment contact
            else {
                $customerName = 'Customer';
                $customerEmail = '';
                $customerPhone = $refund['appointment_contact'] ?? '';
            }
            
            // ✅ FALLBACK: If no email, try to find it using appointment contact
            if (empty($customerEmail) && !empty($refund['appointment_contact'])) {
                
                // ✅ FIRST: Find patient linked to this appointment
                $patientStmt = $this->pdo->prepare("
                    SELECT p.email, p.phone 
                    FROM patients p
                    JOIN appointments a ON a.patient_id = p.id
                    WHERE a.id = ? AND p.clinic_id = ?
                    LIMIT 1
                ");
                $patientStmt->execute([$appointmentId, $refund['clinic_id']]);
                $patientResult = $patientStmt->fetch(PDO::FETCH_ASSOC);
                
                if ($patientResult && !empty($patientResult['email'])) {
                    $customerEmail = $patientResult['email'];
                    if (empty($customerPhone)) {
                        $customerPhone = $patientResult['phone'] ?? '';
                    }
                } else {
                    // ✅ SECOND: Find user linked to this appointment
                    $userStmt = $this->pdo->prepare("
                        SELECT u.email, u.contact 
                        FROM users u
                        JOIN appointments a ON a.user_id = u.id
                        WHERE a.id = ?
                        LIMIT 1
                    ");
                    $userStmt->execute([$appointmentId]);
                    $userResult = $userStmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($userResult && !empty($userResult['email'])) {
                        $customerEmail = $userResult['email'];
                        if (empty($customerPhone)) {
                            $customerPhone = $userResult['contact'] ?? '';
                        }
                    } else {
                        // ✅ THIRD: Search by contact number (same clinic only)
                        $contactStmt = $this->pdo->prepare("
                            SELECT email, phone FROM patients 
                            WHERE phone = ? AND clinic_id = ? 
                            LIMIT 1
                        ");
                        $contactStmt->execute([$refund['appointment_contact'], $refund['clinic_id']]);
                        $contactResult = $contactStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($contactResult && !empty($contactResult['email'])) {
                            $customerEmail = $contactResult['email'];
                            if (empty($customerPhone)) {
                                $customerPhone = $contactResult['phone'] ?? '';
                            }
                        } else {
                            // ✅ FOURTH: Search in users table (same clinic only)
                            $userContactStmt = $this->pdo->prepare("
                                SELECT email, contact FROM users 
                                WHERE contact = ? AND clinic_id = ?
                                LIMIT 1
                            ");
                            $userContactStmt->execute([$refund['appointment_contact'], $refund['clinic_id']]);
                            $userContactResult = $userContactStmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($userContactResult && !empty($userContactResult['email'])) {
                                $customerEmail = $userContactResult['email'];
                                if (empty($customerPhone)) {
                                    $customerPhone = $userContactResult['contact'] ?? '';
                                }
                            }
                        }
                    }
                }
            }
            
            // ✅ LAST RESORT: Default values if still empty
            if (empty($customerName)) {
                $customerName = 'Customer';
            }
            if (empty($customerEmail)) {
                $customerEmail = 'no-email@provided.com';
            }
            if (empty($customerPhone)) {
                $customerPhone = 'N/A';
            }
            
            // Generate reference number
            $refNo = 'REF-' . strtoupper(uniqid());
            
            // ✅ Success and cancel URLs
            $successUrl = $this->baseUrl . "/views/refund-redirect.php?ref_no={$refNo}&appointment_id={$appointmentId}&type=refund&status=success";
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
                        // ✅ Customer billing info
                        'billing' => [
                            'name' => $customerName,
                            'email' => $customerEmail,
                            'phone' => $customerPhone
                        ],
                        'metadata' => [
                            'refund_id' => $refundId,
                            'appointment_id' => $appointmentId,
                            'type' => 'refund',
                            'customer_name' => $customerName,
                            'customer_email' => $customerEmail,
                            'customer_phone' => $customerPhone
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
                    'ref_no' => $refNo,
                    // ✅ Return customer info
                    'customer' => [
                        'name' => $customerName,
                        'email' => $customerEmail,
                        'phone' => $customerPhone
                    ]
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
    
    /**
     * ✅ UPDATED: Update appointment status AND sales table
     */
    private function updateAppointmentStatus($refundId, $status) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rr.appointment_id, rr.amount, a.clinic_id, a.patient_id, a.total_amount
                FROM refund_requests rr
                JOIN appointments a ON rr.appointment_id = a.id
                WHERE rr.id = ?
            ");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$refund) {
                return false;
            }
            
            $pdo = $this->pdo;
            
            // ✅ Start transaction
            $pdo->beginTransaction();
            
            // ✅ 1. Update appointment
            $stmt = $pdo->prepare("
                UPDATE appointments 
                SET status = ?, 
                    refund_status = 'completed',
                    refund_date = NOW(),
                    updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $refund['appointment_id']]);
            
            // ✅ 2. Update payment
            $stmt = $pdo->prepare("
                UPDATE payments 
                SET payment_status = 'refunded',
                    refunded_amount = ?,
                    updated_at = NOW()
                WHERE appointment_id = ?
            ");
            $stmt->execute([$refund['amount'], $refund['appointment_id']]);
            
            // ✅ 3. UPDATE SALES TABLE - DIRECT UPDATE
            $stmt = $pdo->prepare("
                UPDATE sales 
                SET status = 'Refunded',
                    refunded_amount = ?,
                    updated_at = NOW()
                WHERE appointment_id = ?
            ");
            $stmt->execute([$refund['amount'], $refund['appointment_id']]);
            
            // ✅ 4. If no sales record exists or rowCount is 0, create one
            if ($stmt->rowCount() === 0) {
                // Check if sales exists
                $check = $pdo->prepare("SELECT id FROM sales WHERE appointment_id = ?");
                $check->execute([$refund['appointment_id']]);
                if ($check->rowCount() === 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO sales (
                            clinic_id, sale_date, patient_id, appointment_id, items,
                            total_amount, subtotal, discount, discount_type, discount_percentage,
                            vat_percentage, vat_amount, amount_paid, forfeited_amount, refunded_amount,
                            status, created_at, updated_at
                        )
                        SELECT 
                            clinic_id, appointment_date, patient_id, id, items,
                            total_amount, subtotal, discount_amount, discount_type, discount_percentage,
                            vat_percentage, vat_amount, amount_paid, forfeited_amount, ?,
                            'Refunded', created_at, NOW()
                        FROM appointments
                        WHERE id = ?
                    ");
                    $stmt->execute([$refund['amount'], $refund['appointment_id']]);
                } else {
                    // Update existing sales again
                    $stmt = $pdo->prepare("
                        UPDATE sales 
                        SET status = 'Refunded',
                            refunded_amount = ?,
                            updated_at = NOW()
                        WHERE appointment_id = ?
                    ");
                    $stmt->execute([$refund['amount'], $refund['appointment_id']]);
                }
            }
            
            // ✅ 5. Log audit
            $stmt = $pdo->prepare("
                INSERT INTO audit_logs (user_id, clinic_id, action, table_name, record_id, new_values, created_at)
                VALUES (
                    (SELECT COALESCE(user_id, 0) FROM appointments WHERE id = ?),
                    ?,
                    'REFUND_COMPLETED',
                    'sales',
                    ?,
                    ?,
                    NOW()
                )
            ");
            $stmt->execute([
                $refund['appointment_id'],
                $refund['clinic_id'],
                $refund['appointment_id'],
                json_encode([
                    'status' => 'Refunded',
                    'refunded_amount' => $refund['amount'],
                    'appointment_id' => $refund['appointment_id'],
                    'updated_at' => date('Y-m-d H:i:s')
                ])
            ]);
            
            $pdo->commit();
            
            return true;
            
        } catch (Exception $e) {
            if (isset($pdo)) $pdo->rollBack();
            error_log("Failed to update appointment status: " . $e->getMessage());
            return false;
        }
    }
    
    private function sendRefundNotification($refundId, $amount) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rr.*, u.id as user_id, u.first_name, u.last_name
                FROM refund_requests rr
                JOIN users u ON rr.user_id = u.id
                WHERE rr.id = ?
            ");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($refund) {
                $fullname = trim(($refund['first_name'] ?? '') . ' ' . ($refund['last_name'] ?? ''));
                if (empty($fullname)) $fullname = 'Customer';
                
                $title = "✅ Refund Processed Successfully";
                $message = "Hello " . $fullname . ",\n\n"
                    . "Your refund of ₱" . number_format($amount, 2) . " has been processed successfully.\n"
                    . "Please allow 3-5 business days for the amount to reflect in your account.\n\n"
                    . "Thank you for your patience.";
                
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
    
    /**
     * Notify clinic admin for manual refund
     */
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
                    VALUES (?, ?, ?, 'refund_alert', '/views/main.php?view=appointments&tab=refunds', 0, NOW())
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

    /**
 * Send refund confirmation email to customer
 */
private function sendRefundEmail($refundId, $amount, $customerName, $customerEmail) {
    try {
        // Skip if no email
        if (empty($customerEmail) || $customerEmail === 'no-email@provided.com') {
            error_log("Refund email skipped: No valid email for customer");
            return false;
        }
        
        // Get refund and appointment details
        $stmt = $this->pdo->prepare("
            SELECT rr.*, a.*, c.name as clinic_name, c.email as clinic_email
            FROM refund_requests rr
            JOIN appointments a ON rr.appointment_id = a.id
            JOIN clinics c ON a.clinic_id = c.id
            WHERE rr.id = ?
        ");
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$refund) {
            return false;
        }
        
        // ✅ Include PHPMailer
        require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/../PHPMailer/SMTP.php';
        require_once __DIR__ . '/../PHPMailer/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore System');
        $mail->addAddress($customerEmail, $customerName);
        
        // Optional: BCC to clinic admin
        if (!empty($refund['clinic_email'])) {
            $mail->addBCC($refund['clinic_email'], $refund['clinic_name']);
        }
        
        $mail->isHTML(true);
        $mail->Subject = '💰 Refund Processed Successfully - Eyecore System';
        
        $formattedAmount = number_format($amount, 2);
        $clinicName = $refund['clinic_name'] ?? 'Eyecore Clinic';
        $refNo = $refund['ref_no'] ?? 'REF-' . strtoupper(uniqid());
        $appointmentId = $refund['appointment_id'] ?? 'N/A';
        $refundDate = date('F j, Y');
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8fafc; padding: 30px; border-radius: 16px;'>
            
            <!-- Header -->
            <div style='text-align: center; margin-bottom: 30px;'>
                <div style='background: linear-gradient(135deg, #0d9488, #0f766e); padding: 25px; border-radius: 12px;'>
                    <h1 style='color: #fff; margin: 0; font-size: 24px;'>💰 Refund Confirmed</h1>
                    <p style='color: rgba(255,255,255,0.8); margin: 5px 0 0;'>Your refund has been processed successfully</p>
                </div>
            </div>
            
            <!-- Greeting -->
            <p style='font-size: 16px; color: #1e293b;'>Dear <strong>{$customerName}</strong>,</p>
            
            <p style='font-size: 15px; color: #475569; line-height: 1.6;'>
                We are pleased to inform you that your refund has been <strong style='color: #0d9488;'>processed successfully</strong> 
                by <strong>{$clinicName}</strong>. The refund amount has been credited back to your original payment method.
            </p>
            
            <!-- Refund Details Card -->
            <div style='background: #ffffff; border-radius: 12px; padding: 20px; margin: 20px 0; border: 1px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.05);'>
                <h4 style='margin: 0 0 15px; color: #0d9488; font-size: 16px; border-bottom: 2px solid #ccfbf1; padding-bottom: 10px;'>
                    📋 Refund Details
                </h4>
                
                <table style='width: 100%; font-size: 14px;'>
                    <tr>
                        <td style='padding: 6px 0; color: #64748b;'>Clinic</td>
                        <td style='padding: 6px 0; text-align: right; font-weight: 600;'>{$clinicName}</td>
                    </tr>
                    <tr>
                        <td style='padding: 6px 0; color: #64748b;'>Appointment</td>
                        <td style='padding: 6px 0; text-align: right; font-weight: 600;'>#{$appointmentId}</td>
                    </tr>
                    <tr>
                        <td style='padding: 6px 0; color: #64748b;'>Reference Number</td>
                        <td style='padding: 6px 0; text-align: right; font-weight: 600;'>{$refNo}</td>
                    </tr>
                    <tr>
                        <td style='padding: 6px 0; color: #64748b;'>Refund Date</td>
                        <td style='padding: 6px 0; text-align: right; font-weight: 600;'>{$refundDate}</td>
                    </tr>
                    <tr style='border-top: 2px solid #e2e8f0;'>
                        <td style='padding: 10px 0 0; font-weight: 700; color: #1e293b;'>Refund Amount</td>
                        <td style='padding: 10px 0 0; text-align: right; font-weight: 700; font-size: 18px; color: #0d9488;'>
                            ₱ {$formattedAmount}
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Important Info -->
            <div style='background: #f0fdfa; border-left: 4px solid #0d9488; padding: 15px 18px; border-radius: 8px; margin: 20px 0;'>
                <h4 style='margin: 0 0 8px; color: #0d9488; font-size: 14px;'>💡 Important Information</h4>
                <ul style='margin: 0; padding-left: 18px; color: #475569; font-size: 13px; line-height: 1.8;'>
                    <li>Refund amount will reflect in your account within <strong>3-5 business days</strong></li>
                    <li>If you used a credit/debit card, the refund will be credited to your card</li>
                    <li>For GCash/PayMaya, the refund will be sent to your registered mobile number</li>
                    <li>Contact the clinic directly if you have any questions about this refund</li>
                </ul>
            </div>
            
            <!-- Support -->
            <div style='background: #f8fafc; padding: 15px; border-radius: 8px; margin: 20px 0; text-align: center; border: 1px solid #e2e8f0;'>
                <p style='margin: 0; color: #64748b; font-size: 13px;'>
                    <strong>Need assistance?</strong> Contact the clinic directly or our support team.<br>
                    <span style='color: #0d9488;'>support@eyecore.com</span>
                </p>
            </div>
            
            <!-- Footer -->
            <div style='border-top: 1px solid #e2e8f0; padding-top: 20px; margin-top: 20px; text-align: center; color: #94a3b8; font-size: 12px;'>
                <p style='margin: 0;'>This is an automated confirmation from Eyecore System.</p>
                <p style='margin: 5px 0 0;'>Please do not reply to this email.</p>
                <p style='margin: 5px 0 0;'>&copy; " . date('Y') . " Eyecore. All rights reserved.</p>
            </div>
            
        </div>
        ";
        
        $mail->AltBody = "Refund Confirmation\n\n"
            . "Dear {$customerName},\n\n"
            . "Your refund of ₱{$formattedAmount} for appointment #{$appointmentId} has been processed successfully.\n"
            . "Clinic: {$clinicName}\n"
            . "Reference: {$refNo}\n"
            . "Date: {$refundDate}\n\n"
            . "The refund will reflect in your account within 3-5 business days.\n\n"
            . "Thank you for choosing Eyecore.\n"
            . "This is an automated message, please do not reply.";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Refund email failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send refund email to customer (wrapper function)
 */
private function sendRefundEmailToCustomer($refundId, $amount) {
    try {
        // Get customer info
        $stmt = $this->pdo->prepare("
            SELECT rr.*, 
                   COALESCE(p.email, u.email) as customer_email,
                   COALESCE(CONCAT(p.first_name, ' ', p.last_name), CONCAT(u.first_name, ' ', u.last_name)) as customer_name
            FROM refund_requests rr
            JOIN appointments a ON rr.appointment_id = a.id
            LEFT JOIN patients p ON a.patient_id = p.id
            LEFT JOIN users u ON a.user_id = u.id
            WHERE rr.id = ?
        ");
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$refund) {
            error_log("Refund email: No refund found for ID {$refundId}");
            return false;
        }
        
        $customerEmail = $refund['customer_email'] ?? '';
        $customerName = $refund['customer_name'] ?? 'Customer';
        
        // Skip if no email
        if (empty($customerEmail) || $customerEmail === 'no-email@provided.com') {
            error_log("Refund email skipped: No valid email for customer");
            return false;
        }
        
        // Send email
        return $this->sendRefundEmail($refundId, $amount, $customerName, $customerEmail);
        
    } catch (Exception $e) {
        error_log("Failed to send refund email: " . $e->getMessage());
        return false;
    }
}
}
?>