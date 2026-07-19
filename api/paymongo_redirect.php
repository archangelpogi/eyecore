<?php
session_start();
$checkout_id = $_GET['checkout_id'] ?? '';
$request_id  = $_GET['request_id'] ?? '';

if (!$checkout_id) {
    header('Location: /main.php?view=my-3d-models&tab=pending');
    exit;
}

header('Location: https://checkout.paymongo.com/' . $checkout_id);
exit;