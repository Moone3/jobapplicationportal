<?php
// recruiter/notifications.php
include "../includes/auth_check.php";
include "../config/database.php";

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'recruiter') {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'creator') {
        header("Location: ../creator/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'applicant') {
        header("Location: ../applicant/dashboard.php");
        exit();
    }

    header("Location: ../auth/login.php");
    exit();
}

$recruiter_id = intval($_SESSION['user_id']);
$full_name = $_SESSION['full_name'] ?? 'Recruiter';
$current_page = basename($_SERVER['PHP_SELF']);

if (!function_exists('active_link')) {
    function active_link($page_name, $current_page) {
        return $page_name === $current_page ? "active" : "";
    }
}

function get_count($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return $row['total'] ?? 0;
    }

    return 0;
}

function notification_status_class($is_read) {
    return intval($is_read) === 0 ? "unread" : "read";
}

function notification_status_text($is_read) {
    return intval($is_read) === 0 ? "Unread" : "Read";
}

$message = "";
$error = "";

// Recruiter profile/company info
$company_name = "Job Portal";
$recruiter_profile_picture = "";

$company_query = mysqli_query($conn, "
    SELECT 
        users.full_name,
        users.profile_picture,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo
    FROM users
    LEFT JOIN recruiter_profiles ON users.id = recruiter_profiles.user_id
    WHERE users.id = '$recruiter_id'
    LIMIT 1
");

if ($company_query && mysqli_num_rows($company_query) > 0) {
    $company_data = mysqli_fetch_assoc($company_query);

    if (!empty($company_data['full_name'])) {
        $full_name = $company_data['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    if (!empty($company_data['company_name'])) {
        $company_name = $company_data['company_name'];
    }

    if (!empty($company_data['profile_picture'])) {
        $picture_name = $company_data['profile_picture'];

        $possible_pictures = [
            [
                "file" => __DIR__ . "/../assets/uploads/profiles/" . $picture_name,
                "web"  => "../assets/uploads/profiles/" . $picture_name
            ],
            [
                "file" => __DIR__ . "/../profile pictures/" . $picture_name,
                "web"  => "../profile pictures/" . $picture_name
            ],
            [
                "file" => __DIR__ . "/../includes/profile pictures/" . $picture_name,
                "web"  => "../includes/profile pictures/" . $picture_name
            ]
        ];

        foreach ($possible_pictures as $picture) {
            if (file_exists($picture['file'])) {
                $recruiter_profile_picture = $picture['web'];
                break;
            }
        }
    } elseif (!empty($company_data['company_logo'])) {
        $logo_file = __DIR__ . "/../assets/uploads/company_logos/" . $company_data['company_logo'];

        if (file_exists($logo_file)) {
            $recruiter_profile_picture = "../assets/uploads/company_logos/" . $company_data['company_logo'];
        }
    }
}

$sidebar_initial = strtoupper(substr($company_name, 0, 1));

if ($sidebar_initial === "") {
    $sidebar_initial = "R";
}

// Open notification and mark as read
if (isset($_GET['open'])) {
    $notification_id = intval($_GET['open']);

    $notif_sql = "
        SELECT link
        FROM notifications
        WHERE id = ?
        AND user_id = ?
        LIMIT 1
    ";

    $notif_stmt = mysqli_prepare($conn, $notif_sql);

    if (!$notif_stmt) {
        header("Location: notifications.php?error=Database error.");
        exit();
    }

    mysqli_stmt_bind_param($notif_stmt, "ii", $notification_id, $recruiter_id);
    mysqli_stmt_execute($notif_stmt);
    $notif_result = mysqli_stmt_get_result($notif_stmt);

    if ($notif_result && mysqli_num_rows($notif_result) === 1) {
        $notif = mysqli_fetch_assoc($notif_result);

        $update_sql = "
            UPDATE notifications
            SET is_read = 1
            WHERE id = ?
            AND user_id = ?
        ";

        $update_stmt = mysqli_prepare($conn, $update_sql);

        if ($update_stmt) {
            mysqli_stmt_bind_param($update_stmt, "ii", $notification_id, $recruiter_id);
            mysqli_stmt_execute($update_stmt);
        }

        if (!empty($notif['link'])) {
            header("Location: " . $notif['link']);
            exit();
        }

        header("Location: notifications.php");
        exit();
    }

    header("Location: notifications.php?error=Notification not found.");
    exit();
}

// Mark one as read
if (isset($_GET['mark_read'])) {
    $notification_id = intval($_GET['mark_read']);

    $update_sql = "
        UPDATE notifications
        SET is_read = 1
        WHERE id = ?
        AND user_id = ?
    ";

    $update_stmt = mysqli_prepare($conn, $update_sql);

    if (!$update_stmt) {
        $error = "Database error.";
    } else {
        mysqli_stmt_bind_param($update_stmt, "ii", $notification_id, $recruiter_id);

        if (mysqli_stmt_execute($update_stmt)) {
            $message = "Notification marked as read.";
        } else {
            $error = "Failed to mark notification as read.";
        }
    }
}

// Compatibility with old read parameter
if (isset($_GET['read'])) {
    $notification_id = intval($_GET['read']);

    $update_sql = "
        UPDATE notifications
        SET is_read = 1
        WHERE id = ?
        AND user_id = ?
    ";

    $update_stmt = mysqli_prepare($conn, $update_sql);

    if (!$update_stmt) {
        $error = "Database error.";
    } else {
        mysqli_stmt_bind_param($update_stmt, "ii", $notification_id, $recruiter_id);

        if (mysqli_stmt_execute($update_stmt)) {
            $message = "Notification marked as read.";
        } else {
            $error = "Failed to mark notification as read.";
        }
    }
}

// Mark all as read
if (isset($_GET['mark_all_read']) || isset($_GET['read_all'])) {
    $update_sql = "
        UPDATE notifications
        SET is_read = 1
        WHERE user_id = ?
    ";

    $update_stmt = mysqli_prepare($conn, $update_sql);

    if (!$update_stmt) {
        $error = "Database error.";
    } else {
        mysqli_stmt_bind_param($update_stmt, "i", $recruiter_id);

        if (mysqli_stmt_execute($update_stmt)) {
            $message = "All notifications marked as read.";
        } else {
            $error = "Failed to mark all notifications as read.";
        }
    }
}

// Delete notification
if (isset($_GET['delete'])) {
    $notification_id = intval($_GET['delete']);

    $delete_sql = "
        DELETE FROM notifications
        WHERE id = ?
        AND user_id = ?
    ";

    $delete_stmt = mysqli_prepare($conn, $delete_sql);

    if (!$delete_stmt) {
        $error = "Database error.";
    } else {
        mysqli_stmt_bind_param($delete_stmt, "ii", $notification_id, $recruiter_id);

        if (mysqli_stmt_execute($delete_stmt)) {
            $message = "Notification deleted successfully.";
        } else {
            $error = "Failed to delete notification.";
        }
    }
}

if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

if (isset($_GET['error'])) {
    $error = trim($_GET['error']);
}

// Filter
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : "all";

if (isset($_GET['status'])) {
    $filter = trim($_GET['status']);
}

// Counts
$total_notifications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = '$recruiter_id'
");

$unread_notifications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = '$recruiter_id'
    AND is_read = 0
");

$read_notifications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM notifications
    WHERE user_id = '$recruiter_id'
    AND is_read = 1
");

// Message badge count
$unread_messages = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM messages
    WHERE receiver_id = '$recruiter_id'
    AND is_read = 0
");

// Notifications query
$sql = "
    SELECT *
    FROM notifications
    WHERE user_id = ?
";

$params = [$recruiter_id];
$types = "i";

if ($filter === "unread") {
    $sql .= " AND is_read = 0";
} elseif ($filter === "read") {
    $sql .= " AND is_read = 1";
}

$sql .= " ORDER BY created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$notifications = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Recruiter Notifications | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --bg-deep: #0d1630;
            --glass: rgba(255, 255, 255, 0.075);
            --glass-strong: rgba(255, 255, 255, 0.12);
            --line: rgba(255, 255, 255, 0.15);
            --text: #f8fbff;
            --muted: #aab7cf;
            --soft: #dce7ff;
            --cyan: #12d7ff;
            --cyan-2: #00a6c8;
            --blue: #3267ff;
            --purple: #8b5cf6;
            --pink: #ff3ea5;
            --red: #ff3b5f;
            --orange: #ffb454;
            --green: #26e0a8;
            --shadow: 0 22px 60px rgba(0, 0, 0, 0.34);
        }

        * {
            box-sizing: border-box;
            font-family: Inter, "Segoe UI", Arial, Helvetica, sans-serif;
        }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            color: var(--text);
            overflow-x: hidden;
            background:
                radial-gradient(circle at 14% 10%, rgba(18, 215, 255, 0.13), transparent 30%),
                radial-gradient(circle at 88% 18%, rgba(139, 92, 246, 0.11), transparent 30%),
                linear-gradient(135deg, #070b18 0%, #0b1328 58%, #0f1428 100%);
            background-attachment: fixed;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 360px;
            height: 360px;
            border-radius: 999px;
            pointer-events: none;
            filter: blur(28px);
            opacity: 0.13;
            animation: floatGlow 9s ease-in-out infinite alternate;
            z-index: 0;
        }

        body::before { left: -150px; top: -130px; background: var(--cyan); }
        body::after { right: -150px; bottom: -145px; background: var(--purple); animation-delay: -2s; }

        @keyframes floatGlow {
            from { transform: translate3d(0, 0, 0) scale(1); }
            to { transform: translate3d(12px, -10px, 0) scale(1.04); }
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .layout {
            position: relative;
            z-index: 1;
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 275px;
            background: rgba(7, 11, 24, 0.72);
            border-right: 1px solid var(--line);
            padding: 24px 18px;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            box-shadow: 22px 0 80px rgba(0, 0, 0, 0.22);
        }

        .sidebar::-webkit-scrollbar,
        body::-webkit-scrollbar { width: 9px; }

        .sidebar::-webkit-scrollbar-track,
        body::-webkit-scrollbar-track { background: rgba(255, 255, 255, 0.04); }

        .sidebar::-webkit-scrollbar-thumb,
        body::-webkit-scrollbar-thumb {
            background: rgba(18, 215, 255, 0.32);
            border-radius: 999px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 34px;
            padding: 10px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .brand-icon,
        .brand-img {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            flex-shrink: 0;
        }

        .brand-icon {
            background: linear-gradient(135deg, var(--cyan-2), var(--blue));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 15px;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

        .brand-img {
            object-fit: cover;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

        .brand-text-wrap { min-width: 0; }

        .brand-text {
            font-size: 20px;
            font-weight: 900;
            color: var(--text);
            letter-spacing: 0.2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .brand-subtext {
            margin-top: 3px;
            font-size: 12px;
            color: var(--muted);
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 165px;
        }

        .nav-title {
            font-size: 11px;
            text-transform: uppercase;
            color: rgba(248, 251, 255, 0.48);
            margin: 18px 10px 11px;
            letter-spacing: 1.25px;
            font-weight: 900;
        }

        .nav-link {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            margin-bottom: 8px;
            color: var(--muted);
            text-decoration: none;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 800;
            border: 1px solid transparent;
            transition: transform 0.22s ease, background 0.22s ease, color 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            background: rgba(18, 215, 255, 0.12);
            color: var(--text);
            border-color: rgba(18, 215, 255, 0.22);
            transform: translateX(4px);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.16);
        }

        .nav-link.active::before {
            content: "";
            width: 4px;
            height: 22px;
            border-radius: 999px;
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            background: var(--cyan);
            box-shadow: 0 0 18px rgba(18, 215, 255, 0.7);
        }

        .nav-icon {
            width: 22px;
            text-align: center;
            filter: drop-shadow(0 0 8px rgba(18, 215, 255, 0.14));
        }

        .notif-badge {
            margin-left: auto;
            background: rgba(255, 62, 165, 0.88);
            color: #ffffff;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 900;
            box-shadow: 0 0 18px rgba(255, 62, 165, 0.36);
        }

        .logout { margin-top: 26px; color: #ff9bad; }
        .logout:hover { color: #ffffff; border-color: rgba(255, 62, 165, 0.25); }

        .main {
            margin-left: 275px;
            width: calc(100% - 275px);
            padding: 28px;
        }

        .topbar,
        .card,
        .panel,
        .filter-box,
        .notification-card {
            position: relative;
            overflow: hidden;
            background: var(--glass);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .topbar::before,
        .card::before,
        .panel::before,
        .filter-box::before,
        .notification-card::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .card > *,
        .panel > *,
        .filter-box > *,
        .notification-card > * {
            position: relative;
            z-index: 1;
        }

        .topbar {
            border-radius: 28px;
            padding: 24px 26px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            animation: fadeUp 0.45s ease both;
        }

        .topbar h1 {
            margin: 0;
            font-size: clamp(26px, 3vw, 36px);
            color: var(--text);
            letter-spacing: -0.8px;
        }

        .topbar p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .user-pill {
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.16);
            padding: 11px 18px;
            border-radius: 999px;
            color: var(--soft);
            font-size: 14px;
            font-weight: 900;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.12);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 24px;
            padding: 22px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.52s ease both;
        }

        .card:hover,
        .notification-card:hover {
            transform: translateY(-5px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .card-title {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 800;
        }

        .card-number {
            font-size: 34px;
            font-weight: 950;
            color: var(--cyan);
            margin: 0;
            text-shadow: 0 0 22px rgba(18, 215, 255, 0.28);
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            animation: fadeUp 0.62s ease both;
        }

        .filter-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            border-radius: 20px;
            padding: 16px;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.20);
        }

        .filter-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            color: var(--soft);
        }

        select {
            padding: 13px 15px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 15px;
            outline: none;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }

        select option { color: #101827; background: #ffffff; }

        select:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .notification-card {
            border-radius: 22px;
            padding: 20px;
            margin-bottom: 16px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .notification-card.unread {
            border-color: rgba(18, 215, 255, 0.32);
            background: rgba(18, 215, 255, 0.10);
        }

        .notification-card.read {
            background: rgba(255, 255, 255, 0.065);
        }

        .notification-content {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .notification-title {
            margin: 0 0 10px;
            font-size: 19px;
            color: var(--text);
            letter-spacing: -0.2px;
        }

        .notification-message {
            margin: 0 0 12px;
            color: var(--soft);
            font-size: 14px;
            line-height: 1.65;
        }

        .notification-date {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 10px;
            border: 1px solid transparent;
        }

        .badge-unread {
            background: rgba(18, 215, 255, 0.13);
            color: #bff5ff;
            border-color: rgba(18, 215, 255, 0.22);
        }

        .badge-read {
            background: rgba(255, 255, 255, 0.10);
            color: #e7edff;
            border-color: rgba(255, 255, 255, 0.14);
        }

        .actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn {
            padding: 11px 16px;
            border-radius: 14px;
            text-decoration: none;
            border: 1px solid transparent;
            font-size: 14px;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            min-height: 44px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .btn:hover { transform: translateY(-2px); }

        .btn-primary {
            background: linear-gradient(135deg, var(--cyan-2), #2356e8);
            color: #ffffff;
            box-shadow: 0 16px 34px rgba(18, 215, 255, 0.18);
        }

        .btn-primary:hover { box-shadow: 0 20px 42px rgba(18, 215, 255, 0.28); }

        .btn-light {
            background: rgba(255, 255, 255, 0.08);
            color: var(--soft);
            border-color: rgba(255, 255, 255, 0.14);
        }

        .btn-light:hover {
            background: rgba(255, 255, 255, 0.13);
            border-color: rgba(18, 215, 255, 0.24);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.18);
        }

        .btn-danger {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .btn-danger:hover {
            background: rgba(255, 59, 95, 0.20);
            box-shadow: 0 14px 30px rgba(255, 59, 95, 0.12);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            font-weight: 800;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            animation: fadeUp 0.35s ease both;
        }

        .alert-success {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border: 1px solid rgba(38, 224, 168, 0.27);
        }

        .alert-error {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border: 1px solid rgba(255, 59, 95, 0.27);
        }

        .empty {
            color: var(--muted);
            font-size: 15px;
            padding: 22px 0;
            line-height: 1.6;
        }


        .confirm-backdrop {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 22px;
            background: rgba(3, 7, 18, 0.68);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .confirm-backdrop.show {
            display: flex;
            animation: modalFade 0.18s ease both;
        }

        .confirm-modal {
            position: relative;
            width: min(430px, 100%);
            overflow: hidden;
            border-radius: 26px;
            padding: 24px;
            color: var(--text);
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 35px 110px rgba(0, 0, 0, 0.58), 0 0 0 1px rgba(18, 215, 255, 0.08);
            transform: translateY(10px) scale(0.98);
            animation: modalPop 0.2s ease forwards;
        }

        .confirm-modal::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                radial-gradient(circle at 18% 0%, rgba(18, 215, 255, 0.20), transparent 36%),
                radial-gradient(circle at 100% 10%, rgba(255, 62, 165, 0.13), transparent 34%);
        }

        .confirm-modal > * {
            position: relative;
            z-index: 1;
        }

        .confirm-icon {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            font-size: 24px;
            background: rgba(255, 59, 95, 0.14);
            border: 1px solid rgba(255, 59, 95, 0.28);
            box-shadow: 0 18px 40px rgba(255, 59, 95, 0.16);
        }

        .confirm-icon.info {
            background: rgba(18, 215, 255, 0.13);
            border-color: rgba(18, 215, 255, 0.28);
            box-shadow: 0 18px 40px rgba(18, 215, 255, 0.12);
        }

        .confirm-title {
            margin: 0;
            font-size: 24px;
            line-height: 1.2;
            letter-spacing: -0.4px;
        }

        .confirm-text {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .confirm-user {
            margin-top: 14px;
            padding: 13px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.11);
            color: var(--soft);
            font-weight: 900;
            word-break: break-word;
        }

        .confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
            flex-wrap: wrap;
        }

        .confirm-actions .btn {
            min-width: 110px;
        }

        @keyframes modalFade {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes modalPop {
            to { transform: translateY(0) scale(1); }
        }

        .card:nth-child(2) { animation-delay: 0.05s; }
        .card:nth-child(3) { animation-delay: 0.10s; }

        @media (max-width: 900px) {
            .cards { grid-template-columns: 1fr; }
            .notification-content { flex-direction: column; }
            .actions { justify-content: flex-start; }
        }

        @media (max-width: 750px) {
            body::before,
            body::after { width: 280px; height: 280px; }

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .layout { flex-direction: column; }

            .main {
                margin-left: 0;
                width: 100%;
                padding: 18px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .btn { width: 100%; }
        }
    </style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <?php if (!empty($recruiter_profile_picture)) { ?>
                <img src="<?php echo htmlspecialchars($recruiter_profile_picture); ?>" class="brand-img" alt="Recruiter Profile">
            <?php } else { ?>
                <div class="brand-icon"><?php echo htmlspecialchars($sidebar_initial); ?></div>
            <?php } ?>

            <div class="brand-text-wrap">
                <div class="brand-text"><?php echo htmlspecialchars($company_name); ?></div>
                <div class="brand-subtext"><?php echo htmlspecialchars($full_name); ?></div>
            </div>
        </div>

        <div class="nav-title">Recruiter Menu</div>

        <a href="dashboard.php" class="nav-link <?php echo active_link('dashboard.php', $current_page); ?>">
            <span class="nav-icon">▦</span> Dashboard
        </a>

        <a href="post_job.php" class="nav-link <?php echo active_link('post_job.php', $current_page); ?>">
            <span class="nav-icon">➕</span> Post Job
        </a>

        <a href="manage_jobs.php" class="nav-link <?php echo active_link('manage_jobs.php', $current_page); ?>">
            <span class="nav-icon">💼</span> Manage Jobs
        </a>

        <a href="applicants.php" class="nav-link <?php echo active_link('applicants.php', $current_page); ?>">
            <span class="nav-icon">👥</span> Applicants
        </a>

        <a href="rejected_applicants.php" class="nav-link <?php echo active_link('rejected_applicants.php', $current_page); ?>">
            <span class="nav-icon">🚫</span> Rejected Applicants
        </a>

        <a href="applicants.php" class="nav-link <?php echo active_link('schedule_interview.php', $current_page); ?>">
            <span class="nav-icon">🗓️</span> Schedule Interview
        </a>

        <a href="messages.php" class="nav-link <?php echo active_link('messages.php', $current_page); ?>">
            <span class="nav-icon">✉️</span> Messenger

            <?php if ($unread_messages > 0) { ?>
                <span class="notif-badge"><?php echo $unread_messages; ?></span>
            <?php } ?>
        </a>

        <a href="notifications.php" class="nav-link <?php echo active_link('notifications.php', $current_page); ?>">
            <span class="nav-icon">🔔</span> Notifications

            <?php if ($unread_notifications > 0) { ?>
                <span class="notif-badge"><?php echo $unread_notifications; ?></span>
            <?php } ?>
        </a>

        <a href="support_messages.php" class="nav-link <?php echo active_link('support_messages.php', $current_page); ?>">
            <span class="nav-icon">💬</span> Support Messages
        </a>

        <a href="profile.php" class="nav-link <?php echo active_link('profile.php', $current_page); ?>">
            <span class="nav-icon">👤</span> Profile
        </a>

        <a href="../auth/logout.php" class="nav-link logout">
            <span class="nav-icon">↪</span> Logout
        </a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>Notifications</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. View updates about applications, jobs, messages, and interviews.</p>
            </div>

            <div class="user-pill">Recruiter</div>
        </div>

        <?php if (!empty($message)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Notifications</p>
                <p class="card-number"><?php echo $total_notifications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Unread</p>
                <p class="card-number"><?php echo $unread_notifications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Read</p>
                <p class="card-number"><?php echo $read_notifications; ?></p>
            </div>
        </section>

        <div class="panel">
            <form method="GET" class="filter-box">
                <div class="filter-left">
                    <strong>Filter:</strong>

                    <select name="filter" onchange="this.form.submit()">
                        <option value="all" <?php if ($filter === 'all') echo 'selected'; ?>>All Notifications</option>
                        <option value="unread" <?php if ($filter === 'unread') echo 'selected'; ?>>Unread</option>
                        <option value="read" <?php if ($filter === 'read') echo 'selected'; ?>>Read</option>
                    </select>
                </div>

                <a href="notifications.php?mark_all_read=1"
                   class="btn btn-primary js-confirm-notification-action"
                   data-action="mark-all"
                   data-item="All current notifications"
                   data-message="This will mark every notification in your recruiter account as read.">
                    Mark All as Read
                </a>
            </form>

            <?php if ($notifications && mysqli_num_rows($notifications) > 0) { ?>
                <?php while ($row = mysqli_fetch_assoc($notifications)) { ?>
                    <?php
                        $status_css = notification_status_class($row['is_read']);
                        $status_text = notification_status_text($row['is_read']);
                    ?>

                    <div class="notification-card <?php echo htmlspecialchars($status_css); ?>">
                        <div class="notification-content">
                            <div>
                                <?php if (intval($row['is_read']) === 0) { ?>
                                    <span class="badge badge-unread">Unread</span>
                                <?php } else { ?>
                                    <span class="badge badge-read">Read</span>
                                <?php } ?>

                                <h2 class="notification-title">
                                    <?php echo htmlspecialchars($row['title'] ?? 'Notification'); ?>
                                </h2>

                                <p class="notification-message">
                                    <?php echo htmlspecialchars($row['message'] ?? ''); ?>
                                </p>

                                <p class="notification-date">
                                    <?php echo htmlspecialchars($row['created_at'] ?? ''); ?>
                                </p>
                            </div>

                            <div class="actions">
                                <a href="notifications.php?open=<?php echo intval($row['id']); ?>" class="btn btn-primary">
                                    Open
                                </a>

                                <?php if (intval($row['is_read']) === 0) { ?>
                                    <a href="notifications.php?mark_read=<?php echo intval($row['id']); ?>" class="btn btn-light">
                                        Mark as Read
                                    </a>
                                <?php } ?>

                                <a href="notifications.php?delete=<?php echo intval($row['id']); ?>"
                                   class="btn btn-danger js-confirm-notification-action"
                                   data-action="delete"
                                   data-item="<?php echo htmlspecialchars($row['title'] ?? 'Notification'); ?>"
                                   data-message="This notification will be permanently removed from your list.">
                                    Delete
                                </a>
                            </div>
                        </div>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <p class="empty">No notifications found.</p>
            <?php } ?>
        </div>
    </main>
</div>


<div class="confirm-backdrop" id="confirmBackdrop" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="confirm-icon" id="confirmIcon">🗑️</div>
        <h2 class="confirm-title" id="confirmTitle">Delete notification?</h2>
        <p class="confirm-text" id="confirmMessage">This notification will be permanently removed from your list.</p>
        <div class="confirm-user" id="confirmItem">Notification item</div>

        <div class="confirm-actions">
            <button type="button" class="btn btn-light" id="confirmCancel">Cancel</button>
            <a href="#" class="btn btn-danger" id="confirmProceed">Delete</a>
        </div>
    </div>
</div>

<script>
    const confirmBackdrop = document.getElementById('confirmBackdrop');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmItem = document.getElementById('confirmItem');
    const confirmIcon = document.getElementById('confirmIcon');
    const confirmProceed = document.getElementById('confirmProceed');
    const confirmCancel = document.getElementById('confirmCancel');

    document.querySelectorAll('.js-confirm-notification-action').forEach((button) => {
        button.addEventListener('click', function(event) {
            event.preventDefault();

            const action = this.dataset.action || 'delete';
            const item = this.dataset.item || 'Notification';
            const message = this.dataset.message || 'Are you sure you want to continue?';
            const href = this.getAttribute('href');
            const isDelete = action === 'delete';

            confirmTitle.textContent = isDelete ? 'Delete notification?' : 'Mark all as read?';
            confirmMessage.textContent = message;
            confirmItem.textContent = item;
            confirmIcon.textContent = isDelete ? '🗑️' : '🔔';
            confirmIcon.classList.toggle('info', !isDelete);
            confirmProceed.textContent = isDelete ? 'Delete' : 'Mark All Read';
            confirmProceed.href = href;
            confirmProceed.className = isDelete ? 'btn btn-danger' : 'btn btn-primary';

            confirmBackdrop.classList.add('show');
            confirmBackdrop.setAttribute('aria-hidden', 'false');
        });
    });

    function closeConfirmModal() {
        confirmBackdrop.classList.remove('show');
        confirmBackdrop.setAttribute('aria-hidden', 'true');
        confirmProceed.href = '#';
    }

    confirmCancel.addEventListener('click', closeConfirmModal);

    confirmBackdrop.addEventListener('click', function(event) {
        if (event.target === confirmBackdrop) {
            closeConfirmModal();
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && confirmBackdrop.classList.contains('show')) {
            closeConfirmModal();
        }
    });
</script>

</body>
</html>