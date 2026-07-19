// assets/js/auth.js

class AuthSecurity {
    constructor() {
        this.init();
    }
    
    init() {
        this.setupCSRF();
        this.setupActivityMonitor();
        this.preventFormResubmission();
        this.disableRightClick();
    }
    
    // Setup CSRF token for AJAX requests
    setupCSRF() {
        const token = document.querySelector('meta[name="csrf-token"]')?.content;
        if (token) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': token
                }
            });
        }
    }
    
    // Monitor user activity
    setupActivityMonitor() {
        let timer;
        const timeout = 25 * 60 * 1000; // 25 minutes
        
        const resetTimer = () => {
            clearTimeout(timer);
            timer = setTimeout(() => {
                this.showSessionWarning();
            }, timeout);
        };
        
        // Events that reset timer
        ['mousemove', 'keypress', 'click', 'scroll'].forEach(event => {
            document.addEventListener(event, resetTimer);
        });
        
        resetTimer();
    }
    
    // Show session warning
    showSessionWarning() {
        Swal.fire({
            title: 'Session About to Expire',
            text: 'Your session will expire in 5 minutes. Continue working?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Continue',
            cancelButtonText: 'Logout',
            timer: 300000, // 5 minutes
            timerProgressBar: true,
            allowOutsideClick: false
        }).then((result) => {
            if (result.isConfirmed) {
                // Extend session via AJAX
                $.post('/api/auth/verify-session.php', {
                    action: 'extend'
                }).then(() => {
                    Swal.fire('Session Extended', 'Your session has been extended.', 'success');
                });
            } else {
                window.location.href = '/auth/logout.php';
            }
        });
    }
    
    // Prevent form resubmission
    preventFormResubmission() {
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    }
    
    // Disable right-click on sensitive areas
    disableRightClick() {
        document.addEventListener('contextmenu', (e) => {
            if (e.target.closest('.no-right-click')) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    title: 'Restricted Action',
                    text: 'Right-click is disabled on this element.'
                });
            }
        });
    }
    
    // Validate password strength
    validatePassword(password) {
        const minLength = 12;
        const hasUpperCase = /[A-Z]/.test(password);
        const hasLowerCase = /[a-z]/.test(password);
        const hasNumbers = /\d/.test(password);
        const hasSpecialChar = /[!@#$%^&*()_+\-=\[\]{};':"\\|,.<>\/?]/.test(password);
        
        return {
            isValid: password.length >= minLength && hasUpperCase && hasLowerCase && hasNumbers && hasSpecialChar,
            issues: [
                password.length < minLength ? `Minimum ${minLength} characters` : null,
                !hasUpperCase ? 'At least one uppercase letter' : null,
                !hasLowerCase ? 'At least one lowercase letter' : null,
                !hasNumbers ? 'At least one number' : null,
                !hasSpecialChar ? 'At least one special character' : null
            ].filter(Boolean)
        };
    }
    
    // Show password strength meter
    showPasswordStrength(password) {
        const strength = this.calculatePasswordStrength(password);
        const meter = document.getElementById('password-strength-meter');
        const text = document.getElementById('password-strength-text');
        
        if (meter && text) {
            meter.value = strength.score;
            meter.className = `strength-${strength.level}`;
            text.textContent = strength.text;
            text.className = `text-${strength.color}`;
        }
    }
    
    // Calculate password strength
    calculatePasswordStrength(password) {
        let score = 0;
        
        // Length
        if (password.length >= 12) score += 25;
        else if (password.length >= 8) score += 15;
        else if (password.length >= 6) score += 5;
        
        // Complexity
        if (/[A-Z]/.test(password)) score += 20;
        if (/[a-z]/.test(password)) score += 20;
        if (/\d/.test(password)) score += 20;
        if (/[^A-Za-z0-9]/.test(password)) score += 20;
        
        // Common patterns (negative scoring)
        if (/(.)\1{2,}/.test(password)) score -= 10; // Repeated characters
        if (/12345|qwerty|password/i.test(password)) score = 0; // Common patterns
        
        // Determine level
        let level, text, color;
        if (score >= 80) {
            level = 4; text = 'Very Strong'; color = 'success';
        } else if (score >= 60) {
            level = 3; text = 'Strong'; color = 'info';
        } else if (score >= 40) {
            level = 2; text = 'Moderate'; color = 'warning';
        } else if (score >= 20) {
            level = 1; text = 'Weak'; color = 'danger';
        } else {
            level = 0; text = 'Very Weak'; color = 'danger';
        }
        
        return { score, level, text, color };
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.authSecurity = new AuthSecurity();
    
    // Auto-hide alerts after 5 seconds
    setTimeout(() => {
        document.querySelectorAll('.alert:not(.alert-permanent)').forEach(alert => {
            const bsAlert = new bootstrap.Alert(alert);
            bsAlert.close();
        });
    }, 5000);
});