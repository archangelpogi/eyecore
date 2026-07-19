<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
require_once __DIR__ . '/../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['clinic_id'])) {
    header('Location: ../login.php');
    exit;
}

// Get payroll ID
$payroll_id = $_GET['id'] ?? 0;
if (!$payroll_id) {
    die('Invalid payroll ID');
}

// Get action parameters
$download = isset($_GET['download']) && $_GET['download'] == '1';
$print = isset($_GET['print']) && $_GET['print'] == '1';
$preview = isset($_GET['preview']) && $_GET['preview'] == '1';

// Fetch payroll details
try {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            e.employee_no,
            CONCAT(u.first_name, ' ', u.last_name) AS employee_name,
            e.position,
            e.department,
            e.birth_date,
            e.gender,
            e.marital_status,
            e.sss_number,
            e.philhealth_number,
            e.pagibig_number,
            e.tin_number,
            e.bank_name,
            e.bank_account_number,
            e.bank_account_holder,
            e.salary_frequency,
            e.salary_type,
            c.clinic_name,
            c.address as clinic_address,
            c.phone as clinic_phone,
            c.clinic_email,
            CONCAT(ug.first_name, ' ', ug.last_name) as generated_by_name,
            CONCAT(ur.first_name, ' ', ur.last_name) as released_by_name,
            ps.payslip_code
        FROM payroll p
        JOIN employees e ON p.employee_id = e.id
        JOIN users u ON e.user_id = u.id
        JOIN clinics c ON u.clinic_id = c.id
        LEFT JOIN users ug ON p.generated_by = ug.id
        LEFT JOIN users ur ON p.released_by = ur.id
        LEFT JOIN payslips ps ON p.id = ps.payroll_id
        WHERE p.id = ?
        AND u.clinic_id = ?
    ");
    
    $stmt->execute([$payroll_id, $_SESSION['clinic_id']]);
    $payroll = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payroll) {
        die('Payroll not found or access denied');
    }
    
    // Generate payslip code if not exists
    if (empty($payroll['payslip_code'])) {
        $payslip_code = 'PS' . date('Ym') . str_pad($payroll_id, 4, '0', STR_PAD_LEFT);
        $payroll['payslip_code'] = $payslip_code;
    }
    
    // ✅ FIXED: USE ACTUAL DATABASE VALUES FIRST
    $payroll['monthly_gross_salary'] = $payroll['basic_salary'] ?? 0;
    $payroll['basic_pay'] = $payroll['basic_salary'] ?? 0;
    
    // Use database values if they exist
    $payroll['legal_holiday'] = ($payroll['holiday_pay'] ?? 0) * 0.6;
    $payroll['special_holiday'] = ($payroll['holiday_pay'] ?? 0) * 0.4;
    $payroll['leave_credits'] = $payroll['leave_without_pay'] ?? 0;
    
    // ✅ CRITICAL FIX: Use actual database values, not estimates
    $payroll['night_differential'] = $payroll['night_differential'] ?? 0.00;
    $payroll['thirteenth_month_pay'] = $payroll['thirteenth_month'] ?? 0.00;
    
    $payroll['adjustment'] = ($payroll['allowances'] ?? 0) + ($payroll['bonuses'] ?? 0);
    $payroll['undertime'] = $payroll['tardiness'] ?? 0;
    $payroll['sss_loan'] = 0;
    $payroll['cash_advance'] = 0;
    
    // ✅ VERIFY GROSS PAY CALCULATION
    $calculated_total_earnings = 
        $payroll['basic_pay'] + 
        ($payroll['overtime'] ?? 0) + 
        $payroll['legal_holiday'] + 
        $payroll['special_holiday'] + 
        $payroll['night_differential'] + 
        $payroll['adjustment'] + 
        $payroll['thirteenth_month_pay'];
    
    // If there's discrepancy, show both
    $payroll['calculated_total_earnings'] = $calculated_total_earnings;
    
    // Calculate days
    $start_date = new DateTime($payroll['period_start']);
    $end_date = new DateTime($payroll['period_end']);
    $interval = $start_date->diff($end_date);
    $payroll['number_of_days'] = $interval->days + 1;
    
} catch (Exception $e) {
    die('Error fetching payroll details: ' . $e->getMessage());
}

// Format dates
function formatDate($date, $format = 'F d, Y') {
    if (!$date) return 'N/A';
    return date($format, strtotime($date));
}

// Format currency
function formatCurrency($amount) {
    if ($amount === null || $amount === '') {
        return '₱0.00';
    }
    return '₱' . number_format(floatval($amount), 2);
}

// Set headers for download
if ($download) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="Payslip_' . $payroll['employee_no'] . '_' . $payroll['payroll_period'] . '.pdf"');
}

