<?php
// C:\xampp\htdocs\eyecore\suppliers\supplier_login.php
session_start();
require_once __DIR__ . '/../config/db.php';

// Use the global $pdo connection from db.php
global $pdo;

$error = '';
$success = '';
$showForgotPassword = isset($_GET['forgot']);
$showRegister = isset($_GET['register']);

// Handle Login
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    
    try {
        // Check if user exists and is a supplier
        $query = "SELECT u.*, s.supplier_name, s.id as supplier_id, s.contact_person 
                  FROM users u 
                  LEFT JOIN suppliers s ON u.id = s.created_by 
                  WHERE u.email = :email AND u.role = 'Supplier' AND u.status = 'Active'";
        
        $stmt = $pdo->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $user = $stmt->fetch();
            
            // Verify password
            if (password_verify($password, $user['password'])) {
                $_SESSION['supplier_id'] = $user['supplier_id'];
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['supplier_name'] = $user['supplier_name'];
                $_SESSION['contact_person'] = $user['contact_person'];
                $_SESSION['email'] = $user['email'];
                $_SESSION['role'] = $user['role'];
                
                // Update last login
                $updateQuery = "UPDATE users SET last_login = NOW() WHERE id = :id";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateStmt->bindParam(':id', $user['id']);
                $updateStmt->execute();
                
                header("Location: supplier_dashboard.php");
                exit();
            } else {
                $error = "Invalid password!";
            }
        } else {
            $error = "No active supplier account found with this email!";
        }
    } catch (PDOException $e) {
        $error = "Login error: " . $e->getMessage();
    }
}

