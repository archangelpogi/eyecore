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
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="optical_record_'.$id.'.csv"');
        
        $output = fopen('php://output', 'w');
        
        // Headers
        fputcsv($output, ['Field', 'Value']);
        
        // Data
        $data = [
            ['Record ID', $record['record_id']],
            ['Patient Name', $record['patient_name']],
            ['Patient ID', $record['patient_code']],
            ['Examination Date', $record['examination_date']],
            ['Optometrist', $record['optometrist']],
            ['OD SPH', $record['od_sph']],
            ['OD CYL', $record['od_cyl']],
            ['OD Axis', $record['od_axis']],
            ['OD ADD', $record['od_add']],
            ['OD VA', $record['od_va']],
            ['OS SPH', $record['os_sph']],
            ['OS CYL', $record['os_cyl']],
            ['OS Axis', $record['os_axis']],
            ['OS ADD', $record['os_add']],
            ['OS VA', $record['os_va']],
            ['PD', $record['pd']],
            ['Notes', $record['notes']]
        ];
        
        foreach($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
    }
}
?>