<?php
// UPDATED toggle-favorite.php WITH NOTIFICATIONS
error_reporting(E_ALL);
ini_set('display_errors', 1);


include '../includes/config.php';

// Set header to return JSON
header('Content-Type: application/json');

// Initialize response with debug info
$response = [
    'success' => false,
    'message' => '',
    'action' => '',
    'debug' => [
        'session_exists' => isset($_SESSION) ? 'yes' : 'no',
        'session_user_id' => isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'not set',
        'session_data' => $_SESSION,
        'post_data' => $_POST,
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'database' => []
    ]
];

// Check database connection
$response['debug']['database']['connection'] = $conn ? 'connected' : 'failed';
if (!$conn) {
    $response['message'] = 'Database connection failed';
    echo json_encode($response);
    exit();
}

// Check if logged in
if (!isset($_SESSION['user_id'])) {
    $response['message'] = 'Please login first';
    $response['debug']['session_check'] = 'failed - no user_id';
    echo json_encode($response);
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// Check if user exists
$user_check = mysqli_query($conn, "SELECT id FROM users WHERE id = $user_id");
if (!$user_check || mysqli_num_rows($user_check) == 0) {
    $response['message'] = 'User does not exist';
    $response['debug']['user_check'] = [
        'user_id' => $user_id,
        'found' => false,
        'mysql_error' => mysqli_error($conn)
    ];
    echo json_encode($response);
    exit();
}

// Get POST data
$clinic_id = isset($_POST['clinic_id']) ? (int)$_POST['clinic_id'] : 0;

// Validate clinic ID
if ($clinic_id <= 0) {
    $response['message'] = 'Invalid clinic ID';
    $response['debug']['clinic_id_received'] = $_POST['clinic_id'] ?? 'not set';
    $response['debug']['clinic_id_after_cast'] = $clinic_id;
    echo json_encode($response);
    exit();
}

// Check if clinic exists
$clinic_check = mysqli_query($conn, "SELECT id, name FROM clinics WHERE id = $clinic_id");
if (!$clinic_check) {
    $response['message'] = 'Error checking clinic';
    $response['debug']['clinic_query_error'] = mysqli_error($conn);
    echo json_encode($response);
    exit();
}

if (mysqli_num_rows($clinic_check) == 0) {
    $response['message'] = 'Clinic does not exist';
    $response['debug']['clinic_id'] = $clinic_id;
    $response['debug']['available_clinics'] = [];
    
    // Get first 5 clinics for debugging
    $clinic_list = mysqli_query($conn, "SELECT id, name FROM clinics LIMIT 5");
    while($c = mysqli_fetch_assoc($clinic_list)) {
        $response['debug']['available_clinics'][] = $c;
    }
    echo json_encode($response);
    exit();
}

$clinic_data = mysqli_fetch_assoc($clinic_check);
$response['debug']['clinic_found'] = $clinic_data;

// Check if already in favorites
$check_query = mysqli_query($conn, "SELECT * FROM favorites WHERE user_id = $user_id AND clinic_id = $clinic_id");

if (!$check_query) {
    $response['message'] = 'Error checking favorites';
    $response['debug']['favorites_check_error'] = mysqli_error($conn);
    echo json_encode($response);
    exit();
}

if (mysqli_num_rows($check_query) > 0) {
    // REMOVE FROM FAVORITES
    $delete_query = mysqli_query($conn, "DELETE FROM favorites WHERE user_id = $user_id AND clinic_id = $clinic_id");
    
    if ($delete_query) {
        // ===== ADD NOTIFICATION FOR REMOVE =====
        addNotification(
            $user_id,
            'favorite',
            'Clinic Removed from Favorites ❌',
            "You removed {$clinic_data['name']} from your favorites.",
            "clinic-details.php?id=$clinic_id"
        );
        
        $response['success'] = true;
        $response['action'] = 'removed';
        $response['message'] = 'Removed from favorites';
    } else {
        $response['message'] = 'Failed to remove from favorites';
        $response['debug']['delete_error'] = mysqli_error($conn);
    }
} else {
    // ADD TO FAVORITES
    $insert_query = mysqli_query($conn, "INSERT INTO favorites (user_id, clinic_id, created_at) VALUES ($user_id, $clinic_id, NOW())");
    
    if ($insert_query) {
        // ===== ADD NOTIFICATION FOR ADD =====
        addNotification(
            $user_id,
            'favorite',
            'New Favorite Clinic ❤️',
            "You added {$clinic_data['name']} to your favorites. Check out their services and book an appointment!",
            "clinic-details.php?id=$clinic_id"
        );
        
        // Optional: Add promo notification
        addNotification(
            $user_id,
            'promo',
            'Stay Updated 🔔',
            "You'll now receive updates and promos from {$clinic_data['name']}.",
            "clinic-products.php?clinic_id=$clinic_id"
        );
        
        $response['success'] = true;
        $response['action'] = 'added';
        $response['message'] = 'Added to favorites';
    } else {
        $response['message'] = 'Failed to add to favorites';
        $response['debug']['insert_error'] = mysqli_error($conn);
    }
}

// Return JSON response
echo json_encode($response, JSON_PRETTY_PRINT);
exit();
?>