<?php
// Dapat may "../" dahil nasa pages folder ka
include '../includes/config.php';  // <--- Ito ang tamang path!

// Check muna kung may connection
if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

echo "Connected successfully to database!";
echo "<br>Database name: eyecore_db";  // Diretso na lang lagay

// Test kung makakapag-select
$result = mysqli_query($conn, "SELECT * FROM clinics");

if ($result) {
    echo "<br>Number of clinics: " . mysqli_num_rows($result);
} else {
    echo "<br>Error: " . mysqli_error($conn);
}
?>