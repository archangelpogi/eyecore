<?php
session_start();

// ---------- HEADERS ----------
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://eyecore.capstone001.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ---------- DATABASE ----------
include __DIR__ . '/../config/db.php';

// ---------- SESSION CHECK ----------
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'SuperAdmin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized access']);
    exit();
}

// ---------- INPUT ----------
$method = $_SERVER['REQUEST_METHOD'];
$data = json_decode(file_get_contents('php://input'), true);

// fallback if JSON decode fails
if ($method === 'POST' && json_last_error() !== JSON_ERROR_NONE) {
    $data = $_POST;
}

// ---------- ROUTING ----------
$action = $data['action'] ?? ($_GET['action'] ?? '');

if ($method === 'GET') {
    switch ($action) {
        case 'get_stats':
            getStats($pdo);
            break;
        case 'get_clinics':
            $page   = $_GET['page'] ?? 1;
            $limit  = $_GET['limit'] ?? 10;
            $search = $_GET['search'] ?? '';
            $status = $_GET['status'] ?? '';
            $risk   = $_GET['risk'] ?? '';
            $score  = $_GET['score'] ?? '';
            getClinics($pdo, $page, $limit, $search, $status, $risk, $score);
            break;
        case 'get_clinics_datatable':  // ADD THIS LINE
            getClinicsDatatable($pdo); // ADD THIS LINE
            break;                      // ADD THIS LINE
        case 'get_clinic':
            $id = $_GET['id'] ?? 0;
            getClinic($pdo, $id);
            break;
        case 'export_report':
            exportReport($pdo);
            break;
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} elseif ($method === 'POST') {
    switch ($action) {
        case 'get_clinics_datatable':
            getClinicsDatatable($pdo);
            break;
        case 'create_clinic':
            createClinic($pdo, $data);
            break;
        case 'update_status':
            updateClinicStatus($pdo, $data);
            break;
        case 'send_email':
            sendEmailNotification($pdo, $data);
            break;
        case 'review_document':
            reviewDocument($pdo, $data);
            break;
        case 'reset_document_attempts':
            resetDocumentAttempts($pdo, $data);
            break;
            
        // ⬇️ IDAGDAG MO ITO DITO ⬇️
        case 'update_identity_status':
            updateIdentityStatus($pdo, $data);
            break;
        // ⬆️ HANGGANG DITO ⬆️

        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
}

// ==========================
// FUNCTIONS
// ==========================

function getStats($pdo) {
    try {
        $stats = [];
        
        // Pending
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM clinics WHERE status = 'Pending'");
        $stats['pending'] = (int)$stmt->fetch()['count'];
        
        // Active
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM clinics WHERE status = 'Active'");
        $stats['active'] = (int)$stmt->fetch()['count'];
        
        // SUSPENDED (Pinalitan ng Reapplying)
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM clinics WHERE status = 'Reapplying'");
        $stats['reapplying'] = (int)$stmt->fetch()['count'];
        
        // Total
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM clinics");
        $stats['total_clinics'] = (int)$stmt->fetch()['total'];
        
        echo json_encode(['success' => true, 'data' => $stats]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getClinics($pdo, $page = 1, $limit = 10, $search = '', $status = '', $risk = '', $score = '') {
    try {
        $offset = ($page - 1) * $limit;
        $where = [];
        $params = [];

        if (!empty($search)) {
            $where[] = "(clinic_name LIKE ? OR clinic_code LIKE ? OR clinic_email LIKE ? OR contact LIKE ?)";
            $searchParam = "%$search%";
            for($i = 0; $i < 4; $i++) $params[] = $searchParam;
        }

        if (!empty($status)) {
            $where[] = "status = ?";
            $params[] = $status;
        }

        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM clinics $whereClause";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int)$stmt->fetch()['total'];
        $totalPages = ceil($total / $limit);

        // Get clinics with decision support scoring
        $sql = "SELECT 
                    c.*,
                    (SELECT email FROM users WHERE clinic_id = c.id AND role = 'ClinicAdmin' LIMIT 1) as admin_email,
                    (SELECT first_name FROM users WHERE clinic_id = c.id AND role = 'ClinicAdmin' LIMIT 1) as admin_name,
                    (SELECT COUNT(*) FROM users u WHERE u.clinic_id = c.id AND u.status = 'Active') as staff_count,
                    (SELECT COUNT(*) FROM patients p WHERE p.clinic_id = c.id AND p.status = 'Active') as patient_count,
                    -- DECISION SUPPORT: Calculate verification score
                    (
                        -- Clinic email: 25 points
                        CASE WHEN c.clinic_email IS NOT NULL AND c.clinic_email != '' THEN 25 ELSE 0 END +
                        -- Contact number: 20 points
                        CASE WHEN c.contact IS NOT NULL AND c.contact != '' THEN 20 ELSE 0 END +
                        -- Address: 20 points
                        CASE WHEN c.address IS NOT NULL AND c.address != '' THEN 20 ELSE 0 END +
                        -- Clinic name length: 10 points
                        CASE WHEN LENGTH(c.clinic_name) > 5 THEN 10 ELSE 0 END +
                        -- City: 10 points
                        CASE WHEN c.city IS NOT NULL AND c.city != '' THEN 10 ELSE 0 END +
                        -- Branch: 5 points
                        CASE WHEN c.branch IS NOT NULL AND c.branch != '' THEN 5 ELSE 0 END +
                        -- Approved by: 10 points (if already approved by someone)
                        CASE WHEN c.approved_by IS NOT NULL THEN 10 ELSE 0 END
                    ) as verification_score,
                    -- DECISION SUPPORT: Risk assessment
                    CASE 
                        WHEN c.clinic_email IS NULL OR c.clinic_email = '' THEN 'high'
                        WHEN c.contact IS NULL OR c.contact = '' THEN 'high'
                        WHEN c.address IS NULL OR c.address = '' THEN 'medium'
                        WHEN (SELECT COUNT(*) FROM clinic_documents WHERE clinic_id = c.id) < 3 THEN 'medium'
                        ELSE 'low' 
                    END as risk_level
                FROM clinics c 
                $whereClause 
                ORDER BY 
                    CASE status
                        WHEN 'Pending' THEN 1
                        WHEN 'Active' THEN 2
                        WHEN 'Suspended' THEN 3
                        ELSE 4
                    END,
                    verification_score DESC,
                    c.created_at DESC 
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Filter by risk level if specified
        if (!empty($risk)) {
            $clinics = array_filter($clinics, function($clinic) use ($risk) {
                return isset($clinic['risk_level']) && $clinic['risk_level'] === $risk;
            });
            $clinics = array_values($clinics);
        }

        // Filter by score range if specified
        if (!empty($score)) {
            $clinics = array_filter($clinics, function($clinic) use ($score) {
                $verification_score = $clinic['verification_score'] ?? 0;
                switch ($score) {
                    case 'high': return $verification_score >= 80;
                    case 'medium': return $verification_score >= 60 && $verification_score < 80;
                    case 'low': return $verification_score < 60;
                    default: return true;
                }
            });
            $clinics = array_values($clinics);
        }

        echo json_encode([
            'success' => true,
            'data' => $clinics,
            'pagination' => [
                'current_page' => $page,
                'total_pages' => $totalPages,
                'total_items' => $total,
                'items_per_page' => $limit
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getClinicsDatatable($pdo) {
    try {
        $request = $_POST;

        // DataTables parameters
        $start = $request['start'] ?? 0;
        $length = $request['length'] ?? 10;
        $searchValue = $request['search']['value'] ?? '';
        $orderColumn = $request['order'][0]['column'] ?? 0;
        $orderDir = $request['order'][0]['dir'] ?? 'asc';

        // Custom filters
        $status = $request['status'] ?? '';
        $risk = $request['risk'] ?? '';
        $score = $request['score'] ?? '';
        $searchText = $request['search_text'] ?? '';

        // Build WHERE clause
        $where = [];
        $params = [];

        // Global search from DataTables
        if (!empty($searchValue)) {
            $where[] = "(c.clinic_name LIKE ? OR c.clinic_code LIKE ? OR c.clinic_email LIKE ? OR c.contact LIKE ?)";
            $searchParam = "%$searchValue%";
            for ($i = 0; $i < 4; $i++) $params[] = $searchParam;
        }

        // Custom search from input
        if (!empty($searchText)) {
            $where[] = "(c.clinic_name LIKE ? OR c.clinic_code LIKE ? OR c.clinic_email LIKE ? OR c.contact LIKE ?)";
            $searchParam = "%$searchText%";
            for ($i = 0; $i < 4; $i++) $params[] = $searchParam;
        }

        if (!empty($status)) {
            $where[] = "c.status = ?";
            $params[] = $status;
        }

        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

        // Get total records
        $countSql = "SELECT COUNT(*) as total FROM clinics c";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute();
        $totalRecords = $stmt->fetch()['total'];

        // Get filtered count
        $filteredSql = "SELECT COUNT(*) as total FROM clinics c $whereClause";
        $stmt = $pdo->prepare($filteredSql);
        $stmt->execute($params);
        $filteredRecords = $stmt->fetch()['total'];

        // Column mapping for ordering
        $columns = ['c.clinic_name', 'c.clinic_email', 'verification_score', 'risk_level', 'c.status', 'c.created_at'];
        $orderBy = isset($columns[$orderColumn]) ? $columns[$orderColumn] : 'c.created_at';
        $orderBy .= " $orderDir";

        // Main SQL
        $sql = "SELECT 
                    c.*,
                    COALESCE(u.status,'Inactive') AS user_status,
                    u.first_name AS admin_first_name,
                    u.last_name AS admin_last_name,
                    u.email AS admin_email,
                    (SELECT COUNT(*) FROM users WHERE clinic_id = c.id AND status = 'Active') AS staff_count,
                    (SELECT COUNT(*) FROM patients WHERE clinic_id = c.id AND status = 'Active') AS patient_count,
                    
                    -- Documents Concatenation (Keep this)
                    (SELECT GROUP_CONCAT(
                        CONCAT(
                            d.id, '::', 
                            d.document_type, '::', 
                            d.status, '::', 
                            IFNULL(d.remarks,''), '::', 
                            d.uploaded_at
                        ) SEPARATOR '||'
                    ) FROM clinic_documents d WHERE d.clinic_id = c.id) AS documents_concat,

                    -- ✅ ACCURATE VERIFICATION SCORE
                    (
                        SELECT 
                            CASE 
                                -- Kung walang docs, 0 score
                                WHEN COUNT(*) = 0 THEN 0
                                -- Kung lahat ng docs Approved (at Identity Approved), 100%
                                WHEN SUM(status = 'Approved') = COUNT(*) AND 
                                     (SELECT status FROM clinic_identity_verification WHERE clinic_id = c.id LIMIT 1) = 'Approved' 
                                THEN 100
                                -- Kung may Rejected, bawas
                                WHEN SUM(status = 'Rejected') > 0 THEN 50
                                -- Kung Pending lahat, half score
                                ELSE ROUND((SUM(status = 'Approved') / COUNT(*)) * 100)
                            END
                        FROM clinic_documents WHERE clinic_id = c.id
                    ) AS verification_score,

                    -- ✅ ACCURATE RISK LEVEL
                    CASE 
                        -- HIGH: May Rejected na document
                        WHEN EXISTS (SELECT 1 FROM clinic_documents WHERE clinic_id = c.id AND status = 'Rejected') THEN 'high'
                        -- HIGH: Walang Identity
                        WHEN NOT EXISTS (SELECT 1 FROM clinic_identity_verification WHERE clinic_id = c.id AND status = 'Approved') THEN 'high'
                        -- MEDIUM: May Pending pa
                        WHEN EXISTS (SELECT 1 FROM clinic_documents WHERE clinic_id = c.id AND status = 'Pending') THEN 'medium'
                        -- LOW: Lahat Approved at may Identity
                        ELSE 'low'
                    END AS risk_level

                FROM clinics c
                LEFT JOIN users u ON u.clinic_id = c.id AND u.role = 'ClinicAdmin'
                $whereClause
                ORDER BY $orderBy
                LIMIT ? OFFSET ?";

        $params[] = $length;
        $params[] = $start;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Decode documents_concat into array
        foreach($data as &$clinic) {
            $clinic['documents'] = [];
            if(!empty($clinic['documents_concat'])) {
                $docs = explode('||', $clinic['documents_concat']);
                foreach($docs as $d) {
                    $parts = explode('::', $d);
                    $clinic['documents'][] = [
                        'id' => $parts[0] ?? null,
                        'document_type' => $parts[1] ?? null,
                        'status' => $parts[2] ?? null,
                        'remarks' => $parts[3] ?? null,
                        'uploaded_at' => $parts[4] ?? null,
                    ];
                }
            }
        }

        echo json_encode([
            'draw' => intval($request['draw'] ?? 1),
            'recordsTotal' => intval($totalRecords),
            'recordsFiltered' => intval($filteredRecords),
            'data' => $data
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'draw' => 1,
            'recordsTotal' => 0,
            'recordsFiltered' => 0,
            'data' => [],
            'error' => $e->getMessage()
        ]);
    }
}

function getClinic($pdo, $id) {
    try {
        $stmt = $pdo->prepare("SELECT 
                c.*,
                u.email as admin_email,
                u.first_name as admin_first_name,
                u.last_name as admin_last_name,
                u.status as admin_status,
                (SELECT COUNT(*) FROM clinic_documents WHERE clinic_id = c.id) as document_count,
                (SELECT COUNT(*) FROM users WHERE clinic_id = c.id AND status = 'Active') as active_staff,
                (SELECT COUNT(*) FROM patients WHERE clinic_id = c.id AND status = 'Active') as active_patients
            FROM clinics c 
            LEFT JOIN users u ON c.id = u.clinic_id AND u.role = 'ClinicAdmin'
            WHERE c.id = ?");
        $stmt->execute([$id]);
        $clinic = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($clinic) {
            // Get clinic documents
            $docStmt = $pdo->prepare("SELECT * FROM clinic_documents WHERE clinic_id = ?");
            $docStmt->execute([$id]);
            $documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

            // ✅ COMPUTE declined_today + awaiting_reupload PER DOCUMENT
            $today = date('Y-m-d');
            foreach ($documents as &$doc) {
                $status     = $doc['status'] ?? 'Pending';
                $reviewedAt = $doc['reviewed_at'] ?? null;

                $doc['declined_today'] = (
                    $status === 'Rejected'
                    && $reviewedAt
                    && date('Y-m-d', strtotime($reviewedAt)) === $today
                ) ? true : false;

                // Flag para sa admin UI — naka-waiting sa clinic na mag-upload
                $doc['awaiting_reupload'] = ($status === 'Awaiting Re-upload');

                // Legacy field — keep for backward compatibility
                $doc['max_attempts_reached'] = $doc['declined_today'];
            }
            unset($doc);

            $clinic['documents'] = $documents;

            // ✅ KUNIN ANG IDENTITY VERIFICATION DATA
            $idvStmt = $pdo->prepare("SELECT * FROM clinic_identity_verification WHERE clinic_id = ?");
            $idvStmt->execute([$id]);
            $identity = $idvStmt->fetch(PDO::FETCH_ASSOC);

            if ($identity) {
                // I-set ang absolute URL para lumabas ang images
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
                $host = $_SERVER['HTTP_HOST'];
                $baseUrl = $protocol . "://" . $host;
                
                $identity['selfie_url'] = $baseUrl . '/' . ltrim($identity['selfie_path'], '/');
                $identity['id_image_url'] = $baseUrl . '/' . ltrim($identity['id_image_path'], '/');
            }

            $clinic['identity'] = $identity; // Isama sa JSON response
            
            echo json_encode(['success' => true, 'data' => $clinic]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Clinic not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function createClinic($pdo, $data) {
    try {
        // Generate unique clinic code
        $clinicCode = 'CLINIC' . str_pad(rand(1000, 9999), 4, '0', STR_PAD_LEFT);
        
        // Check for duplicate clinic email
        if (!empty($data['clinic_email'])) {
            $stmt = $pdo->prepare("SELECT id FROM clinics WHERE clinic_email = ?");
            $stmt->execute([$data['clinic_email']]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => false, 'error' => 'Clinic email already exists']);
                return;
            }
        }

        $sql = "INSERT INTO clinics (clinic_code, clinic_name, clinic_email, contact, address, city, branch, status, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $clinicCode,
            $data['clinic_name'],
            $data['clinic_email'] ?? '',
            $data['contact'] ?? '',
            $data['address'] ?? '',
            $data['city'] ?? '',
            $data['branch'] ?? '',
            $data['status'] ?? 'Pending'
        ]);

        $clinicId = $pdo->lastInsertId();
        logActivity($pdo, $clinicId, 'CREATE', 'clinic', 'New clinic application: ' . $data['clinic_name']);

        echo json_encode(['success' => true, 'id' => $clinicId, 'message' => 'Clinic application submitted']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function updateClinicStatus($pdo, $data) {
    try {
        $id     = $data['id'] ?? 0;
        $status = $data['status'] ?? '';
        $reason = $data['reason'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;

        if (!$id || !$status) {
            echo json_encode(['success' => false, 'error' => 'Missing required data']);
            return;
        }

        // ===== GET CLINIC + ADMIN DETAILS =====
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                u.id AS admin_user_id,
                u.email AS admin_email,
                u.first_name AS admin_first_name,
                u.last_name AS admin_last_name
            FROM clinics c
            LEFT JOIN users u 
                ON c.id = u.clinic_id 
                AND u.role = 'ClinicAdmin'
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        $clinic = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$clinic) {
            echo json_encode(['success' => false, 'error' => 'Clinic not found']);
            return;
        }

        // ===== GUARD: bawal i-Active kung hindi lahat Approved =====
        if ($status === 'Active') {
            // 1. Check Documents
            $q = $pdo->prepare("SELECT COUNT(*) AS total, SUM(status = 'Approved') AS approved
                                FROM clinic_documents WHERE clinic_id = ?");
            $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            
            // 2. Check Identity Verification
            $idvQ = $pdo->prepare("SELECT status FROM clinic_identity_verification WHERE clinic_id = ?");
            $idvQ->execute([$id]);
            $idvStatus = $idvQ->fetchColumn();

            // Logic: Dapat total > 0, lahat approved, at Identity is Approved
            if ((int)$r['total'] === 0 || (int)$r['approved'] !== (int)$r['total'] || $idvStatus !== 'Approved') {
                
                $errorMsg = 'Cannot activate clinic. ';
                if ((int)$r['approved'] !== (int)$r['total']) {
                    $errorMsg .= 'Not all documents are approved. ';
                }
                if ($idvStatus !== 'Approved') {
                    $errorMsg .= 'Identity verification must be Approved. ';
                }
                
                echo json_encode(['success' => false, 'error' => $errorMsg]);
                return;
            }
        }

        // ===== GUARD: Suspended / Rejected =====
        if (in_array($status, ['Suspended', 'Rejected'])) {
            $q = $pdo->prepare("SELECT 
                    COUNT(*) AS total,
                    SUM(status = 'Rejected') AS declined,
                    SUM(expires_at IS NOT NULL AND expires_at < CURDATE()) AS expired,
                    SUM(ocr_flag IS NOT NULL AND ocr_flag <> '' AND ocr_flag NOT LIKE '%blur%') AS violations
                FROM clinic_documents WHERE clinic_id = ?");
            $q->execute([$id]);
            $r = $q->fetch(PDO::FETCH_ASSOC);

            $total = (int)$r['total'];
            if ($total === 0) {
                echo json_encode(['success' => false, 'error' => 'No documents to base this action on.']);
                return;
            }
            if ($status === 'Rejected' && (int)$r['declined'] !== $total) {
                echo json_encode(['success' => false, 'error' => 'Cannot reject: not all documents are declined.']);
                return;
            }
            if ($status === 'Suspended' && (int)$r['expired'] !== $total && (int)$r['violations'] === 0) {
                echo json_encode(['success' => false, 'error' => 'Cannot suspend: documents are not all expired and no violation found.']);
                return;
            }
        }

        // ===== UPDATE CLINIC STATUS =====
        if ($status === 'Active') {
            // approved_by/approved_at ay para lang sa Active
            $stmt = $pdo->prepare("
                UPDATE clinics 
                SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $userId, $id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE clinics 
                SET status = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$status, $id]);
        }

        // ===== UPDATE CLINIC ADMIN STATUS =====
        if ($status === 'Active') {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'Active' 
                WHERE clinic_id = ? AND role = 'ClinicAdmin'
            ");
            $stmt->execute([$id]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE users 
                SET status = 'Inactive' 
                WHERE clinic_id = ? AND role = 'ClinicAdmin'
            ");
            $stmt->execute([$id]);
        }

        // ===== LOG ACTIVITY =====
        logActivity(
            $pdo,
            $id,
            'STATUS_UPDATE',
            'clinic',
            "Clinic status changed to {$status}: {$clinic['clinic_name']}. Reason: {$reason}"
        );

        // ====================================================
        // 🔔 NOTIFICATION FOR SUSPENDED
        // ====================================================
        if ($status === 'Suspended' && !empty($clinic['admin_user_id'])) {

            createNotification(
                $pdo,
                $clinic['admin_user_id'],
                'Clinic Suspended',
                "Your clinic <b>{$clinic['clinic_name']}</b> has been suspended.<br>
                Reason: {$reason}",
                'warning'
            );

        } 
        // ====================================================
        // 📧 EMAIL FOR ACTIVE & REJECTED
        // ====================================================
        elseif (
            in_array($status, ['Active', 'Rejected']) &&
            !empty($clinic['admin_email'])
        ) {
            sendStatusEmail(
                $clinic['admin_email'],
                $clinic['admin_first_name'] ?? 'Clinic Admin',
                $clinic['clinic_name'],
                $status,
                $reason,
                $userId
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Clinic status updated successfully'
        ]);

    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    }
}


function createNotification($pdo, $userId, $title, $message, $type = 'info') {
    $stmt = $pdo->prepare("
        INSERT INTO notifications 
        (user_id, title, message, type, is_read, created_at)
        VALUES (?, ?, ?, ?, 0, NOW())
    ");
    $stmt->execute([$userId, $title, $message, $type]);
}

function reviewDocument($pdo, $data) {
    try {
        $docId  = $data['docId'] ?? 0;
        $status = $data['status'] ?? '';
        $reason = $data['reason'] ?? '';

        if (!$docId || !in_array($status, ['Approved', 'Rejected'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid document action']);
            return;
        }

        $stmt = $pdo->prepare("SELECT clinic_id, document_type, submission_attempts, status 
                               FROM clinic_documents WHERE id = ?");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            echo json_encode(['success' => false, 'error' => 'Document not found']);
            return;
        }

        // ── BLOCK RE-REVIEW ──
        if (in_array($doc['status'], ['Approved', 'Rejected'])) {
            echo json_encode([
                'success' => false, 
                'error' => 'This document has already been reviewed and cannot be modified.'
            ]);
            return;
        }

        $currentAttempts = (int)($doc['submission_attempts'] ?? 0);
        $docType  = $doc['document_type'];
        $clinicId = $doc['clinic_id'];

        // Per-day rule: once declined, the clinic must wait until the next calendar day
        // to re-upload. We no longer permanently block any document.
        $declinedToday = ($status === 'Rejected') ? 1 : 0;

        // ── UPDATE DOCUMENT ──
        $stmt = $pdo->prepare("UPDATE clinic_documents
            SET 
                status = ?,
                rejection_reason = ?,
                max_attempts_reached = ?,
                reviewed_at = NOW()
            WHERE id = ?");

        $stmt->execute([
            $status,
            $status === 'Rejected' ? $reason : null,
            $declinedToday,
            $docId
        ]);

        // ── GET CLINIC INFO ──
        $stmt = $pdo->prepare("
            SELECT c.clinic_name, 
                   u.id AS admin_user_id, 
                   u.email AS admin_email, 
                   u.first_name AS admin_name
            FROM clinics c
            JOIN users u ON c.id = u.clinic_id AND u.role = 'ClinicAdmin'
            WHERE c.id = ?
        ");
        $stmt->execute([$clinicId]);
        $info = $stmt->fetch(PDO::FETCH_ASSOC);

        // ============================================================
        // HANDLE DECLINED (Manual Admin Decision)
        // ============================================================
        if ($status === 'Rejected') {
            // TINANGGAL ANG AUTO-UPDATE NG CLINIC STATUS DITO.
            // Ang admin na ang magde-desisyon kung gawing Reapplying via SweetAlert.

            // 1. Notify clinic admin
            if (!empty($info['admin_user_id'])) {
                createNotification(
                    $pdo,
                    $info['admin_user_id'],
                    'Document Declined',
                    "Ang dokumentong <b>{$docType}</b> ay na-decline. " .
                    "Maaari kang mag-upload ng corrected version <b>bukas</b> " .
                    "(one submission per document per day).<br><br>" .
                    "<b>Reason:</b> {$reason}",
                    'warning'
                );
            }

            // 2. Email the clinic admin
            if (!empty($info['admin_email'])) {
                sendDocumentRejectedEmail(
                    $info['admin_email'],
                    $info['admin_name'],
                    $info['clinic_name'],
                    $docType,
                    $reason . " — You may re-upload a corrected copy tomorrow."
                );
            }
        }

        echo json_encode([
            'success'        => true,
            'message'        => $status === 'Rejected'
                ? "Document declined. Clinic may re-upload once per day."
                : "Document approved.",
            'attempts'       => $currentAttempts,
            'declined_today' => ($status === 'Rejected') ? true : false
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function resetDocumentAttempts($pdo, $data) {
    try {
        $docId  = $data['docId'] ?? 0;
        $reason = $data['reason'] ?? 'Manual reset by SuperAdmin';
        $userId = $_SESSION['user_id'] ?? 0;

        if (!$docId) {
            echo json_encode(['success' => false, 'error' => 'Missing document ID']);
            return;
        }

        // Get document info
        $stmt = $pdo->prepare("
            SELECT clinic_id, document_type, submission_attempts, status, file_path 
            FROM clinic_documents WHERE id = ?
        ");
        $stmt->execute([$docId]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$doc) {
            echo json_encode(['success' => false, 'error' => 'Document not found']);
            return;
        }

        $clinicId = $doc['clinic_id'];

        // ✅ RE-OPEN the document — clear the file so the clinic must upload a fresh copy.
        // Status = "Awaiting Re-upload" (NOT "Pending") para malinaw na waiting sa clinic.
        $stmt = $pdo->prepare("
            UPDATE clinic_documents
            SET 
                submission_attempts = 0,
                status = 'Awaiting Re-upload',
                max_attempts_reached = 0,
                rejection_reason = NULL,
                reviewed_at = NULL,
                file_path = NULL
            WHERE id = ?
        ");
        $stmt->execute([$docId]);

        // ✅ Update clinic status → Reapplying (hindi Suspended, hindi Pending)
        $stmt = $pdo->prepare("
            UPDATE clinics 
            SET status = 'Reapplying', updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$clinicId]);

        // ✅ Log activity
        logActivity(
            $pdo,
            $clinicId,
            'RESET_ATTEMPTS',
            'document',
            "SuperAdmin reset attempts for {$doc['document_type']} (was {$doc['submission_attempts']} attempts). Reason: {$reason}"
        );

        // ✅ Notify clinic admin — sabihin na kailangan mag-upload muli
        $stmt = $pdo->prepare("
            SELECT u.id AS admin_user_id, u.email AS admin_email, u.first_name AS admin_name, c.clinic_name
            FROM users u
            JOIN clinics c ON c.id = u.clinic_id
            WHERE u.clinic_id = ? AND u.role = 'ClinicAdmin'
            LIMIT 1
        ");
        $stmt->execute([$clinicId]);
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            createNotification(
                $pdo,
                $admin['admin_user_id'],
                'Document Re-opened — Please Re-upload',
                "Ang dokumentong <b>{$doc['document_type']}</b> ay binigyan ng bagong chance ng administrator. " .
                "Maaari ka nang mag-upload ng <b>bagong corrected document</b> ngayon (hindi na kailangang hintayin ang bukas).<br><br>" .
                "<b>Reason:</b> {$reason}",
                'info'
            );

            // Optional: send email
            if (!empty($admin['admin_email'])) {
                sendResetAttemptsEmail(
                    $admin['admin_email'],
                    $admin['admin_name'],
                    $admin['clinic_name'],
                    $doc['document_type'],
                    $reason
                );
            }
        }

        echo json_encode([
            'success' => true,
            'message' => 'Document attempts reset. Clinic can now re-upload.'
        ]);

    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function sendResetAttemptsEmail($toEmail, $adminName, $clinicName, $documentType, $reason) {
    try {
        require '../PHPMailer/PHPMailer.php';
        require '../PHPMailer/SMTP.php';
        require '../PHPMailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Verification');
        $mail->addAddress($toEmail, $adminName);

        $mail->isHTML(true);
        $mail->Subject = "🔄 Document Reset – {$clinicName}";

        $mail->Body = "
            <div style='font-family: Arial; max-width: 600px; margin: 0 auto;'>
                <h2 style='color:#f59e0b; text-align:center;'>Document Reset</h2>

                <p>Hello <b>{$adminName}</b>,</p>

                <p>Good news! Your document has been given a <b>fresh chance</b> by our administrator.</p>

                <ul>
                    <li><b>Clinic:</b> {$clinicName}</li>
                    <li><b>Document:</b> {$documentType}</li>
                    <li><b>Status:</b> Ready for re-upload</li>
                </ul>

                <div style='background:#fffbeb; padding:15px; border-left:5px solid #f59e0b; margin: 20px 0;'>
                    <b>Admin Note:</b><br>
                    {$reason}
                </div>

                <p><b>Next Step:</b> Please log in to your clinic dashboard and upload the <b>corrected version</b> of this document.</p>

                <p style='text-align: center; margin: 30px 0;'>
                    <a href='http://eyecore.capstone001.com/clinic/documents.php' 
                       style='background:#3b82f6; color:white; padding:12px 30px; text-decoration:none; border-radius:8px; font-weight:bold; display:inline-block;'>
                        📄 Go to Documents
                    </a>
                </p>

                <br>
                <small style='color:#6b7280;'>This is an automated email from Eyecore System.</small>
            </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Reset attempts email failed: " . $e->getMessage());
        return false;
    }
}

function sendStatusEmail($toEmail, $adminName, $clinicName, $status, $reason = '', $approvedBy = 0) {
    try {
        require '../PHPMailer/PHPMailer.php';
        require '../PHPMailer/SMTP.php';
        require '../PHPMailer/Exception.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        // Recipients
        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore System');
        $mail->addAddress($toEmail, $adminName);
        
        // Content
        $mail->isHTML(true);
        
        if ($status === 'Active') {
            $mail->Subject = '🎉 Clinic Application Approved - Eyecore System';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2 style='color: #10b981; text-align: center;'>Congratulations! 🎉</h2>
                    <p>Dear <strong>{$adminName}</strong>,</p>
                    <p>We are pleased to inform you that your clinic application for <strong>{$clinicName}</strong> has been <strong style='color:#10b981;'>APPROVED</strong> and is now active in the Eyecore system.</p>
                    
                    <div style='background: #f0f9ff; padding: 20px; border-radius: 10px; border-left: 4px solid #3b82f6; margin: 20px 0;'>
                        <h4 style='margin-top: 0; color: #1e40af;'>Account Details:</h4>
                        <ul style='line-height: 1.6;'>
                            <li><strong>Clinic Name:</strong> {$clinicName}</li>
                            <li><strong>Status:</strong> <span style='color: #10b981; font-weight: bold;'>Active</span></li>
                            <li><strong>Access Level:</strong> Full System Access</li>
                            <li><strong>Approval Date:</strong> " . date('F j, Y') . "</li>
                        </ul>
                    </div>
                    
                    <div style='background: #dcfce7; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <h4 style='margin-top: 0; color: #166534;'>Next Steps:</h4>
                        <ol style='line-height: 1.6;'>
                            <li><strong>Login to your dashboard</strong> using your credentials</li>
                            <li><strong>Complete your clinic profile</strong> with additional details</li>
                            <li><strong>Add staff members</strong> to your clinic</li>
                            <li><strong>Start managing patients</strong> and appointments</li>
                            <li><strong>Explore all features</strong> available in your dashboard</li>
                        </ol>
                    </div>
                    
                    <div style='text-align: center; margin: 30px 0;'>
                        <a href='http://eyecore.capstone001.com/admin/login.php' style='background: linear-gradient(135deg, #0d9488, #3b82f6); color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>
                            🚀 Login to Dashboard
                        </a>
                    </div>
                    
                    <div style='border-top: 1px solid #e5e7eb; padding-top: 20px; color: #6b7280; font-size: 14px;'>
                        <p><strong>Need Help?</strong> Contact our support team if you have any questions.</p>
                        <p>This is an automated message, please do not reply to this email.</p>
                    </div>
                    
                    <p style='text-align: center; margin-top: 30px;'>
                        Best regards,<br>
                        <strong style='color: #3b82f6;'>Eyecore Administration Team</strong>
                    </p>
                </div>
            ";
        } elseif ($status === 'Rejected') {
                    $mail->Subject = '❌ Clinic Application Status Update - Eyecore System';
                    $mail->Body = "
                        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #ef4444; text-align: center;'>Application Status Update</h2>
            <p>Dear <strong>{$adminName}</strong>,</p>
            <p>We regret to inform you that your clinic application for <strong>{$clinicName}</strong> has been <strong style='color:#ef4444;'>REJECTED</strong> due to suspicious or inconsistent information provided in your application.</p>

            " . (!empty($reason) ? "
            <div style='background: #fef2f2; padding: 20px; border-radius: 10px; border-left: 4px solid #ef4444; margin: 20px 0;'>
                <h4 style='margin-top: 0; color: #991b1b;'>Reason for Rejection:</h4>
                <p style='color: #991b1b; font-style: italic;'>{$reason}</p>
            </div>" : "") . "

            <div style='background: #f8fafc; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                <h4 style='margin-top: 0; color: #374151;'>Next Steps:</h4>
                <ul style='line-height: 1.6;'>
                    <li>Ensure that all information provided in your application is accurate and verifiable</li>
                    <li>Resubmit your application through our registration portal once corrections are made</li>
                    <li>Contact support if you have questions or need clarification</li>
                </ul>
            </div>

            <div style='text-align: center; margin: 30px 0;'>
                <a href='http://eyecore.capstone001.com/auth/register.php' style='background: #3b82f6; color: white; padding: 12px 30px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>
                    📝 Resubmit Application
                </a>
            </div>

            <div style='border-top: 1px solid #e5e7eb; padding-top: 20px; color: #6b7280; font-size: 14px;'>
                <p><strong>Need Assistance?</strong> Our support team is here to help you with the reapplication process.</p>
                <p>This is an automated message, please do not reply to this email.</p>
            </div>

            <p style='text-align: center; margin-top: 30px;'>
                Sincerely,<br>
                <strong style='color: #3b82f6;'>Eyecore Administration Team</strong>
            </p>
        </div>

            ";
        } elseif ($status === 'Suspended') {
            $mail->Subject = '⚠️ Clinic Account Suspended - Eyecore System';
            $mail->Body = "
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2 style='color: #f59e0b; text-align: center;'>Account Suspension Notice</h2>
                    <p>Dear <strong>{$adminName}</strong>,</p>
                    <p>Your clinic account for <strong>{$clinicName}</strong> has been <strong style='color:#f59e0b;'>SUSPENDED</strong>.</p>
                    
                    " . (!empty($reason) ? "
                    <div style='background: #fffbeb; padding: 20px; border-radius: 10px; border-left: 4px solid #f59e0b; margin: 20px 0;'>
                        <h4 style='margin-top: 0; color: #92400e;'>Reason for Suspension:</h4>
                        <p style='color: #92400e;'>{$reason}</p>
                    </div>" : "") . "
                    
                    <div style='background: #fef3c7; padding: 15px; border-radius: 8px; margin: 20px 0;'>
                        <h4 style='margin-top: 0; color: #92400e;'>Important Information:</h4>
                        <ul style='line-height: 1.6;'>
                            <li>Your clinic dashboard access has been temporarily disabled</li>
                            <li>All active appointments may be affected</li>
                            <li>Patient data remains secure in our system</li>
                            <li>You cannot perform any operations during suspension</li>
                        </ul>
                    </div>
                    
                    <div style='border-top: 1px solid #e5e7eb; padding-top: 20px; color: #6b7280; font-size: 14px;'>
                        <p><strong>To Reactivate Your Account:</strong> Please contact our support team immediately to resolve this issue and reactivate your account.</p>
                        <p>This is an automated message, please do not reply to this email.</p>
                    </div>
                    
                    <p style='text-align: center; margin-top: 30px;'>
                        Regards,<br>
                        <strong style='color: #3b82f6;'>Eyecore Administration Team</strong>
                    </p>
                </div>
            ";
        }
        
        $mail->AltBody = "Clinic {$clinicName} status updated to {$status}. Please check your email for details.";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: " . $e->getMessage());
        return false;
    }
}

function sendDocumentRejectedEmail($toEmail, $adminName, $clinicName, $documentType, $reason) {
    try {
        require '../PHPMailer/PHPMailer.php';
        require '../PHPMailer/SMTP.php';
        require '../PHPMailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Verification');
        $mail->addAddress($toEmail, $adminName);

        $mail->isHTML(true);
        $mail->Subject = "❌ Document Declined – {$clinicName}";

        $mail->Body = "
            <div style='font-family: Arial'>
                <h2 style='color:#ef4444;'>Document Declined</h2>

                <p>Hello <b>{$adminName}</b>,</p>

                <p>Your submitted document has been declined.</p>

                <ul>
                    <li><b>Clinic:</b> {$clinicName}</li>
                    <li><b>Document:</b> {$documentType}</li>
                </ul>

                <div style='background:#fef2f2;padding:15px;border-left:5px solid #ef4444'>
                    <b>Reason:</b><br>
                    {$reason}
                </div>

                <p>Please log in to your clinic dashboard and re-upload the corrected document.</p>

                <br>
                <small>This is an automated email from Eyecore System.</small>
            </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Document reject email failed: " . $e->getMessage());
        return false;
    }
}

function exportReport($pdo) {
    try {
        $stmt = $pdo->query("
            SELECT 
                clinic_code,
                clinic_name,
                clinic_email,
                contact,
                address,
                city,
                branch,
                status,
                DATE_FORMAT(created_at, '%Y-%m-%d') as created_date,
                DATE_FORMAT(approved_at, '%Y-%m-%d') as approved_date
            FROM clinics 
            ORDER BY created_at DESC
        ");
        $clinics = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Output as CSV
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="clinics_report_' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Code', 'Name', 'Email', 'Contact', 'Address', 'City', 'Branch', 'Status', 'Created Date', 'Approved Date']);
        
        foreach ($clinics as $clinic) {
            fputcsv($output, $clinic);
        }
        
        fclose($output);
        exit();
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function logActivity($pdo, $clinicId, $action, $module, $details) {
    try {
        $userId = $_SESSION['user_id'] ?? 0;
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '';
        
        $sql = "INSERT INTO activity_logs (clinic_id, user_id, action, module, details, ip_address, created_at) 
                VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$clinicId, $userId, $action, $module, $details, $ipAddress]);
    } catch (Exception $e) {
        // Silent fail for logging
    }
}

function updateIdentityStatus($pdo, $data) {
    try {
        $clinicId = $data['clinic_id'] ?? 0;
        $status = $data['status'] ?? ''; 
        $reason = $data['reason'] ?? '';
        $userId = $_SESSION['user_id'] ?? 0;

        if (!$clinicId || !in_array($status, ['Approved', 'Rejected', 'Awaiting Re-upload'])) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            return;
        }

        // Kunin ang clinic info at admin details para sa email
        $stmtInfo = $pdo->prepare("
            SELECT c.clinic_name, u.email AS admin_email, u.first_name AS admin_name
            FROM clinics c
            LEFT JOIN users u ON c.id = u.clinic_id AND u.role = 'ClinicAdmin'
            WHERE c.id = ?
        ");
        $stmtInfo->execute([$clinicId]);
        $info = $stmtInfo->fetch(PDO::FETCH_ASSOC);

        // I-update ang identity record
        $stmt = $pdo->prepare("UPDATE clinic_identity_verification SET 
            status = ?, 
            rejection_reason = ?, 
            reviewed_at = NOW(), 
            reviewed_by = ? 
            WHERE clinic_id = ?");
        
        $stmt->execute([
            $status,
            $status === 'Rejected' ? $reason : null,
            $userId,
            $clinicId
        ]);

        if ($status === 'Approved') {
            $pdo->prepare("UPDATE clinics SET identity_verified = 1 WHERE id = ?")->execute([$clinicId]);
        } else {
            $pdo->prepare("UPDATE clinics SET identity_verified = 0 WHERE id = ?")->execute([$clinicId]);
        }

        // Kunin ang admin user id para sa in-app notification
        $stmtUser = $pdo->prepare("SELECT id FROM users WHERE clinic_id = ? AND role = 'ClinicAdmin' LIMIT 1");
        $stmtUser->execute([$clinicId]);
        $admin = $stmtUser->fetch(PDO::FETCH_ASSOC);

        if ($admin) {
            $msg = $status === 'Approved' 
                ? "Your identity has been successfully verified." 
                : "Your identity verification was declined. Reason: " . $reason . ". Please resubmit.";

            createNotification($pdo, $admin['id'], "Identity Verification: " . $status, $msg, $status === 'Approved' ? 'success' : 'warning');
        }

        // ============================================================
        // MAGPADALA NG EMAIL KAPAG REJECTED
        // ============================================================
        if ($status === 'Rejected' && !empty($info['admin_email'])) {
            sendIdentityRejectedEmail(
                $info['admin_email'],
                $info['admin_name'] ?? 'Clinic Admin',
                $info['clinic_name'],
                $reason
            );
        }

        echo json_encode(['success' => true, 'message' => 'Identity status updated']);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function sendIdentityRejectedEmail($toEmail, $adminName, $clinicName, $reason) {
    try {
        require '../PHPMailer/PHPMailer.php';
        require '../PHPMailer/SMTP.php';
        require '../PHPMailer/Exception.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'angelloricanmendoza27@gmail.com';
        $mail->Password = 'tkyv vypr pxvm pfse';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom('angelloricanmendoza27@gmail.com', 'Eyecore Verification');
        $mail->addAddress($toEmail, $adminName);

        $mail->isHTML(true);
        $mail->Subject = "❌ Identity Verification Declined – {$clinicName}";

        $mail->Body = "
            <div style='font-family: Arial; max-width: 600px; margin: 0 auto;'>
                <h2 style='color:#ef4444; text-align:center;'>Identity Verification Declined</h2>

                <p>Hello <b>{$adminName}</b>,</p>

                <p>Your submitted identity verification (Live Selfie & ID) has been declined.</p>

                <ul>
                    <li><b>Clinic:</b> {$clinicName}</li>
                    <li><b>Document:</b> Identity Verification</li>
                </ul>

                <div style='background:#fef2f2; padding:15px; border-left:5px solid #ef4444; margin: 20px 0;'>
                    <b>Reason:</b><br>
                    {$reason}
                </div>

                <p>Please log in to your clinic dashboard and re-upload a clear and valid ID with a proper selfie.</p>

                <p style='text-align: center; margin: 30px 0;'>
                    <a href='http://eyecore.capstone001.com/clinic/documents.php' 
                       style='background:#3b82f6; color:white; padding:12px 30px; text-decoration:none; border-radius:8px; font-weight:bold; display:inline-block;'>
                        📄 Go to Dashboard
                    </a>
                </p>

                <br>
                <small style='color:#6b7280;'>This is an automated email from Eyecore System.</small>
            </div>
        ";

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("Identity reject email failed: " . $e->getMessage());
        return false;
    }
}
?>