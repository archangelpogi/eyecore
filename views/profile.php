<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../admin/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? '';
$clinic_id = $_SESSION['clinic_id'] ?? null;

// DEBUG: Show session info
echo "<!-- DEBUG: User ID: $user_id, Role: $user_role, Clinic ID: $clinic_id -->";

$user_data = [];
$employee_data = [];

try {
    // DEBUG: Test database connection
    if (!$pdo) {
        die("Database connection failed");
    }
    
    // Get user basic info
    $stmt = $pdo->prepare("
        SELECT id, clinic_id, user_code, first_name, last_name, fullname, 
               email, contact, address, role, status, last_login, created_at,
               avatar, is_verified, password_changed_at
        FROM users 
        WHERE id = ?
    ");
    $stmt->execute([$user_id]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        die("User not found in users table. User ID: " . $user_id);
    }
    
    // DEBUG: User data found
    echo "<!-- DEBUG: User data found: " . json_encode($user_data) . " -->";
    
    // Get employee details if available - check kung may employees table
    try {
        $stmt = $pdo->prepare("SHOW TABLES LIKE 'employees'");
        $stmt->execute();
        $table_exists = $stmt->rowCount() > 0;
        
        if ($table_exists) {
            // Check kung may position at departments tables
            $has_positions = false;
            $has_departments = false;
            
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'positions'");
            $stmt->execute();
            $has_positions = $stmt->rowCount() > 0;
            
            $stmt = $pdo->prepare("SHOW TABLES LIKE 'departments'");
            $stmt->execute();
            $has_departments = $stmt->rowCount() > 0;
            
            if ($has_positions && $has_departments) {
                $stmt = $pdo->prepare("
                    SELECT e.*, p.name as position_name, d.name as department_name
                    FROM employees e
                    LEFT JOIN positions p ON e.position_id = p.id
                    LEFT JOIN departments d ON e.department = d.id
                    WHERE e.user_id = ?
                ");
            } else {
                // Simple query without joins
                $stmt = $pdo->prepare("SELECT * FROM employees WHERE user_id = ?");
            }
            
            $stmt->execute([$user_id]);
            $employee_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($employee_data) {
                echo "<!-- DEBUG: Employee data found -->";
            } else {
                echo "<!-- DEBUG: No employee data found for user_id: $user_id -->";
            }
        } else {
            echo "<!-- DEBUG: Employees table does not exist -->";
        }
    } catch (PDOException $e) {
        echo "<!-- DEBUG ERROR (employees): " . $e->getMessage() . " -->";
    }
    
} catch (PDOException $e) {
    echo "<!-- DEBUG ERROR: " . $e->getMessage() . " -->";
    die("Error loading profile: " . $e->getMessage());
}

// Handle profile update
$update_success = false;
$update_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_profile'])) {
        // Update basic info
        $first_name = trim($_POST['first_name'] ?? '');
        $last_name = trim($_POST['last_name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $fullname = $first_name . ' ' . $last_name;
        
        try {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET first_name = ?, last_name = ?, fullname = ?, contact = ?, address = ?
                WHERE id = ?
            ");
            $stmt->execute([$first_name, $last_name, $fullname, $contact, $address, $user_id]);
            
            // Update session
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            
            $update_success = true;
            
            // Refresh user data
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user_data = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (PDOException $e) {
            $update_error = "Error updating profile: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['change_password'])) {
        // Change password logic
        $current_password = $_POST['current_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Verify current password
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $stored_password = $stmt->fetchColumn();
        
        if (!password_verify($current_password, $stored_password)) {
            $update_error = "Current password is incorrect";
        } elseif (strlen($new_password) < 8) {
            $update_error = "New password must be at least 8 characters";
        } elseif ($new_password !== $confirm_password) {
            $update_error = "New passwords do not match";
        } else {
            // Update password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ?, password_changed_at = NOW() WHERE id = ?");
            $stmt->execute([$hashed_password, $user_id]);
            $update_success = true;
        }
    }
}

// Get initials for avatar
$initials = '';
foreach (explode(' ', $user_data['fullname'] ?? '') as $i => $part) {
    if ($i >= 2) break;
    $initials .= strtoupper(substr($part, 0, 1));
}
$initials = !empty($initials) ? $initials : 'U';

// Get avatar URL
$avatar_url = null;
if (!empty($user_data['avatar'])) {
    $avatar_url = '../uploads/avatars/' . $user_data['avatar'];
}

// Include header
require_once __DIR__ . '/../include/header.php';
?>

<div class="wrapper d-flex">
    <?php require_once __DIR__ . '/../include/sidebar.php'; ?>

    <div class="main-content flex-fill">
        <?php require_once __DIR__ . '/../include/topbar.php'; ?>

        <!-- Main Content -->
        <main class="content-area p-4">
            <div class="container-fluid px-0">
                
                <?php if ($update_success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i> Profile updated successfully!
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($update_error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i> <?php echo htmlspecialchars($update_error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <!-- Page Title -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <h4 class="fw-semibold mb-0" style="color: #1e293b;">
                        <i class="bi bi-person-circle me-2" style="color: #008080;"></i> My Profile
                    </h4>
                </div>
                
                <div class="row">
                    <!-- Profile Summary Card -->
                    <div class="col-md-4 mb-4">
                        <div class="card border-0 shadow-sm" style="border-radius: 12px;">
                            <div class="card-body text-center p-4">
                                <!-- Avatar -->
                                <div class="position-relative d-inline-block mb-3">
                                    <?php if ($avatar_url && file_exists($_SERVER['DOCUMENT_ROOT'] . '/eyecore/' . $avatar_url)): ?>
                                        <img src="<?php echo $avatar_url; ?>" alt="Profile" class="rounded-circle" style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #008080;">
                                    <?php else: ?>
                                        <div style="width: 120px; height: 120px; border-radius: 50%; background-color: #008080; color: white; display: flex; align-items: center; justify-content: center; font-size: 3rem; font-weight: 600; margin: 0 auto; border: 3px solid #e9ecef;">
                                            <?php echo $initials; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <h5 class="fw-semibold mb-1"><?php echo htmlspecialchars($user_data['fullname'] ?? ''); ?></h5>
                                <p class="text-muted mb-2">
                                    <span class="badge" style="background-color: #008080; color: white;"><?php echo htmlspecialchars($user_data['role'] ?? ''); ?></span>
                                </p>
                                <p class="text-muted small mb-3">
                                    <i class="bi bi-envelope me-1" style="color: #008080;"></i> <?php echo htmlspecialchars($user_data['email'] ?? ''); ?>
                                </p>
                                
                                <hr class="my-3">
                                
                                <div class="text-start small">
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">User Code:</span>
                                        <span class="fw-semibold"><?php echo htmlspecialchars($user_data['user_code'] ?? 'N/A'); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Member Since:</span>
                                        <span class="fw-semibold"><?php echo date('M d, Y', strtotime($user_data['created_at'] ?? 'now')); ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between mb-2">
                                        <span class="text-muted">Last Login:</span>
                                        <span class="fw-semibold"><?php echo $user_data['last_login'] ? date('M d, Y', strtotime($user_data['last_login'])) : 'Never'; ?></span>
                                    </div>
                                    <div class="d-flex justify-content-between">
                                        <span class="text-muted">Status:</span>
                                        <span class="badge <?php echo ($user_data['status'] ?? 'Active') === 'Active' ? 'bg-success' : 'bg-secondary'; ?>">
                                            <?php echo htmlspecialchars($user_data['status'] ?? 'Active'); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Main Content Tabs -->
                    <div class="col-md-8 mb-4">
                        <div class="card border-0 shadow-sm" style="border-radius: 12px;">
                            <div class="card-header bg-white border-0 pt-4 px-4">
                                <ul class="nav nav-tabs" id="profileTabs" role="tablist">
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link active" id="personal-tab" data-bs-toggle="tab" data-bs-target="#personal" type="button" role="tab" style="color: #008080;">
                                            <i class="bi bi-person me-1"></i> Personal Information
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="employee-tab" data-bs-toggle="tab" data-bs-target="#employee" type="button" role="tab" style="color: #008080;">
                                            <i class="bi bi-briefcase me-1"></i> Employment Details
                                        </button>
                                    </li>
                                    <li class="nav-item" role="presentation">
                                        <button class="nav-link" id="password-tab" data-bs-toggle="tab" data-bs-target="#password" type="button" role="tab" style="color: #008080;">
                                            <i class="bi bi-key me-1"></i> Change Password
                                        </button>
                                    </li>
                                </ul>
                            </div>
                            
                            <div class="card-body p-4">
                                <div class="tab-content" id="profileTabsContent">
                                    <!-- Personal Information Tab -->
                                    <div class="tab-pane fade show active" id="personal" role="tabpanel">
                                        <form method="POST" action="">
                                            <h6 class="fw-semibold mb-3" style="color: #008080;">Basic Information</h6>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">First Name</label>
                                                    <input type="text" class="form-control" name="first_name" value="<?php echo htmlspecialchars($user_data['first_name'] ?? ''); ?>" required>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Last Name</label>
                                                    <input type="text" class="form-control" name="last_name" value="<?php echo htmlspecialchars($user_data['last_name'] ?? ''); ?>" required>
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Email Address</label>
                                                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                    <small class="text-muted">Email cannot be changed</small>
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Contact Number</label>
                                                    <input type="text" class="form-control" name="contact" value="<?php echo htmlspecialchars($user_data['contact'] ?? ''); ?>">
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">Address</label>
                                                <textarea class="form-control" name="address" rows="2"><?php echo htmlspecialchars($user_data['address'] ?? ''); ?></textarea>
                                            </div>
                                            
                                            <hr class="my-4">
                                            
                                            <div class="text-end">
                                                <button type="submit" name="update_profile" class="btn btn-primary" style="background-color: #008080; border-color: #008080;">
                                                    <i class="bi bi-save me-2"></i> Save Changes
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                    
                                    <!-- Employment Details Tab -->
                                    <div class="tab-pane fade" id="employee" role="tabpanel">
                                        <?php if ($employee_data): ?>
                                            <h6 class="fw-semibold mb-3" style="color: #008080;">Employment Information</h6>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Employee No.</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['employee_no'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Position</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['position_name'] ?? $employee_data['position'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Department</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['department_name'] ?? $employee_data['department'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Employment Type</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['employment_type'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                            </div>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Date Hired</label>
                                                    <input type="text" class="form-control" value="<?php echo $employee_data['date_hired'] ? date('M d, Y', strtotime($employee_data['date_hired'])) : 'N/A'; ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-6">
                                                    <label class="form-label small text-muted">Date Regularized</label>
                                                    <input type="text" class="form-control" value="<?php echo $employee_data['date_regularized'] ? date('M d, Y', strtotime($employee_data['date_regularized'])) : 'Not yet'; ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                            </div>
                                            
                                            <hr class="my-4">
                                            
                                            <h6 class="fw-semibold mb-3" style="color: #008080;">Personal Details</h6>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Birth Date</label>
                                                    <input type="text" class="form-control" value="<?php echo $employee_data['birth_date'] ? date('M d, Y', strtotime($employee_data['birth_date'])) : 'N/A'; ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Gender</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['gender'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Marital Status</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['marital_status'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                            </div>
                                            
                                            <h6 class="fw-semibold mb-3 mt-4" style="color: #008080;">Government IDs</h6>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">SSS No.</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['sss_number'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">PhilHealth No.</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['philhealth_number'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Pag-IBIG No.</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['pagibig_number'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">TIN No.</label>
                                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['tin_number'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                            </div>
                                            
                                            <h6 class="fw-semibold mb-3 mt-4" style="color: #008080;">Bank Information</h6>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Bank Name</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['bank_name'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Account Holder</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['bank_account_holder'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Account No.</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['bank_account_number'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                            </div>
                                            
                                            <h6 class="fw-semibold mb-3 mt-4" style="color: #008080;">Emergency Contact</h6>
                                            
                                            <div class="row mb-3">
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Name</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['emergency_contact_name'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Number</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['emergency_contact_number'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                                <div class="col-md-4">
                                                    <label class="form-label small text-muted">Relationship</label>
                                                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($employee_data['emergency_contact_relationship'] ?? 'N/A'); ?>" readonly disabled style="background-color: #f8f9fa;">
                                                </div>
                                            </div>
                                            
                                            <div class="alert alert-info mt-3">
                                                <i class="bi bi-info-circle me-2"></i> For changes to employment details, please contact HR or your administrator.
                                            </div>
                                            
                                        <?php else: ?>
                                            <div class="text-center py-4">
                                                <i class="bi bi-briefcase" style="font-size: 3rem; color: #008080; opacity: 0.3;"></i>
                                                <h6 class="mt-3">No employment details found</h6>
                                                <p class="text-muted small">Please contact HR to set up your employee record.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Change Password Tab -->
                                    <div class="tab-pane fade" id="password" role="tabpanel">
                                        <form method="POST" action="">
                                            <h6 class="fw-semibold mb-3" style="color: #008080;">Change Password</h6>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">Current Password</label>
                                                <div class="input-group">
                                                    <span class="input-group-text" style="background-color: #f8f9fa;"><i class="bi bi-lock" style="color: #008080;"></i></span>
                                                    <input type="password" class="form-control" name="current_password" required>
                                                </div>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">New Password</label>
                                                <div class="input-group">
                                                    <span class="input-group-text" style="background-color: #f8f9fa;"><i class="bi bi-key" style="color: #008080;"></i></span>
                                                    <input type="password" class="form-control" name="new_password" required>
                                                </div>
                                                <small class="text-muted">Minimum 8 characters</small>
                                            </div>
                                            
                                            <div class="mb-3">
                                                <label class="form-label small text-muted">Confirm New Password</label>
                                                <div class="input-group">
                                                    <span class="input-group-text" style="background-color: #f8f9fa;"><i class="bi bi-check-circle" style="color: #008080;"></i></span>
                                                    <input type="password" class="form-control" name="confirm_password" required>
                                                </div>
                                            </div>
                                            
                                            <hr class="my-4">
                                            
                                            <div class="text-end">
                                                <button type="submit" name="change_password" class="btn btn-primary" style="background-color: #008080; border-color: #008080;">
                                                    <i class="bi bi-arrow-repeat me-2"></i> Change Password
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php require_once __DIR__ . '/../include/footer.php'; ?>