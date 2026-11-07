<?php
// C:\xampp\htdocs\eyecore\suppliers\supplier_login.php
session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

global $pdo;

// ============================================
// CSRF TOKEN
// ============================================
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function verifyCsrf() {
    return isset($_POST['csrf_token']) 
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'] ?? '');
}

function generateCode($prefix, $length = 4) {
    return $prefix . date('Ymd') . strtoupper(bin2hex(random_bytes($length)));
}

// ============================================
// FILE UPLOAD HELPERS
// ============================================
const ALLOWED_MIME = ['application/pdf','image/jpeg','image/jpg','image/png'];
const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];
const MAX_FILE_SIZE = 5 * 1024 * 1024;

function handleDocumentUpload($file, $documentType) {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new Exception("Invalid file upload for {$documentType}.");
    }
    if ($file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception("Upload failed for {$documentType}. Error code: {$file['error']}");
    }
    if ($file['size'] > MAX_FILE_SIZE) {
        throw new Exception("File too large for {$documentType}. Max 5MB allowed.");
    }
    if ($file['size'] === 0) throw new Exception("Empty file for {$documentType}.");
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXT)) {
        throw new Exception("Invalid file type for {$documentType}. Allowed: PDF, JPG, PNG.");
    }
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $realMime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($realMime, ALLOWED_MIME)) {
        throw new Exception("Invalid file content for {$documentType}.");
    }
    
    return [
        'tmp_name' => $file['tmp_name'],
        'original_name' => basename($file['name']),
        'ext' => $ext,
        'size' => $file['size'],
        'mime' => $realMime
    ];
}

function saveDocument($fileData, $supplierId, $documentType) {
    $uploadDir = __DIR__ . '/../uploads/suppliers/documents/' . $supplierId . '/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true)) {
            throw new Exception("Failed to create upload directory.");
        }
    }
    $safeName = $documentType . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(6)) . '.' . $fileData['ext'];
    $fullPath = $uploadDir . $safeName;
    if (!move_uploaded_file($fileData['tmp_name'], $fullPath)) {
        throw new Exception("Failed to save file for {$documentType}.");
    }
    $relativePath = 'uploads/suppliers/documents/' . $supplierId . '/' . $safeName;
    return [
        'file_name' => $safeName,
        'file_path' => $relativePath,
        'original_name' => $fileData['original_name'],
        'file_size' => $fileData['size'],
        'mime_type' => $fileData['mime']
    ];
}

$error = '';
$success = '';
$showForgotPassword = isset($_GET['forgot']);
$showRegister = isset($_GET['register']);

// ============================================
// HANDLE UNIFIED LOGIN
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login'])) {
    if (!verifyCsrf()) {
        $error = "Invalid session. Please refresh the page.";
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $secret = $_POST['secret'] ?? '';
        
        if (empty($identifier) || empty($secret)) {
            $error = "Please fill in all fields.";
        } else {
            try {
                $isEmail = (strpos($identifier, '@') !== false);
                
                if ($isEmail) {
                    $query = "SELECT u.id, u.email, u.password, u.role, u.status, 
                                     u.is_verified, u.failed_attempts, u.locked_until,
                                     s.supplier_name, s.id AS supplier_id, s.contact_person,
                                     s.status AS supplier_status
                              FROM users u 
                              LEFT JOIN suppliers s ON u.id = s.created_by 
                              WHERE u.email = :email 
                                AND u.role = 'Supplier'
                              LIMIT 1";
                    $stmt = $pdo->prepare($query);
                    $stmt->bindParam(':email', $identifier);
                    $stmt->execute();
                    
                    if ($stmt->rowCount() > 0) {
                        $user = $stmt->fetch();
                        
                        if ($user['status'] === 'Suspended') {
                            $error = "Your account has been <strong>suspended</strong>. Please contact support.";
                        } 
                        elseif ($user['status'] === 'Blocked') {
                            $error = "Your account has been <strong>blocked</strong>. Please contact support.";
                        } 
                        elseif ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
                            $mins = ceil((strtotime($user['locked_until']) - time()) / 60);
                            $error = "Account locked. Please try again in {$mins} minute(s).";
                        } 
                        elseif (password_verify($secret, $user['password'])) {
                            session_regenerate_id(true);
                            
                            $_SESSION['supplier_id']     = $user['supplier_id'];
                            $_SESSION['user_id']         = $user['id'];
                            $_SESSION['supplier_name']   = $user['supplier_name'];
                            $_SESSION['contact_person']  = $user['contact_person'];
                            $_SESSION['email']           = $user['email'];
                            $_SESSION['role']            = $user['role'];
                            $_SESSION['supplier_status'] = $user['supplier_status'];
                            
                            $pdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
                                ->execute([$user['id']]);
                            
                            header("Location: supplier_dashboard.php");
                            exit();
                        } 
                        else {
                            $newAttempts = ($user['failed_attempts'] ?? 0) + 1;
                            if ($newAttempts >= 5) {
                                $pdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                                    ->execute([$newAttempts, $user['id']]);
                                $error = "Too many failed attempts. Account locked for 15 minutes.";
                            } else {
                                $pdo->prepare("UPDATE users SET failed_attempts = ? WHERE id = ?")
                                    ->execute([$newAttempts, $user['id']]);
                                $remaining = 5 - $newAttempts;
                                $error = "Invalid password! {$remaining} attempt(s) remaining.";
                            }
                        }
                    } else {
                        $error = "No account found with this email.";
                    }
                }
                
                if (!$isEmail) {
                    $phoneNormalized = preg_replace('/[\s\-\(\)\+]/', '', $identifier);
                    $query = "SELECT id, supplier_id, full_name, phone, pin_hash, 
                                     status, verification_status, login_attempts, locked_until
                              FROM supplier_riders
                              WHERE (phone = :phone OR REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', '') = :phone_norm)
                                AND status = 'Active'
                                AND verification_status = 'Verified'
                              LIMIT 1";
                    $stmt = $pdo->prepare($query);
                    $stmt->bindParam(':phone', $identifier);
                    $stmt->bindParam(':phone_norm', $phoneNormalized);
                    $stmt->execute();
                    
                    if ($stmt->rowCount() > 0) {
                        $rider = $stmt->fetch();
                        if ($rider['locked_until'] && strtotime($rider['locked_until']) > time()) {
                            $mins = ceil((strtotime($rider['locked_until']) - time()) / 60);
                            $error = "Account locked. Please try again in {$mins} minute(s).";
                        } else {
                            if (password_verify($secret, $rider['pin_hash'])) {
                                session_regenerate_id(true);
                                $_SESSION['rider_id']    = $rider['id'];
                                $_SESSION['supplier_id'] = $rider['supplier_id'];
                                $_SESSION['rider_name']  = $rider['full_name'];
                                $_SESSION['rider_phone'] = $rider['phone'];
                                $_SESSION['role']        = 'Rider';
                                
                                $pdo->prepare("UPDATE supplier_riders SET login_attempts = 0, locked_until = NULL, last_login = NOW() WHERE id = ?")
                                    ->execute([$rider['id']]);
                                
                                header("Location: ../rider/dashboard.php");
                                exit();
                            } else {
                                $newAttempts = ($rider['login_attempts'] ?? 0) + 1;
                                if ($newAttempts >= 5) {
                                    $pdo->prepare("UPDATE supplier_riders SET login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                                        ->execute([$newAttempts, $rider['id']]);
                                    $error = "Too many failed attempts. Account locked for 15 minutes.";
                                } else {
                                    $pdo->prepare("UPDATE supplier_riders SET login_attempts = ? WHERE id = ?")
                                        ->execute([$newAttempts, $rider['id']]);
                                    $remaining = 5 - $newAttempts;
                                    $error = "Invalid PIN! {$remaining} attempt(s) remaining.";
                                }
                            }
                        }
                    } else {
                        $error = "No active rider found with this phone number.";
                    }
                }
                
            } catch (PDOException $e) {
                error_log("Login error: " . $e->getMessage());
                $error = "A system error occurred. Please try again.";
            }
        }
    }
}