// Handle Registration
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    // Get and sanitize inputs
    $supplier_name = trim($_POST['supplier_name'] ?? '');
    $contact_person = trim($_POST['contact_person'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $mobile = trim($_POST['mobile'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $state = trim($_POST['state'] ?? '');
    $country = trim($_POST['country'] ?? 'Philippines');
    $postal_code = trim($_POST['postal_code'] ?? '');
    $tax_id = trim($_POST['tax_id'] ?? '');
    $payment_terms = trim($_POST['payment_terms'] ?? 'Net 30');
    $category = trim($_POST['category'] ?? 'General');
    $website = trim($_POST['website'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validation
    $errors = [];
    
    if (empty($supplier_name)) $errors[] = "Company name is required";
    if (empty($contact_person)) $errors[] = "Contact person is required";
    if (empty($email)) $errors[] = "Email is required";
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email format";
    if (empty($mobile)) $errors[] = "Mobile number is required";
    if (empty($address)) $errors[] = "Address is required";
    if (empty($city)) $errors[] = "City is required";
    if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters";
    if ($password !== $confirm_password) $errors[] = "Passwords do not match";
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // Check if email already exists
            $checkQuery = "SELECT id FROM users WHERE email = :email";
            $checkStmt = $pdo->prepare($checkQuery);
            $checkStmt->bindParam(':email', $email);
            $checkStmt->execute();
            
            if ($checkStmt->rowCount() > 0) {
                throw new Exception("Email already registered!");
            }
            
            // Generate codes
            $user_code = 'USR' . date('Ymd') . rand(1000, 9999);
            $supplier_code = 'SUP' . date('Ymd') . rand(100, 999);
            
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert into users table
            $userQuery = "INSERT INTO users (user_code, first_name, last_name, email, password, role, status, is_verified, created_at) 
                         VALUES (:user_code, :first_name, :last_name, :email, :password, 'Supplier', 'Active', 1, NOW())";
            
            $userStmt = $pdo->prepare($userQuery);
            $userStmt->bindParam(':user_code', $user_code);
            $userStmt->bindParam(':first_name', $contact_person);
            $userStmt->bindParam(':last_name', $supplier_name);
            $userStmt->bindParam(':email', $email);
            $userStmt->bindParam(':password', $hashed_password);
            $userStmt->execute();
            
            $user_id = $pdo->lastInsertId();
            
            // Insert into suppliers table
            $supplierQuery = "INSERT INTO suppliers (
                supplier_code, supplier_name, contact_person, email, phone, mobile, 
                address, city, state, country, postal_code, tax_id, payment_terms, 
                category, website, status, created_by, clinic_id, created_at
            ) VALUES (
                :supplier_code, :supplier_name, :contact_person, :email, :phone, :mobile,
                :address, :city, :state, :country, :postal_code, :tax_id, :payment_terms,
                :category, :website, 'Active', :created_by, 1, NOW()
            )";
            
            $supplierStmt = $pdo->prepare($supplierQuery);
            $supplierStmt->bindParam(':supplier_code', $supplier_code);
            $supplierStmt->bindParam(':supplier_name', $supplier_name);
            $supplierStmt->bindParam(':contact_person', $contact_person);
            $supplierStmt->bindParam(':email', $email);
            $supplierStmt->bindParam(':phone', $phone);
            $supplierStmt->bindParam(':mobile', $mobile);
            $supplierStmt->bindParam(':address', $address);
            $supplierStmt->bindParam(':city', $city);
            $supplierStmt->bindParam(':state', $state);
            $supplierStmt->bindParam(':country', $country);
            $supplierStmt->bindParam(':postal_code', $postal_code);
            $supplierStmt->bindParam(':tax_id', $tax_id);
            $supplierStmt->bindParam(':payment_terms', $payment_terms);
            $supplierStmt->bindParam(':category', $category);
            $supplierStmt->bindParam(':website', $website);
            $supplierStmt->bindParam(':created_by', $user_id);
            $supplierStmt->execute();
            
            $supplier_id = $pdo->lastInsertId();
            
            // Add primary contact
            $contactQuery = "INSERT INTO supplier_contacts (
                supplier_id, contact_name, position, email, phone, mobile, is_primary
            ) VALUES (
                :supplier_id, :contact_name, :position, :email, :phone, :mobile, 1
            )";
            
            $contactStmt = $pdo->prepare($contactQuery);
            $contactStmt->bindParam(':supplier_id', $supplier_id);
            $contactStmt->bindParam(':contact_name', $contact_person);
            $position = 'Primary Contact';
            $contactStmt->bindParam(':position', $position);
            $contactStmt->bindParam(':email', $email);
            $contactStmt->bindParam(':phone', $phone);
            $contactStmt->bindParam(':mobile', $mobile);
            $contactStmt->execute();
            
            $pdo->commit();
            
            $_SESSION['swal'] = [
                'icon' => 'success',
                'title' => 'Registration Successful!',
                'text' => 'Your supplier account has been created. You can now login.'
            ];
            
            header("Location: supplier_login.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = "Registration failed: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}

// Handle Forgot Password
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['forgot_password'])) {
    $reset_email = trim($_POST['reset_email'] ?? '');
    
    if (!empty($reset_email) && filter_var($reset_email, FILTER_VALIDATE_EMAIL)) {
        try {
            $query = "SELECT id FROM users WHERE email = :email AND role = 'Supplier'";
            $stmt = $pdo->prepare($query);
            $stmt->bindParam(':email', $reset_email);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                // Generate reset token
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                $updateQuery = "UPDATE users SET reset_token = :token, reset_expires = :expires WHERE email = :email";
                $updateStmt = $pdo->prepare($updateQuery);
                $updateStmt->bindParam(':token', $token);
                $updateStmt->bindParam(':expires', $expires);
                $updateStmt->bindParam(':email', $reset_email);
                $updateStmt->execute();
                
                // In production, send email here
                $success = "Password reset link has been sent to your email!";
            } else {
                $error = "No supplier account found with this email!";
            }
        } catch (PDOException $e) {
            $error = "Error processing request";
        }
    } else {
        $error = "Please enter a valid email address";
    }
}

// Get supplier categories for dropdown
$categories = [];
try {
    $categoryQuery = "SELECT category_name FROM supplier_categories ORDER BY category_name";
    $categoryStmt = $pdo->prepare($categoryQuery);
    $categoryStmt->execute();
    $categories = $categoryStmt->fetchAll();
} catch (PDOException $e) {
    // Table might not exist yet, use default categories
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eyecore - Supplier Portal</title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --teal-50: #f0fdfa;
            --teal-100: #ccfbf1;
            --teal-600: #0d9488;
            --teal-700: #0f766e;
            --blue-50: #eff6ff;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-900: #111827;
        }
        body {
            background: linear-gradient(135deg, var(--teal-50), var(--blue-50));
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        .brand-logo {
            background-color: var(--teal-600);
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }
        .auth-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .input-group-icon {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            z-index: 10;
        }
        .password-toggle {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--gray-400);
            cursor: pointer;
            z-index: 10;
        }
        .btn-teal {
            background-color: var(--teal-600);
            color: white;
            border: none;
            padding: 10px 20px;
        }
        .btn-teal:hover {
            background-color: var(--teal-700);
            color: white;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--teal-600);
            box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.25);
        }
        .progress {
            height: 4px;
            border-radius: 2px;
        }
        .progress-bar {
            background-color: var(--teal-600);
        }
        .form-section {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .form-section h5 {
            color: var(--gray-700);
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        .password-strength {
            height: 4px;
            margin-top: 8px;
            border-radius: 2px;
            transition: all 0.3s ease;
        }
        .password-strength.weak { background-color: #dc3545; width: 33.33%; }
        .password-strength.medium { background-color: #ffc107; width: 66.66%; }
        .password-strength.strong { background-color: #28a745; width: 100%; }
        .text-teal-600 {
            color: var(--teal-600);
        }
    </style>
</head>
<body>
    <div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center p-4">
        <div class="w-100" style="max-width: <?= $showRegister ? '900px' : '400px'; ?>;">
            <!-- Logo & Branding -->
            <div class="text-center mb-4">
                <div class="brand-logo">
                    <i class="fas fa-eye text-white fa-2x"></i>
                </div>
                <h1 class="h3 fw-bold text-gray-900 mt-3 mb-1">Eyecore Supplier Portal</h1>
                <p class="text-gray-600">Supply Chain Management System</p>
            </div>

            <!-- Auth Card -->
            <div class="auth-card p-4 p-md-5">
                <?php if (!$showRegister): ?>
                    <!-- Login Form -->
                    <h2 class="h4 fw-bold text-gray-900 mb-2">Supplier Login</h2>
                    <p class="text-gray-600 mb-4">Sign in to manage your supplies</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <?= $success ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="">
                        <input type="hidden" name="login" value="1">
                        
                        <div class="mb-4">
                            <label for="email" class="form-label text-gray-700">Email Address</label>
                            <div class="position-relative">
                                <i class="fas fa-envelope input-group-icon"></i>
                                <input type="email" id="email" name="email" class="form-control ps-5" 
                                       placeholder="supplier@company.com" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="password" class="form-label text-gray-700">Password</label>
                            <div class="position-relative">
                                <i class="fas fa-lock input-group-icon"></i>
                                <input type="password" id="password" name="password" class="form-control ps-5" 
                                       placeholder="Enter your password" required>
                                <button type="button" class="password-toggle" onclick="togglePassword('password')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                <label class="form-check-label text-gray-700" for="remember">Remember me</label>
                            </div>
                            <a href="?forgot=1" class="text-decoration-none text-teal-600">Forgot password?</a>
                        </div>

                        <button type="submit" class="btn btn-teal w-100 py-2 mb-3">
                            <i class="fas fa-sign-in-alt me-2"></i>Sign In
                        </button>
                        
                        <p class="text-center text-gray-600 mb-0">
                            New supplier? <a href="?register=1" class="text-teal-600 text-decoration-none">Register here</a>
                        </p>
                    </form>

                <?php else: ?>
                    <!-- Registration Form -->
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <h2 class="h4 fw-bold text-gray-900 mb-0">Supplier Registration</h2>
                        <a href="supplier_login.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-2"></i>Back to Login
                        </a>
                    </div>
                    
                    <!-- Progress Bar -->
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-gray-600">Account Information</small>
                            <small class="text-gray-600">Company Details</small>
                            <small class="text-gray-600">Contact Information</small>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" style="width: 33.33%" id="formProgress"></div>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" id="registerForm">
                        <input type="hidden" name="register" value="1">
                        
                        <!-- Step 1: Account Information -->
                        <div class="form-step" id="step1">
                            <div class="form-section">
                                <h5><i class="fas fa-user-circle me-2 text-teal-600"></i>Login Credentials</h5>
                                
                                <div class="mb-3">
                                    <label for="reg_email" class="form-label text-gray-700">Email Address <span class="text-danger">*</span></label>
                                    <input type="email" class="form-control" id="reg_email" name="email" 
                                           placeholder="supplier@company.com" required>
                                    <small class="text-muted">This will be your username for login</small>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="reg_password" class="form-label text-gray-700">Password <span class="text-danger">*</span></label>
                                        <div class="position-relative">
                                            <input type="password" class="form-control" id="reg_password" name="password" 
                                                   placeholder="Create password" required onkeyup="checkPasswordStrength()">
                                            <button type="button" class="password-toggle" onclick="togglePassword('reg_password')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <div class="password-strength" id="passwordStrength"></div>
                                        <small class="text-muted">Min. 8 characters with letters and numbers</small>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label text-gray-700">Confirm Password <span class="text-danger">*</span></label>
                                        <div class="position-relative">
                                            <input type="password" class="form-control" id="confirm_password" 
                                                   name="confirm_password" placeholder="Confirm password" required>
                                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="button" class="btn btn-teal" onclick="nextStep(2)">
                                    Next Step <i class="fas fa-arrow-right ms-2"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 2: Company Details -->
                        <div class="form-step" id="step2" style="display: none;">
                            <div class="form-section">
                                <h5><i class="fas fa-building me-2 text-teal-600"></i>Company Information</h5>
                                
                                <div class="mb-3">
                                    <label for="supplier_name" class="form-label text-gray-700">Company Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="supplier_name" name="supplier_name" 
                                           placeholder="Enter company name" required>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="contact_person" class="form-label text-gray-700">Contact Person <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="contact_person" name="contact_person" 
                                               placeholder="Full name" required>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="tax_id" class="form-label text-gray-700">Tax ID / VAT Number</label>
                                        <input type="text" class="form-control" id="tax_id" name="tax_id" 
                                               placeholder="Enter tax ID">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="website" class="form-label text-gray-700">Website</label>
                                    <input type="url" class="form-control" id="website" name="website" 
                                           placeholder="https://www.company.com">
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="category" class="form-label text-gray-700">Supplier Category <span class="text-danger">*</span></label>
                                        <select class="form-select" id="category" name="category" required>
                                            <option value="">Select category</option>
                                            <?php 
                                            $default_categories = ['General', 'Medical Equipment', 'Optical Frames', 'Lenses', 'Contact Lenses', 'Cleaning Supplies', 'Office Supplies'];
                                            if (!empty($categories)) {
                                                foreach ($categories as $cat): ?>
                                                    <option value="<?= htmlspecialchars($cat['category_name']) ?>">
                                                        <?= htmlspecialchars($cat['category_name']) ?>
                                                    </option>
                                                <?php endforeach; 
                                            } else {
                                                foreach ($default_categories as $cat): ?>
                                                    <option value="<?= $cat ?>"><?= $cat ?></option>
                                                <?php endforeach;
                                            }
                                            ?>
                                        </select>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="payment_terms" class="form-label text-gray-700">Payment Terms</label>
                                        <select class="form-select" id="payment_terms" name="payment_terms">
                                            <option value="Net 30">Net 30</option>
                                            <option value="Net 15">Net 15</option>
                                            <option value="Net 45">Net 45</option>
                                            <option value="Net 60">Net 60</option>
                                            <option value="Due on Receipt">Due on Receipt</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" onclick="prevStep(1)">
                                    <i class="fas fa-arrow-left me-2"></i>Previous
                                </button>
                                <button type="button" class="btn btn-teal" onclick="nextStep(3)">
                                    Next Step <i class="fas fa-arrow-right ms-2"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 3: Address & Contact -->
                        <div class="form-step" id="step3" style="display: none;">
                            <div class="form-section">
                                <h5><i class="fas fa-map-marker-alt me-2 text-teal-600"></i>Address Information</h5>
                                
                                <div class="mb-3">
                                    <label for="address" class="form-label text-gray-700">Street Address <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="address" name="address" 
                                           placeholder="Enter street address" required>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="city" class="form-label text-gray-700">City <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="city" name="city" 
                                               placeholder="City" required>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="state" class="form-label text-gray-700">State/Province</label>
                                        <input type="text" class="form-control" id="state" name="state" 
                                               placeholder="State/Province">
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="postal_code" class="form-label text-gray-700">Postal Code</label>
                                        <input type="text" class="form-control" id="postal_code" name="postal_code" 
                                               placeholder="Postal code">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="country" class="form-label text-gray-700">Country</label>
                                        <input type="text" class="form-control" id="country" name="country" 
                                               value="Philippines" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <h5><i class="fas fa-phone me-2 text-teal-600"></i>Contact Numbers</h5>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label text-gray-700">Telephone</label>
                                        <input type="text" class="form-control" id="phone" name="phone" 
                                               placeholder="(02) 1234 5678">
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label for="mobile" class="form-label text-gray-700">Mobile Number <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="mobile" name="mobile" 
                                               placeholder="0917 123 4567" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="terms" required>
                                <label class="form-check-label text-gray-700" for="terms">
                                    I agree to the <a href="#" class="text-teal-600">Terms and Conditions</a> and 
                                    <a href="#" class="text-teal-600">Privacy Policy</a>
                                </label>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" onclick="prevStep(2)">
                                    <i class="fas fa-arrow-left me-2"></i>Previous
                                </button>
                                <button type="submit" class="btn btn-teal">
                                    <i class="fas fa-check-circle me-2"></i>Complete Registration
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="mt-4 pt-4 border-top">
                    <p class="small text-muted text-center mb-0">
                        <i class="fas fa-shield-alt me-1"></i> Secure supplier portal with role-based access
                    </p>
                </div>
            </div>

            <p class="text-center text-gray-600 mt-4">© 2026 Eyecore Optical Clinic. All rights reserved.</p>
        </div>
    </div>

    <!-- Forgot Password Modal -->
    <?php if ($showForgotPassword): ?>
    <div class="modal fade show" style="display: block;" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title text-gray-900">Reset Supplier Password</h5>
                    <a href="supplier_login.php" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <p class="text-gray-600 mb-4">Enter your email address and we'll send you a reset link</p>
                    <form method="POST" action="">
                        <input type="hidden" name="forgot_password" value="1">
                        <div class="mb-4">
                            <label for="reset_email" class="form-label text-gray-700">Email Address</label>
                            <input type="email" id="reset_email" name="reset_email" class="form-control" 
                                   placeholder="Enter your email" required>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="supplier_login.php" class="btn btn-outline-secondary flex-grow-1">Cancel</a>
                            <button type="submit" class="btn btn-teal flex-grow-1">
                                <i class="fas fa-paper-plane me-2"></i>Send Reset Link
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <div class="modal-backdrop fade show"></div>
    <?php endif; ?>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        // Toggle password visibility
        function togglePassword(fieldId) {
            const passwordInput = document.getElementById(fieldId);
            const toggleIcon = event.currentTarget.querySelector('i');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }

        // Check password strength
        function checkPasswordStrength() {
            const password = document.getElementById('reg_password').value;
            const strengthBar = document.getElementById('passwordStrength');
            
            let strength = 0;
            
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]+/)) strength++;
            if (password.match(/[A-Z]+/)) strength++;
            if (password.match(/[0-9]+/)) strength++;
            if (password.match(/[$@#&!]+/)) strength++;
            
            strengthBar.className = 'password-strength';
            
            if (strength < 2) {
                strengthBar.classList.add('weak');
            } else if (strength < 4) {
                strengthBar.classList.add('medium');
            } else {
                strengthBar.classList.add('strong');
            }
        }

        // Multi-step form navigation
        let currentStep = 1;

        function nextStep(step) {
            // Validate current step
            if (!validateStep(currentStep)) {
                return;
            }
            
            document.getElementById(`step${currentStep}`).style.display = 'none';
            document.getElementById(`step${step}`).style.display = 'block';
            
            // Update progress bar
            const progress = document.getElementById('formProgress');
            if (step === 2) progress.style.width = '66.66%';
            if (step === 3) progress.style.width = '100%';
            
            currentStep = step;
        }

        function prevStep(step) {
            document.getElementById(`step${currentStep}`).style.display = 'none';
            document.getElementById(`step${step}`).style.display = 'block';
            
            // Update progress bar
            const progress = document.getElementById('formProgress');
            if (step === 1) progress.style.width = '33.33%';
            if (step === 2) progress.style.width = '66.66%';
            
            currentStep = step;
        }

        function validateStep(step) {
            const stepElement = document.getElementById(`step${step}`);
            const inputs = stepElement.querySelectorAll('input[required], select[required]');
            
            for (let input of inputs) {
                if (!input.value.trim()) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Required Field',
                        text: 'Please fill in all required fields.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    input.focus();
                    return false;
                }
            }
            
            // Validate email format if on step 1
            if (step === 1) {
                const email = document.getElementById('reg_email').value;
                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Invalid Email',
                        text: 'Please enter a valid email address.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    return false;
                }
                
                const password = document.getElementById('reg_password').value;
                const confirm = document.getElementById('confirm_password').value;
                
                if (password.length < 8) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Weak Password',
                        text: 'Password must be at least 8 characters long.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    return false;
                }
                
                if (password !== confirm) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Password Mismatch',
                        text: 'Passwords do not match.',
                        timer: 2000,
                        showConfirmButton: false
                    });
                    return false;
                }
            }
            
            return true;
        }
    </script>

    <?php if (isset($_SESSION['swal'])): ?>
    <script>
        Swal.fire({
            icon: "<?= $_SESSION['swal']['icon']; ?>",
            title: "<?= $_SESSION['swal']['title']; ?>",
            text: "<?= $_SESSION['swal']['text']; ?>",
            confirmButtonColor: "#0d9488",
            timer: 2000,
            timerProgressBar: true
        });
    </script>
    <?php unset($_SESSION['swal']); endif; ?>

</body>
</html>