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

function getInvoiceStats($pdo, $supplier_id) {
    try {
        // Get unpaid count and total
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as unpaid_count,
                COALESCE(SUM(amount), 0) as unpaid_total
            FROM expenses 
            WHERE vendor IN (SELECT supplier_name FROM suppliers WHERE id = ?)
            AND status IN ('Pending', 'Approved', 'Ready to Pay')
        ");
        $stmt->execute([$supplier_id]);
        $unpaid = $stmt->fetch();

        // Get paid count and total
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as paid_count,
                COALESCE(SUM(amount), 0) as paid_total
            FROM expenses 
            WHERE vendor IN (SELECT supplier_name FROM suppliers WHERE id = ?)
            AND status = 'Paid'
        ");
        $stmt->execute([$supplier_id]);
        $paid = $stmt->fetch();

        // Get overdue count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as overdue_count
            FROM expenses 
            WHERE vendor IN (SELECT supplier_name FROM suppliers WHERE id = ?)
            AND status NOT IN ('Paid', 'Cancelled')
            AND due_date < CURDATE()
        ");
        $stmt->execute([$supplier_id]);
        $overdue = $stmt->fetch();

        // Get month total
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(amount), 0) as month_total
            FROM expenses 
            WHERE vendor IN (SELECT supplier_name FROM suppliers WHERE id = ?)
            AND status = 'Paid'
            AND MONTH(payment_date) = MONTH(CURDATE())
            AND YEAR(payment_date) = YEAR(CURDATE())
        ");
        $stmt->execute([$supplier_id]);
        $month = $stmt->fetch();

        // Calculate on-time rate
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_paid,
                SUM(CASE WHEN payment_date <= due_date OR due_date IS NULL THEN 1 ELSE 0 END) as on_time
            FROM expenses 
            WHERE vendor IN (SELECT supplier_name FROM suppliers WHERE id = ?)
            AND status = 'Paid'
        ");
        $stmt->execute([$supplier_id]);
        $rate = $stmt->fetch();
        
        $on_time_rate = $rate['total_paid'] > 0 ? round(($rate['on_time'] / $rate['total_paid']) * 100) : 100;

        echo json_encode([
            'success' => true,
            'data' => [
                'unpaid_count' => $unpaid['unpaid_count'],
                'unpaid_total' => $unpaid['unpaid_total'],
                'paid_count' => $paid['paid_count'],
                'paid_total' => $paid['paid_total'],
                'overdue_count' => $overdue['overdue_count'],
                'month_total' => $month['month_total'],
                'on_time_rate' => $on_time_rate
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
    $page = (int)($_GET['page'] ?? 1);
    $limit = (int)($_GET['limit'] ?? 10);
    $offset = ($page - 1) * $limit;

    try {
        // Kunin ang supplier_name
        $stmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch();
        $supplier_name = $supplier['supplier_name'];
        
        // Base WHERE clause
        $where = "e.vendor = ?";
        
        if ($status === 'unpaid') {
            $where .= " AND e.status IN ('Pending', 'Approved', 'Ready to Pay')";
            if ($filter !== 'all') {
                $where .= " AND e.status = " . $pdo->quote($filter);
            }
        } else {
            $where .= " AND e.status = 'Paid'";
            if ($filter !== 'all') {
                $where .= " AND e.payment_method = " . $pdo->quote($filter);
            }
        }
        
        if (!empty($search)) {
            $where .= " AND (e.expense_code LIKE '%$search%' OR e.description LIKE '%$search%' OR e.po_number LIKE '%$search%')";
        }

        // Get total count
        $countQuery = "SELECT COUNT(*) as total FROM expenses e WHERE $where";
        $stmt = $pdo->prepare($countQuery);
        $stmt->execute([$supplier_name]);
        $total = $stmt->fetchColumn();

        // Get data - with proper table aliases
        $query = "
            SELECT 
                e.*,
                pr.pr_number,
                po.po_number,
                po.status as po_status
            FROM expenses e
            LEFT JOIN purchase_requests pr ON e.pr_id = pr.id
            LEFT JOIN purchase_orders po ON e.po_number = po.po_number
            WHERE $where
            ORDER BY e.created_at DESC
            LIMIT $limit OFFSET $offset
        ";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$supplier_name]);
        $data = $stmt->fetchAll();

        echo json_encode([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'total_pages' => ceil($total / $limit)
            ]
        ]);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

function getPaymentHistory($pdo, $supplier_id) {
    $from = $_GET['from'] ?? date('Y-m-01');
    $to = $_GET['to'] ?? date('Y-m-d');
    $search = $_GET['search'] ?? '';

    try {
        // Kunin ang supplier_name
        $stmt = $pdo->prepare("SELECT supplier_name FROM suppliers WHERE id = ?");
        $stmt->execute([$supplier_id]);
        $supplier = $stmt->fetch();
        $supplier_name = $supplier['supplier_name'];

        // Base WHERE clause - specify table alias
        $where = "e.vendor = ? AND e.status = 'Paid'";
        
        // Date filter - use e.payment_date
        $where .= " AND DATE(e.payment_date) BETWEEN ? AND ?";
        
        if (!empty($search)) {
            $where .= " AND (e.expense_code LIKE ? OR e.description LIKE ? OR e.po_number LIKE ?)";
        }

        // Query with proper aliases
        $query = "
            SELECT 
                e.*,
                pr.pr_number,
                po.po_number
            FROM expenses e
            LEFT JOIN purchase_requests pr ON e.pr_id = pr.id
            LEFT JOIN purchase_orders po ON e.po_number = po.po_number
            WHERE $where
            ORDER BY e.payment_date DESC, e.updated_at DESC
        ";
        
        $stmt = $pdo->prepare($query);
        
        if (!empty($search)) {
            $searchTerm = "%$search%";
            $stmt->execute([$supplier_name, $from, $to, $searchTerm, $searchTerm, $searchTerm]);
        } else {
            $stmt->execute([$supplier_name, $from, $to]);
        }
        
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
    $id = $_GET['id'] ?? 0;

    try {
        $stmt = $pdo->prepare("
            SELECT 
                e.*,
                pr.pr_number,
                po.po_number,
                pr.purpose as description
            FROM expenses e
            LEFT JOIN purchase_requests pr ON e.pr_id = pr.id
            LEFT JOIN purchase_orders po ON e.po_number = po.po_number
            WHERE e.id = ? AND vendor IN (SELECT supplier_name FROM suppliers WHERE id = ?)
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
    $invoice_id = $_POST['invoice_id'] ?? 0;
    $payment_date = $_POST['payment_date'] ?? date('Y-m-d');
    $payment_method = $_POST['payment_method'] ?? '';
    $payment_reference = $_POST['payment_reference'] ?? '';
    $notes = $_POST['notes'] ?? '';

    try {
        // Check if invoice exists and belongs to this supplier
        $stmt = $pdo->prepare("
            SELECT id FROM expenses 
            WHERE id = ? AND vendor IN (SELECT supplier_name FROM suppliers WHERE id = ?)
        ");
        $stmt->execute([$invoice_id, $supplier_id]);
        
        if (!$stmt->fetch()) {
            echo json_encode(['success' => false, 'error' => 'Invoice not found']);
            return;
        }

        // Update invoice
        $stmt = $pdo->prepare("
            UPDATE expenses 
            SET status = 'Paid',
                payment_date = ?,
                payment_method = ?,
                payment_reference = ?,
                notes = CONCAT(COALESCE(notes, ''), '\n[Payment: ', ?)
            WHERE id = ?
        ");
        $stmt->execute([$payment_date, $payment_method, $payment_reference, $notes, $invoice_id]);

        // Log activity
        $stmt = $pdo->prepare("
            INSERT INTO expense_activity_log (expense_id, action, user_id, user_name, details, created_at)
            VALUES (?, 'MARKED_PAID', ?, 'Supplier', ?, NOW())
        ");
        $stmt->execute([$invoice_id, $_SESSION['user_id'] ?? 0, "Marked as paid via $payment_method"]);

        echo json_encode(['success' => true, 'message' => 'Invoice marked as paid']);

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}
?>