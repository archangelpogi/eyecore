<?php
function allowRoles(array $roles) {
    if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], $roles)) {
        die('Access denied.');
    }
}
