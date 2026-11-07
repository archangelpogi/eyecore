<?php
// config/mailer.php
// Reusable email helper para sa supplier verification

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer/SMTP.php';
require_once __DIR__ . '/../PHPMailer/Exception.php';

/**
 * Send supplier verification email
 */
function sendSupplierVerificationEmail($toEmail, $contactPerson, $supplierName, $token) {
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'angelloricanmendoza27@gmail.com';
        $mail->Password   = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Supplier Portal');
        $mail->addAddress($toEmail, $contactPerson);
        
        $verifyLink = "http://eyecore.capstone001.com/suppliers/verify_email.php?token=" . urlencode($token);
        
        $mail->isHTML(true);
        $mail->Subject = "✅ Verify your Eyecore Supplier Account";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='text-align:center; margin-bottom: 30px;'>
                    <h1 style='color:#0d9488;'>Eyecore Supplier Portal</h1>
                </div>
                
                <h2 style='color:#111827;'>Welcome, {$contactPerson}!</h2>
                
                <p style='color:#4b5563; line-height: 1.6;'>
                    Thank you for registering <strong>{$supplierName}</strong> on the Eyecore Supplier Portal.
                </p>
                
                <p style='color:#4b5563; line-height: 1.6;'>
                    Please verify your email address by clicking the button below. 
                    This link will expire in <strong>24 hours</strong>.
                </p>
                
                <div style='text-align: center; margin: 40px 0;'>
                    <a href='{$verifyLink}' 
                       style='background:#0d9488; color:white; padding:14px 36px; 
                              text-decoration:none; border-radius:8px; font-weight:bold; 
                              display:inline-block; font-size: 16px;'>
                        ✉️ Verify Email Address
                    </a>
                </div>
                
                <div style='background:#f0fdfa; padding:16px; border-left:4px solid #0d9488; border-radius:6px; margin: 24px 0;'>
                    <strong style='color:#0f766e;'>What happens next?</strong>
                    <ol style='color:#4b5563; line-height: 1.8; margin: 8px 0 0 0; padding-left: 20px;'>
                        <li>Verify your email (this step)</li>
                        <li>Our team reviews your application</li>
                        <li>You'll be notified once approved</li>
                    </ol>
                </div>
                
                <p style='color:#6b7280; font-size: 13px; line-height: 1.6;'>
                    If the button doesn't work, copy and paste this link into your browser:<br>
                    <a href='{$verifyLink}' style='color:#0d9488; word-break: break-all;'>{$verifyLink}</a>
                </p>
                
                <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;'>
                
                <p style='color:#9ca3af; font-size: 12px; text-align: center;'>
                    This is an automated email from Eyecore System. Please do not reply.
                </p>
            </div>
        ";
        
        $mail->AltBody = "Verify your Eyecore Supplier account: {$verifyLink}";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Supplier verification email failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send admin notification for new supplier registration
 */
function sendAdminNewSupplierNotification($supplierName, $contactPerson, $email, $mobile, $category) {
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'angelloricanmendoza27@gmail.com';
        $mail->Password   = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore System');
        $mail->addAddress('angelloricanmendoza27@gmail.com', 'Eyecore Admin');
        
        $mail->isHTML(true);
        $mail->Subject = "🔔 New Supplier Registration: {$supplierName}";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                <h2 style='color:#0d9488;'>New Supplier Registration</h2>
                <p>A new supplier has registered and is awaiting review:</p>
                <table style='border-collapse: collapse; width: 100%; margin: 20px 0;'>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'><strong>Company:</strong></td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$supplierName}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'><strong>Contact:</strong></td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$contactPerson}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'><strong>Email:</strong></td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$email}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'><strong>Mobile:</strong></td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$mobile}</td></tr>
                    <tr><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'><strong>Category:</strong></td><td style='padding: 8px; border-bottom: 1px solid #e5e7eb;'>{$category}</td></tr>
                </table>
                <p style='color:#6b7280;'>Please review this application in the admin panel.</p>
            </div>
        ";
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Admin notification failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send document received acknowledgment email
 */
