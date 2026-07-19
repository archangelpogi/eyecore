<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once '../config/db.php';

// SuperAdmin only ang makakakita ng income data
if (($_SESSION['role'] ?? '') !== 'SuperAdmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_GET['action'] ?? '';

// Optional filters (galing sa query string)
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // default: simula ng buwan
$date_to   = $_GET['date_to'] ?? date('Y-m-d');
$plan_id   = $_GET['plan_id'] ?? '';

// Common WHERE builder
function buildFilters($pdo, $date_from, $date_to, $plan_id) {
    $where = "pt.status = 'completed' AND DATE(pt.transaction_date) BETWEEN ? AND ?";
    $params = [$date_from, $date_to];

    if (!empty($plan_id)) {
        $where .= " AND cs.plan_id = ?";
        $params[] = $plan_id;
    }

    return [$where, $params];
}

try {
    if ($action === 'summary') {
        // ===== SUMMARY CARDS =====
        [$where, $params] = buildFilters($pdo, $date_from, $date_to, $plan_id);

        // Total income within filtered range
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(pt.amount), 0) AS total_income, COUNT(*) AS total_transactions
            FROM payment_transactions pt
            JOIN clinic_subscriptions cs ON pt.subscription_id = cs.id
            WHERE $where
        ");
        $stmt->execute($params);
        $rangeData = $stmt->fetch(PDO::FETCH_ASSOC);

        // All-time total income (walang filter)
        $allTime = $pdo->query("
            SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions WHERE status = 'completed'
        ")->fetch(PDO::FETCH_ASSOC);

        // This month income
        $thisMonth = $pdo->query("
            SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions 
            WHERE status = 'completed' AND MONTH(transaction_date) = MONTH(CURDATE()) 
            AND YEAR(transaction_date) = YEAR(CURDATE())
        ")->fetch(PDO::FETCH_ASSOC);

        // Today income
        $today = $pdo->query("
            SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions 
            WHERE status = 'completed' AND DATE(transaction_date) = CURDATE()
        ")->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'range_income' => (float)$rangeData['total_income'],
            'range_transactions' => (int)$rangeData['total_transactions'],
            'all_time_income' => (float)$allTime['total'],
            'this_month_income' => (float)$thisMonth['total'],
            'today_income' => (float)$today['total'],
        ]);
        exit;
    }

    if ($action === 'chart') {
        // ===== MONTHLY INCOME CHART (last 12 months, hindi apektado ng filter) =====
        $stmt = $pdo->query("
            SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month, SUM(amount) AS total
            FROM payment_transactions
            WHERE status = 'completed'
            AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY month
            ORDER BY month ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    if ($action === 'by_plan') {
        // ===== INCOME PER PLAN (para sa pie/bar breakdown) =====
        [$where, $params] = buildFilters($pdo, $date_from, $date_to, $plan_id);

        $stmt = $pdo->prepare("
            SELECT sp.plan_name, SUM(pt.amount) AS total, COUNT(*) AS transactions
            FROM payment_transactions pt
            JOIN clinic_subscriptions cs ON pt.subscription_id = cs.id
            JOIN subscription_plans sp ON cs.plan_id = sp.id
            WHERE $where
            GROUP BY sp.id, sp.plan_name
            ORDER BY total DESC
        ");
        $stmt->execute($params);

        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    if ($action === 'table') {
        // ===== TRANSACTION TABLE (filtered, paginated) =====
        [$where, $params] = buildFilters($pdo, $date_from, $date_to, $plan_id);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

        // Total count for pagination
        $countStmt = $pdo->prepare("
            SELECT COUNT(*) FROM payment_transactions pt
            JOIN clinic_subscriptions cs ON pt.subscription_id = cs.id
            WHERE $where
        ");
        $countStmt->execute($params);
        $totalRows = (int)$countStmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT pt.id, pt.transaction_date, pt.amount, pt.transaction_type, 
                   pt.payment_method, pt.payment_reference,
                   sp.plan_name, c.clinic_name
            FROM payment_transactions pt
            JOIN clinic_subscriptions cs ON pt.subscription_id = cs.id
            JOIN subscription_plans sp ON cs.plan_id = sp.id
            LEFT JOIN clinics c ON pt.clinic_id = c.id
            WHERE $where
            ORDER BY pt.transaction_date DESC
            LIMIT $per_page OFFSET $offset
        ");
        $stmt->execute($params);

        echo json_encode([
            'success' => true,
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total_rows' => $totalRows,
            'total_pages' => (int)ceil($totalRows / $per_page),
            'current_page' => $page
        ]);
        exit;
    }

    if ($action === 'plans_list') {
        // Para sa filter dropdown
        $plans = $pdo->query("SELECT id, plan_name FROM subscription_plans ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $plans]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);

} catch (PDOException $e) {
    error_log("Income API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}