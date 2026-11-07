<?php
// api/supplier_action.php
// SuperAdmin — Supplier Actions API

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Default JSON header
header('Content-Type: application/json');

// ============================================
// AUTH CHECK
// ============================================
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/mailer.php';

global $pdo;

$action = $_REQUEST['action'] ?? '';
$csrfToken = $_REQUEST['csrf_token'] ?? '';

// ============================================
// CSRF CHECK (except for view_json)
// ============================================
if ($action !== 'view_json') {
    if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'] ?? '', $csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit;
    }
}

$adminId = $_SESSION['user_db_id'] ?? $_SESSION['user_id'] ?? 0;

// ============================================
// HELPER: Log activity
// ============================================
function logActivity($pdo, $adminId, $action, $description) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO activity_logs 
            (user_id, action, module, description, created_at) 
            VALUES (?, ?, 'supplier_approvals', ?, NOW())
        ");
        $stmt->execute([$adminId, $action, $description]);
    } catch (Exception $e) {
        error_log("Activity log failed: " . $e->getMessage());
    }
}

// ============================================
// ACTION: VIEW SUPPLIER DETAILS (JSON FORMAT)
// ============================================
if ($action === 'view_json') {
    header('Content-Type: application/json');
    
    $id = (int)($_GET['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid supplier ID.']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT s.*, u.email AS user_email, u.status AS user_status, 
                   u.email_verified_at, u.created_at AS user_created_at
            FROM suppliers s
            LEFT JOIN users u ON u.id = s.created_by
            WHERE s.id = ?
            LIMIT 1
        ");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) {
            echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
            exit;
        }
        
        // Fetch documents
        $docStmt = $pdo->prepare("SELECT * FROM supplier_documents WHERE supplier_id = ? ORDER BY upload_date DESC");
        $docStmt->execute([$id]);
        $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format documents for frontend
        $docLabels = [
            'business_permit'      => 'Business Permit',
            'dti_sec_registration' => 'DTI/SEC Registration',
            'bir_certificate'      => 'BIR Certificate',
            'valid_id'             => 'Valid ID',
            'company_logo'         => 'Company Logo',
            'philgeps'             => 'PhilGEPS',
            'fda_license'          => 'FDA License'
        ];

        $formattedDocs = [];
        foreach ($documents as $doc) {
            // Normalize status to match JS logic (Approved -> Verified, Rejected -> Rejected, Pending -> Pending)
            $rawStatus = $doc['status'] ?? 'Pending';
            $normStatus = 'Pending';
            if ($rawStatus === 'Approved' || $rawStatus === 'Verified') $normStatus = 'Verified';
            elseif ($rawStatus === 'Rejected') $normStatus = 'Rejected';

            $label = $docLabels[$doc['document_type']] ?? ucwords(str_replace('_', ' ', $doc['document_type']));
            
            $formattedDocs[] = [
                'id' => (int)$doc['id'],
                'document_name' => $label,
                'original_name' => $doc['original_name'] ?? $doc['file_name'] ?? '',
                'file_path' => $doc['file_path'],
                'mime_type' => $doc['mime_type'],
                'status' => $normStatus,
                'upload_date' => $doc['upload_date'] ?? $doc['created_at'] ?? date('Y-m-d H:i:s'),
                'rejection_reason' => $doc['rejection_reason'] ?? null,
                // If API provides these, they will be displayed, otherwise 'N/A'
                'release_date' => $doc['release_date'] ?? null,
                'expiry_date' => $doc['expiry_date'] ?? null,
            ];
        }

        // Return JSON
        echo json_encode([
            'success' => true,
            'supplier' => $supplier,
            'documents' => $formattedDocs
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        exit;
    }
}

// ============================================
// ACTION: APPROVE DOCUMENT
// ============================================
if ($action === 'approve_document') {
    $docId = (int)($_POST['doc_id'] ?? 0);
    
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE supplier_documents 
            SET status = 'Approved', reviewed_by = ?, reviewed_at = NOW(), rejection_reason = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$adminId, $docId]);
        
        logActivity($pdo, $adminId, 'DOCUMENT_APPROVED', "Document ID {$docId} approved");
        
        echo json_encode(['success' => true, 'message' => 'Document approved.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: DECLINE DOCUMENT
// ============================================
if ($action === 'decline_document') {
    $docId = (int)($_POST['doc_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    
    if ($docId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid document ID.']);
        exit;
    }
    
    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Reason is required.']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("
            UPDATE supplier_documents 
            SET status = 'Rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$reason, $adminId, $docId]);
        
        logActivity($pdo, $adminId, 'DOCUMENT_DECLINED', "Document ID {$docId} declined — {$reason}");
        
        echo json_encode(['success' => true, 'message' => 'Document declined.']);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: APPROVE SUPPLIER (FINAL)
// ============================================
if ($action === 'approve') {
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid supplier ID.']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) throw new Exception('Supplier not found.');
        
        // Update Supplier
        $updateStmt = $pdo->prepare("
            UPDATE suppliers 
            SET status = 'Active', approved_by = ?, approved_at = NOW(), rejection_reason = NULL 
            WHERE id = ?
        ");
        $updateStmt->execute([$adminId, $id]);
        
        // Update User
        $updateUserStmt = $pdo->prepare("
            UPDATE users 
            SET status = 'Active', is_verified = 1 
            WHERE id = ?
        ");
        $updateUserStmt->execute([$supplier['created_by']]);
        
        // Auto-Approve all pending docs
        $docStmt = $pdo->prepare("
            UPDATE supplier_documents 
            SET status = 'Approved', reviewed_by = ?, reviewed_at = NOW() 
            WHERE supplier_id = ? AND status IN ('Pending', 'Pending Verification')
        ");
        $docStmt->execute([$adminId, $id]);
        
        $pdo->commit();
        
        logActivity($pdo, $adminId, 'SUPPLIER_APPROVED', "Approved supplier: {$supplier['supplier_name']}");
        
        if (function_exists('sendSupplierApprovedEmail')) {
            @sendSupplierApprovedEmail($supplier['email'], $supplier['contact_person'], $supplier['supplier_name']);
        }
        
        echo json_encode(['success' => true, 'message' => 'Supplier approved successfully.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: REJECT SUPPLIER (FINAL)
// ============================================
if ($action === 'reject') {
    $id = (int)($_POST['id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid supplier ID.']);
        exit;
    }
    if (empty($reason)) {
        echo json_encode(['success' => false, 'message' => 'Rejection reason is required.']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) throw new Exception('Supplier not found.');
        
        $updateStmt = $pdo->prepare("
            UPDATE suppliers 
            SET status = 'Rejected', rejection_reason = ?, approved_by = ?, approved_at = NOW() 
            WHERE id = ?
        ");
        $updateStmt->execute([$reason, $adminId, $id]);
        
        $updateUserStmt = $pdo->prepare("UPDATE users SET status = 'Inactive' WHERE id = ?");
        $updateUserStmt->execute([$supplier['created_by']]);
        
        $pdo->commit();
        
        logActivity($pdo, $adminId, 'SUPPLIER_REJECTED', "Rejected: {$supplier['supplier_name']} — {$reason}");
        
        if (function_exists('sendSupplierRejectedEmail')) {
            @sendSupplierRejectedEmail($supplier['email'], $supplier['contact_person'], $supplier['supplier_name'], $reason);
        }
        
        echo json_encode(['success' => true, 'message' => 'Supplier rejected.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ============================================
// ACTION: SUSPEND
// ============================================
if ($action === 'suspend') {
    $id = (int)($_POST['id'] ?? 0);
    
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid supplier ID.']);
        exit;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM suppliers WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) throw new Exception('Supplier not found.');
        
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE suppliers SET status = 'Suspended' WHERE id = ?")->execute([$id]);
        $pdo->prepare("UPDATE users SET status = 'Inactive' WHERE id = ?")->execute([$supplier['created_by']]);
        $pdo->commit();
        
        logActivity($pdo, $adminId, 'SUPPLIER_SUSPENDED', "Suspended: {$supplier['supplier_name']}");
        
        echo json_encode(['success' => true, 'message' => 'Supplier suspended.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);