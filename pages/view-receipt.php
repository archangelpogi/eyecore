<?php
// PINAKAUNANG linya - walang space bago nito
include '../includes/config.php';
include '../includes/theme.php';

// Auth check
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth/user_login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$input_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$input_id) {
    header('Location: my-appointments.php');
    exit();
}

// ============================================
// ✅ UPDATED QUERY - WITH DISCOUNT AND VAT
// ============================================
$query = "
    SELECT s.*, 
           p.first_name, p.last_name, p.email, p.phone, p.address as patient_address,
           c.clinic_name, c.address as clinic_address, c.contact as clinic_contact, c.clinic_email, c.logo, c.cover_photo,
           DATE_FORMAT(s.sale_date, '%Y%m%d') as invoice_code,
           a.user_id as appointment_user_id, 
           a.appointment_date, 
           a.appointment_time, 
           a.ref_no,
           -- ✅ DISCOUNT AND VAT FIELDS
           s.subtotal,
           s.discount,
           s.discount_type,
           s.discount_percentage,
           s.vat_percentage,
           s.vat_amount,
           s.forfeited_amount,
           s.refunded_amount,
           s.status as sale_status,
           a.discount_type as appt_discount_type,
           a.discount_percentage as appt_discount_percentage,
           a.discount_amount as appt_discount_amount,
           a.vat_percentage as appt_vat_percentage,
           a.vat_amount as appt_vat_amount,
           a.subtotal as appt_subtotal,
           a.forfeited_amount as appt_forfeited_amount,
           a.status as appt_status
    FROM sales s
    INNER JOIN patients p ON s.patient_id = p.id
    INNER JOIN clinics c ON s.clinic_id = c.id
    LEFT JOIN appointments a ON s.appointment_id = a.id
    WHERE s.id = $input_id OR s.appointment_id = $input_id
";

$result = mysqli_query($conn, $query);

if (!$result) {
    die('Database error: ' . mysqli_error($conn));
}

$invoice = mysqli_fetch_assoc($result);

if (!$invoice) {
    die('No receipt found for ID: ' . $input_id);
}

$sale_id = $invoice['id'];

