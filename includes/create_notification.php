<?php
// includes/create_notification.php

function create_notification($conn, $user_id, $title, $message, $link = null) {
    if (empty($user_id) || empty($title) || empty($message)) {
        return false;
    }

    $sql = "
        INSERT INTO notifications (user_id, title, message, link)
        VALUES (?, ?, ?, ?)
    ";

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "isss", $user_id, $title, $message, $link);

    return mysqli_stmt_execute($stmt);
}
?>