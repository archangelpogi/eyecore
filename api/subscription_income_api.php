<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

require_once '../config/db.php';

// SuperAdmin only
if (($_SESSION['role'] ?? '') !== 'SuperAdmin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

$date_from = $_GET['date_from'] ?? date('Y-m-01');
$date_to   = $_GET['date_to'] ?? date('Y-m-d');
$plan_id   = $_GET['plan_id'] ?? '';

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
        [$where, $params] = buildFilters($pdo, $date_from, $date_to, $plan_id);

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(pt.amount), 0) AS total_income, COUNT(*) AS total_transactions
            FROM payment_transactions pt
            JOIN clinic_subscriptions cs ON pt.subscription_id = cs.id
            WHERE $where
        ");
        $stmt->execute($params);
        $rangeData = $stmt->fetch(PDO::FETCH_ASSOC);

        $allTime = $pdo->query("
            SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions WHERE status = 'completed'
        ")->fetch(PDO::FETCH_ASSOC);

        $thisMonth = $pdo->query("
            SELECT COALESCE(SUM(amount), 0) AS total FROM payment_transactions 
            WHERE status = 'completed' AND MONTH(transaction_date) = MONTH(CURDATE()) 
            AND YEAR(transaction_date) = YEAR(CURDATE())
        ")->fetch(PDO::FETCH_ASSOC);

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
        [$where, $params] = buildFilters($pdo, $date_from, $date_to, $plan_id);

        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = 20;
        $offset = ($page - 1) * $per_page;

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
        $plans = $pdo->query("SELECT id, plan_name FROM subscription_plans ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $plans]);
        exit;
    }

    // ============================================================
    // SUBSCRIPTION EXPIRY DETECTION
    // ============================================================

    if ($action === 'status_summary') {
        $sql = "
            SELECT 
                SUM(CASE WHEN cs.end_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN cs.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expiring_soon,
                SUM(CASE WHEN cs.end_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS active
            FROM clinic_subscriptions cs
            INNER JOIN (
                SELECT clinic_id, MAX(id) AS latest_id
                FROM clinic_subscriptions
                GROUP BY clinic_id
            ) latest ON latest.latest_id = cs.id
        ";
        $row = $pdo->query($sql)->fetch(PDO::FETCH_ASSOC);

        $noSub = $pdo->query("
            SELECT COUNT(*) FROM clinics c
            WHERE NOT EXISTS (
                SELECT 1 FROM clinic_subscriptions cs WHERE cs.clinic_id = c.id
            )
        ")->fetchColumn();

        echo json_encode([
            'success' => true,
            'expired' => (int)($row['expired'] ?? 0),
            'expiring_soon' => (int)($row['expiring_soon'] ?? 0),
            'active' => (int)($row['active'] ?? 0),
            'no_subscription' => (int)$noSub,
        ]);
        exit;
    }

    if ($action === 'expired_list') {
        $stmt = $pdo->query("
            SELECT 
                c.id AS clinic_id,
                c.clinic_name,
                sp.plan_name,
                cs.end_date,
                DATEDIFF(CURDATE(), cs.end_date) AS days_expired,
                (SELECT MAX(pt.transaction_date)
                 FROM payment_transactions pt
                 WHERE pt.subscription_id = cs.id
                   AND pt.status = 'completed') AS last_payment
            FROM clinic_subscriptions cs
            INNER JOIN (
                SELECT clinic_id, MAX(id) AS latest_id
                FROM clinic_subscriptions
                GROUP BY clinic_id
            ) latest ON latest.latest_id = cs.id
            JOIN clinics c ON c.id = cs.clinic_id
            LEFT JOIN subscription_plans sp ON sp.id = cs.plan_id
            WHERE cs.end_date < CURDATE()
            ORDER BY cs.end_date ASC
        ");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // ============================================================
    // FULL CLINIC SUBSCRIPTION STATUS LIST (NEW)
    // ============================================================
    if ($action === 'all_clinics_status') {
        $status_filter = $_GET['status'] ?? 'all';  // all | active | expiring | expired | none

        $sql = "
            SELECT 
                c.id AS clinic_id,
                c.clinic_name,
                sp.plan_name,
                cs.subscription_type,
                cs.start_date,
                cs.end_date,
                cs.status AS sub_status,
                cs.last_payment_date,
                CASE 
                    WHEN cs.end_date IS NULL THEN 'none'
                    WHEN cs.end_date < CURDATE() THEN 'expired'
                    WHEN cs.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'expiring'
                    ELSE 'active'
                END AS state,
                CASE 
                    WHEN cs.end_date IS NULL THEN NULL
                    WHEN cs.end_date < CURDATE() THEN DATEDIFF(CURDATE(), cs.end_date)
                    ELSE DATEDIFF(cs.end_date, CURDATE())
                END AS days_diff,
                (SELECT MAX(pt.transaction_date)
                 FROM payment_transactions pt
                 WHERE pt.clinic_id = c.id
                   AND pt.status = 'completed') AS last_transaction
            FROM clinics c
            LEFT JOIN clinic_subscriptions cs 
                ON cs.clinic_id = c.id
                AND cs.id = (
                    SELECT MAX(id) FROM clinic_subscriptions 
                    WHERE clinic_id = c.id
                )
            LEFT JOIN subscription_plans sp ON sp.id = cs.plan_id
        ";

        // Apply filter
        if ($status_filter === 'expired') {
            $sql .= " HAVING state = 'expired' ";
        } elseif ($status_filter === 'expiring') {
            $sql .= " HAVING state = 'expiring' ";
        } elseif ($status_filter === 'active') {
            $sql .= " HAVING state = 'active' ";
        } elseif ($status_filter === 'none') {
            $sql .= " HAVING state = 'none' ";
        }

        $sql .= " ORDER BY 
            CASE state
                WHEN 'expired' THEN 1
                WHEN 'expiring' THEN 2
                WHEN 'none' THEN 3
                ELSE 4
            END ASC,
            days_diff ASC, c.clinic_name ASC";

        $stmt = $pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $counts = $pdo->query("
            SELECT 
                SUM(CASE WHEN cs.end_date < CURDATE() THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN cs.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expiring,
                SUM(CASE WHEN cs.end_date > DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN cs.end_date IS NULL THEN 1 ELSE 0 END) AS none
            FROM clinic_subscriptions cs
            INNER JOIN (
                SELECT clinic_id, MAX(id) AS latest_id
                FROM clinic_subscriptions
                GROUP BY clinic_id
            ) latest ON latest.latest_id = cs.id
        ")->fetch(PDO::FETCH_ASSOC);

        $totalClinics = $pdo->query("SELECT COUNT(*) FROM clinics")->fetchColumn();

        echo json_encode([
            'success' => true,
            'data' => $rows,
            'counts' => [
                'all' => (int)$totalClinics,
                'expired' => (int)($counts['expired'] ?? 0),
                'expiring' => (int)($counts['expiring'] ?? 0),
                'active' => (int)($counts['active'] ?? 0),
                'none' => (int)($counts['none'] ?? 0),
            ]
        ]);
        exit;
    }

    // ============================================================
    // BASIC PLAN TRIAL DAYS SETTINGS
    // ============================================================

    if ($action === 'get_trial_days') {
        $stmt = $pdo->prepare(
            "SELECT setting_value, updated_at FROM subscription_settings 
             WHERE setting_key = 'basic_trial_days'"
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $snapshots = $pdo->query("
            SELECT trial_days_snapshot AS days, COUNT(*) AS clinics
            FROM clinic_subscriptions
            WHERE trial_days_snapshot IS NOT NULL
            GROUP BY trial_days_snapshot
            ORDER BY trial_days_snapshot DESC
        ")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'current_value' => (int)($row['setting_value'] ?? 3),
            'updated_at' => $row['updated_at'] ?? null,
            'snapshot_distribution' => $snapshots,
        ]);
        exit;
    }

    if ($action === 'update_trial_days') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['success' => false, 'error' => 'POST required']);
            exit;
        }

        $newDays = (int)($_POST['trial_days'] ?? 0);
        if ($newDays < 1 || $newDays > 365) {
            echo json_encode(['success' => false, 'error' => 'Value must be between 1 and 365 days']);
            exit;
        }

        require_once '../include/SubscriptionHelper.php';
        $adminId = (int)($_SESSION['user_id'] ?? 0);
        $subHelper = new SubscriptionHelper($pdo, 0);

        if ($subHelper->setBasicTrialDays($newDays, $adminId)) {
            echo json_encode([
                'success' => true,
                'message' => "Trial days updated to {$newDays}. Only new registrations will use this value — existing subscriptions are NOT affected."
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to update setting']);
        }
        exit;
    }

    if ($action === 'trial_days_history') {
        $stmt = $pdo->query("
            SELECT a.*, 
                   COALESCE(CONCAT(u.first_name, ' ', u.last_name), CONCAT('User #', a.changed_by)) AS changed_by_name
            FROM subscription_settings_audit a
            LEFT JOIN users u ON u.id = a.changed_by
            WHERE a.setting_key = 'basic_trial_days'
            ORDER BY a.changed_at DESC
            LIMIT 20
        ");
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action']);

} catch (PDOException $e) {
    error_log("Income API error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}