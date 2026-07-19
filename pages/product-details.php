<?php
// product-details.php is deprecated.
// product-view.php now handles both regular and sale products.
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) {
    header('Location: sale-products.php');
    exit();
}
header('Location: product-view.php?id=' . $id);
exit();