function sendDocumentsReceivedEmail($toEmail, $contactPerson, $supplierName, $docCount) {
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'angelloricanmendoza27@gmail.com';
        $mail->Password   = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Supplier Portal');
        $mail->addAddress($toEmail, $contactPerson);
        
        $mail->isHTML(true);
        $mail->Subject = "📄 Documents Received – Eyecore Supplier Portal";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color:#0d9488;'>Documents Received</h2>
                <p>Hello <b>{$contactPerson}</b>,</p>
                <p>Thank you for submitting your verification documents for <strong>{$supplierName}</strong>.</p>
                
                <div style='background:#f0fdfa; padding:16px; border-left:4px solid #0d9488; border-radius:6px; margin: 20px 0;'>
                    <strong>Received:</strong> {$docCount} document(s)<br>
                    <strong>Status:</strong> Pending review by our team
                </div>
                
                <p>Our team will review your documents within <strong>1-3 business days</strong>. 
                You'll receive another email once your account is approved.</p>
                
                <p style='color:#6b7280; font-size: 13px;'>
                    If you have any questions, please contact our support team.
                </p>
                
                <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;'>
                <p style='color:#9ca3af; font-size: 12px; text-align: center;'>
                    This is an automated email from Eyecore System.
                </p>
            </div>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Documents received email failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send supplier approved email
 */
function sendSupplierApprovedEmail($toEmail, $contactPerson, $supplierName) {
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'angelloricanmendoza27@gmail.com';
        $mail->Password   = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Supplier Portal');
        $mail->addAddress($toEmail, $contactPerson);
        
        $loginLink = "http://eyecore.capstone001.com/suppliers/supplier_login.php";
        
        $mail->isHTML(true);
        $mail->Subject = "🎉 Your Eyecore Supplier Account is Approved!";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <div style='text-align:center; margin-bottom: 30px;'>
                    <h1 style='color:#0d9488;'>Congratulations! 🎉</h1>
                </div>
                
                <h2 style='color:#111827;'>Welcome, {$contactPerson}!</h2>
                
                <p style='color:#4b5563; line-height: 1.6;'>
                    We are pleased to inform you that your supplier account for 
                    <strong>{$supplierName}</strong> has been <strong style='color:#059669;'>APPROVED</strong>.
                </p>
                
                <div style='background:#f0fdfa; padding:20px; border-left:4px solid #0d9488; border-radius:6px; margin: 24px 0;'>
                    <h4 style='color:#0f766e; margin-top:0;'>Account Details:</h4>
                    <ul style='color:#4b5563; line-height: 1.8;'>
                        <li><strong>Company:</strong> {$supplierName}</li>
                        <li><strong>Status:</strong> <span style='color:#059669; font-weight:bold;'>Active</span></li>
                        <li><strong>Access:</strong> Full Supplier Portal Access</li>
                    </ul>
                </div>
                
                <div style='background:#dcfce7; padding:15px; border-radius:8px; margin: 20px 0;'>
                    <h4 style='color:#166534; margin-top:0;'>Next Steps:</h4>
                    <ol style='color:#166534; line-height: 1.8;'>
                        <li>Log in to your supplier dashboard</li>
                        <li>Complete your company profile</li>
                        <li>Start managing orders and deliveries</li>
                    </ol>
                </div>
                
                <div style='text-align: center; margin: 40px 0;'>
                    <a href='{$loginLink}' 
                       style='background:#0d9488; color:white; padding:14px 36px; 
                              text-decoration:none; border-radius:8px; font-weight:bold; 
                              display:inline-block; font-size: 16px;'>
                        🚀 Login to Dashboard
                    </a>
                </div>
                
                <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;'>
                
                <p style='color:#9ca3af; font-size: 12px; text-align: center;'>
                    This is an automated email from Eyecore System.
                </p>
            </div>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Supplier approved email failed: " . $e->getMessage());
        return false;
    }
}

/**
 * Send supplier rejected email
 */
function sendSupplierRejectedEmail($toEmail, $contactPerson, $supplierName, $reason) {
    try {
        $mail = new PHPMailer(true);
        
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'angelloricanmendoza27@gmail.com';
        $mail->Password   = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;
        
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Supplier Portal');
        $mail->addAddress($toEmail, $contactPerson);
        
        $mail->isHTML(true);
        $mail->Subject = "Application Status Update – Eyecore Supplier Portal";
        $mail->Body = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color:#dc2626;'>Application Status Update</h2>
                
                <p>Dear <strong>{$contactPerson}</strong>,</p>
                
                <p>We regret to inform you that your supplier application for 
                <strong>{$supplierName}</strong> has been <strong style='color:#dc2626;'>REJECTED</strong>.</p>
                
                " . (!empty($reason) ? "
                <div style='background:#fef2f2; padding:20px; border-left:4px solid #dc2626; border-radius:6px; margin: 24px 0;'>
                    <h4 style='color:#991b1b; margin-top:0;'>Reason:</h4>
                    <p style='color:#991b1b; font-style: italic;'>{$reason}</p>
                </div>" : "") . "
                
                <div style='background:#f8fafc; padding:15px; border-radius:8px; margin: 20px 0;'>
                    <h4 style='color:#374151; margin-top:0;'>What you can do:</h4>
                    <ul style='color:#4b5563; line-height: 1.8;'>
                        <li>Review the reason above</li>
                        <li>Correct the issue</li>
                        <li>Contact support for assistance</li>
                    </ul>
                </div>
                
                <hr style='border: none; border-top: 1px solid #e5e7eb; margin: 30px 0;'>
                
                <p style='color:#9ca3af; font-size: 12px; text-align: center;'>
                    This is an automated email from Eyecore System.
                </p>
            </div>
        ";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Supplier rejected email failed: " . $e->getMessage());
        return false;
    }
}