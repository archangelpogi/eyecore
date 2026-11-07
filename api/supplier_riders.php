<?php
// api/supplier_riders.php
// Supplier Rider Management API
// Handles: list, get, add, update, delete, reset_pin, verify, schedule, history

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

// Check if supplier is logged in
if (!isset($_SESSION['supplier_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$supplier_id = (int)$_SESSION['supplier_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ============================================================
// HELPER FUNCTIONS
// ============================================================

/**
 * Handle file upload safely
 * Returns relative path or null
 */
function handleUpload($fileKey, $subfolder, $prefix = 'file') {
    if (!isset($_FILES[$fileKey]) || $_FILES[$fileKey]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    
    $file = $_FILES[$fileKey];
    
    // Validate size (5MB max)
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new Exception("File $fileKey is too large (max 5MB)");
    }
    
    // Validate type
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mime, $allowed)) {
        throw new Exception("Invalid file type for $fileKey. Only JPG, PNG, WEBP, PDF allowed.");
    }
    
    // Generate unique filename
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext = strtolower($ext);
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'])) {
        $ext = 'jpg';
    }
    
    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $upload_dir = __DIR__ . '/../uploads/rider_documents/' . $subfolder . '/';
    
    // Create folder if not exists
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    $target = $upload_dir . $filename;
    
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        throw new Exception("Failed to upload $fileKey");
    }
    
    return 'uploads/rider_documents/' . $subfolder . '/' . $filename;
}

/**
 * Delete old file if exists
 */
function deleteFile($path) {
    if (empty($path)) return;
    $full_path = __DIR__ . '/../' . $path;
    if (file_exists($full_path) && is_file($full_path)) {
        @unlink($full_path);
    }
}

/**
 * Validate phone number (PH format)
 */
function validatePhone($phone) {
    $phone = preg_replace('/[^0-9]/', '', $phone);
    return strlen($phone) >= 10 && strlen($phone) <= 13;
}

/**
 * Verify rider belongs to this supplier
 */
function getRiderOrFail($pdo, $rider_id, $supplier_id) {
    $stmt = $pdo->prepare("SELECT * FROM supplier_riders WHERE id = ? AND supplier_id = ?");
    $stmt->execute([$rider_id, $supplier_id]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rider) {
        throw new Exception('Rider not found');
    }
    return $rider;
}

