<?php
if (session_status() === PHP_SESSION_NONE) {
    session_name('eyecore_admin');
    session_start();
}

if (isset($_SESSION['user_id'])) {
    $_SESSION['doctor_prompt_dismissed'] = true;
}

echo json_encode(['success' => true]);