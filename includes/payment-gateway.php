<?php
class PaymentGateway {
    
    /**
     * Process GCash payment with validation
     */
    public static function processGCash($amount, $mobile_number, $mpin) {
        // Validate mobile number (dapat 11 digits, start with 09)
        if (!preg_match('/^09\d{9}$/', $mobile_number)) {
            return [
                'success' => false,
                'message' => 'Invalid mobile number format'
            ];
        }
        
        // Validate MPIN (dapat 4 digits)
        if (!preg_match('/^\d{4}$/', $mpin)) {
            return [
                'success' => false,
                'message' => 'MPIN must be 4 digits'
            ];
        }
        
        // Simulate API call to GCash
        // Sa totoong buhay, dito mo talaga iva-validate sa GCash server
        
        // Sample validation: 1234 lang ang valid na MPIN
        if ($mpin !== '1234') {
            return [
                'success' => false,
                'message' => 'Invalid MPIN'
            ];
        }
        
        // Check if sufficient balance (sample)
        $reference = 'GCASH-' . time() . rand(1000, 9999);
        
        return [
            'success' => true,
            'reference' => $reference,
            'message' => 'Payment successful'
        ];
    }
    
    /**
     * Process PayMaya payment with validation
     */
    public static function processPayMaya($amount, $card_details) {
        $card_number = str_replace(' ', '', $card_details['card_number']);
        $expiry = $card_details['expiry'];
        $cvv = $card_details['cvv'];
        $cardholder = $card_details['cardholder'];
        
        // Validate card number (16 digits)
        if (!preg_match('/^\d{16}$/', $card_number)) {
            return [
                'success' => false,
                'message' => 'Invalid card number'
            ];
        }
        
        // Validate expiry (MM/YY)
        if (!preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/', $expiry)) {
            return [
                'success' => false,
                'message' => 'Invalid expiry date'
            ];
        }
        
        // Validate CVV (3 digits)
        if (!preg_match('/^\d{3}$/', $cvv)) {
            return [
                'success' => false,
                'message' => 'Invalid CVV'
            ];
        }
        
        // Sample validation: 4111111111111111 lang ang valid
        if ($card_number !== '4111111111111111') {
            return [
                'success' => false,
                'message' => 'Card declined'
            ];
        }
        
        $reference = 'MAYA-' . time() . rand(1000, 9999);
        
        return [
            'success' => true,
            'reference' => $reference,
            'message' => 'Payment successful'
        ];
    }
}
?>