// ============================================================
// ACTION: LIST — Listahan ng riders
// ============================================================
if ($action === 'list') {
    try {
        $status_filter = $_GET['status'] ?? 'all';
        $search = $_GET['search'] ?? '';
        
        $sql = "SELECT 
                    r.id,
                    r.full_name,
                    r.phone,
                    r.email,
                    r.photo_path,
                    r.vehicle_type,
                    r.plate_number,
                    r.status,
                    r.verification_status,
                    r.total_deliveries,
                    r.rating,
                    r.last_login,
                    r.created_at,
                    (SELECT COUNT(*) FROM purchase_orders po 
                     WHERE po.assigned_rider_id = r.id 
                     AND po.status IN ('To Ship', 'Shipped')) as active_deliveries
                FROM supplier_riders r
                WHERE r.supplier_id = ?";
        
        $params = [$supplier_id];
        
        if ($status_filter !== 'all') {
            $sql .= " AND r.status = ?";
            $params[] = $status_filter;
        }
        
        if (!empty($search)) {
            $sql .= " AND (r.full_name LIKE ? OR r.phone LIKE ? OR r.email LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY r.created_at DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cast numeric fields
        foreach ($riders as &$r) {
            $r['id'] = (int)$r['id'];
            $r['total_deliveries'] = (int)$r['total_deliveries'];
            $r['active_deliveries'] = (int)$r['active_deliveries'];
            $r['rating'] = (float)$r['rating'];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $riders,
            'count' => count($riders)
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

if ($action === 'get_delivery') {
    try {
        $po_id = (int)($_GET['id'] ?? 0);
        if (!$po_id) throw new Exception('PO ID is required');

        $stmt = $pdo->prepare("
            SELECT po.*, 
                   pr.pr_number, pr.department, pr.purpose,
                   c.clinic_name, c.address as clinic_address, c.phone as clinic_phone, c.clinic_email,
                   e.estimated_amount,
                   e.price_change_amount,
                   e.price_change_percent,
                   CASE 
                       WHEN po.status = 'Delivered' THEN 'Delivered'
                       WHEN po.out_for_delivery_at IS NOT NULL THEN 'In Transit'
                       WHEN po.picked_up_at IS NOT NULL THEN 'Picked Up'
                       WHEN po.status = 'Shipped' THEN 'Assigned'
                       ELSE po.status
                   END AS rider_status
            FROM purchase_orders po
            JOIN purchase_requests pr ON po.pr_id = pr.id
            LEFT JOIN clinics c ON pr.clinic_id = c.id
            LEFT JOIN expenses e ON po.pr_id = e.pr_id
            WHERE po.id = ? AND po.assigned_rider_id = ?
        ");
        $stmt->execute([$po_id, $rider_id]);
        $delivery = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$delivery) throw new Exception('Delivery not found or not assigned to you');

        // ✅ Determine if COD
        $payment_method = $delivery['payment_method'] ?? 'Bank Transfer';
        $is_cod = in_array($payment_method, ['COD', 'Cash']);
        
        $delivery['payment_method'] = $payment_method;
        $delivery['is_cod'] = $is_cod;

        // Get items
        $stmt = $pdo->prepare("SELECT * FROM pr_items WHERE pr_id = ?");
        $stmt->execute([$delivery['pr_id']]);
        $delivery['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $delivery['photos'] = $delivery['shipping_photos'] ? json_decode($delivery['shipping_photos'], true) : [];
        $delivery['time_window'] = ($delivery['scheduled_time_start'] && $delivery['scheduled_time_end'])
            ? substr($delivery['scheduled_time_start'], 0, 5) . ' - ' . substr($delivery['scheduled_time_end'], 0, 5)
            : null;

        echo json_encode(['success' => true, 'data' => $delivery]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: ADD — Bagong rider
// ============================================================
if ($action === 'add') {
    try {
        // === Validate required fields ===
        $full_name = trim($_POST['full_name'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $pin = trim($_POST['pin'] ?? '');
        
        if (empty($full_name)) {
            throw new Exception('Full name is required');
        }
        if (!validatePhone($phone)) {
            throw new Exception('Invalid phone number');
        }
        if (!preg_match('/^\d{6}$/', $pin)) {
            throw new Exception('PIN must be exactly 6 digits');
        }
        
        // Normalize phone (remove non-digits)
        $phone_clean = preg_replace('/[^0-9]/', '', $phone);
        
        // Check duplicate phone
        $check = $pdo->prepare("
            SELECT id FROM supplier_riders 
            WHERE phone = ? AND supplier_id = ?
        ");
        $check->execute([$phone_clean, $supplier_id]);
        if ($check->fetch()) {
            throw new Exception('May existing rider na may ganitong phone number');
        }
        
        // === Hash PIN ===
        $pin_hash = password_hash($pin, PASSWORD_DEFAULT);
        
        // === Handle file uploads ===
        $uploads = [
            'photo_path' => null,
            'id_photo_path' => null,
            'id_back_photo_path' => null,
            'license_photo_path' => null,
            'nbi_clearance_path' => null,
            'police_clearance_path' => null,
            'barangay_clearance_path' => null,
            'medical_cert_path' => null,
            'vehicle_photo_path' => null,
            'or_cr_path' => null,
            'vehicle_insurance_path' => null,
        ];
        
        $uploadMap = [
            'photo_path' => 'profiles',
            'id_photo_path' => 'ids',
            'id_back_photo_path' => 'ids',
            'license_photo_path' => 'licenses',
            'nbi_clearance_path' => 'clearances',
            'police_clearance_path' => 'clearances',
            'barangay_clearance_path' => 'clearances',
            'medical_cert_path' => 'clearances',
            'vehicle_photo_path' => 'vehicles',
            'or_cr_path' => 'vehicles',
            'vehicle_insurance_path' => 'vehicles',
        ];
        
        foreach ($uploadMap as $field => $subfolder) {
            $path = handleUpload($field, $subfolder, $field);
            if ($path !== null) {
                $uploads[$field] = $path;
            }
        }
        
        // === Insert rider ===
        $sql = "INSERT INTO supplier_riders (
            supplier_id, full_name, phone, email, birthdate, address,
            photo_path, id_type, id_number, id_photo_path, id_back_photo_path,
            nbi_clearance_path, nbi_expiry, police_clearance_path, police_expiry,
            barangay_clearance_path, barangay_expiry, medical_cert_path, medical_expiry,
            license_number, license_expiry, license_photo_path, restriction_codes,
            vehicle_type, vehicle_make, vehicle_model, vehicle_color, plate_number,
            vehicle_photo_path, or_cr_path, vehicle_insurance_path, vehicle_insurance_expiry,
            emergency_name, emergency_relationship, emergency_phone, emergency_address,
            blood_type, allergies, date_hired, notes, pin_hash,
            status, verification_status
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?,
            ?, ?, ?, ?, ?,
            'Active', 'Pending'
        )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $supplier_id,
            $full_name,
            $phone_clean,
            !empty($_POST['email']) ? trim($_POST['email']) : null,
            !empty($_POST['birthdate']) ? $_POST['birthdate'] : null,
            !empty($_POST['address']) ? trim($_POST['address']) : null,
            $uploads['photo_path'],
            !empty($_POST['id_type']) ? trim($_POST['id_type']) : null,
            !empty($_POST['id_number']) ? trim($_POST['id_number']) : null,
            $uploads['id_photo_path'],
            $uploads['id_back_photo_path'],
            $uploads['nbi_clearance_path'],
            !empty($_POST['nbi_expiry']) ? $_POST['nbi_expiry'] : null,
            $uploads['police_clearance_path'],
            !empty($_POST['police_expiry']) ? $_POST['police_expiry'] : null,
            $uploads['barangay_clearance_path'],
            !empty($_POST['barangay_expiry']) ? $_POST['barangay_expiry'] : null,
            $uploads['medical_cert_path'],
            !empty($_POST['medical_expiry']) ? $_POST['medical_expiry'] : null,
            !empty($_POST['license_number']) ? trim($_POST['license_number']) : null,
            !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null,
            $uploads['license_photo_path'],
            !empty($_POST['restriction_codes']) ? trim($_POST['restriction_codes']) : null,
            !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null,
            !empty($_POST['vehicle_make']) ? trim($_POST['vehicle_make']) : null,
            !empty($_POST['vehicle_model']) ? trim($_POST['vehicle_model']) : null,
            !empty($_POST['vehicle_color']) ? trim($_POST['vehicle_color']) : null,
            !empty($_POST['plate_number']) ? trim($_POST['plate_number']) : null,
            $uploads['vehicle_photo_path'],
            $uploads['or_cr_path'],
            $uploads['vehicle_insurance_path'],
            !empty($_POST['vehicle_insurance_expiry']) ? $_POST['vehicle_insurance_expiry'] : null,
            !empty($_POST['emergency_name']) ? trim($_POST['emergency_name']) : null,
            !empty($_POST['emergency_relationship']) ? trim($_POST['emergency_relationship']) : null,
            !empty($_POST['emergency_phone']) ? trim($_POST['emergency_phone']) : null,
            !empty($_POST['emergency_address']) ? trim($_POST['emergency_address']) : null,
            !empty($_POST['blood_type']) ? trim($_POST['blood_type']) : null,
            !empty($_POST['allergies']) ? trim($_POST['allergies']) : null,
            !empty($_POST['date_hired']) ? $_POST['date_hired'] : date('Y-m-d'),
            !empty($_POST['notes']) ? trim($_POST['notes']) : null,
            $pin_hash
        ]);
        
        $new_rider_id = (int)$pdo->lastInsertId();
        
        // === Default schedule (Mon-Fri 8AM-5PM) ===
        $schedStmt = $pdo->prepare("
            INSERT INTO supplier_rider_schedules 
            (rider_id, day_of_week, time_in, time_out, break_start, break_end, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        for ($day = 1; $day <= 7; $day++) {
            $is_weekday = $day <= 5;
            $schedStmt->execute([
                $new_rider_id,
                $day,
                '08:00:00',
                '17:00:00',
                $is_weekday ? '12:00:00' : null,
                $is_weekday ? '13:00:00' : null,
                $is_weekday ? 1 : 0
            ]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Rider added successfully. Verification: PENDING.',
            'rider_id' => $new_rider_id
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: UPDATE — Edit rider info
// ============================================================
if ($action === 'update') {
    try {
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        $rider = getRiderOrFail($pdo, $rider_id, $supplier_id);
        
        $full_name = trim($_POST['full_name'] ?? '');
        if (empty($full_name)) {
            throw new Exception('Full name is required');
        }
        
        // === Handle file uploads (optional sa update) ===
        $uploadMap = [
            'photo_path' => 'profiles',
            'id_photo_path' => 'ids',
            'id_back_photo_path' => 'ids',
            'license_photo_path' => 'licenses',
            'nbi_clearance_path' => 'clearances',
            'police_clearance_path' => 'clearances',
            'barangay_clearance_path' => 'clearances',
            'medical_cert_path' => 'clearances',
            'vehicle_photo_path' => 'vehicles',
            'or_cr_path' => 'vehicles',
            'vehicle_insurance_path' => 'vehicles',
        ];
        
        $uploads = [];
        foreach ($uploadMap as $field => $subfolder) {
            $path = handleUpload($field, $subfolder, $field);
            if ($path !== null) {
                // Delete old file
                deleteFile($rider[$field]);
                $uploads[$field] = $path;
            }
        }
        
        // === Build dynamic UPDATE query ===
        $updates = [
            'full_name = ?',
            'email = ?',
            'birthdate = ?',
            'address = ?',
            'id_type = ?',
            'id_number = ?',
            'nbi_expiry = ?',
            'police_expiry = ?',
            'barangay_expiry = ?',
            'medical_expiry = ?',
            'license_number = ?',
            'license_expiry = ?',
            'restriction_codes = ?',
            'vehicle_type = ?',
            'vehicle_make = ?',
            'vehicle_model = ?',
            'vehicle_color = ?',
            'plate_number = ?',
            'vehicle_insurance_expiry = ?',
            'emergency_name = ?',
            'emergency_relationship = ?',
            'emergency_phone = ?',
            'emergency_address = ?',
            'blood_type = ?',
            'allergies = ?',
            'date_hired = ?',
            'notes = ?',
        ];
        
        $params = [
            $full_name,
            !empty($_POST['email']) ? trim($_POST['email']) : null,
            !empty($_POST['birthdate']) ? $_POST['birthdate'] : null,
            !empty($_POST['address']) ? trim($_POST['address']) : null,
            !empty($_POST['id_type']) ? trim($_POST['id_type']) : null,
            !empty($_POST['id_number']) ? trim($_POST['id_number']) : null,
            !empty($_POST['nbi_expiry']) ? $_POST['nbi_expiry'] : null,
            !empty($_POST['police_expiry']) ? $_POST['police_expiry'] : null,
            !empty($_POST['barangay_expiry']) ? $_POST['barangay_expiry'] : null,
            !empty($_POST['medical_expiry']) ? $_POST['medical_expiry'] : null,
            !empty($_POST['license_number']) ? trim($_POST['license_number']) : null,
            !empty($_POST['license_expiry']) ? $_POST['license_expiry'] : null,
            !empty($_POST['restriction_codes']) ? trim($_POST['restriction_codes']) : null,
            !empty($_POST['vehicle_type']) ? trim($_POST['vehicle_type']) : null,
            !empty($_POST['vehicle_make']) ? trim($_POST['vehicle_make']) : null,
            !empty($_POST['vehicle_model']) ? trim($_POST['vehicle_model']) : null,
            !empty($_POST['vehicle_color']) ? trim($_POST['vehicle_color']) : null,
            !empty($_POST['plate_number']) ? trim($_POST['plate_number']) : null,
            !empty($_POST['vehicle_insurance_expiry']) ? $_POST['vehicle_insurance_expiry'] : null,
            !empty($_POST['emergency_name']) ? trim($_POST['emergency_name']) : null,
            !empty($_POST['emergency_relationship']) ? trim($_POST['emergency_relationship']) : null,
            !empty($_POST['emergency_phone']) ? trim($_POST['emergency_phone']) : null,
            !empty($_POST['emergency_address']) ? trim($_POST['emergency_address']) : null,
            !empty($_POST['blood_type']) ? trim($_POST['blood_type']) : null,
            !empty($_POST['allergies']) ? trim($_POST['allergies']) : null,
            !empty($_POST['date_hired']) ? $_POST['date_hired'] : null,
            !empty($_POST['notes']) ? trim($_POST['notes']) : null,
        ];
        
        // Add uploaded files
        foreach ($uploads as $field => $path) {
            $updates[] = "$field = ?";
            $params[] = $path;
        }
        
        // Allow status update
        if (!empty($_POST['status']) && in_array($_POST['status'], ['Active', 'Inactive', 'Suspended', 'On Leave'])) {
            $updates[] = "status = ?";
            $params[] = $_POST['status'];
        }
        
        // Allow verification_status update
        if (!empty($_POST['verification_status']) && 
            in_array($_POST['verification_status'], ['Pending', 'Verified', 'Rejected', 'Expired'])) {
            $updates[] = "verification_status = ?";
            $params[] = $_POST['verification_status'];
            
            if ($_POST['verification_status'] === 'Verified') {
                $updates[] = "verified_at = NOW()";
                $updates[] = "verified_by = ?";
                $params[] = $supplier_id;
            }
        }
        
        $params[] = $rider_id;
        $params[] = $supplier_id;
        
        $sql = "UPDATE supplier_riders SET " . implode(', ', $updates) . " 
                WHERE id = ? AND supplier_id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        echo json_encode([
            'success' => true,
            'message' => 'Rider updated successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: DELETE — Deactivate rider (soft delete)
// ============================================================
if ($action === 'delete') {
    try {
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        getRiderOrFail($pdo, $rider_id, $supplier_id);
        
        // Check kung may active deliveries
        $check = $pdo->prepare("
            SELECT COUNT(*) FROM purchase_orders
            WHERE assigned_rider_id = ?
            AND status IN ('To Ship', 'Shipped')
        ");
        $check->execute([$rider_id]);
        $active = (int)$check->fetchColumn();
        
        if ($active > 0) {
            throw new Exception("Cannot deactivate rider with $active active deliveries. Please reassign first.");
        }
        
        // Soft delete: set status to Inactive
        $stmt = $pdo->prepare("
            UPDATE supplier_riders SET status = 'Inactive' 
            WHERE id = ? AND supplier_id = ?
        ");
        $stmt->execute([$rider_id, $supplier_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Rider deactivated'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: RESET_PIN — I-reset ang rider PIN
// ============================================================
if ($action === 'reset_pin') {
    try {
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        $new_pin = trim($_POST['new_pin'] ?? '');
        
        getRiderOrFail($pdo, $rider_id, $supplier_id);
        
        if (!preg_match('/^\d{6}$/', $new_pin)) {
            throw new Exception('PIN must be exactly 6 digits');
        }
        
        $pin_hash = password_hash($new_pin, PASSWORD_DEFAULT);
        
        $stmt = $pdo->prepare("
            UPDATE supplier_riders 
            SET pin_hash = ?, 
                login_attempts = 0,
                locked_until = NULL
            WHERE id = ? AND supplier_id = ?
        ");
        $stmt->execute([$pin_hash, $rider_id, $supplier_id]);
        
        echo json_encode([
            'success' => true,
            'message' => 'PIN reset successfully'
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: VERIFY — I-verify ang rider
// ============================================================
if ($action === 'verify') {
    try {
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        $decision = $_POST['decision'] ?? ''; // 'approve' or 'reject'
        $reason = trim($_POST['reason'] ?? '');
        
        getRiderOrFail($pdo, $rider_id, $supplier_id);
        
        if (!in_array($decision, ['approve', 'reject'])) {
            throw new Exception('Invalid decision');
        }
        
        $new_status = ($decision === 'approve') ? 'Verified' : 'Rejected';
        
        $sql = "UPDATE supplier_riders 
                SET verification_status = ?,
                    verified_at = NOW(),
                    verified_by = ?";
        
        if ($decision === 'reject' && !empty($reason)) {
            $sql .= ", notes = CONCAT(COALESCE(notes, ''), '\n[Rejected: ', ?, ']')";
        }
        
        $sql .= " WHERE id = ? AND supplier_id = ?";
        
        $stmt = $pdo->prepare($sql);
        
        if ($decision === 'reject' && !empty($reason)) {
            $stmt->execute([$new_status, $supplier_id, $reason, $rider_id, $supplier_id]);
        } else {
            $stmt->execute([$new_status, $supplier_id, $rider_id, $supplier_id]);
        }
        
        echo json_encode([
            'success' => true,
            'message' => "Rider $new_status successfully"
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: GET_SCHEDULE — Kunin ang shift schedule
// ============================================================
if ($action === 'get_schedule') {
    try {
        $rider_id = (int)($_GET['rider_id'] ?? 0);
        getRiderOrFail($pdo, $rider_id, $supplier_id);
        
        $stmt = $pdo->prepare("
            SELECT id, day_of_week, time_in, time_out, break_start, break_end, is_active
            FROM supplier_rider_schedules
            WHERE rider_id = ?
            ORDER BY day_of_week ASC
        ");
        $stmt->execute([$rider_id]);
        $schedule = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Ensure all 7 days exist
        $byDay = [];
        foreach ($schedule as $s) {
            $byDay[(int)$s['day_of_week']] = $s;
        }
        
        $complete = [];
        for ($day = 1; $day <= 7; $day++) {
            if (isset($byDay[$day])) {
                $complete[] = $byDay[$day];
            } else {
                // Default empty
                $complete[] = [
                    'id' => null,
                    'day_of_week' => $day,
                    'time_in' => '08:00:00',
                    'time_out' => '17:00:00',
                    'break_start' => null,
                    'break_end' => null,
                    'is_active' => 0
                ];
            }
        }
        
        echo json_encode([
            'success' => true,
            'data' => $complete
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: UPDATE_SCHEDULE — I-update ang shift schedule
// ============================================================
if ($action === 'update_schedule') {
    try {
        $rider_id = (int)($_POST['rider_id'] ?? 0);
        $schedule_json = $_POST['schedule'] ?? '';
        
        getRiderOrFail($pdo, $rider_id, $supplier_id);
        
        if (empty($schedule_json)) {
            throw new Exception('Schedule data is required');
        }
        
        $schedule = json_decode($schedule_json, true);
        if (!is_array($schedule)) {
            throw new Exception('Invalid schedule format');
        }
        
        $pdo->beginTransaction();
        
        // Delete existing
        $delStmt = $pdo->prepare("DELETE FROM supplier_rider_schedules WHERE rider_id = ?");
        $delStmt->execute([$rider_id]);
        
        // Insert new
        $insStmt = $pdo->prepare("
            INSERT INTO supplier_rider_schedules 
            (rider_id, day_of_week, time_in, time_out, break_start, break_end, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($schedule as $day) {
            $day_num = (int)($day['day_of_week'] ?? 0);
            if ($day_num < 1 || $day_num > 7) continue;
            
            $insStmt->execute([
                $rider_id,
                $day_num,
                $day['time_in'] ?? '08:00:00',
                $day['time_out'] ?? '17:00:00',
                !empty($day['break_start']) ? $day['break_start'] : null,
                !empty($day['break_end']) ? $day['break_end'] : null,
                !empty($day['is_active']) ? 1 : 0
            ]);
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Schedule updated successfully'
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: DELIVERY_HISTORY — History ng rider deliveries
// ============================================================
if ($action === 'delivery_history') {
    try {
        $rider_id = (int)($_GET['rider_id'] ?? 0);
        $limit = min(100, (int)($_GET['limit'] ?? 50));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $status_filter = $_GET['status'] ?? 'all';
        
        getRiderOrFail($pdo, $rider_id, $supplier_id);
        
        $sql = "SELECT 
                    po.id,
                    po.po_number,
                    po.status,
                    po.total_amount,
                    po.actual_delivery,
                    po.delivery_receiver_name,
                    po.delivery_attempts,
                    po.failure_reason,
                    po.shipping_address,
                    po.created_at
                FROM purchase_orders po
                WHERE po.assigned_rider_id = ?";
        
        $params = [$rider_id];
        
        if ($status_filter !== 'all') {
            $sql .= " AND po.status = ?";
            $params[] = $status_filter;
        }
        
        $sql .= " ORDER BY po.created_at DESC LIMIT $limit OFFSET $offset";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Count total
        $countSql = "SELECT COUNT(*) FROM purchase_orders WHERE assigned_rider_id = ?";
        $countParams = [$rider_id];
        if ($status_filter !== 'all') {
            $countSql .= " AND status = ?";
            $countParams[] = $status_filter;
        }
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($countParams);
        $total = (int)$countStmt->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => $history,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: GET_AVAILABLE — Dropdown para sa assign
// ============================================================
if ($action === 'get_available') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                r.id,
                r.full_name,
                r.phone,
                r.vehicle_type,
                r.plate_number,
                r.photo_path,
                (SELECT COUNT(*) FROM purchase_orders po 
                 WHERE po.assigned_rider_id = r.id 
                 AND po.status IN ('To Ship', 'Shipped')) as active_deliveries
            FROM supplier_riders r
            WHERE r.supplier_id = ?
              AND r.status = 'Active'
              AND r.verification_status = 'Verified'
            ORDER BY r.full_name ASC
        ");
        $stmt->execute([$supplier_id]);
        $riders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Cast
        foreach ($riders as &$r) {
            $r['id'] = (int)$r['id'];
            $r['active_deliveries'] = (int)$r['active_deliveries'];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $riders
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// ACTION: STATS — Stats para sa rider dashboard card
// ============================================================
if ($action === 'stats') {
    try {
        $stats = [];
        
        // Total riders
        $s = $pdo->prepare("SELECT COUNT(*) FROM supplier_riders WHERE supplier_id = ?");
        $s->execute([$supplier_id]);
        $stats['total_riders'] = (int)$s->fetchColumn();
        
        // Active riders
        $s = $pdo->prepare("SELECT COUNT(*) FROM supplier_riders WHERE supplier_id = ? AND status = 'Active'");
        $s->execute([$supplier_id]);
        $stats['active_riders'] = (int)$s->fetchColumn();
        
        // Verified riders
        $s = $pdo->prepare("SELECT COUNT(*) FROM supplier_riders WHERE supplier_id = ? AND verification_status = 'Verified'");
        $s->execute([$supplier_id]);
        $stats['verified_riders'] = (int)$s->fetchColumn();
        
        // Pending verification
        $s = $pdo->prepare("SELECT COUNT(*) FROM supplier_riders WHERE supplier_id = ? AND verification_status = 'Pending'");
        $s->execute([$supplier_id]);
        $stats['pending_verification'] = (int)$s->fetchColumn();
        
        // Currently delivering (riders na may active deliveries)
        $s = $pdo->prepare("
            SELECT COUNT(DISTINCT assigned_rider_id) FROM purchase_orders
            WHERE supplier_id = ?
              AND assigned_rider_id IS NOT NULL
              AND status IN ('To Ship', 'Shipped')
        ");
        $s->execute([$supplier_id]);
        $stats['currently_delivering'] = (int)$s->fetchColumn();
        
        echo json_encode([
            'success' => true,
            'data' => $stats
        ]);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ============================================================
// DEFAULT — Invalid action
// ============================================================
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Invalid action']);