// Permission check
$hasPermission = false;
if ($invoice['appointment_user_id'] == $user_id) {
    $hasPermission = true;
}
if (!$hasPermission) {
    $check_query = mysqli_query($conn, "
        SELECT COUNT(*) as count 
        FROM appointments 
        WHERE patient_id = {$invoice['patient_id']} AND user_id = $user_id
        LIMIT 1
    ");
    $check = mysqli_fetch_assoc($check_query);
    if ($check['count'] > 0) {
        $hasPermission = true;
    }
}
if (!$hasPermission) {
    die('You do not have permission to view this receipt.');
}

// Get items
$items_query = "SELECT * FROM sale_items WHERE sale_id = " . (int)$sale_id;
$items_result = mysqli_query($conn, $items_query);
$items = [];
while ($row = mysqli_fetch_assoc($items_result)) {
    $items[] = $row;
}

// Get payments
$payments_query = "SELECT * FROM payments WHERE sale_id = " . (int)$sale_id . " ORDER BY payment_date DESC";
$payments_result = mysqli_query($conn, $payments_query);
$payments = [];
if ($payments_result) {
    while ($row = mysqli_fetch_assoc($payments_result)) {
        $payments[] = $row;
    }
}
$total_paid = array_sum(array_column($payments, 'amount'));
$balance = floatval($invoice['total_amount']) - $total_paid;

// ✅ Get discount and VAT info
$discount_type = $invoice['discount_type'] ?? $invoice['appt_discount_type'] ?? 'none';
$discount_amount = (float)($invoice['discount'] ?? $invoice['appt_discount_amount'] ?? 0);
$discount_percentage = (float)($invoice['discount_percentage'] ?? $invoice['appt_discount_percentage'] ?? 0);
$vat_amount = (float)($invoice['vat_amount'] ?? $invoice['appt_vat_amount'] ?? 0);
$vat_percentage = (float)($invoice['vat_percentage'] ?? $invoice['appt_vat_percentage'] ?? 0);
$subtotal = (float)($invoice['subtotal'] ?? $invoice['appt_subtotal'] ?? $invoice['total_amount'] ?? 0);
$forfeited = (float)($invoice['forfeited_amount'] ?? $invoice['appt_forfeited_amount'] ?? 0);
$refunded = (float)($invoice['refunded_amount'] ?? 0);
$status = $invoice['sale_status'] ?? $invoice['appt_status'] ?? 'Unpaid';

// Check if refunded
$is_refunded = ($status === 'Refunded' || $status === 'refunded');

// Helper functions
if (!function_exists('getThemeClass')) {
    function getThemeClass() {
        return isset($_COOKIE['theme']) && $_COOKIE['theme'] === 'dark' ? 'theme-dark' : '';
    }
}

function getStatusBadge($status) {
    $badges = [
        'Paid' => '<span class="badge-paid"><i class="fas fa-check-circle"></i> PAID</span>',
        'Unpaid' => '<span class="badge-unpaid"><i class="fas fa-clock"></i> UNPAID</span>',
        'Partial' => '<span class="badge-partial"><i class="fas fa-adjust"></i> PARTIAL</span>',
        'Refunded' => '<span class="badge-refunded"><i class="fas fa-arrow-return-left"></i> REFUNDED</span>',
        'refunded' => '<span class="badge-refunded"><i class="fas fa-arrow-return-left"></i> REFUNDED</span>'
    ];
    return $badges[$status] ?? '<span class="badge-unpaid">' . htmlspecialchars($status) . '</span>';
}

function getPaymentMethodIcon($method) {
    $icons = [
        'cash' => 'fa-money-bill-wave',
        'gcash' => 'fa-mobile-alt',
        'paymaya' => 'fa-mobile-alt',
        'credit_card' => 'fa-credit-card',
        'bank_transfer' => 'fa-university'
    ];
    return $icons[$method] ?? 'fa-receipt';
}

function getClinicImg($c) {
    if (!empty($c['cover_photo']))  return '/eyecore/assets/images/clinic-covers/'  . $c['cover_photo'];
    if (!empty($c['logo']))         return '/eyecore/assets/images/clinic-logos/'   . $c['logo'];
    return null;
}
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo getThemeClass(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Receipt - <?php echo htmlspecialchars($invoice['clinic_name']); ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        :root {
            --primary: #00b761;
            --primary-dark: #00914d;
            --primary-light: rgba(0, 183, 97, 0.1);
            --primary-gradient: linear-gradient(135deg, #00b761 0%, #00914d 100%);
            --success: #00b761;
            --danger: #dc3545;
            --warning: #ffc107;
            --info: #17a2b8;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --radius-full: 9999px;
            --shadow-sm: 0 2px 8px rgba(0,0,0,0.04);
            --shadow-md: 0 4px 12px rgba(0,0,0,0.08);
            --shadow-lg: 0 8px 24px rgba(0,0,0,0.12);
        }

        /* Light Theme (Default) */
        :root {
            --bg-primary: #f5f7fb;
            --bg-secondary: #ffffff;
            --text-primary: #1a1a2e;
            --text-secondary: #4a5568;
            --text-muted: #718096;
            --border-color: #e2e8f0;
            --border-light: #edf2f7;
        }

        /* Dark Theme */
        .theme-dark {
            --bg-primary: #0f172a;
            --bg-secondary: #1e293b;
            --text-primary: #f1f5f9;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
            --border-color: #334155;
            --border-light: #1e293b;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-primary);
            color: var(--text-primary);
            min-height: 100vh;
            transition: all 0.3s;
        }

        .main-content {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 40px;
        }

        @media (max-width: 1024px) { .main-content { padding: 24px; } }
        @media (max-width: 768px)  { .main-content { padding: 18px 16px 100px; } }

        /* Top Bar */
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .page-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .page-title i {
            font-size: 24px;
            color: var(--primary);
            background: var(--primary-light);
            width: 50px;
            height: 50px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-full);
        }

        .page-title h1 {
            font-size: 24px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .back-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-full);
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .back-btn:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        /* Receipt Grid */
        .receipt-grid {
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 25px;
            align-items: start;
        }

        @media (max-width: 900px) {
            .receipt-grid { grid-template-columns: 1fr; }
        }

        /* Cards */
        .card {
            background: var(--bg-secondary);
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 25px;
            border-bottom: 1px solid var(--border-light);
        }

        .card-header i {
            width: 40px;
            height: 40px;
            background: var(--primary-light);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            font-size: 18px;
            flex-shrink: 0;
        }

        .card-header h2 {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-primary);
        }

        .card-body {
            padding: 25px;
        }

        /* Receipt Header */
        .receipt-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--primary);
        }

        .clinic-info h3 {
            font-size: 24px;
            font-weight: 800;
            color: var(--primary);
            margin-bottom: 4px;
        }

        .receipt-title {
            text-align: right;
        }

        .receipt-title .receipt-badge {
            font-size: 28px;
            font-weight: 800;
            color: var(--primary);
            letter-spacing: 2px;
        }

        /* Info Rows */
        .info-row {
            display: flex;
            margin-bottom: 12px;
        }

        .info-label {
            width: 100px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }

        .info-value {
            flex: 1;
            font-size: 14px;
            color: var(--text-secondary);
        }

        .info-value strong {
            color: var(--text-primary);
        }

        /* Badges */
        .badge-paid, .badge-unpaid, .badge-partial, .badge-refunded {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
        }
        .badge-paid { background: #d1fae5; color: #065f46; }
        .badge-unpaid { background: #fee2e2; color: #991b1b; }
        .badge-partial { background: #fed7aa; color: #92400e; }
        .badge-refunded { background: #f3e8ff; color: #6d28d9; }

        .theme-dark .badge-paid { background: #064e3b; color: #a7f3d0; }
        .theme-dark .badge-unpaid { background: #7f1d1d; color: #fecaca; }
        .theme-dark .badge-partial { background: #78350f; color: #fed7aa; }
        .theme-dark .badge-refunded { background: #4c1d95; color: #c4b5fd; }

        /* Items Table */
        .items-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .items-table th {
            text-align: left;
            padding: 12px 8px;
            border-bottom: 1px solid var(--border-color);
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .items-table td {
            padding: 14px 8px;
            border-bottom: 1px solid var(--border-light);
            font-size: 14px;
            color: var(--text-secondary);
        }

        .items-table td:last-child, .items-table th:last-child {
            text-align: right;
        }

        .grand-total {
            font-size: 18px;
            font-weight: 800;
            color: var(--primary);
        }

        .discount-row { color: #dc2626; }
        .vat-row { color: #d97706; }
        .vat-exempt-row { color: #065f46; }
        .refunded-row { color: #6d28d9; }

        .theme-dark .discount-row { color: #fca5a5; }
        .theme-dark .vat-row { color: #fcd34d; }
        .theme-dark .vat-exempt-row { color: #6ee7b7; }
        .theme-dark .refunded-row { color: #a78bfa; }

        /* Payment History */
        .payment-history {
            background: var(--bg-primary);
            border-radius: var(--radius-md);
            padding: 16px;
            margin-top: 20px;
        }

        .payment-history h4 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-light);
        }

        .payment-item:last-child {
            border-bottom: none;
        }

        .payment-method {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .payment-method i {
            width: 28px;
            color: var(--primary);
        }

        .payment-amount {
            font-weight: 700;
            color: var(--success);
        }

        .balance-row {
            margin-top: 12px;
            padding-top: 12px;
            border-top: 2px dashed var(--border-color);
            display: flex;
            justify-content: space-between;
            font-weight: 700;
        }

        .receipt-footer {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-light);
            text-align: center;
        }

        .footer-note {
            font-size: 12px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }

        /* Sidebar Styles */
        .clinic-sidebar-header {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .clinic-sidebar-avatar {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-md);
            overflow: hidden;
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 28px;
            flex-shrink: 0;
        }

        .clinic-sidebar-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .clinic-sidebar-name {
            font-size: 17px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 4px;
        }

        .clinic-sidebar-detail {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            margin-bottom: 14px;
        }

        .clinic-sidebar-detail:last-child {
            margin-bottom: 0;
        }

        .clinic-sidebar-detail i {
            width: 20px;
            color: var(--primary);
            font-size: 14px;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .clinic-sidebar-detail span {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .apt-ref-row {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .apt-ref-row:last-child {
            margin-bottom: 0;
        }

        .apt-ref-row i {
            color: var(--primary);
            font-size: 13px;
            width: 16px;
        }

        .apt-ref-row span {
            font-size: 13px;
            color: var(--text-secondary);
        }

        .apt-ref-row strong {
            font-size: 13px;
            color: var(--text-primary);
            font-weight: 600;
        }

        /* Buttons */
        .btn-primary-solid {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            background: var(--primary-gradient);
            color: white;
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-primary-solid:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 183, 97, 0.3);
        }

        .btn-outline-pill {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 20px;
            background: transparent;
            border: 1.5px solid var(--primary);
            color: var(--primary);
            border-radius: var(--radius-full);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            margin-top: 10px;
            transition: all 0.3s;
        }

        .btn-outline-pill:hover {
            background: var(--primary);
            color: white;
        }

        /* Theme Toggle Button */
        .theme-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: var(--bg-secondary);
            border: 1px solid var(--border-color);
            color: var(--primary);
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            box-shadow: var(--shadow-md);
            transition: all 0.3s;
            z-index: 999;
        }

        .theme-toggle:hover {
            transform: scale(1.1);
            background: var(--primary);
            color: white;
        }

        @media print {
            .top-bar, .back-btn, .btn-primary-solid, .btn-outline-pill, .theme-toggle {
                display: none !important;
            }
            body { background: white; padding: 0; margin: 0; }
            .main-content { padding: 0; margin: 0; }
            .card { box-shadow: none; border: 1px solid #ddd; }
        }
    </style>
</head>
<body>

<div class="main-content">
    <div class="top-bar">
        <div class="page-title">
            <i class="fas fa-receipt"></i>
            <h1>Receipt Details</h1>
        </div>
        <a href="my-appointments.php" class="back-btn">
            <i class="fas fa-arrow-left"></i> Back to Appointments
        </a>
    </div>

    <div class="receipt-grid">

        <!-- LEFT: Main Receipt Card -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-file-invoice"></i>
                <h2>Official Receipt</h2>
            </div>
            <div class="card-body">

                <div class="receipt-header">
                    <div class="clinic-info">
                        <h3><?php echo htmlspecialchars($invoice['clinic_name']); ?></h3>
                        <div style="font-size: 12px; color: var(--text-muted);">Optical Clinic</div>
                        <div style="font-size: 11px; color: var(--text-muted); margin-top: 5px;">
                            <?php echo htmlspecialchars($invoice['clinic_address'] ?? ''); ?>
                        </div>
                    </div>
                    <div class="receipt-title">
                        <div class="receipt-badge">RECEIPT</div>
                        <div style="font-size: 14px; color: var(--text-secondary); margin-top: 5px;">
                            #<?php echo htmlspecialchars($invoice['invoice_code'] ?? $sale_id); ?>
                        </div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <div class="info-row">
                            <div class="info-label">Bill To:</div>
                            <div class="info-value"><strong><?php echo htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']); ?></strong></div>
                        </div>
                        <?php if ($invoice['email']): ?>
                        <div class="info-row">
                            <div class="info-label">Email:</div>
                            <div class="info-value"><?php echo htmlspecialchars($invoice['email']); ?></div>
                        </div>
                        <?php endif; ?>
                        <?php if ($invoice['phone']): ?>
                        <div class="info-row">
                            <div class="info-label">Phone:</div>
                            <div class="info-value"><?php echo htmlspecialchars($invoice['phone']); ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="info-row">
                            <div class="info-label">Date:</div>
                            <div class="info-value"><?php echo date('F j, Y', strtotime($invoice['sale_date'])); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Clinic:</div>
                            <div class="info-value"><?php echo htmlspecialchars($invoice['clinic_name']); ?></div>
                        </div>
                        <div class="info-row">
                            <div class="info-label">Status:</div>
                            <div class="info-value"><?php echo getStatusBadge($invoice['sale_status'] ?? $invoice['appt_status'] ?? 'Unpaid'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- ITEMS TABLE WITH DISCOUNT AND VAT           -->
                <!-- ============================================ -->
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>Price</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($item['item_name']); ?>
                                <small style="display:block; color:var(--text-muted);"><?php echo ucfirst($item['item_type']); ?></small>
                            </td>
                            <td style="text-align:center"><?php echo $item['quantity']; ?></td>
                            <td style="text-align:right">₱<?php echo number_format($item['unit_price'], 2); ?></td>
                            <td style="text-align:right">₱<?php echo number_format($item['total_price'], 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <!-- ✅ SUBTOTAL -->
                        <tr>
                            <td colspan="3" style="text-align:right; font-weight:600; padding-top:12px;">Subtotal:</td>
                            <td style="text-align:right; font-weight:700; padding-top:12px;">₱<?php echo number_format($subtotal, 2); ?></td>
                        </tr>
                        
                        <!-- ✅ DISCOUNT (if any) -->
                        <?php if ($discount_type !== 'none' && $discount_amount > 0): ?>
                        <tr class="discount-row">
                            <td colspan="3" style="text-align:right; font-weight:600;">
                                <?php echo strtoupper($discount_type); ?> Discount (<?php echo round($discount_percentage); ?>%):
                            </td>
                            <td style="text-align:right; font-weight:700;">-₱<?php echo number_format($discount_amount, 2); ?></td>
                        </tr>
                        <tr>
                            <td colspan="3" style="text-align:right; font-weight:600; color: var(--text-muted);">
                                Subtotal after discount:
                            </td>
                            <td style="text-align:right; font-weight:600;">
                                ₱<?php echo number_format($subtotal - $discount_amount, 2); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- ✅ VAT -->
                        <?php if ($vat_amount > 0): ?>
                        <tr class="vat-row">
                            <td colspan="3" style="text-align:right; font-weight:600;">
                                VAT (<?php echo round($vat_percentage); ?>%):
                            </td>
                            <td style="text-align:right; font-weight:700;">+₱<?php echo number_format($vat_amount, 2); ?></td>
                        </tr>
                        <?php else: ?>
                        <tr class="vat-exempt-row">
                            <td colspan="3" style="text-align:right; font-weight:600;">VAT:</td>
                            <td style="text-align:right; font-weight:700;">Exempt</td>
                        </tr>
                        <?php endif; ?>
                        
                        <!-- ✅ GRAND TOTAL -->
                        <tr style="font-weight:700; border-top: 2px solid var(--primary);">
                            <td colspan="3" style="text-align:right; font-size:18px; color: var(--primary);">TOTAL:</td>
                            <td style="text-align:right; font-size:18px; color: var(--primary);">
                                ₱<?php echo number_format($invoice['total_amount'], 2); ?>
                            </td>
                        </tr>
                        
                        <!-- ✅ FORFEITED / REFUNDED (if any) -->
                        <?php if ($is_refunded && $refunded > 0): ?>
                        <tr class="refunded-row">
                            <td colspan="3" style="text-align:right; font-weight:600;">Refunded Amount:</td>
                            <td style="text-align:right; font-weight:700;">₱<?php echo number_format($refunded, 2); ?></td>
                        </tr>
                        <?php elseif ($forfeited > 0 && ($status === 'Cancelled' || $status === 'no-show')): ?>
                        <tr style="color: #dc2626;">
                            <td colspan="3" style="text-align:right; font-weight:600;">Forfeited Amount:</td>
                            <td style="text-align:right; font-weight:700;">₱<?php echo number_format($forfeited, 2); ?></td>
                        </tr>
                        <?php endif; ?>
                    </tfoot>
                </table>

                <?php if (!empty($payments)): ?>
                <div class="payment-history">
                    <h4><i class="fas fa-history"></i> Payment History</h4>
                    <?php foreach ($payments as $payment): ?>
                    <div class="payment-item">
                        <div class="payment-method">
                            <i class="fas <?php echo getPaymentMethodIcon($payment['payment_method']); ?>"></i>
                            <div>
                                <strong><?php echo ucfirst($payment['payment_method']); ?></strong>
                                <small style="display:block; color:var(--text-muted);"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></small>
                            </div>
                        </div>
                        <div class="payment-amount">₱<?php echo number_format($payment['amount'], 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                    <div class="balance-row">
                        <span>Total Paid:</span>
                        <span>₱<?php echo number_format($total_paid, 2); ?></span>
                    </div>
                    <div class="balance-row">
                        <span>Balance Due:</span>
                        <span style="color: var(--danger);">₱<?php echo number_format($balance, 2); ?></span>
                    </div>
                </div>
                <?php endif; ?>

                <div class="receipt-footer">
                    <div class="footer-note">
                        <?php echo nl2br(htmlspecialchars($invoice['notes'] ?? 'Thank you for your business!')); ?>
                    </div>
                    <div class="footer-note">
                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($invoice['clinic_contact'] ?? 'N/A'); ?>
                        <i class="fas fa-envelope" style="margin-left: 12px;"></i> <?php echo htmlspecialchars($invoice['clinic_email'] ?? 'info@eyecore.com'); ?>
                    </div>
                </div>

            </div>
        </div>

        <!-- RIGHT: Sidebar -->
        <div style="display: flex; flex-direction: column; gap: 20px;">

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-clinic-medical"></i>
                    <h2>Clinic Info</h2>
                </div>
                <div class="card-body">
                    <div class="clinic-sidebar-header">
                        <?php $clinic_img = getClinicImg($invoice); ?>
                        <div class="clinic-sidebar-avatar">
                            <?php if ($clinic_img): ?>
                                <img src="<?php echo htmlspecialchars($clinic_img); ?>"
                                     alt="<?php echo htmlspecialchars($invoice['clinic_name']); ?>"
                                     onerror="this.style.display='none';this.parentElement.innerHTML='<i class=\'fas fa-eye\'></i>'">
                            <?php else: ?>
                                <i class="fas fa-eye"></i>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="clinic-sidebar-name"><?php echo htmlspecialchars($invoice['clinic_name']); ?></div>
                            <div style="font-size: 12px; color: var(--text-muted);">Optical Clinic</div>
                        </div>
                    </div>

                    <div class="clinic-sidebar-detail">
                        <i class="fas fa-map-marker-alt"></i>
                        <span><?php echo htmlspecialchars($invoice['clinic_address'] ?? 'N/A'); ?></span>
                    </div>

                    <div class="clinic-sidebar-detail">
                        <i class="fas fa-phone"></i>
                        <span><?php echo htmlspecialchars($invoice['clinic_contact'] ?? 'N/A'); ?></span>
                    </div>

                    <?php if (!empty($invoice['clinic_email'])): ?>
                    <div class="clinic-sidebar-detail">
                        <i class="fas fa-envelope"></i>
                        <span><?php echo htmlspecialchars($invoice['clinic_email']); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-calendar-check"></i>
                    <h2>Appointment Summary</h2>
                </div>
                <div class="card-body">
                    <div style="font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                        Details
                    </div>

                    <?php if (!empty($invoice['ref_no'])): ?>
                    <div class="apt-ref-row">
                        <i class="fas fa-hashtag"></i>
                        <span>Ref #:</span>
                        <strong><?php echo htmlspecialchars($invoice['ref_no']); ?></strong>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['appointment_date'])): ?>
                    <div class="apt-ref-row">
                        <i class="fas fa-calendar"></i>
                        <span>Date:</span>
                        <strong><?php echo date('F j, Y', strtotime($invoice['appointment_date'])); ?></strong>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($invoice['appointment_time'])): ?>
                    <div class="apt-ref-row">
                        <i class="fas fa-clock"></i>
                        <span>Time:</span>
                        <strong><?php echo date('g:i A', strtotime($invoice['appointment_time'])); ?></strong>
                    </div>
                    <?php endif; ?>

                    <div class="apt-ref-row">
                        <i class="fas fa-receipt"></i>
                        <span>Receipt #:</span>
                        <strong><?php echo htmlspecialchars($invoice['invoice_code'] ?? $sale_id); ?></strong>
                    </div>

                    <?php if ($is_refunded): ?>
                    <div class="apt-ref-row" style="margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border-light);">
                        <i class="fas fa-arrow-return-left" style="color: #6d28d9;"></i>
                        <span>Status:</span>
                        <strong style="color: #6d28d9;">Refunded</strong>
                    </div>
                    <?php if ($refunded > 0): ?>
                    <div class="apt-ref-row">
                        <i class="fas fa-money-bill-wave" style="color: #6d28d9;"></i>
                        <span>Refund Amount:</span>
                        <strong style="color: #6d28d9;">₱<?php echo number_format($refunded, 2); ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ============================================ -->
            <!-- PAYMENT SUMMARY WITH DISCOUNT BREAKDOWN     -->
            <!-- ============================================ -->
            <div class="card">
                <div class="card-header">
                    <i class="fas fa-chart-line"></i>
                    <h2>Payment Summary</h2>
                </div>
                <div class="card-body">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px;">
                        <span style="font-size: 13px; color: var(--text-muted);">Subtotal:</span>
                        <span style="font-weight: 600;">₱<?php echo number_format($subtotal, 2); ?></span>
                    </div>
                    
                    <?php if ($discount_type !== 'none' && $discount_amount > 0): ?>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: #dc2626;">
                        <span style="font-size: 13px;">Discount (<?php echo round($discount_percentage); ?>%):</span>
                        <span style="font-weight: 600;">-₱<?php echo number_format($discount_amount, 2); ?></span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($vat_amount > 0): ?>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: #d97706;">
                        <span style="font-size: 13px;">VAT (<?php echo round($vat_percentage); ?>%):</span>
                        <span style="font-weight: 600;">+₱<?php echo number_format($vat_amount, 2); ?></span>
                    </div>
                    <?php else: ?>
                    <div style="display: flex; justify-content: space-between; margin-bottom: 8px; color: #065f46;">
                        <span style="font-size: 13px;">VAT:</span>
                        <span style="font-weight: 600;">Exempt</span>
                    </div>
                    <?php endif; ?>
                    
                    <div style="display: flex; justify-content: space-between; padding-top: 12px; border-top: 2px solid var(--primary); margin-top: 8px;">
                        <span style="font-weight: 700; font-size: 16px;">Total:</span>
                        <span style="font-weight: 800; font-size: 16px; color: var(--primary);">
                            ₱<?php echo number_format($invoice['total_amount'], 2); ?>
                        </span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-light);">
                        <span style="font-size: 13px; color: var(--text-muted);">Amount Paid:</span>
                        <span style="font-weight: 700; color: var(--success);">₱<?php echo number_format($total_paid, 2); ?></span>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; padding-top: 8px;">
                        <span style="font-weight: 600;">Balance:</span>
                        <span style="font-weight: 800; color: <?php echo $balance > 0 ? 'var(--danger)' : 'var(--success)'; ?>;">
                            ₱<?php echo number_format($balance, 2); ?>
                        </span>
                    </div>
                    
                    <?php if ($is_refunded && $refunded > 0): ?>
                    <div style="display: flex; justify-content: space-between; margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border-light);">
                        <span style="font-size: 13px; color: #6d28d9; font-weight: 600;">Refunded:</span>
                        <span style="font-weight: 700; color: #6d28d9;">
                            ₱<?php echo number_format($refunded, 2); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($forfeited > 0 && ($status === 'Cancelled' || $status === 'no-show')): ?>
                    <div style="display: flex; justify-content: space-between; margin-top: 8px;">
                        <span style="font-size: 13px; color: #dc2626; font-weight: 600;">Forfeited:</span>
                        <span style="font-weight: 700; color: #dc2626;">
                            ₱<?php echo number_format($forfeited, 2); ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <i class="fas fa-print"></i>
                    <h2>Actions</h2>
                </div>
                <div class="card-body">
                    <button class="btn-primary-solid" onclick="window.print()">
                        <i class="fas fa-print"></i> Print Receipt
                    </button>
                    <a href="my-appointments.php" class="btn-outline-pill">
                        <i class="fas fa-calendar-alt"></i> My Appointments
                    </a>
                </div>
            </div>

        </div>
    </div>
</div>

<!-- Simple Theme Toggle Button -->
<button class="theme-toggle" onclick="toggleTheme()">
    <i class="fas fa-moon"></i>
</button>

<script>
    function toggleTheme() {
        const html = document.documentElement;
        const icon = document.querySelector('.theme-toggle i');
        
        if (html.classList.contains('theme-dark')) {
            html.classList.remove('theme-dark');
            document.cookie = "theme=light; path=/";
            icon.className = 'fas fa-moon';
        } else {
            html.classList.add('theme-dark');
            document.cookie = "theme=dark; path=/";
            icon.className = 'fas fa-sun';
        }
    }
    
    // Set correct icon on load
    if (document.documentElement.classList.contains('theme-dark')) {
        document.querySelector('.theme-toggle i').className = 'fas fa-sun';
    }
</script>

</body>
</html>