// For printing
if ($print) {
    header('Content-Type: text/html');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payslip - <?php echo htmlspecialchars($payroll['employee_name']); ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <style>
        @media print {
            .no-print { display: none !important; }
            body { font-size: 12px; }
            .container { max-width: 100% !important; padding: 0 !important; }
        }
        
        .payslip-container {
            border: 2px solid #000;
            padding: 20px;
            margin: 0 auto;
            max-width: 800px;
        }
        
        .company-header {
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .border-dashed {
            border-bottom: 2px dashed #666;
        }
        
        .total-row {
            background-color: #f8f9fa;
            font-weight: bold;
        }
        
        .net-pay-box {
            background-color: #e8f5e9;
            border: 2px solid #28a745;
            padding: 15px;
            margin-top: 20px;
        }
        
        .signature-line {
            border-top: 1px solid #000;
            width: 200px;
            margin: 20px auto 5px;
        }
        
        table {
            width: 100%;
            margin-bottom: 0;
        }
        
        table th, table td {
            padding: 5px 8px;
            vertical-align: middle;
        }
        
        .text-right {
            text-align: right;
        }
    </style>
</head>
<body>
    <!-- Action Buttons -->
    <div class="container-fluid bg-light py-2 no-print">
        <div class="row align-items-center">
            <div class="col">
<!-- Palitan ang back button ng close button -->
<button onclick="window.close()" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-x-circle"></i> Close
</button>
                <button class="btn btn-sm btn-outline-primary ms-1" onclick="window.print()">
                    🖨️ Print
                </button>
                <button class="btn btn-sm btn-success ms-1" onclick="window.location.href='generate_payslip.php?id=<?php echo $payroll_id; ?>&download=1'">
                    📥 Download PDF
                </button>
            </div>
            <div class="col-auto">
                <span class="badge bg-<?php echo $payroll['status'] == 'Released' ? 'success' : 'warning'; ?>">
                    <?php echo htmlspecialchars($payroll['status']); ?>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Payslip Container -->
    <div class="container mt-3">
        <div class="payslip-container">
            <!-- Company Header -->
            <div class="company-header text-center">
                <h1 class="mb-2"><?php echo htmlspecialchars($payroll['clinic_name']); ?></h1>
                <p class="mb-1"><strong>PAYSLIP</strong></p>
                <p class="mb-0"><?php echo htmlspecialchars($payroll['clinic_address']); ?></p>
                <?php if ($payroll['clinic_phone']): ?>
                <p class="mb-0">Tel: <?php echo htmlspecialchars($payroll['clinic_phone']); ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Employee Information -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr>
                            <td class="fw-bold" style="width: 120px;">ID No.:</td>
                            <td><?php echo htmlspecialchars($payroll['employee_no']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Name:</td>
                            <td><strong><?php echo htmlspecialchars($payroll['employee_name']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Department:</td>
                            <td><?php echo htmlspecialchars($payroll['department']); ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Position:</td>
                            <td><?php echo htmlspecialchars($payroll['position']); ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-borderless">
                        <tr>
                            <td class="fw-bold" style="width: 140px;">Period Covered:</td>
                            <td><strong><?php echo formatDate($payroll['period_start'], 'M d') . ' - ' . formatDate($payroll['period_end'], 'M d, Y'); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Payslip No:</td>
                            <td><strong><?php echo htmlspecialchars($payroll['payslip_code']); ?></strong></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Payment Date:</td>
                            <td><?php echo $payroll['released_at'] ? formatDate($payroll['released_at']) : 'Pending'; ?></td>
                        </tr>
                        <tr>
                            <td class="fw-bold">Status:</td>
                            <td><span class="badge bg-<?php echo $payroll['status'] == 'Released' ? 'success' : 'warning'; ?>">
                                <?php echo htmlspecialchars($payroll['status']); ?>
                            </span></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Earnings & Deductions Table -->
            <div class="border-dashed mb-3"></div>
            
            <table class="table table-bordered mb-0">
                <thead>
                    <tr class="table-light">
                        <th colspan="2" class="text-center">EARNINGS</th>
                        <th colspan="2" class="text-center">DEDUCTIONS</th>
                    </tr>
                    <tr class="table-light">
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                        <th>Description</th>
                        <th class="text-right">Amount</th>
                    </tr>
                </thead>
<tbody>
    <!-- SECTION HEADER: Monthly Gross Salary -->
    <tr class="fw-bold table-primary">
        <td colspan="2" class="text-center">MONTHLY GROSS SALARY</td>
        <td colspan="2" class="text-center">DEDUCTIONS</td>
    </tr>
    
    <!-- Row 1 -->
    <tr>
        <td>Basic Salary</td>
        <td class="text-right"><?php echo formatCurrency($payroll['basic_salary']); ?></td>
        <td>Tardiness/UT:</td>
        <td class="text-right"><?php echo formatCurrency($payroll['undertime']); ?></td>
    </tr>
    
    <!-- Row 2 -->
    <tr>
        <td>No. of Days</td>
        <td class="text-right"><?php echo $payroll['number_of_days']; ?></td>
        <td>Withholding Tax</td>
        <td class="text-right"><?php echo formatCurrency($payroll['withholding_tax']); ?></td>
    </tr>
    
    <!-- SECTION HEADER: Additional Earnings -->
    <tr class="fw-bold table-light">
        <td colspan="2" class="text-center">ADDITIONAL EARNINGS</td>
        <td colspan="2" class="text-center">GOVERNMENT DEDUCTIONS</td>
    </tr>
    
    <!-- Row 3 -->
    <tr>
        <td>Overtime</td>
        <td class="text-right"><?php echo formatCurrency($payroll['overtime']); ?></td>
        <td>SSS Premium</td>
        <td class="text-right"><?php echo formatCurrency($payroll['sss_contribution']); ?></td>
    </tr>
    
    <!-- Row 4 -->
    <tr>
        <td>Legal Holiday</td>
        <td class="text-right"><?php echo formatCurrency($payroll['legal_holiday']); ?></td>
        <td>SSS Loan</td>
        <td class="text-right"><?php echo formatCurrency($payroll['sss_loan']); ?></td>
    </tr>
    
    <!-- Row 5 -->
    <tr>
        <td>Special Holiday</td>
        <td class="text-right"><?php echo formatCurrency($payroll['special_holiday']); ?></td>
        <td>Philhealth</td>
        <td class="text-right"><?php echo formatCurrency($payroll['philhealth_contribution']); ?></td>
    </tr>
    
    <!-- Row 6 -->
    <tr>
        <td>Leave Credits</td>
        <td class="text-right"><?php echo formatCurrency($payroll['leave_credits']); ?></td>
        <td>Pag-Ibig Fund</td>
        <td class="text-right"><?php echo formatCurrency($payroll['pagibig_contribution']); ?></td>
    </tr>
    
    <!-- Row 7 -->
    <tr>
        <td>Night Differential</td>
        <td class="text-right"><?php echo formatCurrency($payroll['night_differential']); ?></td>
        <td>Cash Advance</td>
        <td class="text-right"><?php echo formatCurrency($payroll['cash_advance']); ?></td>
    </tr>
    
    <!-- Row 8 -->
    <tr>
        <td>Adjustment</td>
        <td class="text-right"><?php echo formatCurrency($payroll['adjustment']); ?></td>
        <td>Other Deductions</td>
        <td class="text-right"><?php echo formatCurrency($payroll['other_deductions']); ?></td>
    </tr>
    
    <!-- Row 9 -->
    <tr>
        <td>13th Month Pay</td>
        <td class="text-right"><?php echo formatCurrency($payroll['thirteenth_month_pay']); ?></td>
        <td rowspan="1" class="align-middle text-center fw-bold">TOTAL DEDUCTIONS</td>
        <td rowspan="1" class="text-right align-middle">
            <h5 class="mb-0"><?php echo formatCurrency($payroll['total_deductions']); ?></h5>
        </td>
    </tr>
    
    <!-- TOTALS SECTION -->
    <tr class="table-secondary fw-bold">
        <td>TOTAL EARNINGS</td>
        <td class="text-right"><?php echo formatCurrency($payroll['gross_pay']); ?></td>
        <td>TOTAL DEDUCTIONS</td>
        <td class="text-right"><?php echo formatCurrency($payroll['total_deductions']); ?></td>
    </tr>
    
    <!-- NET PAY ROW -->
    <tr class="table-success fw-bold">
        <td colspan="2" class="text-center">NET PAY</td>
        <td colspan="2" class="text-center">
            <h3 class="text-success mb-0"><?php echo formatCurrency($payroll['net_pay']); ?></h3>
        </td>
    </tr>
</tbody>
            </table>
            
            <!-- Signatures -->
            <div class="row mt-4">
                <div class="col-md-6 text-center">
                    <div class="signature-line"></div>
                    <p class="mb-0 fw-bold">Employee's Signature</p>
                    <p class="mb-0 text-muted small">Date Received: _______________</p>
                </div>
                <div class="col-md-6 text-center">
                    <div class="signature-line"></div>
                    <p class="mb-0 fw-bold">Prepared by</p>
                    <p class="mb-0 text-muted small"><?php echo htmlspecialchars($payroll['generated_by_name'] ?? 'HR Department'); ?></p>
                </div>
            </div>
            
            <!-- Acknowledgment -->
            <div class="border-top mt-4 pt-3">
                <p class="text-center mb-0">
                    <em>
                        I acknowledge that the above computation are true and correct. 
                        I reserve the right for any legal adjustment necessary in accordance to company's policies.
                    </em>
                </p>
            </div>
            
            <!-- Footer -->
            <div class="border-top mt-3 pt-2">
                <div class="row">
                    <div class="col-md-6">
                        <p class="small text-muted mb-0">
                            <strong>Generated on:</strong> <?php echo date('F d, Y h:i A'); ?>
                        </p>
                    </div>
                    <div class="col-md-6 text-end">
                        <p class="small text-muted mb-0">
                            <strong>Reference:</strong> <?php echo htmlspecialchars($payroll['payslip_code']); ?>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto-print if print parameter is set
        <?php if ($print): ?>
        window.onload = function() {
            window.print();
        }
        <?php endif; ?>
        
        // Print optimization
        window.onbeforeprint = function() {
            document.querySelectorAll('.no-print').forEach(el => {
                el.style.display = 'none';
            });
        };
        
        window.onafterprint = function() {
            document.querySelectorAll('.no-print').forEach(el => {
                el.style.display = '';
            });
        };
    </script>
</body>
</html>