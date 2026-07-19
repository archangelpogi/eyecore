<?php
// includes/theme.php

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get theme from session or set default
if (!isset($_SESSION['theme'])) {
    // Check if there's a saved theme in cookies/localStorage
    // Default to 'light' if none
    $_SESSION['theme'] = 'light';
}

// Function to get current theme
function getCurrentTheme() {
    return $_SESSION['theme'] ?? 'light';
}

// Function to set theme
function setTheme($theme) {
    $_SESSION['theme'] = $theme;
}

// Function to get theme class for HTML
function getThemeClass() {
    return getCurrentTheme() === 'dark' ? 'theme-dark' : '';
}
?>