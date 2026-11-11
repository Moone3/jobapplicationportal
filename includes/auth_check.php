<?php
// includes/auth_check.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit();
}

// Optional: prevent banned or disabled users from continuing
if (isset($_SESSION['status']) && $_SESSION['status'] !== 'active') {
    session_destroy();
    header("Location: ../auth/login.php?error=account_not_active");
    exit();
}
?>