<?php
session_start();

/* ===============================
   BASE URL
================================ */
$base_url = 'http://eyecore.capstone001.com/';

/* ===============================
   CLEAR ALL SESSION DATA
================================ */
$_SESSION = [];

/* ===============================
   DELETE SESSION COOKIE (IF ANY)
================================ */
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

/* ===============================
   DESTROY SESSION
================================ */
session_destroy();

/* ===============================
   OPTIONAL: DELETE REMEMBER TOKEN COOKIE
================================ */
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', false, true);
}

/* ===============================
   REDIRECT TO LOGIN WITH SWEETALERT
================================ */
session_start(); // restart session to pass swal message
$_SESSION['swal'] = [
    'icon'  => 'success',
    'title' => 'Logged Out',
    'text'  => 'You have been successfully logged out.'
];

header("Location: {$base_url}admin/login.php");
exit();
?>
