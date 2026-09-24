<?php
/**
 * PayMongo Refund API Integration with Auto Retry
 * ✅ FIXED: Handle BOTH appointment AND reservation refunds
 */

require_once __DIR__ . '/../config/db.php';

class PayMongoRefund {
    private $pdo;
    private $secretKey;
    private $apiUrl;
    private $maxRetries = 5;
    private $retryDelay = 3;
    private $baseUrl;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->secretKey = "sk_test_qcZwF33CQGUk9owjBgRtGFbS";
        $this->apiUrl = "https://api.paymongo.com/v1/refunds";
        $this->baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
    }
    
    private function getValidReason($reason) {
        $validReasons = [
            'duplicate', 'fraudulent', 'requested_by_customer',
            'bank_return', 'cancelled_recurring_billing',
            'recurring_billing_stopped', 'others'
        ];
        if (in_array($reason, $validReasons)) return $reason;
        
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
            
            if ($httpCode !== 200) return null;
            
            $data = json_decode($response, true);
            $paymentIntentId = $data['data']['attributes']['payment_intent']['id'] ?? null;
            
            if ($paymentIntentId) {
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
                    if (!empty($payments)) return $payments[0]['id'] ?? null;
                }
                return $paymentIntentId;
            }
            return null;
        } catch (Exception $e) {
            error_log("Failed to get payment ID: " . $e->getMessage());
            return null;
        }
    }
    
    public function processRefundWithRetry($refundId, $paymongoCheckoutId, $amount, $reason = 'Customer requested refund') {
        $attempt = 1;
        $lastError = null;
        $result = null;
        
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
            $this->updateRefundAttempt($refundId, $attempt);
            $result = $this->processRefund($refundId, $paymentId, $amount, $reason);
            
            if ($result['success']) {
                $this->updateRefundStatus($refundId, 'completed', $result['refund_id'] ?? null, null, $amount);
                $this->updateAppointmentStatus($refundId, 'refunded');
                $this->sendRefundNotification($refundId, $amount);
                $this->sendRefundEmailToCustomer($refundId, $amount);
                
                return [
                    'success' => true,
                    'message' => "Refund processed successfully after {$attempt} attempt(s)!",
                    'refund_id' => $result['refund_id'] ?? null,
                    'amount' => $amount,
                    'attempts' => $attempt
                ];
            }
            
            $lastError = $result['message'] ?? 'Unknown error';
            $this->updateRefundStatus($refundId, 'failed', null, "Attempt {$attempt}: " . $lastError);
            
            if ($attempt < $this->maxRetries) sleep($this->retryDelay);
            $attempt++;
        }
        
        $this->updateRefundStatus(
            $refundId, 
            'failed', 
            null, 
            "All {$this->maxRetries} attempts failed. Last error: " . $lastError . ". Payment ID used: " . $paymentId
        );
        
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
     * ✅ FIXED: Handle BOTH appointment AND reservation refunds
     */
    private function processRefund($refundId, $paymentId, $amount, $reason = 'Customer requested refund') {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rr.*,
                       COALESCE(a.total_amount, r.total_amount) AS total_amount,
                       COALESCE(a.id, 0) AS appointment_id,
                       COALESCE(r.id, 0) AS reservation_id,
                       COALESCE(a.user_id, r.user_id) AS user_id
                FROM refund_requests rr
                LEFT JOIN appointments a ON rr.appointment_id = a.id AND rr.appointment_id > 0
                LEFT JOIN reservations r ON rr.reservation_id = r.id AND rr.reservation_id > 0
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
                            'appointment_id' => $refund['appointment_id'] ?? 0,
                            'reservation_id' => $refund['reservation_id'] ?? 0,
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
            
            if ($curlError) return ['success' => false, 'message' => 'CURL Error: ' . $curlError];
            
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
    
    private function updateRefundAttempt($refundId, $attempt) {
        try {
            $check = $this->pdo->query("SHOW COLUMNS FROM refund_requests LIKE 'refund_attempts'");
            if ($check->rowCount() > 0) {
                $stmt = $this->pdo->prepare("UPDATE refund_requests SET refund_attempts = ? WHERE id = ?");
                $stmt->execute([$attempt, $refundId]);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function updateRefundStatus($refundId, $status, $paymongoRefundId = null, $errorMsg = null, $amount = null) {
        try {
            $sql = "UPDATE refund_requests SET refund_status = ?";
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
                $sql .= ", refund_date = NOW(), status = 'completed'";
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
     * ✅ FIXED: Handle BOTH appointment AND reservation
     */
    private function updateAppointmentStatus($refundId, $status) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rr.id,
                       rr.appointment_id,
                       rr.reservation_id,
                       rr.amount,
                       COALESCE(a.clinic_id, r.clinic_id) AS clinic_id
                FROM refund_requests rr
                LEFT JOIN appointments a ON rr.appointment_id = a.id AND rr.appointment_id > 0
                LEFT JOIN reservations r ON rr.reservation_id = r.id AND rr.reservation_id > 0
                WHERE rr.id = ?
            ");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$refund) return false;
            
            $pdo = $this->pdo;
            $pdo->beginTransaction();
            
            $appointmentId = !empty($refund['appointment_id']) ? (int)$refund['appointment_id'] : 0;
            $reservationId = !empty($refund['reservation_id']) ? (int)$refund['reservation_id'] : 0;
            
            if ($appointmentId > 0) {
                $pdo->prepare("
                    UPDATE appointments 
                    SET status = ?, refund_status = 'completed',
                        refund_date = NOW(), updated_at = NOW()
                    WHERE id = ?
                ")->execute([$status, $appointmentId]);
                
                $pdo->prepare("
                    UPDATE payments 
                    SET payment_status = 'refunded',
                        refunded_amount = ?, updated_at = NOW()
                    WHERE appointment_id = ?
                ")->execute([$refund['amount'], $appointmentId]);
                
                $pdo->prepare("
                    UPDATE sales 
                    SET status = 'Refunded',
                        refunded_amount = ?, updated_at = NOW()
                    WHERE appointment_id = ?
                ")->execute([$refund['amount'], $appointmentId]);
            }
            
            if ($reservationId > 0) {
                $pdo->prepare("
                    UPDATE reservations 
                    SET payment_status = 'refunded',
                        refund_status = 'completed', updated_at = NOW()
                    WHERE id = ?
                ")->execute([$reservationId]);
                
                $pdo->prepare("
                    UPDATE payments 
                    SET payment_status = 'refunded',
                        refunded_amount = ?, updated_at = NOW()
                    WHERE reservation_id = ?
                ")->execute([$refund['amount'], $reservationId]);
            }
            
            $pdo->commit();
            return true;
            
        } catch (Exception $e) {
            if (isset($pdo)) $pdo->rollBack();
            error_log("Failed to update status: " . $e->getMessage());
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
                    VALUES (?, ?, ?, 'refund', 'my-reservations.php', 0, NOW())
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
                if ($paymentId) $message .= "Payment ID: {$paymentId}. ";
                $message .= "Please process manually via PayMongo dashboard.";
                
                $stmt = $this->pdo->prepare("
                    INSERT INTO notifications (user_id, title, message, type, link, is_read, created_at)
                    VALUES (?, ?, ?, 'refund_alert', 'main.php?view=reservations', 0, NOW())
                ");
                $stmt->execute([$admin['admin_id'], $title, $message]);
            }
            return true;
        } catch (Exception $e) {
            error_log("Failed to notify admin: " . $e->getMessage());
            return false;
        }
    }
    
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

    public function processRefundDirect($refundId, $paymentRef, $amount, $reason = 'requested_by_customer') {
        if (empty($paymentRef)) {
            return ['success' => false, 'message' => 'No payment reference provided'];
        }
        
        $validReason = $this->getValidReason($reason);
        $paymentId = $paymentRef;
        
        if (strpos($paymentRef, 'cs_') === 0) {
            $resolved = $this->getPaymentIdFromCheckout($paymentRef);
            if (!$resolved) {
                return [
                    'success' => false,
                    'message' => 'Could not resolve payment ID from checkout session',
                    'requires_manual' => true
                ];
            }
            $paymentId = $resolved;
        }
        
        if (strpos($paymentId, 'pay_') !== 0 && strpos($paymentId, 'pi_') !== 0) {
            return [
                'success' => false,
                'message' => 'Invalid payment reference format: ' . substr($paymentId, 0, 10) . '...'
            ];
        }
        
        $result = $this->processRefund($refundId, $paymentId, $amount, $validReason);
        
        if ($result['success']) {
            $this->updateRefundStatus($refundId, 'completed', $result['refund_id'] ?? null, null, $amount);
            $this->updateAppointmentStatus($refundId, 'refunded');
            $this->sendRefundNotification($refundId, $amount);
            $this->sendRefundEmailToCustomer($refundId, $amount);
        } else {
            $this->updateRefundStatus(
                $refundId, 'failed', null,
                $result['message'] ?? 'Unknown error'
            );
        }
        
        return $result;
    }

    /**
     * ✅ FIXED: Handle BOTH appointment AND reservation
     */
    public function retryRefund($refundId, $amount, $reason = 'requested_by_customer') {
        $stmt = $this->pdo->prepare("
            SELECT rr.*,
                   COALESCE(a.paymongo_payment_id, r.paymongo_payment_id) AS paymongo_payment_id
            FROM refund_requests rr
            LEFT JOIN appointments a ON rr.appointment_id = a.id AND rr.appointment_id > 0
            LEFT JOIN reservations r ON rr.reservation_id = r.id AND rr.reservation_id > 0
            WHERE rr.id = ?
        ");
        $stmt->execute([$refundId]);
        $refund = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$refund) return ['success' => false, 'message' => 'Refund request not found'];
        
        $paymentRef = $refund['paymongo_payment_id'] ?? null;
        if (!$paymentRef) return ['success' => false, 'message' => 'No PayMongo payment reference found'];
        
        return $this->processRefundDirect($refundId, $paymentRef, $amount, $reason);
    }

    /**
     * ✅ FIXED: Handle BOTH appointment AND reservation
     */
    private function sendRefundEmail($refundId, $amount, $customerName, $customerEmail) {
        try {
            if (empty($customerEmail) || $customerEmail === 'no-email@provided.com') {
                error_log("Refund email skipped: No valid email for customer");
                return false;
            }
            
            $stmt = $this->pdo->prepare("
                SELECT rr.*,
                       COALESCE(a.id, 0) AS appointment_id,
                       COALESCE(r.id, 0) AS reservation_id,
                       c.name as clinic_name, 
                       c.email as clinic_email
                FROM refund_requests rr
                LEFT JOIN appointments a ON rr.appointment_id = a.id AND rr.appointment_id > 0
                LEFT JOIN reservations r ON rr.reservation_id = r.id AND rr.reservation_id > 0
                LEFT JOIN clinics c ON c.id = COALESCE(a.clinic_id, r.clinic_id)
                WHERE rr.id = ?
            ");
            $stmt->execute([$refundId]);
            $refund = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$refund) return false;
            
            require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
            require_once __DIR__ . '/../PHPMailer/SMTP.php';
            require_once __DIR__ . '/../PHPMailer/Exception.php';
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com';
            $mail->SMTPAuth = true;
            $mail->Username = 'angelloricanmendoza27@gmail.com';
            $mail->Password = 'tkyv vypr pxvm pfse';
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = 587;
            $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore System');
            $mail->addAddress($customerEmail, $customerName);
            
            if (!empty($refund['clinic_email'])) {
                $mail->addBCC($refund['clinic_email'], $refund['clinic_name']);
            }
            
            $mail->isHTML(true);
            $mail->Subject = '💰 Refund Processed Successfully - Eyecore System';
            
            $formattedAmount = number_format($amount, 2);
            $clinicName = $refund['clinic_name'] ?? 'Eyecore Clinic';
            $refNo = $refund['ref_no'] ?? 'REF-' . strtoupper(uniqid());
            $orderRef = $refund['reservation_id'] > 0 ? 'RES-' . $refund['reservation_id'] : 'APT-' . $refund['appointment_id'];
            $refundDate = date('F j, Y');
            
            $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #f8fafc; padding: 30px; border-radius: 16px;'>
                <div style='text-align: center; margin-bottom: 30px;'>
                    <div style='background: linear-gradient(135deg, #0d9488, #0f766e); padding: 25px; border-radius: 12px;'>
                        <h1 style='color: #fff; margin: 0; font-size: 24px;'>💰 Refund Confirmed</h1>
                        <p style='color: rgba(255,255,255,0.8); margin: 5px 0 0;'>Your refund has been processed successfully</p>
                    </div>
                </div>
                
                <p style='font-size: 16px; color: #1e293b;'>Dear <strong>{$customerName}</strong>,</p>
                
                <p style='font-size: 15px; color: #475569; line-height: 1.6;'>
                    We are pleased to inform you that your refund has been <strong style='color: #0d9488;'>processed successfully</strong> 
                    by <strong>{$clinicName}</strong>.
                </p>
                
                <div style='background: #ffffff; border-radius: 12px; padding: 20px; margin: 20px 0; border: 1px solid #e2e8f0;'>
                    <h4 style='margin: 0 0 15px; color: #0d9488; font-size: 16px; border-bottom: 2px solid #ccfbf1; padding-bottom: 10px;'>
                        📋 Refund Details
                    </h4>
                    <table style='width: 100%; font-size: 14px;'>
                        <tr><td style='padding: 6px 0; color: #64748b;'>Clinic</td><td style='padding: 6px 0; text-align: right; font-weight: 600;'>{$clinicName}</td></tr>
                        <tr><td style='padding: 6px 0; color: #64748b;'>Order / Appointment</td><td style='padding: 6px 0; text-align: right; font-weight: 600;'>{$orderRef}</td></tr>
                        <tr><td style='padding: 6px 0; color: #64748b;'>Reference</td><td style='padding: 6px 0; text-align: right; font-weight: 600;'>{$refNo}</td></tr>
                        <tr><td style='padding: 6px 0; color: #64748b;'>Refund Date</td><td style='padding: 6px 0; text-align: right; font-weight: 600;'>{$refundDate}</td></tr>
                        <tr style='border-top: 2px solid #e2e8f0;'><td style='padding: 10px 0 0; font-weight: 700; color: #1e293b;'>Refund Amount</td><td style='padding: 10px 0 0; text-align: right; font-weight: 700; font-size: 18px; color: #0d9488;'>₱ {$formattedAmount}</td></tr>
                    </table>
                </div>
                
                <div style='background: #f0fdfa; border-left: 4px solid #0d9488; padding: 15px 18px; border-radius: 8px; margin: 20px 0;'>
                    <h4 style='margin: 0 0 8px; color: #0d9488; font-size: 14px;'>💡 Important Information</h4>
                    <ul style='margin: 0; padding-left: 18px; color: #475569; font-size: 13px; line-height: 1.8;'>
                        <li>Refund amount will reflect in your account within <strong>3-5 business days</strong></li>
                        <li>If you used a credit/debit card, the refund will be credited to your card</li>
                        <li>For GCash/PayMaya, the refund will be sent to your registered mobile number</li>
                    </ul>
                </div>
                
                <div style='border-top: 1px solid #e2e8f0; padding-top: 20px; margin-top: 20px; text-align: center; color: #94a3b8; font-size: 12px;'>
                    <p style='margin: 0;'>This is an automated confirmation from Eyecore System.</p>
                    <p style='margin: 5px 0 0;'>&copy; " . date('Y') . " Eyecore. All rights reserved.</p>
                </div>
            </div>
            ";
            
            $mail->AltBody = "Refund of ₱{$formattedAmount} processed successfully. Ref: {$refNo}. Order: {$orderRef}.";
            $mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Refund email failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * ✅ FIXED: Handle BOTH appointment AND reservation
     */
    private function sendRefundEmailToCustomer($refundId, $amount) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT rr.*,
                       COALESCE(u.email, au.email) as customer_email,
                       COALESCE(CONCAT(u.first_name, ' ', u.last_name), CONCAT(au.first_name, ' ', au.last_name)) as customer_name
                FROM refund_requests rr
                LEFT JOIN reservations r ON rr.reservation_id = r.id AND rr.reservation_id > 0
                LEFT JOIN appointments a ON rr.appointment_id = a.id AND rr.appointment_id > 0
                LEFT JOIN users u ON u.id = COALESCE(r.user_id, a.user_id)
                LEFT JOIN users au ON au.id = a.user_id
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
            
            if (empty($customerEmail) || $customerEmail === 'no-email@provided.com') {
                error_log("Refund email skipped: No valid email for customer");
                return false;
            }
            
            return $this->sendRefundEmail($refundId, $amount, $customerName, $customerEmail);
            
        } catch (Exception $e) {
            error_log("Failed to send refund email: " . $e->getMessage());
            return false;
        }
    }
}
?>