// ============================================
// HANDLE REGISTRATION (with Cavite validation)
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['register'])) {
    if (!verifyCsrf()) {
        $error = "Invalid session. Please refresh the page.";
    } else {
        $supplier_name   = trim($_POST['supplier_name'] ?? '');
        $contact_person  = trim($_POST['contact_person'] ?? '');
        $email           = trim($_POST['email'] ?? '');
        $phone           = trim($_POST['phone'] ?? '');
        $mobile          = trim($_POST['mobile'] ?? '');
        $address         = trim($_POST['address'] ?? '');
        $barangay        = trim($_POST['barangay'] ?? '');
        $city            = trim($_POST['city'] ?? '');
        $state           = trim($_POST['state'] ?? 'Cavite');
        $country         = 'Philippines';
        $postal_code     = trim($_POST['postal_code'] ?? '');
        $latitude        = $_POST['latitude'] ?? null;
        $longitude       = $_POST['longitude'] ?? null;
        $locationConfirmed = ($_POST['location_confirmed'] ?? '0') === '1';
        $tax_id          = trim($_POST['tax_id'] ?? '');
        $payment_terms   = trim($_POST['payment_terms'] ?? 'Net 30');
        $category        = trim($_POST['category'] ?? 'General');
        $website         = trim($_POST['website'] ?? '');
        $password        = $_POST['password'] ?? '';
        $confirm_password= $_POST['confirm_password'] ?? '';
        $terms_accepted  = isset($_POST['terms']);
        
        $errors = [];
        
        if (empty($supplier_name))                          $errors[] = "Company name is required";
        if (strlen($supplier_name) < 3)                     $errors[] = "Company name must be at least 3 characters";
        if (empty($contact_person))                         $errors[] = "Contact person is required";
        if (empty($email))                                  $errors[] = "Email is required";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors[] = "Invalid email format";
        if (empty($mobile))                                 $errors[] = "Mobile number is required";
        if (!preg_match('/^(09|\+639|639)\d{9}$/', preg_replace('/[\s\-\(\)]/', '', $mobile))) {
            $errors[] = "Invalid PH mobile number (format: 09XXXXXXXXX)";
        }
        if (empty($address))                                $errors[] = "Street address is required";
        if (empty($barangay))                               $errors[] = "Barangay is required";
        if (empty($city))                                   $errors[] = "City/Municipality is required";
        if (!preg_match('/^\d{4}$/', $postal_code))         $errors[] = "ZIP code must be 4 digits";
        if (strlen($password) < 8)                          $errors[] = "Password must be at least 8 characters";
        if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
            $errors[] = "Password must contain both letters and numbers";
        }
        if ($password !== $confirm_password)                $errors[] = "Passwords do not match";
        if (!$terms_accepted)                               $errors[] = "You must agree to the Terms and Conditions";
        if (!empty($website) && !filter_var($website, FILTER_VALIDATE_URL)) {
            $errors[] = "Invalid website URL";
        }
        
        // ============================================
        // ✅ CAVITE LOCATION VALIDATION
        // ============================================
        if (!$locationConfirmed) {
            $errors[] = "Please use 'Use Current Location' and confirm your pin is within Cavite.";
        }
        if (empty($latitude) || empty($longitude)) {
            $errors[] = "Location coordinates are required.";
        } else {
            // Bounding box check for Cavite
            $lat = (float)$latitude;
            $lng = (float)$longitude;
            $inCaviteBox = ($lat >= 14.00 && $lat <= 14.60 && $lng >= 120.55 && $lng <= 121.15);
            if (!$inCaviteBox) {
                $errors[] = "Your pin is outside Cavite province. Only Cavite-based suppliers are accepted.";
            }
        }
        
        $requiredDocs = [
            'business_permit'      => 'Business Permit',
            'dti_sec_registration' => 'DTI/SEC Registration',
            'bir_certificate'      => 'BIR Certificate',
            'valid_id'             => 'Valid ID'
        ];
        
        $uploadedFiles = [];
        foreach ($requiredDocs as $key => $label) {
            if (!isset($_FILES[$key]) || $_FILES[$key]['error'] === UPLOAD_ERR_NO_FILE) {
                $errors[] = "{$label} is required.";
            } else {
                try {
                    $uploadedFiles[$key] = handleDocumentUpload($_FILES[$key], $label);
                } catch (Exception $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }
        
        if (isset($_FILES['company_logo']) && $_FILES['company_logo']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $uploadedFiles['company_logo'] = handleDocumentUpload($_FILES['company_logo'], 'Company Logo');
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }
        
        if (empty($errors)) {
            try {
                $pdo->beginTransaction();
                
                $checkQuery = "SELECT u.id, u.email, s.supplier_name, s.mobile
                               FROM users u 
                               LEFT JOIN suppliers s ON u.id = s.created_by
                               WHERE u.email = :email 
                                  OR s.supplier_name = :supplier_name 
                                  OR s.mobile = :mobile
                               LIMIT 1";
                $checkStmt = $pdo->prepare($checkQuery);
                $checkStmt->execute([
                    ':email'         => $email,
                    ':supplier_name' => $supplier_name,
                    ':mobile'        => $mobile
                ]);
                
                if ($checkStmt->rowCount() > 0) {
                    $dup = $checkStmt->fetch();
                    if ($dup['email'] === $email) {
                        throw new Exception("This email is already registered.");
                    } elseif ($dup['supplier_name'] === $supplier_name) {
                        throw new Exception("A supplier with this company name already exists.");
                    } elseif ($dup['mobile'] === $mobile) {
                        throw new Exception("This mobile number is already registered.");
                    }
                    throw new Exception("Duplicate entry detected.");
                }
                
                $user_code      = generateCode('USR', 3);
                $supplier_code  = generateCode('SUP', 3);
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                $verify_token   = bin2hex(random_bytes(32));
                $verify_expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
                
                $userQuery = "INSERT INTO users 
                    (user_code, clinic_id, first_name, last_name, email, contact, password, 
                     role, status, is_verified, email_verification_token, email_verification_expires, created_at) 
                    VALUES 
                    (:user_code, 1, :first_name, :last_name, :email, :contact, :password, 
                     'Supplier', 'Pending', 0, :token, :expires, NOW())";
                
                $userStmt = $pdo->prepare($userQuery);
                $userStmt->execute([
                    ':user_code'  => $user_code,
                    ':first_name' => $contact_person,
                    ':last_name'  => $supplier_name,
                    ':email'      => $email,
                    ':contact'    => $mobile,
                    ':password'   => $hashed_password,
                    ':token'      => $verify_token,
                    ':expires'    => $verify_expires
                ]);
                $user_id = $pdo->lastInsertId();
                
                // ✅ Include latitude & longitude in INSERT
                $supplierQuery = "INSERT INTO suppliers (
                    supplier_code, supplier_name, contact_person, email, phone, mobile, 
                    address, barangay, city, state, country, postal_code, 
                    latitude, longitude,
                    tax_id, payment_terms, category, website, status, created_by, clinic_id, created_at
                ) VALUES (
                    :supplier_code, :supplier_name, :contact_person, :email, :phone, :mobile,
                    :address, :barangay, :city, :state, :country, :postal_code,
                    :latitude, :longitude,
                    :tax_id, :payment_terms, :category, :website, 'Pending', :created_by, 1, NOW()
                )";
                
                $supplierStmt = $pdo->prepare($supplierQuery);
                $supplierStmt->execute([
                    ':supplier_code'  => $supplier_code,
                    ':supplier_name'  => $supplier_name,
                    ':contact_person' => $contact_person,
                    ':email'          => $email,
                    ':phone'          => $phone,
                    ':mobile'         => $mobile,
                    ':address'        => $address,
                    ':barangay'       => $barangay,
                    ':city'           => $city,
                    ':state'          => $state,
                    ':country'        => $country,
                    ':postal_code'    => $postal_code,
                    ':latitude'       => $latitude,
                    ':longitude'      => $longitude,
                    ':tax_id'         => $tax_id,
                    ':payment_terms'  => $payment_terms,
                    ':category'       => $category,
                    ':website'        => $website,
                    ':created_by'     => $user_id
                ]);
                $supplier_id = $pdo->lastInsertId();
                
                $contactQuery = "INSERT INTO supplier_contacts 
                    (supplier_id, contact_name, position, email, phone, mobile, is_primary) 
                    VALUES 
                    (:supplier_id, :contact_name, 'Primary Contact', :email, :phone, :mobile, 1)";
                
                $contactStmt = $pdo->prepare($contactQuery);
                $contactStmt->execute([
                    ':supplier_id'  => $supplier_id,
                    ':contact_name' => $contact_person,
                    ':email'        => $email,
                    ':phone'        => $phone,
                    ':mobile'       => $mobile
                ]);
                
                $docNames = [
                    'business_permit'      => 'Business Permit',
                    'dti_sec_registration' => 'DTI/SEC Registration',
                    'bir_certificate'      => 'BIR Certificate (2303)',
                    'valid_id'             => 'Valid ID',
                    'company_logo'         => 'Company Logo'
                ];
                
                $docCount = 0;
                foreach ($uploadedFiles as $docType => $fileData) {
                    if ($fileData === null) continue;
                    $saved = saveDocument($fileData, $supplier_id, $docType);
                    $docQuery = "INSERT INTO supplier_documents 
                        (supplier_id, document_type, document_name, file_name, original_name, 
                         file_path, file_size, mime_type, status, uploaded_by, upload_date) 
                        VALUES 
                        (:supplier_id, :doc_type, :document_name, :file_name, :original_name, 
                         :file_path, :file_size, :mime_type, 'Pending', :uploaded_by, NOW())";
                    $docStmt = $pdo->prepare($docQuery);
                    $docStmt->execute([
                        ':supplier_id'    => $supplier_id,
                        ':doc_type'       => $docType,
                        ':document_name'  => $docNames[$docType] ?? ucwords(str_replace('_', ' ', $docType)),
                        ':file_name'      => $saved['file_name'],
                        ':original_name'  => $saved['original_name'],
                        ':file_path'      => $saved['file_path'],
                        ':file_size'      => $saved['file_size'],
                        ':mime_type'      => $saved['mime_type'],
                        ':uploaded_by'    => $user_id
                    ]);
                    $docCount++;
                }
                
                $pdo->commit();
                
                @sendSupplierVerificationEmail($email, $contact_person, $supplier_name, $verify_token);
                @sendAdminNewSupplierNotification($supplier_name, $contact_person, $email, $mobile, $category);
                @sendDocumentsReceivedEmail($email, $contact_person, $supplier_name, $docCount);
                
                $_SESSION['swal'] = [
                    'icon'  => 'success',
                    'title' => 'Registration Submitted!',
                    'text'  => 'Please check your email to verify your account. '
                             . 'Your application and documents will be reviewed by our team once verified.'
                ];
                
                header("Location: supplier_login.php");
                exit();
                
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $error = $e->getMessage();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log("Registration DB error: " . $e->getMessage());
                $error = "Registration failed. Please try again later.";
            }
        } else {
            $error = implode("<br>", $errors);
        }
    }
}

