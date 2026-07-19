<?php
include '../config/db.php';

// Users to insert
$users = [
    ['HR001', 'Maria', 'Santos', 'maria.santos@example.com', 'Admin1234!', 'HR'],
    ['FIN001', 'Juan', 'Dela Cruz', 'juan.delacruz@example.com', 'Admin1234!', 'Finance'],
    ['CRM001', 'Ana', 'Reyes', 'ana.reyes@example.com', 'Admin1234!', 'CRM'],
    ['SCM001', 'Carlos', 'Lopez', 'carlos.lopez@example.com', 'Admin1234!', 'SCM'],
    ['OPT001', 'Liza', 'Gomez', 'liza.gomez@example.com', 'Admin1234!', 'Optometrist'],
    ['STF001', 'Mark', 'Velasco', 'mark.velasco@example.com', 'Admin1234!', 'Staff'],
];

foreach ($users as $u) {
    $stmt = $pdo->prepare("INSERT INTO users (user_code, clinic_id, first_name, last_name, email, password, role, status) VALUES (?, 36, ?, ?, ?, ?, ?, 'Active')");
    $stmt->execute([
        $u[0],           // user_code
        $u[1],           // first_name
        $u[2],           // last_name
        $u[3],           // email
        password_hash($u[4], PASSWORD_DEFAULT), // password hashed
        $u[5]            // role
    ]);
}

echo "Users inserted successfully!";
