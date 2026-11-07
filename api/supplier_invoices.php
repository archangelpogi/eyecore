<?php
// api/supplier_invoices.php
require_once __DIR__ . '/../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['supplier_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$supplier_id = $_SESSION['supplier_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch($action) {
    case 'get_stats':
        getInvoiceStats($pdo, $supplier_id);
        break;
    case 'get_invoices':
        getInvoices($pdo, $supplier_id);
        break;
    case 'get_payment_history':
        getPaymentHistory($pdo, $supplier_id);
        break;
    case 'get_invoice_details':
        getInvoiceDetails($pdo, $supplier_id);
        break;
    case 'mark_paid':
        markAsPaid($pdo, $supplier_id);
        break;
    default:
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
}

// ============================================
// HELPER: Collation-safe comparison
// ============================================
// Ang mga column na `vendor` at `supplier_name` ay magkaibang collation
// (utf8mb4_general_ci vs utf8mb4_unicode_ci). Para maiwasan ang
// "Illegal mix of collations" error, laging i-apply ang COLLATE sa
// magkabilang side ng comparison at sa JOINs.

function getInvoiceStats($pdo, $supplier_id) {
    try {
        // 1. Unpaid
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as unpaid_count,
                COALESCE(SUM(amount), 0) as unpaid_total
            FROM expenses 
            WHERE vendor COLLATE utf8mb4_unicode_ci IN (
                SELECT supplier_name COLLATE utf8mb4_unicode_ci 
                FROM suppliers WHERE id = ?
            )
            AND status IN ('Pending', 'Approved', 'Ready to Pay')
        ");
        $stmt->execute([$supplier_id]);
        $unpaid = $stmt->fetch();

        // 2. Paid
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as paid_count,
                COALESCE(SUM(amount), 0) as paid_total
            FROM expenses 
            WHERE vendor COLLATE utf8mb4_unicode_ci IN (
                SELECT supplier_name COLLATE utf8mb4_unicode_ci 
                FROM suppliers WHERE id = ?
            )
            AND status = 'Paid'
        ");
        $stmt->execute([$supplier_id]);
        $paid = $stmt->fetch();

        // 3. Overdue
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as overdue_count
            FROM expenses 
            WHERE vendor COLLATE utf8mb4_unicode_ci IN (
                SELECT supplier_name COLLATE utf8mb4_unicode_ci 
                FROM suppliers WHERE id = ?
            )
            AND status NOT IN ('Paid', 'Cancelled')
            AND due_date < CURDATE()
        ");
        $stmt->execute([$supplier_id]);
        $overdue = $stmt->fetch();

        // 4. Month total
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as month_total
            FROM expenses 
            WHERE vendor COLLATE utf8mb4_unicode_ci IN (
                SELECT supplier_name COLLATE utf8mb4_unicode_ci 
                FROM suppliers WHERE id = ?
            )
            AND status = 'Paid'
            AND MONTH(payment_date) = MONTH(CURDATE())
            AND YEAR(payment_date) = YEAR(CURDATE())
        ");
        $stmt->execute([$supplier_id]);
        $month = $stmt->fetch();

        // 5. On-time rate
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_paid,
                SUM(CASE WHEN payment_date <= due_date OR due_date IS NULL THEN 1 ELSE 0 END) as on_time
            FROM expenses 
            WHERE vendor COLLATE utf8mb4_unicode_ci IN (
                SELECT supplier_name COLLATE utf8mb4_unicode_ci 
                FROM suppliers WHERE id = ?
            )
            AND status = 'Paid'
        ");
        $stmt->execute([$supplier_id]);
        $rate = $stmt->fetch();
        
        $on_time_rate = ($rate['total_paid'] ?? 0) > 0 
            ? round(($rate['on_time'] / $rate['total_paid']) * 100) 
            : 100;

        echo json_encode([
            'success' => true,
            'data' => [
                'unpaid_count'   => (int)($unpaid['unpaid_count'] ?? 0),
                'unpaid_total'   => (float)($unpaid['unpaid_total'] ?? 0),
                'paid_count'     => (int)($paid['paid_count'] ?? 0),
                'paid_total'     => (float)($paid['paid_total'] ?? 0),
                'overdue_count'  => (int)($overdue['overdue_count'] ?? 0),
                'month_total'    => (float)($month['month_total'] ?? 0),
                'on_time_rate'   => (int)$on_time_rate
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getInvoices($pdo, $supplier_id) {
    $status = $_GET['status'] ?? 'unpaid';
    $filter = $_GET['filter'] ?? 'all';
    $search = $_GET['search'] ?? '';
    $page   = max(1, (int)($_GET['page'] ?? 1));
    $limit  = max(1, min(100, (int)($_GET['limit'] ?? 10)));
    $offset = ($page - 1) * $limit;

    try {
        // Kunin ang supplier_name
        $stmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch();

        if (!$supplier) {
            echo json_encode(['success' => false, 'error' => 'Supplier not found']);
            return;
        }
        $supplier_name = $supplier['supplier_name'];

        // Base WHERE clause — collate-safe
        $where  = "e.vendor COLLATE utf8mb4_unicode_ci = ?";
        $params = [$supplier_name];

        if ($status === 'unpaid') {
            $where .= " AND e.status IN ('Pending', 'Approved', 'Ready to Pay')";
            if ($filter !== 'all') {
                $where .= " AND e.status = ?";
                $params[] = $filter;
            }
        } else {
            $where .= " AND e.status = 'Paid'";
            if ($filter !== 'all') {
                $where .= " AND e.payment_method = ?";
                $params[] = $filter;
            }
        }

        if (!empty($search)) {
            $where .= " AND (e.expense_code LIKE ? OR e.description LIKE ? OR e.po_number LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM expenses e WHERE $where";
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        // Get data — with collate-safe JOINs
        $query = "
            SELECT 
                e.*,
                pr.pr_number,
                po.po_number,
                po.status as po_status
            FROM expenses e
            LEFT JOIN purchase_requests pr 
                ON e.pr_id COLLATE utf8mb4_unicode_ci = pr.id COLLATE utf8mb4_unicode_ci
            LEFT JOIN purchase_orders po 
                ON e.po_number COLLATE utf8mb4_unicode_ci = po.po_number COLLATE utf8mb4_unicode_ci
            WHERE $where
            ORDER BY e.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => $limit > 0 ? ceil($total / $limit) : 0
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getPaymentHistory($pdo, $supplier_id) {
    $from   = $_GET['from']   ?? date('Y-m-01');
    $to     = $_GET['to']     ?? date('Y-m-d');
    $search = $_GET['search'] ?? '';

    try {
        // Kunin ang supplier_name
        $stmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch();

        if (!$supplier) {
            echo json_encode(['success' => false, 'error' => 'Supplier not found']);
            return;
        }
        $supplier_name = $supplier['supplier_name'];

        // Base WHERE clause — collate-safe
        $where  = "e.vendor COLLATE utf8mb4_unicode_ci = ? AND e.status = 'Paid'";
        $params = [$supplier_name];

        // Date filter
        $where .= " AND DATE(e.payment_date) BETWEEN ? AND ?";
        $params[] = $from;
        $params[] = $to;

        if (!empty($search)) {
            $where .= " AND (e.expense_code LIKE ? OR e.description LIKE ? OR e.po_number LIKE ?)";
            $searchTerm = "%$search%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }

        // Query with collate-safe aliases
        $query = "
            SELECT 
                e.*,
                pr.pr_number,
                po.po_number
            FROM expenses e
            LEFT JOIN purchase_requests pr 
                ON e.pr_id COLLATE utf8mb4_unicode_ci = pr.id COLLATE utf8mb4_unicode_ci
            LEFT JOIN purchase_orders po 
                ON e.po_number COLLATE utf8mb4_unicode_ci = po.po_number COLLATE utf8mb4_unicode_ci
            WHERE $where
            ORDER BY e.payment_date DESC, e.updated_at DESC
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $data = $stmt->fetchAll();

        // Calculate total
        $total = array_sum(array_column($data, 'amount'));

        echo json_encode([
            'success' => true,
            'data' => $data,
            'total' => $total,
            'debug' => [
                'count' => count($data),
                'supplier' => $supplier_name
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getInvoiceDetails($pdo, $supplier_id) {
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            SELECT 
                e.*,
                pr.pr_number,
                po.po_number,
                pr.purpose as description
            FROM expenses e
            LEFT JOIN purchase_requests pr 
                ON e.pr_id COLLATE utf8mb4_unicode_ci = pr.id COLLATE utf8mb4_unicode_ci
            LEFT JOIN purchase_orders po 
                ON e.po_number COLLATE utf8mb4_unicode_ci = po.po_number COLLATE utf8mb4_unicode_ci
            WHERE e.id = ? 
            AND e.vendor COLLATE utf8mb4_unicode_ci IN (
                SELECT supplier_name COLLATE utf8mb4_unicode_ci 
                FROM suppliers WHERE id = ?
            )
        ");
        $stmt->execute([$id, $supplier_id]);
        $invoice = $stmt->fetch();

        if (!$invoice) {
            echo json_encode(['success' => false, 'error' => 'Invoice not found']);
            return;
        }

        // Get items
        $stmt = $pdo->prepare("
            SELECT * FROM expense_items 
            WHERE expense_id = ?
        ");
        $stmt->execute([$id]);
        $items = $stmt->fetchAll();

        $invoice['items'] = $items;

        echo json_encode([
            'success' => true,
            'data' => $invoice
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function markAsPaid($pdo, $supplier_id) {
    $invoice_id        = (int)($_POST['invoice_id'] ?? 0);
    $payment_date      = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method    = $_POST['payment_method'] ?? '';
    $payment_reference = $_POST['payment_reference'] ?? '';
    $notes             = $_POST['notes'] ?? '';

    if ($invoice_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid invoice ID']);
        return;
    }

    try {
        // Check if invoice exists and belongs to this supplier
        $stmt = $pdo->prepare("
            SELECT id FROM expenses 
            WHERE id = ? 
            AND vendor COLLATE utf8mb4_unicode_ci IN (
                SELECT supplier_name COLLATE utf8mb4_unicode_ci 
                FROM suppliers WHERE id = ?
            )
        ");
        $stmt->execute([$invoice_id, $supplier_id]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Invoice not found']);
            return;
        }

        // Update invoice — ayos ang CONCAT, dinagdagan ng closing bracket at separator
        $stmt = $pdo->prepare("
            UPDATE expenses 
            SET status = 'Paid',
                payment_date = ?,
                payment_method = ?,
                payment_reference = ?,
                notes = CONCAT(
                    COALESCE(notes, ''), 
                    CASE WHEN notes IS NULL OR notes = '' THEN '' ELSE '\n' END,
                    '[Payment: ', ?, ']'
                )
            WHERE id = ?
        ");
        $stmt->execute([
            $payment_date, 
            $payment_method, 
            $payment_reference, 
            $notes, 
            $invoice_id
        ]);

        // Log activity (safe — kung walang user_id, 0 ang gagamitin)
        $userId = $_SESSION['user_id'] ?? 0;
        $stmt = $pdo->prepare("
            INSERT INTO expense_activity_log 
                (expense_id, action, user_id, user_name, details, created_at)
            VALUES 
                (?, 'MARKED_PAID', ?, 'Supplier', ?, NOW())
        ");
        $stmt->execute([
            $invoice_id, 
            $userId, 
            "Marked as paid via $payment_method"
        ]);

        echo json_encode(['success' => true, 'message' => 'Invoice marked as paid']);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}