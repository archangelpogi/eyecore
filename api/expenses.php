<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../include/RBACHelper.php';

// ✅ Initialize RBACHelper
RBACHelper::init($pdo);

// Load permissions to session if not already loaded
if (isset($_SESSION['user_id']) && isset($_SESSION['clinic_id']) && !isset($_SESSION['permissions'])) {
    RBACHelper::loadPermissionsToSession($_SESSION['user_id'], $_SESSION['clinic_id']);
}

// Check authentication
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$current_user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'SCM';
$clinic_id = $_SESSION['clinic_id'] ?? 1;
$user_name = $_SESSION['name'] ?? 'Unknown User';

// ✅ RBAC Permission Functions using RBACHelper
function canViewExpenses() {
    return RBACHelper::hasPermission('expenses_view');
}

function canCreateExpense() {
    return RBACHelper::hasPermission('expenses_create');
}

function canEditExpense() {
    return RBACHelper::hasPermission('expenses_edit');
}

function canDeleteExpense() {
    return RBACHelper::hasPermission('expenses_delete');
}

function canApproveExpense() {
    return RBACHelper::hasPermission('expenses_approve');
}

function canRejectExpense() {
    return RBACHelper::hasPermission('expenses_reject');
}

function canExportExpense() {
    return RBACHelper::hasPermission('expenses_view'); // export uses view permission
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_pr') {
    try {
        // ✅ Use expenses_approve permission instead
        if (!canApproveExpense()) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to approve purchase requests']);
            exit;
        }
        // ... rest of the code
        
        $pr_id = $_POST['pr_id'] ?? 0;
        $payment_method = $_POST['payment_method'] ?? 'Bank Transfer';
        $payment_reference = $_POST['payment_reference'] ?? '';
        $convert_to_expense = $_POST['convert_to_expense'] ?? 'yes';
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        // ✅ FIX: Get actual user name from database
        $userQuery = "SELECT CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE id = ?";
        $userStmt = $pdo->prepare($userQuery);
        $userStmt->execute([$current_user_id]);
        $userData = $userStmt->fetch();
        $approver_name = $userData ? $userData['full_name'] : $user_name;
        
        // Get PR details with items and supplier info
        $prQuery = "SELECT pr.*, 
                        CONCAT(u.first_name, ' ', u.last_name) as requester_name,
                        u.email as requester_email,
                        u.id as requester_id
                    FROM purchase_requests pr
                    LEFT JOIN users u ON pr.requested_by = u.id
                    WHERE pr.id = ? AND pr.clinic_id = ?";
        $prStmt = $pdo->prepare($prQuery);
        $prStmt->execute([$pr_id, $clinic_id]);
        $pr = $prStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pr) {
            http_response_code(404);
            echo json_encode(['error' => 'Purchase request not found']);
            exit;
        }
        
        if ($pr['status'] !== 'Pending Approval') {
            http_response_code(400);
            echo json_encode(['error' => 'Only pending PRs can be approved']);
            exit;
        }
        
        // Get PR items with supplier details
        $itemsQuery = "SELECT i.*, 
                              s.supplier_name,
                              s.contact_person as supplier_contact,
                              s.email as supplier_email,
                              s.mobile as supplier_mobile,
                              s.phone as supplier_phone,
                              s.payment_terms as supplier_payment_terms
                       FROM pr_items i
                       LEFT JOIN suppliers s ON i.supplier_id = s.id
                       WHERE i.pr_id = ?";
        $itemsStmt = $pdo->prepare($itemsQuery);
        $itemsStmt->execute([$pr_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Group items by supplier for better tracking
        $suppliers = [];
        foreach ($items as $item) {
            if ($item['supplier_id']) {
                $supplier_id = $item['supplier_id'];
                if (!isset($suppliers[$supplier_id])) {
                    $suppliers[$supplier_id] = [
                        'id' => $supplier_id,
                        'name' => $item['supplier_name'],
                        'contact' => $item['supplier_contact'],
                        'email' => $item['supplier_email'],
                        'mobile' => $item['supplier_mobile'],
                        'payment_terms' => $item['supplier_payment_terms'],
                        'items' => [],
                        'total' => 0
                    ];
                }
                $suppliers[$supplier_id]['items'][] = $item['item_name'];
                $suppliers[$supplier_id]['total'] += $item['total_price'];
            }
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Update PR status
        $updateQuery = "UPDATE purchase_requests SET 
                        status = 'Approved', 
                        approved_by = ?,
                        approved_by_name = ?,
                        approved_at = NOW() 
                        WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$current_user_id, $approver_name, $pr_id]);
        
        $expense_id = null;
        $expense_code = null;
        
        // Convert to expense if requested
        if ($convert_to_expense === 'yes') {
            // Generate expense code
            $expense_code = 'EXP-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            
            // Determine category based on department
            $category_map = [
                'SCM' => 'Office Supplies',
                'Optical' => 'Equipment',
                'Clinic' => 'Equipment',
                'Admin' => 'Office Supplies',
                'Pharmacy' => 'Medical Supplies',
                'Laboratory' => 'Equipment'
            ];
            $category = $category_map[$pr['department']] ?? 'Other';
            
            // Get all vendors (suppliers) as comma-separated list
            $vendors = [];
            $supplier_details = [];
            foreach ($suppliers as $supplier) {
                $vendors[] = $supplier['name'];
                $supplier_details[] = [
                    'id' => $supplier['id'],
                    'name' => $supplier['name'],
                    'contact' => $supplier['contact'],
                    'email' => $supplier['email'],
                    'mobile' => $supplier['mobile'],
                    'total' => $supplier['total'],
                    'items' => $supplier['items']
                ];
            }
            
            $vendor_string = !empty($vendors) ? implode(', ', $vendors) : 'Various Suppliers';
            
            // Insert expense with supplier details in notes
            $expenseNotes = $pr['notes'] ?? '';
            if (!empty($suppliers)) {
                $expenseNotes .= "\n\nSUPPLIER DETAILS:\n";
                foreach ($suppliers as $supplier) {
                    $expenseNotes .= "• {$supplier['name']}";
                    if ($supplier['contact']) $expenseNotes .= " (Contact: {$supplier['contact']})";
                    if ($supplier['email']) $expenseNotes .= " - {$supplier['email']}";
                    if ($supplier['mobile']) $expenseNotes .= " - {$supplier['mobile']}";
                    $expenseNotes .= " - Total: ₱" . number_format($supplier['total'], 2);
                    $expenseNotes .= "\n  Items: " . implode(', ', $supplier['items']) . "\n";
                }
            }
            
            $expenseQuery = "INSERT INTO expenses (
                expense_code, pr_id, description, category, amount, 
                expense_date, due_date, vendor, department, 
                requested_by, requested_by_name, status, 
                payment_method, payment_reference, has_receipt, notes,
                supplier_json, clinic_id, created_by,
                approved_by, approved_by_name, approved_at
            ) VALUES (?, ?, ?, ?, ?, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 15 DAY), 
                ?, ?, ?, ?, 'Approved', ?, ?, FALSE, ?, ?, ?, ?, ?, ?, NOW())";

            $expenseStmt = $pdo->prepare($expenseQuery);
            $expenseStmt->execute([
                $expense_code,
                $pr_id,
                $pr['purpose'],
                $category,
                $pr['total_amount'],
                $vendor_string,
                $pr['department'],
                $pr['requested_by'],
                $pr['requester_name'] ?? $pr['requested_by'],
                $payment_method,
                $payment_reference,
                $expenseNotes,
                json_encode($supplier_details),
                $clinic_id,
                $current_user_id,
                $current_user_id,
                $approver_name
            ]);
            
            $expense_id = $pdo->lastInsertId();
            
            // Insert individual expense items with supplier info
            $itemExpenseQuery = "INSERT INTO expense_items (
                expense_id, item_name, description, quantity, unit_price, total_price,
                supplier_id, supplier_name, supplier_contact, supplier_email
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $itemExpenseStmt = $pdo->prepare($itemExpenseQuery);
            
            foreach ($items as $item) {
                $itemExpenseStmt->execute([
                    $expense_id,
                    $item['item_name'],
                    $item['description'] ?? '',
                    $item['quantity'],
                    $item['unit_price'],
                    $item['total_price'],
                    $item['supplier_id'],
                    $item['supplier_name'],
                    $item['supplier_contact'],
                    $item['supplier_email']
                ]);
            }
            
            // Update PR with expense ID
            $linkExpense = $pdo->prepare("UPDATE purchase_requests SET expense_id = ? WHERE id = ?");
            $linkExpense->execute([$expense_id, $pr_id]);
        }
        
        // Log activity with approver name
        $logQuery = "INSERT INTO expense_activity_log (expense_id, action, user_id, user_name, details, ip_address) 
                     VALUES (?, 'PR_APPROVED', ?, ?, ?, ?)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([
            $expense_id,
            $current_user_id,
            $approver_name,
            "Approved PR: {$pr['pr_number']} - Amount: ₱" . number_format($pr['total_amount'], 2) . " - Suppliers: " . count($suppliers),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        
        // ========== INSERT NOTIFICATIONS ==========
        
        // 1. Notify the requester (yung gumawa ng PR)
        $notif_requester_message = "Your PR {$pr['pr_number']} has been APPROVED by Finance.";
        if ($expense_id) {
            $notif_requester_message .= " Converted to Expense #{$expense_code}.";
        }
        
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $insertNotif->execute([
            $pr['requester_id'],
            'PR Approved',
            $notif_requester_message,
            'success',
            $pr['pr_number'],
            "purchase_request.php?view=details&id=$pr_id"
        ]);
        
        // 2. NOTIFY SCM ROLES - NEW APPROVED PR NEEDS ATTENTION
        $scm_message = "New PR #{$pr['pr_number']} has been APPROVED by Finance. ";
        if ($expense_id) {
            $scm_message .= "Expense #{$expense_code} created. Please process for ordering.";
        } else {
            $scm_message .= "Ready for processing.";
        }
        
        $getSCMUsers = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role IN ('SCM')  /* SCM role lang */
            AND id != ? 
            AND id != ?  /* Hindi yung nag-approve at hindi rin yung requester */
            AND status = 'Active'
        ");
        $getSCMUsers->execute([$clinic_id, $current_user_id, $pr['requester_id']]);
        $scm_users = $getSCMUsers->fetchAll();
        
        foreach ($scm_users as $scm_user) {
            $insertNotif->execute([
                $scm_user['id'],
                'New Approved PR - Ready for Order',
                $scm_message,
                'info',  // info type para sa SCM
                $pr['pr_number'],
                "purchase_request.php?view=details&id=$pr_id"
            ]);
        }
        
        // 3. NOTIFY CLINICADMIN ROLES (except yung nag-approve at requester)
        $clinicadmin_message = "PR #{$pr['pr_number']} has been APPROVED by Finance. ";
        if ($expense_id) {
            $clinicadmin_message .= "Expense #{$expense_code} created.";
        } else {
            $clinicadmin_message .= "Ready for next step.";
        }
        
        $getClinicAdmins = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role = 'ClinicAdmin'
            AND id != ? 
            AND id != ?
            AND status = 'Active'
        ");
        $getClinicAdmins->execute([$clinic_id, $current_user_id, $pr['requester_id']]);
        $clinicadmins = $getClinicAdmins->fetchAll();
        
        foreach ($clinicadmins as $admin) {
            $insertNotif->execute([
                $admin['id'],
                'PR Approved - Finance',
                $clinicadmin_message,
                'success',
                $pr['pr_number'],
                "purchase_request.php?view=details&id=$pr_id"
            ]);
        }
        
        // 4. Optional: Notify HR kung related sa HR expenses
        if ($pr['department'] == 'HR' || $pr['department'] == 'ClinicAdmin') {
            $getHRUsers = $pdo->prepare("
                SELECT id FROM users 
                WHERE clinic_id = ? 
                AND role = 'HR'
                AND status = 'Active'
            ");
            $getHRUsers->execute([$clinic_id]);
            $hr_users = $getHRUsers->fetchAll();
            
            foreach ($hr_users as $hr_user) {
                $insertNotif->execute([
                    $hr_user['id'],
                    'PR Approved - HR Related',
                    "PR #{$pr['pr_number']} has been approved. Department: {$pr['department']}",
                    'info',
                    $pr['pr_number'],
                    "purchase_request.php?view=details&id=$pr_id"
                ]);
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request approved successfully. Notifications sent to SCM and ClinicAdmin.',
            'pr_id' => $pr_id,
            'expense_id' => $expense_id,
            'expense_code' => $expense_code ?? null,
            'suppliers' => $suppliers
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject_pr') {
    try {
        // ✅ Use expenses_approve permission for reject as well
        if (!canApproveExpense()) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to reject purchase requests']);
            exit;
        }
        
        $pr_id = $_POST['pr_id'] ?? 0;
        $rejection_reason = $_POST['rejection_reason'] ?? '';
        
        if (!$pr_id) {
            http_response_code(400);
            echo json_encode(['error' => 'PR ID is required']);
            exit;
        }
        
        if (empty($rejection_reason)) {
            http_response_code(400);
            echo json_encode(['error' => 'Rejection reason is required']);
            exit;
        }
        
        // Get PR details including requester info
        $prQuery = "SELECT pr.*, u.id as requester_id 
                    FROM purchase_requests pr
                    LEFT JOIN users u ON pr.requested_by = u.id
                    WHERE pr.id = ? AND pr.clinic_id = ?";
        $prStmt = $pdo->prepare($prQuery);
        $prStmt->execute([$pr_id, $clinic_id]);
        $pr = $prStmt->fetch();
        
        if (!$pr) {
            http_response_code(404);
            echo json_encode(['error' => 'Purchase request not found']);
            exit;
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Update PR status
        $updateQuery = "UPDATE purchase_requests SET 
                        status = 'Rejected', 
                        rejection_reason = ?,
                        updated_at = NOW() 
                        WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([$rejection_reason, $pr_id]);
        
        // ========== INSERT NOTIFICATIONS FOR REJECTION ==========
        
        // 1. Notify the requester (yung gumawa ng PR)
        $insertNotif = $pdo->prepare("
            INSERT INTO notifications 
            (user_id, title, message, type, reference_number, link, created_at)
            VALUES (?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $requester_message = "Your PR {$pr['pr_number']} has been REJECTED. Reason: $rejection_reason";
        
        $insertNotif->execute([
            $pr['requester_id'],
            'PR Rejected',
            $requester_message,
            'danger',  // danger type for rejection
            $pr['pr_number'],
            "purchase_request.php?view=details&id=$pr_id"
        ]);
        
        // 2. Notify ClinicAdmin about the rejection (for awareness)
        $getClinicAdmins = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role = 'ClinicAdmin'
            AND id != ?
            AND status = 'Active'
        ");
        $getClinicAdmins->execute([$clinic_id, $current_user_id]);
        $clinicadmins = $getClinicAdmins->fetchAll();
        
        $admin_message = "PR #{$pr['pr_number']} has been REJECTED by Finance. Reason: $rejection_reason";
        
        foreach ($clinicadmins as $admin) {
            $insertNotif->execute([
                $admin['id'],
                'PR Rejected - Finance',
                $admin_message,
                'warning',
                $pr['pr_number'],
                "purchase_request.php?view=details&id=$pr_id"
            ]);
        }
        
        // 3. Notify SCM (if needed for awareness)
        $getSCMUsers = $pdo->prepare("
            SELECT id FROM users 
            WHERE clinic_id = ? 
            AND role = 'SCM'
            AND status = 'Active'
        ");
        $getSCMUsers->execute([$clinic_id]);
        $scm_users = $getSCMUsers->fetchAll();
        
        $scm_message = "PR #{$pr['pr_number']} has been REJECTED by Finance. Reason: $rejection_reason";
        
        foreach ($scm_users as $scm_user) {
            $insertNotif->execute([
                $scm_user['id'],
                'PR Rejected - Finance',
                $scm_message,
                'warning',
                $pr['pr_number'],
                "purchase_request.php?view=details&id=$pr_id"
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Purchase request rejected successfully. Notifications sent.',
            'pr_id' => $pr_id
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_expense') {
    // ✅ Use create permission
    if (!canCreateExpense()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to create expenses']);
        exit;
    }
    try {
        // Validate required fields
        $required_fields = ['description', 'category', 'amount', 'expense_date'];
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Field '$field' is required"]);
                exit;
            }
        }
        
        // Generate expense code
        $year = date('Y');
        $month = date('m');
        
        // Get next sequence number
        $seqQuery = "SELECT COUNT(*) + 1 as next_num FROM expenses WHERE YEAR(created_at) = ? AND MONTH(created_at) = ?";
        $seqStmt = $pdo->prepare($seqQuery);
        $seqStmt->execute([$year, $month]);
        $seq = $seqStmt->fetch(PDO::FETCH_ASSOC);
        $next_num = str_pad($seq['next_num'] ?? 1, 4, '0', STR_PAD_LEFT);
        
        $expense_code = 'EXP-' . $year . $month . '-' . $next_num;
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Insert expense
        $query = "INSERT INTO expenses (
            expense_code, description, category, amount, expense_date, due_date,
            vendor, department, requested_by, requested_by_name, status,
            notes, has_receipt, clinic_id, created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $expense_code,
            $_POST['description'],
            $_POST['category'],
            $_POST['amount'],
            $_POST['expense_date'],
            $_POST['due_date'] ?? date('Y-m-d', strtotime('+15 days', strtotime($_POST['expense_date']))),
            $_POST['vendor'] ?? '',
            $_POST['department'] ?? '',
            $current_user_id,
            $user_name,
            $_POST['status'] ?? 'Pending',
            $_POST['notes'] ?? '',
            isset($_POST['has_receipt']) ? 1 : 0,
            $clinic_id,
            $current_user_id
        ]);
        
        $expense_id = $pdo->lastInsertId();
        
        // Log activity
        $logQuery = "INSERT INTO expense_activity_log (expense_id, action, user_id, user_name, details, ip_address) 
                     VALUES (?, 'CREATED', ?, ?, ?, ?)";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([
            $expense_id,
            $current_user_id,
            $user_name,
            "Created expense: $expense_code - ₱" . number_format($_POST['amount'], 2),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Expense created successfully',
            'expense_id' => $expense_id,
            'expense_code' => $expense_code
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_expense') {
    // ✅ Add permission check
    if (!canEditExpense()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to edit expenses']);
        exit;
    }
    try {
        $expense_id = $_POST['id'] ?? 0;
        
        if (!$expense_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Expense ID is required']);
            exit;
        }
        
        // Check if expense exists and user has permission
        $checkQuery = "SELECT * FROM expenses WHERE id = ? AND clinic_id = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$expense_id, $clinic_id]);
        $expense = $checkStmt->fetch();
        
        if (!$expense) {
            http_response_code(404);
            echo json_encode(['error' => 'Expense not found']);
            exit;
        }
        
        // Only allow edit if status is Pending or user is Admin/Finance
        if ($expense['status'] !== 'Pending' && $user_role !== 'ClinicAdmin' && $user_role !== 'Finance') {
            http_response_code(403);
            echo json_encode(['error' => 'Cannot edit approved or paid expenses']);
            exit;
        }
        
        // Build update query dynamically
        $updates = [];
        $params = [];
        
        $editable_fields = [
            'description', 'category', 'amount', 'expense_date', 'due_date',
            'vendor', 'department', 'notes', 'status'
        ];
        
        foreach ($editable_fields as $field) {
            if (isset($_POST[$field])) {
                $updates[] = "$field = ?";
                $params[] = $_POST[$field];
            }
        }
        
        if (empty($updates)) {
            http_response_code(400);
            echo json_encode(['error' => 'No fields to update']);
            exit;
        }
        
        $params[] = $expense_id;
        $params[] = $clinic_id;
        
        $query = "UPDATE expenses SET " . implode(', ', $updates) . " WHERE id = ? AND clinic_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        
        echo json_encode([
            'success' => true,
            'message' => 'Expense updated successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_expense') {
    // ✅ Add permission check
    if (!canDeleteExpense()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to delete expenses']);
        exit;
    }
    try {
        $expense_id = $_POST['id'] ?? 0;
        
        if (!$expense_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Expense ID is required']);
            exit;
        }
        
        // Check if expense exists and is deletable
        $checkQuery = "SELECT status FROM expenses WHERE id = ? AND clinic_id = ?";
        $checkStmt = $pdo->prepare($checkQuery);
        $checkStmt->execute([$expense_id, $clinic_id]);
        $expense = $checkStmt->fetch();
        
        if (!$expense) {
            http_response_code(404);
            echo json_encode(['error' => 'Expense not found']);
            exit;
        }
        
        if ($expense['status'] === 'Paid' || $expense['status'] === 'Approved') {
            http_response_code(400);
            echo json_encode(['error' => 'Cannot delete approved or paid expenses']);
            exit;
        }
        
        // Soft delete or hard delete? Let's do hard delete for now
        $deleteQuery = "DELETE FROM expenses WHERE id = ? AND clinic_id = ?";
        $deleteStmt = $pdo->prepare($deleteQuery);
        $deleteStmt->execute([$expense_id, $clinic_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Expense deleted successfully'
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'approve_expense') {
    try {
        // ✅ Use approve permission
        if (!canApproveExpense()) {
            http_response_code(403);
            echo json_encode(['error' => 'You do not have permission to approve expenses']);
            exit;
        }
        
        $expense_id = $_POST['id'] ?? 0;
        $payment_method = $_POST['payment_method'] ?? 'Bank Transfer';
        $payment_reference = $_POST['payment_reference'] ?? '';
        $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
        $remarks = $_POST['remarks'] ?? '';
        
        if (!$expense_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Expense ID is required']);
            exit;
        }
        
        // Validate required fields based on payment method
        $paymongo_methods = ['GCash', 'ATM', 'PayMongo'];
        if (!in_array($payment_method, $paymongo_methods) && empty($payment_reference)) {
            http_response_code(400);
            echo json_encode(['error' => 'Reference number is required for this payment method']);
            exit;
        }
        
        // Get expense details
        $expenseQuery = "SELECT * FROM expenses WHERE id = ? AND clinic_id = ?";
        $expenseStmt = $pdo->prepare($expenseQuery);
        $expenseStmt->execute([$expense_id, $clinic_id]);
        $expense = $expenseStmt->fetch();
        
        if (!$expense) {
            http_response_code(404);
            echo json_encode(['error' => 'Expense not found']);
            exit;
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // ========== HANDLE PROOF FILE UPLOAD ==========
        $uploaded_file_path = null;
        if (isset($_FILES['proof_file']) && $_FILES['proof_file']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/payment_proofs/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            
            $file = $_FILES['proof_file'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
            $max_size = 10 * 1024 * 1024; // 10MB
            
            if (!in_array($file['type'], $allowed_types)) {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid file type. Allowed: JPG, PNG, PDF']);
                exit;
            }
            
            if ($file['size'] > $max_size) {
                http_response_code(400);
                echo json_encode(['error' => 'File too large. Max size: 10MB']);
                exit;
            }
            
            $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'proof_' . $expense_id . '_' . time() . '.' . $extension;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $uploaded_file_path = 'uploads/payment_proofs/' . $filename;
            }
        }
        
        // ========== UPDATE EXPENSE ==========
        $updateQuery = "UPDATE expenses SET 
                        status = 'Paid',
                        approved_by = ?,
                        approved_by_name = ?,
                        approved_at = NOW(),
                        payment_date = ?,
                        payment_method = ?,
                        payment_reference = ?,
                        payment_remarks = ?,
                        payment_proof = COALESCE(?, payment_proof),
                        updated_at = NOW()
                        WHERE id = ?";
        $updateStmt = $pdo->prepare($updateQuery);
        $updateStmt->execute([
            $current_user_id,
            $user_name,
            $payment_date,
            $payment_method,
            $payment_reference,
            $remarks,
            $uploaded_file_path,
            $expense_id
        ]);
        
        // ========== UPDATE BUDGET ==========
        // Get category ID
        $catStmt = $pdo->prepare("SELECT id FROM expense_categories WHERE category_name = ?");
        $catStmt->execute([$expense['category']]);
        $category_id = $catStmt->fetchColumn();
        
        if ($category_id) {
            $year = date('Y');
            $month = date('m');
            
            // Check if budget plan exists
            $checkBudget = $pdo->prepare("
                SELECT id, spent_amount FROM budget_plans 
                WHERE clinic_id = ? AND year = ? AND month = ? AND category_id = ?
            ");
            $checkBudget->execute([$clinic_id, $year, $month, $category_id]);
            $budget = $checkBudget->fetch();
            
            if ($budget) {
                // Update existing budget
                $budgetQuery = "UPDATE budget_plans SET 
                                spent_amount = spent_amount + ?,
                                remaining_amount = allocated_amount - (spent_amount + ?),
                                updated_at = NOW()
                                WHERE id = ?";
                $budgetStmt = $pdo->prepare($budgetQuery);
                $budgetStmt->execute([$expense['amount'], $expense['amount'], $budget['id']]);
            } else {
                // Create new budget entry if doesn't exist
                $budgetQuery = "INSERT INTO budget_plans (clinic_id, year, month, category_id, allocated_amount, spent_amount, remaining_amount, created_at) 
                                VALUES (?, ?, ?, ?, 0, ?, ?, NOW())";
                $budgetStmt = $pdo->prepare($budgetQuery);
                $budgetStmt->execute([
                    $clinic_id, $year, $month, $category_id,
                    $expense['amount'], -$expense['amount']
                ]);
            }
        }
        
        // ========== LOG ACTIVITY ==========
        $logQuery = "INSERT INTO expense_activity_log (expense_id, action, user_id, user_name, details, ip_address, created_at) 
                     VALUES (?, 'APPROVED_PAID', ?, ?, ?, ?, NOW())";
        $logStmt = $pdo->prepare($logQuery);
        $logStmt->execute([
            $expense_id,
            $current_user_id,
            $user_name,
            "Approved and paid expense: {$expense['expense_code']} - ₱" . number_format($expense['amount'], 2) . 
            " | Method: $payment_method" . ($payment_reference ? " | Ref: $payment_reference" : "") .
            ($uploaded_file_path ? " | Proof uploaded" : ""),
            $_SERVER['REMOTE_ADDR'] ?? ''
        ]);
        
        // ========== SEND NOTIFICATION ==========
        // Notify requester that expense is paid
        if ($expense['requested_by']) {
            $notifStmt = $pdo->prepare("
                INSERT INTO notifications (user_id, title, message, type, reference_number, link, created_at)
                VALUES (?, 'Expense Paid', ?, 'success', ?, ?, NOW())
            ");
            $notifMessage = "Expense {$expense['expense_code']} has been PAID. Amount: ₱" . number_format($expense['amount'], 2);
            $notifStmt->execute([
                $expense['requested_by'],
                $notifMessage,
                $expense['expense_code'],
                "expenses.php?view=details&id=$expense_id"
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Expense approved and marked as paid',
            'expense_id' => $expense_id,
            'expense_code' => $expense['expense_code'],
            'amount' => $expense['amount'],
            'payment_method' => $payment_method,
            'payment_reference' => $payment_reference,
            'proof_uploaded' => !empty($uploaded_file_path)
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// UPLOAD RECEIPT
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'upload_receipt') {
    try {
        $expense_id = $_POST['expense_id'] ?? 0;
        
        if (!$expense_id) {
            http_response_code(400);
            echo json_encode(['error' => 'Expense ID is required']);
            exit;
        }
        
        if (!isset($_FILES['receipt']) || $_FILES['receipt']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['error' => 'No file uploaded or upload error']);
            exit;
        }
        
        $file = $_FILES['receipt'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'application/pdf'];
        $max_size = 10 * 1024 * 1024; // 10MB
        
        if (!in_array($file['type'], $allowed_types)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid file type. Allowed: JPG, PNG, PDF']);
            exit;
        }
        
        if ($file['size'] > $max_size) {
            http_response_code(400);
            echo json_encode(['error' => 'File too large. Max size: 10MB']);
            exit;
        }
        
        // Create upload directory if not exists
        $upload_dir = __DIR__ . '/../uploads/receipts/';
        if (!file_exists($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }
        
        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'receipt_' . $expense_id . '_' . time() . '.' . $extension;
        $filepath = $upload_dir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $filepath)) {
            // Save to database
            $query = "INSERT INTO expense_attachments (expense_id, file_name, file_path, file_type, file_size, uploaded_by) 
                      VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $expense_id,
                $file['name'],
                'uploads/receipts/' . $filename,
                $file['type'],
                $file['size'],
                $current_user_id
            ]);
            
            // Update has_receipt flag
            $updateQuery = "UPDATE expenses SET has_receipt = TRUE WHERE id = ?";
            $updateStmt = $pdo->prepare($updateQuery);
            $updateStmt->execute([$expense_id]);
            
            echo json_encode([
                'success' => true,
                'message' => 'Receipt uploaded successfully',
                'file_path' => 'uploads/receipts/' . $filename
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save file']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Upload error: ' . $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_pending_prs') {
    // ✅ Use expenses_view to view pending PRs (read-only)
    if (!canViewExpenses()) {
        echo json_encode([
            'success' => false,
            'error' => 'You do not have permission to view pending purchase requests'
        ]);
        exit;
    }
    
    try {
        $query = "SELECT pr.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
                         (SELECT COUNT(*) FROM pr_items WHERE pr_id = pr.id) as item_count
                  FROM purchase_requests pr
                  LEFT JOIN users u ON pr.requested_by = u.id
                  WHERE pr.status = 'Pending Approval' 
                    AND pr.clinic_id = ?
                  ORDER BY 
                    CASE pr.priority 
                        WHEN 'Critical' THEN 1
                        WHEN 'High' THEN 2
                        WHEN 'Medium' THEN 3
                        WHEN 'Low' THEN 4
                    END,
                    pr.created_at ASC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$clinic_id]);
        $prs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $prs
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_expenses') {
    // ✅ Add permission check
    if (!canViewExpenses()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view expenses']);
        exit;
    }
    try {
        // ========== PARAMETERS ==========
        $page = $_GET['page'] ?? 1;
        $limit = $_GET['limit'] ?? 10;
        $status = $_GET['status'] ?? 'all';
        $category = $_GET['category'] ?? 'all';
        $department = $_GET['department'] ?? 'all';
        $search = $_GET['search'] ?? '';
        $month = $_GET['month'] ?? date('Y-m');
        $tab = $_GET['tab'] ?? 'all';
        $today = date('Y-m-d'); // For overdue calculation
        
        $offset = ($page - 1) * $limit;
        
        // Parse month
        $year = substr($month, 0, 4);
        $month_num = substr($month, 5, 2);
        
        // ========== BASE QUERIES ==========
        $query = "SELECT e.*, 
                        ec.color_code,
                        ec.icon,
                        (SELECT COUNT(*) FROM expense_attachments WHERE expense_id = e.id) as attachment_count,
                        (SELECT COUNT(*) FROM expense_items WHERE expense_id = e.id) as item_count,
                        (SELECT GROUP_CONCAT(DISTINCT supplier_name SEPARATOR ', ') 
                        FROM expense_items 
                        WHERE expense_id = e.id AND supplier_name IS NOT NULL) as suppliers_list
                FROM expenses e
                LEFT JOIN expense_categories ec ON e.category = ec.category_name
                WHERE e.clinic_id = ?";
                
        $countQuery = "SELECT COUNT(*) as total FROM expenses e WHERE e.clinic_id = ?";
        
        // Initialize conditions and parameters
        $conditions = [];
        $params = [$clinic_id];
        $countParams = [$clinic_id];
        
        // ========== MONTH FILTER (ALWAYS APPLIED) ==========
        $conditions[] = "YEAR(e.expense_date) = ? AND MONTH(e.expense_date) = ?";
        $params[] = $year;
        $params[] = $month_num;
        $countParams[] = $year;
        $countParams[] = $month_num;

// ========== TAB FILTER (MAIN FILTER) ==========
if ($tab !== 'all') {
    switch($tab) {
        case 'pending_approval':
        case 'pending':
            // ✅ DAPAT: Isama ang 'Return Requested' sa pending tab
            $conditions[] = "e.status IN ('Approved', 'Waiting For Delivery', 'Ready to Pay', 'Return Requested')";
            break;
            
        case 'paid':
            // ✅ DAPAT: Isama ang 'Return Completed' sa paid tab
            $conditions[] = "e.status IN ('Paid', 'Return Completed')";
            break;
            
        case 'overdue':
            $conditions[] = "e.due_date < ? AND e.status NOT IN ('Paid', 'Cancelled', 'Return Completed')";
            $params[] = $today;
            $countParams[] = $today;
            break;
            
        case 'returns':  // ✅ BAGONG TAB PARA SA RETURNS LANG
            $conditions[] = "e.status IN ('Return Requested', 'Return Completed')";
            break;
            
        case 'pr_for_approval':
            // Handled separately
            break;
    }
} else {
    // 'ALL' TAB - DEFAULT TO PENDING (including returns)
    $conditions[] = "e.status IN ('Pending', 'Return Requested')";
    
    // Override with status filter if provided
    if ($status !== 'all') {
        array_pop($conditions);
        $conditions[] = "e.status = ?";
        $params[] = $status;
        $countParams[] = $status;
    }
}

        // ========== ADDITIONAL FILTERS ==========
        if ($category !== 'all') {
            $conditions[] = "e.category = ?";
            $params[] = $category;
            $countParams[] = $category;
        }
        
        if ($department !== 'all') {
            $conditions[] = "e.department = ?";
            $params[] = $department;
            $countParams[] = $department;
        }
        
        if (!empty($search)) {
            $conditions[] = "(e.description LIKE ? OR e.expense_code LIKE ? OR e.vendor LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $countParams[] = $searchTerm;
            $countParams[] = $searchTerm;
            $countParams[] = $searchTerm;
        }
        
        // ========== BUILD WHERE CLAUSE ==========
        if (!empty($conditions)) {
            $whereClause = " AND " . implode(" AND ", $conditions);
            $query .= $whereClause;
            $countQuery .= $whereClause;
        }
        
// ========== ADD ORDER BY ==========
$query .= " ORDER BY 
            CASE 
                WHEN e.status = 'Return Requested' THEN 1
                WHEN e.due_date < ? AND e.status NOT IN ('Paid', 'Cancelled', 'Return Completed') THEN 2
                WHEN e.status = 'Pending' THEN 3
                WHEN e.status = 'Ready to Pay' THEN 4
                WHEN e.status = 'Approved' THEN 5
                WHEN e.status = 'Return Completed' THEN 6
                WHEN e.status = 'Paid' THEN 7
                ELSE 8
            END,
            e.due_date ASC,
            e.expense_date DESC 
            LIMIT ? OFFSET ?";
        
        $params[] = $today; // For overdue ordering
        $params[] = (int)$limit;
        $params[] = (int)$offset;
        
        // ========== EXECUTE MAIN QUERY ==========
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // ========== GET TOTAL COUNT ==========
        $countStmt = $pdo->prepare($countQuery);
        $countStmt->execute($countParams);
        $totalResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $total = $totalResult['total'] ?? 0;
        
// ========== GET MONTHLY STATS ==========
$statsQuery = "SELECT 
                SUM(CASE WHEN status = 'Paid' THEN amount ELSE 0 END) as total_paid,
                SUM(CASE WHEN status = 'Pending' THEN amount ELSE 0 END) as total_pending,
                SUM(CASE WHEN status = 'Return Requested' THEN amount ELSE 0 END) as total_return_requested,
                SUM(CASE WHEN status = 'Return Completed' THEN amount ELSE 0 END) as total_return_completed,
                SUM(CASE WHEN due_date < ? AND status NOT IN ('Paid', 'Cancelled', 'Return Completed') THEN amount ELSE 0 END) as total_overdue,
                SUM(CASE WHEN status IN ('Approved', 'Waiting For Delivery', 'Ready to Pay', 'Return Requested') THEN amount ELSE 0 END) as total_for_approval,
                COUNT(*) as total_count,
                SUM(amount) as total_amount
               FROM expenses e
               WHERE e.clinic_id = ? 
                 AND YEAR(e.expense_date) = ? 
                 AND MONTH(e.expense_date) = ?";
        
        $statsStmt = $pdo->prepare($statsQuery);
        $statsStmt->execute([$today, $clinic_id, $year, $month_num]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);
        
        // ========== GET BUDGET UTILIZATION ==========
        $budgetQuery = "SELECT 
                         SUM(allocated_amount) as total_budget,
                         SUM(spent_amount) as total_spent,
                         (SUM(spent_amount) / NULLIF(SUM(allocated_amount), 0)) * 100 as utilization
                        FROM budget_plans
                        WHERE clinic_id = ? AND year = ? AND month = ?";
        
        $budgetStmt = $pdo->prepare($budgetQuery);
        $budgetStmt->execute([$clinic_id, $year, $month_num]);
        $budget = $budgetStmt->fetch(PDO::FETCH_ASSOC);
        
        // ========== RETURN RESPONSE ==========
        echo json_encode([
            'success' => true,
            'data' => $expenses,
            'stats' => [
                'total_month' => $stats['total_amount'] ?? 0,
                'total_paid' => $stats['total_paid'] ?? 0,
                'total_pending' => $stats['total_pending'] ?? 0,
                'total_overdue' => $stats['total_overdue'] ?? 0,
                'total_for_approval' => $stats['total_for_approval'] ?? 0,
                'total_count' => $stats['total_count'] ?? 0,
                'budget_allocated' => $budget['total_budget'] ?? 0,
                'budget_spent' => $budget['total_spent'] ?? 0,
                'budget_utilization' => round($budget['utilization'] ?? 0, 2)
            ],
            'pagination' => [
                'total' => (int)$total,
                'page' => (int)$page,
                'pages' => ceil($total / $limit),
                'limit' => (int)$limit
            ],
            'as_of' => $today
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Database error: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_expense') {
    try {
        $id = $_GET['id'] ?? 0;
        
        $query = "SELECT e.*, 
                         ec.color_code, ec.icon,
                         (SELECT COUNT(*) FROM expense_attachments WHERE expense_id = e.id) as attachment_count
                  FROM expenses e
                  LEFT JOIN expense_categories ec ON e.category = ec.category_name
                  WHERE e.id = ? AND e.clinic_id = ?";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$id, $clinic_id]);
        $expense = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($expense) {
            // Get expense items with supplier details
            $itemsQuery = "SELECT * FROM expense_items WHERE expense_id = ?";
            $itemsStmt = $pdo->prepare($itemsQuery);
            $itemsStmt->execute([$id]);
            $expense['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // ===== GET SUPPLIER DETAILS =====
            $suppliers = [];
            foreach ($expense['items'] as $item) {
                if ($item['supplier_id']) {
                    $supplier_id = $item['supplier_id'];
                    if (!isset($suppliers[$supplier_id])) {
                        // Get full supplier details
                        $supplierQuery = "SELECT id, supplier_name, contact_person, email, mobile, phone, address 
                                         FROM suppliers WHERE id = ?";
                        $supplierStmt = $pdo->prepare($supplierQuery);
                        $supplierStmt->execute([$supplier_id]);
                        $supplier = $supplierStmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($supplier) {
                            $suppliers[$supplier_id] = [
                                'id' => $supplier_id,
                                'name' => $supplier['supplier_name'],
                                'contact' => $supplier['contact_person'],
                                'email' => $supplier['email'],
                                'mobile' => $supplier['mobile'],
                                'phone' => $supplier['phone'],
                                'address' => $supplier['address'],
                                'items' => [],
                                'total' => 0
                            ];
                        }
                    }
                    if (isset($suppliers[$supplier_id])) {
                        $suppliers[$supplier_id]['items'][] = $item['item_name'];
                        $suppliers[$supplier_id]['total'] += $item['total_price'];
                    }
                }
            }
            
            // Parse supplier JSON if exists
            if (!empty($expense['supplier_json'])) {
                $expense['suppliers'] = json_decode($expense['supplier_json'], true);
            } else {
                $expense['suppliers'] = array_values($suppliers);
            }
            
            // Get attachments
            $attachmentsQuery = "SELECT * FROM expense_attachments WHERE expense_id = ?";
            $attachmentsStmt = $pdo->prepare($attachmentsQuery);
            $attachmentsStmt->execute([$id]);
            $expense['attachments'] = $attachmentsStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get activity log
            $logQuery = "SELECT * FROM expense_activity_log WHERE expense_id = ? ORDER BY created_at DESC LIMIT 10";
            $logStmt = $pdo->prepare($logQuery);
            $logStmt->execute([$id]);
            $expense['activity_log'] = $logStmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'data' => $expense]);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Expense not found']);
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}



// GET EXPENSE CATEGORIES
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_categories') {
    try {
        $query = "SELECT * FROM expense_categories WHERE is_active = TRUE ORDER BY category_name";
        $stmt = $pdo->query($query);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'data' => $categories]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// GET DEPARTMENTS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_departments') {
    try {
        $query = "SELECT DISTINCT department FROM expenses WHERE clinic_id = ? AND department IS NOT NULL AND department != '' ORDER BY department";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$clinic_id]);
        $departments = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Add default departments if none
        if (empty($departments)) {
            $departments = ['Administration', 'Human Resources', 'Finance', 'Optometry', 'Inventory', 'IT', 'Marketing', 'Sales'];
        }
        
        echo json_encode(['success' => true, 'data' => $departments]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ==================== BUDGET REQUESTS ====================

// GET BUDGET - FIXED VERSION
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_budget') {
    try {
        $month = $_GET['month'] ?? date('Y-m');
        $year = (int)substr($month, 0, 4);
        $month_num = (int)substr($month, 5, 2);
        
        $query = "SELECT 
                    c.category_name as category,
                    COALESCE(b.allocated_amount, 0) as allocated,
                    COALESCE((
                        SELECT SUM(amount) 
                        FROM expenses 
                        WHERE category = c.category_name 
                        AND status = 'Paid'
                        AND YEAR(expense_date) = ? 
                        AND MONTH(expense_date) = ?
                        AND clinic_id = ?
                    ), 0) as spent,
                    COALESCE(b.allocated_amount, 0) - COALESCE((
                        SELECT SUM(amount) 
                        FROM expenses 
                        WHERE category = c.category_name 
                        AND status = 'Paid'
                        AND YEAR(expense_date) = ? 
                        AND MONTH(expense_date) = ?
                        AND clinic_id = ?
                    ), 0) as remaining
                  FROM expense_categories c
                  LEFT JOIN budget_plans b ON b.category_id = c.id 
                      AND b.year = ? AND b.month = ? AND b.clinic_id = ?
                  -- REMOVED: WHERE c.clinic_id = ? OR c.clinic_id IS NULL
                  -- Dahil walang clinic_id sa expense_categories
                  GROUP BY c.id, c.category_name, b.allocated_amount
                  ORDER BY c.category_name";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([
            $year, $month_num, $clinic_id,  // for spent subquery
            $year, $month_num, $clinic_id,  // for remaining subquery
            $year, $month_num, $clinic_id   // for budget join
        ]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'data' => $data
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// SAVE BUDGET
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_budget') {
    try {
        $month = $_POST['month'];
        $year = (int)substr($month, 0, 4);
        $month_num = (int)substr($month, 5, 2);
        $budgets = json_decode($_POST['budgets'], true);
        
        $pdo->beginTransaction();
        
        foreach($budgets as $b) {
            if ($b['amount'] > 0) {
                // Get category ID
                $catStmt = $pdo->prepare("SELECT id FROM expense_categories WHERE category_name = ?");
                $catStmt->execute([$b['category']]);
                $catId = $catStmt->fetchColumn();
                
                if ($catId) {
                    // Check if exists
                    $checkStmt = $pdo->prepare("SELECT id FROM budget_plans WHERE clinic_id = ? AND year = ? AND month = ? AND category_id = ?");
                    $checkStmt->execute([$clinic_id, $year, $month_num, $catId]);
                    $existing = $checkStmt->fetchColumn();
                    
                    if ($existing) {
                        // Update
                        $updateStmt = $pdo->prepare("UPDATE budget_plans SET allocated_amount = ? WHERE id = ?");
                        $updateStmt->execute([$b['amount'], $existing]);
                    } else {
                        // Insert
                        $insertStmt = $pdo->prepare("INSERT INTO budget_plans (clinic_id, year, month, category_id, allocated_amount) VALUES (?, ?, ?, ?, ?)");
                        $insertStmt->execute([$clinic_id, $year, $month_num, $catId, $b['amount']]);
                    }
                }
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Budget saved successfully'
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
    exit;
}

// EXPORT EXPENSES
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'export') {
    // ✅ Add permission check
    if (!canExportExpense()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to export expenses']);
        exit;
    }
    try {
        $month = $_GET['month'] ?? date('Y-m');
        $year = substr($month, 0, 4);
        $month_num = substr($month, 5, 2);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="expenses_' . $month . '_' . date('Ymd') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, [
            'Expense Code', 'Description', 'Category', 'Amount', 'Expense Date',
            'Due Date', 'Payment Date', 'Vendor', 'Department', 'Status',
            'Payment Method', 'Payment Reference', 'Requested By', 'Notes'
        ]);
        
        $query = "SELECT e.* FROM expenses e 
                  WHERE e.clinic_id = ? 
                    AND YEAR(e.expense_date) = ? 
                    AND MONTH(e.expense_date) = ?
                  ORDER BY e.expense_date DESC";
        $stmt = $pdo->prepare($query);
        $stmt->execute([$clinic_id, $year, $month_num]);
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, [
                $row['expense_code'],
                $row['description'],
                $row['category'],
                '₱' . number_format($row['amount'], 2),
                $row['expense_date'],
                $row['due_date'] ?? '',
                $row['payment_date'] ?? '',
                $row['vendor'] ?? '',
                $row['department'] ?? '',
                $row['status'],
                $row['payment_method'] ?? '',
                $row['payment_reference'] ?? '',
                $row['requested_by_name'] ?? '',
                $row['notes'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// GET PERMISSIONS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_permissions') {
    echo json_encode([
        'success' => true,
        'data' => [
            'role' => $user_role,
            'permissions' => [
                'can_view' => canViewExpenses(),
                'can_create' => canCreateExpense(),
                'can_edit' => canEditExpense(),
                'can_delete' => canDeleteExpense(),
                'can_approve' => canApproveExpense(),
                'can_reject' => canRejectExpense(),
                'can_export' => canExportExpense(),
                'can_approve_pr' => canApproveExpense()  // ← PR approve uses expenses_approve
            ]
        ]
    ]);
    exit;
}

// GET PURCHASE REQUEST DETAILS FOR EXPENSES MODULE
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_pr_details') {
    // ✅ Check if user has permission to view PRs
    if (!canViewExpenses() && !canApprovePR()) {
        http_response_code(403);
        echo json_encode(['error' => 'You do not have permission to view purchase request details']);
        exit;
    }
    
    $pr_id = $_GET['id'] ?? 0;
    
    if (!$pr_id) {
        echo json_encode(['error' => 'PR ID is required']);
        exit;
    }
    
    try {
        // Get PR details
        $query = "SELECT pr.*, 
                         CONCAT(u.first_name, ' ', u.last_name) as requested_by_name,
                         CONCAT(ua.first_name, ' ', ua.last_name) as approved_by_name
                  FROM purchase_requests pr
                  LEFT JOIN users u ON pr.requested_by = u.id
                  LEFT JOIN users ua ON pr.approved_by = ua.id
                  WHERE pr.id = ? AND pr.clinic_id = ?";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$pr_id, $clinic_id]);
        $pr = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pr) {
            echo json_encode(['error' => 'Purchase request not found']);
            exit;
        }
        
        // Get items with supplier details
        $itemsQuery = "SELECT i.*, 
                              s.id as supplier_id,
                              s.supplier_name,
                              s.contact_person as supplier_contact,
                              s.email as supplier_email,
                              s.mobile as supplier_mobile,
                              s.phone as supplier_phone,
                              s.payment_terms as supplier_payment_terms
                       FROM pr_items i
                       LEFT JOIN suppliers s ON i.supplier_id = s.id
                       WHERE i.pr_id = ?";
        
        $itemsStmt = $pdo->prepare($itemsQuery);
        $itemsStmt->execute([$pr_id]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        $pr['items'] = $items;
        
        echo json_encode([
            'success' => true,
            'data' => $pr
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// Helper function to get user name
function getUserName($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) as full_name FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $result = $stmt->fetch();
    return $result ? $result['full_name'] : 'Unknown User';
}

// Default response
http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
?>