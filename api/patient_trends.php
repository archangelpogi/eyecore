<?php
header('Content-Type: application/json');
include __DIR__ . '/../config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}
$clinic_id = $_SESSION['clinic_id'] ?? 0;

$method = $_SERVER['REQUEST_METHOD'];

if($method === 'GET'){
    if(isset($_GET['id'])){
        $id = (int)$_GET['id'];
        $stmt = $pdo->prepare("
        SELECT o.*, CONCAT(p.first_name,' ',p.last_name) AS patient_name, CONCAT('PAT-',LPAD(p.id,5,'0')) AS patient_code
        FROM optical_records o
        LEFT JOIN patients p ON o.patient_id = p.id
        $whereClause
        ORDER BY o.examination_date DESC

        ");
        $stmt->execute([$id, $clinic_id]);
        echo json_encode($stmt->fetch(PDO::FETCH_ASSOC));
        exit;
    }
} elseif($method === 'POST'){
    $data = json_decode(file_get_contents('php://input'), true);
    try{
        $stmt = $pdo->prepare("
            INSERT INTO optical_records
            (record_id, clinic_id, patient_id, examination_date,
             od_sph, od_cyl, od_axis, od_add, od_va,
             os_sph, os_cyl, os_axis, os_add, os_va,
             pd, notes, optometrist)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $data['record_id'] ?? 'REC-'.time(),
            $clinic_id,
            $data['patient_id'],
            $data['examination_date'],
            $data['od_sph'] ?? null,
            $data['od_cyl'] ?? null,
            $data['od_axis'] ?? null,
            $data['od_add'] ?? null,
            $data['od_va'] ?? null,
            $data['os_sph'] ?? null,
            $data['os_cyl'] ?? null,
            $data['os_axis'] ?? null,
            $data['os_add'] ?? null,
            $data['os_va'] ?? null,
            $data['pd'] ?? null,
            $data['notes'] ?? null,
            $data['optometrist']
        ]);
        echo json_encode(['success'=>true,'message'=>'Record created']);
    } catch(Exception $e){
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
}
