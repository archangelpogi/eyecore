<?php
// Enable logging
$logFile = __DIR__ . '/../logs/cron.log';
$log = function($message) use ($logFile) {
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message\n";
    echo $logMessage;
    file_put_contents($logFile, $logMessage, FILE_APPEND);
};

$log("Starting auto-deletion process...");

try {
    require_once __DIR__ . '/../config/db.php';
    
    // Find requests ready for deletion
    $stmt = $pdo->prepare("
        SELECT d.*, p.email as patient_email, p.first_name, p.last_name, c.clinic_name
        FROM data_retention_log d
        LEFT JOIN patients p ON d.patient_id = p.id
        LEFT JOIN clinics c ON d.clinic_id = c.id
        WHERE d.status = 'approved' 
        AND d.scheduled_delete_date <= CURDATE()
        AND d.actual_delete_date IS NULL
    ");
    $stmt->execute();
    $toDelete = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $log("Found " . count($toDelete) . " requests ready for deletion.");
    
    foreach ($toDelete as $request) {
        $log("Processing request #{$request['id']}...");
        
        $pdo->beginTransaction();
        
        try {
            // Anonymize patient data
            $updatePatient = $pdo->prepare("
                UPDATE patients 
                SET first_name = 'DELETED',
                    last_name = 'DELETED',
                    email = CONCAT('deleted_', id, '@deleted.com'),
                    phone = NULL,
                    contact = NULL,
                    address = NULL
                WHERE id = ?
            ");
            $updatePatient->execute([$request['patient_id']]);
            
            // Anonymize optical records
            $updateRecords = $pdo->prepare("
                UPDATE optical_records 
                SET od_sph = NULL, od_cyl = NULL, od_axis = NULL, od_add = NULL, od_va = NULL,
                    os_sph = NULL, os_cyl = NULL, os_axis = NULL, os_add = NULL, os_va = NULL,
                    pd = NULL,
                    notes = CONCAT(notes, '\n[DELETED: ', NOW(), ']')
                WHERE patient_id = ? AND clinic_id = ?
            ");
            $updateRecords->execute([$request['patient_id'], $request['clinic_id']]);
            
            // Mark request as completed
            $updateRequest = $pdo->prepare("
                UPDATE data_retention_log 
                SET status = 'completed',
                    action_taken = 'COMPLETED',
                    actual_delete_date = NOW(),
                    deleted_by = 'system_cron'
                WHERE id = ?
            ");
            $updateRequest->execute([$request['id']]);
            
            $pdo->commit();
            $log("  ✓ Request #{$request['id']} completed successfully.");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $log("  ✗ Error: " . $e->getMessage());
        }
    }
    
    $log("Auto-deletion process completed.");
    
} catch (Exception $e) {
    $log("FATAL ERROR: " . $e->getMessage());
}
?>