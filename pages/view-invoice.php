<?php
session_start();

include __DIR__ . '/../config/db.php';

// Check kung may active session
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$id = $_GET['id'] ?? 0;

$sql = "SELECT i.*, p.first_name, p.last_name, p.patient_code, c.clinic_name, c.address as clinic_address, c.contact as clinic_contact
        FROM invoices i 
        LEFT JOIN patients p ON i.patient_id = p.id 
        LEFT JOIN clinics c ON i.clinic_id = c.id 
        WHERE i.id = ?";
        
$stmt = $pdo->prepare($sql);
$stmt->execute([$id]);
$invoice = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$invoice) {
    die('Invoice not found');
}

$items = json_decode($invoice['items'] ?? '[]', true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Invoice <?= $invoice['invoice_code'] ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
    body{font-family:Arial,sans-serif}
    .invoice-container{max-width:800px;margin:auto;padding:20px}
    .invoice-header{border-bottom:2px solid #0d9488;padding-bottom:20px;margin-bottom:30px}
    .invoice-footer{margin-top:50px;padding-top:20px;border-top:1px solid #dee2e6}
    .logo{color:#0d9488;font-weight:bold;font-size:24px}
    </style>
</head>
<body>
    <div class="invoice-container">
        <div class="invoice-header">
            <div class="row">
                <div class="col-6"><div class="logo">EYECORE</div><p class="mb-0">Optical Clinic</p><small class="text-muted"><?= htmlspecialchars($invoice['clinic_address'] ?? '') ?></small></div>
                <div class="col-6 text-end"><h2>INVOICE</h2><p class="mb-0"><strong>#<?= htmlspecialchars($invoice['invoice_code']) ?></strong></p><p class="mb-0">Date: <?= date('M d, Y', strtotime($invoice['invoice_date'])) ?></p></div>
            </div>
        </div>
        
        <div class="row mb-4">
            <div class="col-6">
                <h5>Bill To:</h5>
                <p class="mb-0"><strong><?= htmlspecialchars($invoice['first_name'] . ' ' . $invoice['last_name']) ?></strong></p>
                <p class="mb-0">Patient ID: <?= htmlspecialchars($invoice['patient_code']) ?></p>
                <p class="mb-0">Clinic: <?= htmlspecialchars($invoice['clinic_name']) ?></p>
            </div>
            <div class="col-6">
                <h5>Payment Status:</h5>
                <?php $statusClass = match($invoice['status']) {'Paid'=>'badge bg-success','Partial'=>'badge bg-warning','Unpaid'=>'badge bg-danger',default=>'badge bg-secondary'} ?>
                <span class="<?= $statusClass ?>"><?= $invoice['status'] ?></span>
            </div>
        </div>
        
        <table class="table table-bordered">
            <thead class="table-light">
                <tr><th>Item</th><th class="text-end">Amount</th></tr>
            </thead>
            <tbody>
                <?php foreach($items as $item): ?>
                <tr><td><?= htmlspecialchars($item) ?></td><td class="text-end">-</td></tr>
                <?php endforeach; ?>
                <tr><td class="text-end fw-bold">Total:</td><td class="text-end fw-bold">₱<?= number_format($invoice['total'], 2) ?></td></tr>
                <?php if($invoice['amount_paid'] > 0): ?>
                <tr><td class="text-end">Amount Paid:</td><td class="text-end">₱<?= number_format($invoice['amount_paid'], 2) ?></td></tr>
                <tr><td class="text-end">Balance:</td><td class="text-end">₱<?= number_format($invoice['total'] - $invoice['amount_paid'], 2) ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <div class="invoice-footer">
            <div class="row">
                <div class="col-12">
                    <p class="mb-1"><strong>Notes:</strong></p>
                    <p class="text-muted"><?= nl2br(htmlspecialchars($invoice['notes'] ?? 'Thank you for your business!')) ?></p>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col-6">
                    <p class="mb-0">Clinic Contact: <?= htmlspecialchars($invoice['clinic_contact'] ?? '') ?></p>
                </div>
                <div class="col-6 text-end">
                    <button class="btn btn-primary" onclick="window.print()">Print Invoice</button>
                </div>
            </div>
        </div>
    </div>
</body>
</html>