<?php
require_once __DIR__ . '/../config/db.php';

if(isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    $stmt = $pdo->prepare("
        SELECT o.*, 
               CONCAT(p.first_name, ' ', p.last_name) as patient_name,
               p.patient_id as patient_code
        FROM optical_records o
        LEFT JOIN patients p ON o.patient_id = p.id 
        WHERE o.id = ?
    ");
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($record) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="optical_record_'.$id.'.pdf"');
        
        // Simple HTML to PDF (you can use TCPDF or DomPDF for better PDF generation)
        $html = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                h2 { color: #333; border-bottom: 2px solid #007bff; padding-bottom: 10px; }
                .section { margin-bottom: 20px; }
                .label { font-weight: bold; }
                table { width: 100%; border-collapse: collapse; margin-top: 10px; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                th { background-color: #f2f2f2; }
            </style>
        </head>
        <body>
            <h2>Eye Examination Report</h2>
            
            <div class='section'>
                <h3>Patient Information</h3>
                <p><span class='label'>Name:</span> {$record['patient_name']}</p>
                <p><span class='label'>Patient ID:</span> {$record['patient_code']}</p>
                <p><span class='label'>Record ID:</span> {$record['record_id']}</p>
                <p><span class='label'>Examination Date:</span> {$record['examination_date']}</p>
                <p><span class='label'>Optometrist:</span> {$record['optometrist']}</p>
            </div>
            
            <div class='section'>
                <h3>Prescription</h3>
                <table>
                    <tr>
                        <th>Eye</th>
                        <th>SPH</th>
                        <th>CYL</th>
                        <th>Axis</th>
                        <th>ADD</th>
                        <th>VA</th>
                    </tr>
                    <tr>
                        <td>OD (Right)</td>
                        <td>{$record['od_sph']}</td>
                        <td>{$record['od_cyl']}</td>
                        <td>{$record['od_axis']}</td>
                        <td>{$record['od_add']}</td>
                        <td>{$record['od_va']}</td>
                    </tr>
                    <tr>
                        <td>OS (Left)</td>
                        <td>{$record['os_sph']}</td>
                        <td>{$record['os_cyl']}</td>
                        <td>{$record['os_axis']}</td>
                        <td>{$record['os_add']}</td>
                        <td>{$record['os_va']}</td>
                    </tr>
                </table>
                <p><span class='label'>PD:</span> {$record['pd']} mm</p>
            </div>
        ";
        
        if(!empty($record['notes'])) {
            $html .= "
            <div class='section'>
                <h3>Notes</h3>
                <p>{$record['notes']}</p>
            </div>
            ";
        }
        
        $html .= "
            <div class='section'>
                <p style='margin-top: 30px; border-top: 1px solid #ddd; padding-top: 10px;'>
                    Generated on: " . date('Y-m-d H:i:s') . "
                </p>
            </div>
        </body>
        </html>
        ";
        
        echo $html;
    }
}
?>