// ============================================
// HANDLE FORGOT PASSWORD
// ============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['forgot_password'])) {
    if (!verifyCsrf()) {
        $error = "Invalid session. Please refresh the page.";
    } else {
        $reset_email = trim($_POST['reset_email'] ?? '');
        if (!empty($reset_email) && filter_var($reset_email, FILTER_VALIDATE_EMAIL)) {
            try {
                $query = "SELECT id FROM users WHERE email = :email AND role = 'Supplier'";
                $stmt = $pdo->prepare($query);
                $stmt->bindParam(':email', $reset_email);
                $stmt->execute();
                if ($stmt->rowCount() > 0) {
                    $token = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
                    $updateQuery = "UPDATE users SET reset_token = :token, reset_expires = :expires WHERE email = :email";
                    $updateStmt = $pdo->prepare($updateQuery);
                    $updateStmt->execute([
                        ':token' => $token,
                        ':expires' => $expires,
                        ':email' => $reset_email
                    ]);
                }
                $success = "If an account exists with that email, a password reset link has been sent.";
            } catch (PDOException $e) {
                error_log("Forgot password error: " . $e->getMessage());
                $error = "A system error occurred. Please try again.";
            }
        } else {
            $error = "Please enter a valid email address";
        }
    }
}

$categories = [];
try {
    $categoryQuery = "SELECT category_name FROM supplier_categories ORDER BY category_name";
    $categoryStmt = $pdo->prepare($categoryQuery);
    $categoryStmt->execute();
    $categories = $categoryStmt->fetchAll();
} catch (PDOException $e) {
    $categories = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Eyecore - Portal Login</title>
    <link rel="icon" type="image/png" href="../assets/images/eyecore-logo.png">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

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
            width: 64px; height: 64px;
            border-radius: 16px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto;
        }
        .auth-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        .input-group-icon {
            position: absolute; left: 12px; top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400); z-index: 10;
        }
        .password-toggle {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--gray-400); cursor: pointer; z-index: 10;
        }
        .btn-teal {
            background-color: var(--teal-600);
            color: white; border: none;
            padding: 10px 20px;
        }
        .btn-teal:hover { background-color: var(--teal-700); color: white; }
        .btn-teal:disabled { background-color: #9ca3af; cursor: not-allowed; }
        .form-control:focus, .form-select:focus {
            border-color: var(--teal-600);
            box-shadow: 0 0 0 0.2rem rgba(13, 148, 136, 0.25);
        }
        .progress { height: 4px; border-radius: 2px; }
        .progress-bar { background-color: var(--teal-600); }
        .form-section {
            background: var(--gray-50);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .form-section h5 {
            color: var(--gray-700);
            font-size: 1rem; font-weight: 600;
            margin-bottom: 1rem;
        }
        .password-strength {
            height: 4px; margin-top: 8px;
            border-radius: 2px; transition: all 0.3s ease;
        }
        .password-strength.weak { background-color: #dc3545; width: 33.33%; }
        .password-strength.medium { background-color: #ffc107; width: 66.66%; }
        .password-strength.strong { background-color: #28a745; width: 100%; }
        .text-teal-600 { color: var(--teal-600); }
        
        .upload-box {
            border: 2px dashed var(--gray-300);
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            transition: all 0.2s ease;
            cursor: pointer;
            background: white;
            position: relative;
        }
        .upload-box:hover {
            border-color: var(--teal-600);
            background: var(--teal-50);
        }
        .upload-box.has-file {
            border-color: #28a745;
            background: #f0fdf4;
        }
        .upload-box i.upload-icon {
            font-size: 1.75rem;
            color: var(--gray-400);
            margin-bottom: 0.5rem;
        }
        .upload-box.has-file i.upload-icon {
            color: #28a745;
        }
        .upload-box input[type="file"] {
            position: absolute;
            top: 0; left: 0;
            width: 100%; height: 100%;
            opacity: 0; cursor: pointer;
        }
        .upload-box .file-name {
            font-size: 0.85rem;
            color: var(--gray-600);
            margin-top: 0.5rem;
            word-break: break-all;
        }
        .upload-box .file-name.has-file {
            color: #28a745;
            font-weight: 600;
        }
        .upload-label {
            font-weight: 600;
            color: var(--gray-700);
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
            display: block;
        }
        .required-badge { color: #dc3545; font-size: 0.75rem; font-weight: bold; }
        .optional-badge { color: var(--gray-500); font-size: 0.75rem; }
        .file-info { font-size: 0.75rem; color: var(--gray-500); margin-top: 0.25rem; }

        /* ── Location styles ── */
        .btn-location {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--teal-600);
            background: var(--teal-50);
            border: 1.5px solid var(--teal-100);
            border-radius: 8px;
            padding: 6px 14px;
            cursor: pointer;
            transition: all 0.2s;
            margin-top: 6px;
        }
        .btn-location:hover {
            background: var(--teal-600);
            color: white;
            border-color: var(--teal-600);
        }
        .btn-location.loading { opacity: 0.7; pointer-events: none; }
        .location-status {
            font-size: 0.75rem;
            margin-top: 6px;
            display: none;
            padding: 6px 10px;
            border-radius: 6px;
            line-height: 1.4;
        }
        .location-status.success {
            color: #15803d;
            background: #f0fdf4;
            border: 1px solid #86efac;
            display: block;
        }
        .location-status.error {
            color: #b91c1c;
            background: #fef2f2;
            border: 1px solid #fecaca;
            display: block;
        }
        #supplierMap {
            width: 100%;
            height: 240px;
            border-radius: 12px;
            margin-top: 10px;
            border: 1.5px solid var(--gray-200);
            display: none;
        }
        #supplierMap.show { display: block; }
        .province-lock {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.72rem;
            font-weight: 700;
            color: var(--teal-700);
            background: var(--teal-50);
            border: 1px solid var(--teal-100);
            border-radius: 6px;
            padding: 3px 9px;
            margin-left: 6px;
        }
    </style>
</head>
<body>
    <div class="container-fluid min-vh-100 d-flex align-items-center justify-content-center p-4">
        <div class="w-100" style="max-width: <?= $showRegister ? '900px' : '420px'; ?>;">
            
            <div class="text-center mb-4">
                <div class="brand-logo">
                    <i class="fas fa-eye text-white fa-2x"></i>
                </div>
                <h1 class="h3 fw-bold text-gray-900 mt-3 mb-1">Eyecore Portal</h1>
                <p class="text-gray-600">Supplier &amp; Rider Login</p>
            </div>

            <div class="auth-card p-4 p-md-5">
                <?php if (!$showRegister): ?>
                    <!-- ============ LOGIN FORM ============ -->
                    <h2 class="h4 fw-bold text-gray-900 mb-2">Sign In</h2>
                    <p class="text-gray-600 mb-3">Enter your credentials to continue</p>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <i class="fas fa-exclamation-circle me-1"></i><?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="fas fa-check-circle me-1"></i><?= $success ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="" autocomplete="off">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="login" value="1">
                        
                        <div class="mb-4">
                            <label for="identifier" class="form-label text-gray-700">Email or Phone Number</label>
                            <div class="position-relative">
                                <i class="fas fa-user input-group-icon"></i>
                                <input type="text" 
                                       id="identifier" 
                                       name="identifier" 
                                       class="form-control ps-5" 
                                       placeholder="supplier@company.com or 09XXXXXXXXX"
                                       required autofocus
                                       value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="secret" class="form-label text-gray-700">Password or PIN</label>
                            <div class="position-relative">
                                <i class="fas fa-lock input-group-icon"></i>
                                <input type="password" id="secret" name="secret" 
                                       class="form-control ps-5" 
                                       placeholder="Enter password (supplier) or 6-digit PIN (rider)"
                                       required>
                                <button type="button" class="password-toggle" onclick="togglePassword('secret')">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-4">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="remember" name="remember">
                                <label class="form-check-label text-gray-700" for="remember">Remember me</label>
                            </div>
                            <a href="?forgot=1" class="text-decoration-none text-teal-600 small">Forgot password?</a>
                        </div>

                        <button type="submit" class="btn btn-teal w-100 py-2 mb-3">
                            <i class="fas fa-sign-in-alt me-2"></i>Sign In
                        </button>
                        
                        <p class="text-center text-gray-600 mb-0 small">
                            New supplier? <a href="?register=1" class="text-teal-600 text-decoration-none">Register here</a>
                        </p>
                        <p class="text-center text-gray-500 mb-0 mt-2" style="font-size: 0.75rem;">
                            Riders — contact your supplier for account setup
                        </p>
                    </form>

                <?php else: ?>
                    <!-- ============ REGISTER FORM ============ -->
                    <div class="d-flex align-items-center justify-content-between mb-4">
                        <h2 class="h4 fw-bold text-gray-900 mb-0">Supplier Registration</h2>
                        <a href="supplier_login.php" class="btn btn-outline-secondary btn-sm">
                            <i class="fas fa-arrow-left me-2"></i>Back to Login
                        </a>
                    </div>
                    
                    <div class="mb-4">
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-gray-600">Account</small>
                            <small class="text-gray-600">Company</small>
                            <small class="text-gray-600">Address</small>
                            <small class="text-gray-600">Documents</small>
                        </div>
                        <div class="progress">
                            <div class="progress-bar" style="width: 25%" id="formProgress"></div>
                        </div>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <?= $error ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info" style="background:#f0fdfa; border-left: 3px solid #0d9488; color:#0f766e;">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>Note:</strong> Your account will be reviewed by our team before activation. 
                        You'll receive a verification email after registration.
                    </div>

                    <form method="POST" action="" id="registerForm" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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
                                           placeholder="Enter company name" required minlength="3">
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

                        <!-- Step 3: Address & Contact (WITH CAVITE LOCATION) -->
                        <div class="form-step" id="step3" style="display: none;">
                            <div class="form-section">
                                <h5>
                                    <i class="fas fa-map-marker-alt me-2 text-teal-600"></i>Address Information
                                    <span class="province-lock"><i class="fas fa-lock"></i> Cavite Province Only</span>
                                </h5>

                                <!-- ═══ USE CURRENT LOCATION ═══ -->
                                <div class="mb-3">
                                    <label class="form-label text-gray-700">
                                        Auto-fill Address
                                    </label>
                                    <div>
                                        <button type="button" class="btn-location" id="useLocationBtn" onclick="getSupplierLocation()">
                                            <i class="fas fa-map-marker-alt"></i>
                                            Use Current Location
                                        </button>
                                    </div>
                                    <div class="location-status" id="locationStatus"></div>
                                    <div id="supplierMap"></div>
                                </div>

                                <!-- Hidden location fields -->
                                <input type="hidden" name="latitude"  id="supplier_lat">
                                <input type="hidden" name="longitude" id="supplier_lng">
                                <input type="hidden" name="location_confirmed" id="location_confirmed_field" value="0">

                                <div class="row">
                                    <div class="col-md-8 mb-3">
                                        <label for="address" class="form-label text-gray-700">Street Address <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="address" name="address" 
                                               placeholder="House #, Street" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="barangay" class="form-label text-gray-700">Barangay <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="barangay" name="barangay" 
                                               placeholder="Barangay" required>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="city" class="form-label text-gray-700">City / Municipality <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="city" name="city" 
                                               placeholder="City" required>
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="state" class="form-label text-gray-700">Province</label>
                                        <input type="text" class="form-control" id="state" name="state" 
                                               value="Cavite" readonly
                                               style="background:#f0fdfa;color:#0f766e;font-weight:700;">
                                    </div>
                                    
                                    <div class="col-md-4 mb-3">
                                        <label for="postal_code" class="form-label text-gray-700">ZIP Code <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="postal_code" name="postal_code" 
                                               placeholder="e.g. 4114" required
                                               inputmode="numeric" pattern="\d{4}" maxlength="4">
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="country" class="form-label text-gray-700">Country</label>
                                        <input type="text" class="form-control" id="country" name="country" 
                                               value="Philippines" readonly
                                               style="background:#f0fdfa;color:#0f766e;font-weight:700;">
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
                                               placeholder="0917 123 4567" required 
                                               pattern="^(09|\+639|639)\d{9}$" 
                                               title="Format: 09XXXXXXXXX">
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" onclick="prevStep(2)">
                                    <i class="fas fa-arrow-left me-2"></i>Previous
                                </button>
                                <button type="button" class="btn btn-teal" onclick="nextStep(4)">
                                    Next Step <i class="fas fa-arrow-right ms-2"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Step 4: Document Upload -->
                        <div class="form-step" id="step4" style="display: none;">
                            <div class="form-section">
                                <h5><i class="fas fa-file-upload me-2 text-teal-600"></i>Verification Documents</h5>
                                <p class="text-muted small mb-3">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Please upload clear copies. Allowed: <strong>PDF, JPG, PNG</strong> (max <strong>5MB</strong> each).
                                </p>
                                
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="upload-label">
                                            Business Permit <span class="required-badge">*</span>
                                        </label>
                                        <div class="upload-box" id="box_business_permit">
                                            <i class="fas fa-file-upload upload-icon"></i>
                                            <div><small>Click to upload</small></div>
                                            <div class="file-name" id="name_business_permit">No file selected</div>
                                            <input type="file" name="business_permit" 
                                                   accept=".pdf,.jpg,.jpeg,.png"
                                                   required
                                                   onchange="handleFileSelect(this, 'business_permit')">
                                        </div>
                                        <div class="file-info">Mayor's Permit</div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="upload-label">
                                            DTI / SEC Registration <span class="required-badge">*</span>
                                        </label>
                                        <div class="upload-box" id="box_dti_sec_registration">
                                            <i class="fas fa-file-upload upload-icon"></i>
                                            <div><small>Click to upload</small></div>
                                            <div class="file-name" id="name_dti_sec_registration">No file selected</div>
                                            <input type="file" name="dti_sec_registration" 
                                                   accept=".pdf,.jpg,.jpeg,.png"
                                                   required
                                                   onchange="handleFileSelect(this, 'dti_sec_registration')">
                                        </div>
                                        <div class="file-info">Business registration</div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="upload-label">
                                            BIR Certificate (2303) <span class="required-badge">*</span>
                                        </label>
                                        <div class="upload-box" id="box_bir_certificate">
                                            <i class="fas fa-file-upload upload-icon"></i>
                                            <div><small>Click to upload</small></div>
                                            <div class="file-name" id="name_bir_certificate">No file selected</div>
                                            <input type="file" name="bir_certificate" 
                                                   accept=".pdf,.jpg,.jpeg,.png"
                                                   required
                                                   onchange="handleFileSelect(this, 'bir_certificate')">
                                        </div>
                                        <div class="file-info">Tax registration</div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="upload-label">
                                            Valid ID (Contact Person) <span class="required-badge">*</span>
                                        </label>
                                        <div class="upload-box" id="box_valid_id">
                                            <i class="fas fa-id-card upload-icon"></i>
                                            <div><small>Click to upload</small></div>
                                            <div class="file-name" id="name_valid_id">No file selected</div>
                                            <input type="file" name="valid_id" 
                                                   accept=".pdf,.jpg,.jpeg,.png"
                                                   required
                                                   onchange="handleFileSelect(this, 'valid_id')">
                                        </div>
                                        <div class="file-info">Government-issued ID</div>
                                    </div>
                                    
                                    <div class="col-md-6 mb-3">
                                        <label class="upload-label">
                                            Company Logo <span class="optional-badge">(Optional)</span>
                                        </label>
                                        <div class="upload-box" id="box_company_logo">
                                            <i class="fas fa-image upload-icon"></i>
                                            <div><small>Click to upload</small></div>
                                            <div class="file-name" id="name_company_logo">No file selected</div>
                                            <input type="file" name="company_logo" 
                                                   accept=".jpg,.jpeg,.png"
                                                   onchange="handleFileSelect(this, 'company_logo')">
                                        </div>
                                        <div class="file-info">For branding</div>
                                    </div>
                                </div>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="terms" name="terms" required>
                                <label class="form-check-label text-gray-700" for="terms">
                                    I agree to the <a href="#" class="text-teal-600">Terms and Conditions</a> and 
                                    <a href="#" class="text-teal-600">Privacy Policy</a>
                                </label>
                            </div>

                            <div class="d-flex justify-content-between">
                                <button type="button" class="btn btn-outline-secondary" onclick="prevStep(3)">
                                    <i class="fas fa-arrow-left me-2"></i>Previous
                                </button>
                                <button type="submit" class="btn btn-teal" id="submitBtn">
                                    <i class="fas fa-check-circle me-2"></i>Complete Registration
                                </button>
                            </div>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="mt-4 pt-4 border-top">
                    <p class="small text-muted text-center mb-0">
                        <i class="fas fa-shield-alt me-1"></i> Secure portal with role-based access
                    </p>
                </div>
            </div>

            <p class="text-center text-gray-600 mt-4">© 2026 Eyecore Optical Clinic. All rights reserved.</p>
        </div>
    </div>

    <?php if ($showForgotPassword): ?>
    <div class="modal fade show" style="display: block;" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h5 class="modal-title text-gray-900">Reset Password</h5>
                    <a href="supplier_login.php" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <p class="text-gray-600 mb-4">Enter your email address and we'll send you a reset link</p>
                    <form method="POST" action="">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <script>
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
            if (strength < 2) strengthBar.classList.add('weak');
            else if (strength < 4) strengthBar.classList.add('medium');
            else strengthBar.classList.add('strong');
        }

        const MAX_FILE_SIZE = 5 * 1024 * 1024;
        const ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png'];

        function handleFileSelect(input, docType) {
            const box = document.getElementById('box_' + docType);
            const nameEl = document.getElementById('name_' + docType);
            
            if (!input.files || !input.files[0]) {
                box.classList.remove('has-file');
                nameEl.classList.remove('has-file');
                nameEl.textContent = 'No file selected';
                return;
            }
            
            const file = input.files[0];
            const ext = file.name.split('.').pop().toLowerCase();
            
            if (!ALLOWED_EXT.includes(ext)) {
                Swal.fire({ icon: 'error', title: 'Invalid File', text: 'Only PDF, JPG, PNG allowed.', timer: 2500, showConfirmButton: false });
                input.value = '';
                box.classList.remove('has-file');
                nameEl.classList.remove('has-file');
                nameEl.textContent = 'No file selected';
                return;
            }
            
            if (file.size > MAX_FILE_SIZE) {
                Swal.fire({ icon: 'error', title: 'File Too Large', text: 'Max 5MB per file.', timer: 2500, showConfirmButton: false });
                input.value = '';
                box.classList.remove('has-file');
                nameEl.classList.remove('has-file');
                nameEl.textContent = 'No file selected';
                return;
            }
            
            box.classList.add('has-file');
            nameEl.classList.add('has-file');
            const sizeMB = (file.size / 1024 / 1024).toFixed(2);
            nameEl.textContent = '✓ ' + file.name + ' (' + sizeMB + ' MB)';
        }

        let currentStep = 1;

        function nextStep(step) {
            if (!validateStep(currentStep)) return;
            document.getElementById(`step${currentStep}`).style.display = 'none';
            document.getElementById(`step${step}`).style.display = 'block';
            const progress = document.getElementById('formProgress');
            progress.style.width = (step * 25) + '%';
            currentStep = step;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function prevStep(step) {
            document.getElementById(`step${currentStep}`).style.display = 'none';
            document.getElementById(`step${step}`).style.display = 'block';
            const progress = document.getElementById('formProgress');
            progress.style.width = (step * 25) + '%';
            currentStep = step;
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function validateStep(step) {
            const stepElement = document.getElementById(`step${step}`);
            const inputs = stepElement.querySelectorAll('input[required], select[required]');
            
            for (let input of inputs) {
                if (input.type === 'checkbox') {
                    if (!input.checked) {
                        Swal.fire({ icon: 'warning', title: 'Required', text: 'Please check the required box.', timer: 2000, showConfirmButton: false });
                        input.focus();
                        return false;
                    }
                } else if (input.type === 'file') {
                    if (!input.files || !input.files[0]) {
                        Swal.fire({ icon: 'warning', title: 'Required Document', text: 'Please upload the required document.', timer: 2000, showConfirmButton: false });
                        return false;
                    }
                } else if (!input.value.trim()) {
                    Swal.fire({ icon: 'warning', title: 'Required Field', text: 'Please fill in all required fields.', timer: 2000, showConfirmButton: false });
                    input.focus();
                    return false;
                }
            }
            
            if (step === 1) {
                const email = document.getElementById('reg_email').value;
                if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                    Swal.fire({ icon: 'warning', title: 'Invalid Email', text: 'Please enter a valid email address.', timer: 2000, showConfirmButton: false });
                    return false;
                }
                const password = document.getElementById('reg_password').value;
                const confirm = document.getElementById('confirm_password').value;
                if (password.length < 8) {
                    Swal.fire({ icon: 'warning', title: 'Weak Password', text: 'Password must be at least 8 characters.', timer: 2000, showConfirmButton: false });
                    return false;
                }
                if (!/[A-Za-z]/.test(password) || !/[0-9]/.test(password)) {
                    Swal.fire({ icon: 'warning', title: 'Weak Password', text: 'Password must contain letters and numbers.', timer: 2000, showConfirmButton: false });
                    return false;
                }
                if (password !== confirm) {
                    Swal.fire({ icon: 'warning', title: 'Password Mismatch', text: 'Passwords do not match.', timer: 2000, showConfirmButton: false });
                    return false;
                }
            }
            
            if (step === 3) {
                const mobile = document.getElementById('mobile').value;
                const cleaned = mobile.replace(/[\s\-\(\)]/g, '');
                if (!/^(09|\+639|639)\d{9}$/.test(cleaned)) {
                    Swal.fire({ icon: 'warning', title: 'Invalid Mobile', text: 'Format: 09XXXXXXXXX', timer: 2000, showConfirmButton: false });
                    return false;
                }
                
                // ✅ Require Cavite location confirmation
                const confirmed = document.getElementById('location_confirmed_field').value === '1';
                if (!confirmed) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Location Required',
                        html: 'Please tap <strong>"Use Current Location"</strong> and confirm your pin is within <strong>Cavite</strong> before continuing.',
                        confirmButtonColor: '#0d9488'
                    });
                    return false;
                }
            }
            
            return true;
        }

        document.getElementById('registerForm')?.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading... Please wait';
            }
        });

        /* ═══════════════════════════════════════════════
           CAVITE-ONLY LOCATION — with polygon validation
        ═══════════════════════════════════════════════ */
        let supplierMap = null;
        let supplierMarker = null;

        // Cavite land polygon (excludes ocean)
        const caviteLandPolygon = [
            [14.4950, 120.8700], [14.4950, 120.9200], [14.4800, 120.9500], [14.4500, 120.9600], 
            [14.4300, 121.0000], [14.3800, 121.0500], [14.3200, 121.0500], [14.2500, 120.9800], 
            [14.1500, 120.9500], [14.1100, 120.9300], [14.1000, 120.8500], [14.1200, 120.7000], 
            [14.2000, 120.6000], [14.3000, 120.5800], [14.4000, 120.5800], [14.4500, 120.6200], 
            [14.4600, 120.7000], [14.4800, 120.8000], [14.4950, 120.8700]
        ];

        function isPointInPolygon(lat, lng, polygon) {
            let inside = false;
            for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
                const xi = polygon[i][0], yi = polygon[i][1];
                const xj = polygon[j][0], yj = polygon[j][1];
                const intersect = ((yi > lng) !== (yj > lng)) && (lat < (xj - xi) * (lng - yi) / (yj - yi) + xi);
                if (intersect) inside = !inside;
            }
            return inside;
        }

        function ensureSupplierMap(lat, lng) {
            const mapDiv = document.getElementById('supplierMap');
            mapDiv.classList.add('show');

            if (!supplierMap) {
                supplierMap = L.map('supplierMap').setView([lat, lng], 16);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                    maxZoom: 19
                }).addTo(supplierMap);

                supplierMarker = L.marker([lat, lng], { draggable: true }).addTo(supplierMap);
                supplierMarker.on('dragend', () => {
                    const pos = supplierMarker.getLatLng();
                    reverseGeocodeSupplier(pos.lat, pos.lng);
                });

                supplierMap.on('click', (e) => {
                    supplierMarker.setLatLng(e.latlng);
                    reverseGeocodeSupplier(e.latlng.lat, e.latlng.lng);
                });
            } else {
                supplierMap.setView([lat, lng], 16);
                supplierMarker.setLatLng([lat, lng]);
            }

            setTimeout(() => supplierMap.invalidateSize(), 200);
        }

        function getSupplierLocation() {
            if (!navigator.geolocation) {
                return Swal.fire({
                    icon: 'error',
                    title: 'Not Supported',
                    text: 'Geolocation is not supported by your browser.'
                });
            }

            const btn = document.getElementById('useLocationBtn');
            const status = document.getElementById('locationStatus');

            btn.classList.add('loading');
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Getting location...';
            status.className = 'location-status';
            status.textContent = '';

            navigator.geolocation.getCurrentPosition(
                async (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    await reverseGeocodeSupplier(lat, lng);
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Refresh Location';
                },
                (err) => {
                    btn.classList.remove('loading');
                    btn.innerHTML = '<i class="fas fa-map-marker-alt"></i> Use Current Location';

                    const msgs = {
                        1: 'Location permission denied. Please enable it in your browser settings.',
                        2: 'Location information unavailable.',
                        3: 'Location request timed out.'
                    };
                    status.className = 'location-status error';
                    status.innerHTML = '<i class="fas fa-times-circle me-1"></i>' + (msgs[err.code] || 'Unknown error.');
                },
                { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
            );
        }

        function clearAutoFilledAddress() {
            document.getElementById('address').value = '';
            document.getElementById('barangay').value = '';
            document.getElementById('city').value = '';
            document.getElementById('postal_code').value = '';
            document.getElementById('location_confirmed_field').value = '0';
        }

        async function reverseGeocodeSupplier(lat, lng) {
            const status = document.getElementById('locationStatus');
            const btn = document.getElementById('useLocationBtn');

            document.getElementById('supplier_lat').value = lat;
            document.getElementById('supplier_lng').value = lng;
            ensureSupplierMap(lat, lng);

            status.className = 'location-status';
            status.innerHTML = '<i class="fas fa-hourglass-half me-1"></i> Checking location...';

            // 1. Check kung nasa Cavite land (polygon)
            const isLand = isPointInPolygon(lat, lng, caviteLandPolygon);

            if (!isLand) {
                // Check kung malapit pa rin sa Cavite (bounding box)
                const isNearCavite = (lat >= 14.00 && lat <= 14.60 && lng >= 120.55 && lng <= 121.15);

                clearAutoFilledAddress();
                status.className = 'location-status error';

                if (isNearCavite) {
                    status.innerHTML = '<i class="fas fa-water me-1"></i>' +
                        '<strong>This pin is in the ocean / body of water.</strong> ' +
                        'Registration is only open to suppliers on land within Cavite. Please move the pin to a land area.';
                } else {
                    status.innerHTML = '<i class="fas fa-times-circle me-1"></i>' +
                        '<strong>This pin is outside Cavite province.</strong> ' +
                        'Registration is only open to suppliers located within Cavite. Please move the pin.';
                }

                btn.style.background = '';
                btn.style.color = '';
                btn.style.borderColor = '';
                return;
            }

            // 2. Land confirmed — reverse geocode for complete address
            try {
                const res = await fetch(
                    `https://nominatim.openstreetmap.org/reverse?format=json&lat=${lat}&lon=${lng}&addressdetails=1&zoom=18`,
                    { headers: { 'Accept-Language': 'en' } }
                );
                const data = await res.json();

                if (!data || !data.address) {
                    clearAutoFilledAddress();
                    status.className = 'location-status error';
                    status.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>Address lookup failed. Try dropping the pin again.';
                    btn.style.background = '';
                    btn.style.color = '';
                    btn.style.borderColor = '';
                    return;
                }

                const addr = data.address;
                
                // Build complete street address
                const road     = addr.road || addr.pedestrian || addr.footway || addr.path || addr.street || '';
                const houseNum = addr.house_number || '';
                const street   = [houseNum, road].filter(Boolean).join(' ');
                const barangay = addr.village || addr.suburb || addr.quarter || addr.neighbourhood || addr.hamlet || '';
                const city     = addr.city || addr.town || addr.municipality || addr.county || '';
                const province = addr.state || addr.province || addr.region || 'Cavite';
                const zip      = addr.postcode || '';

                if (street)    document.getElementById('address').value     = street;
                if (barangay)  document.getElementById('barangay').value    = barangay;
                if (city)      document.getElementById('city').value        = city;
                if (zip)       document.getElementById('postal_code').value = zip;
                document.getElementById('state').value = province;

                document.getElementById('location_confirmed_field').value = '1';
                status.className = 'location-status success';
                status.innerHTML = '<i class="fas fa-check-circle me-1"></i>' +
                    '<strong>Location confirmed on land within Cavite.</strong> ' +
                    'Address auto-filled — please review and adjust if needed.';

                btn.style.background = '#16a34a';
                btn.style.color = 'white';
                btn.style.borderColor = '#16a34a';

            } catch (err) {
                clearAutoFilledAddress();
                status.className = 'location-status error';
                status.innerHTML = '<i class="fas fa-exclamation-circle me-1"></i>Address lookup failed. Try again.';
                btn.style.background = '';
                btn.style.color = '';
                btn.style.borderColor = '';
            }
        }
    </script>

    <?php if (isset($_SESSION['swal'])): ?>
    <script>
        Swal.fire({
            icon: "<?= $_SESSION['swal']['icon']; ?>",
            title: "<?= $_SESSION['swal']['title']; ?>",
            text: "<?= $_SESSION['swal']['text']; ?>",
            confirmButtonColor: "#0d9488",
            timer: 4000,
            timerProgressBar: true
        });
    </script>
    <?php unset($_SESSION['swal']); endif; ?>

</body>
</html>