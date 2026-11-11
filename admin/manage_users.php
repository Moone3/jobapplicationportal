<?php
// admin/manage_users.php
include "../includes/auth_check.php";
include "../config/database.php";

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$full_name = $_SESSION['full_name'] ?? 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);

$message = "";
$error = "";

// Admin profile picture for sidebar
$admin_profile_picture = "";

$admin_query = mysqli_query($conn, "
    SELECT full_name, profile_picture
    FROM users 
    WHERE id = '$admin_id' 
    LIMIT 1
");

if ($admin_query && mysqli_num_rows($admin_query) > 0) {
    $admin_data = mysqli_fetch_assoc($admin_query);

    if (!empty($admin_data['full_name'])) {
        $full_name = $admin_data['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    if (!empty($admin_data['profile_picture'])) {
        $picture_name = $admin_data['profile_picture'];

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
                $admin_profile_picture = $picture['web'];
                break;
            }
        }
    }
}

// Show admin initials if no picture
$name_parts = preg_split('/\s+/', trim($full_name));
$sidebar_initial = "";

if (!empty($name_parts[0])) {
    $sidebar_initial .= strtoupper(substr($name_parts[0], 0, 1));
}

if (!empty($name_parts[1])) {
    $sidebar_initial .= strtoupper(substr($name_parts[1], 0, 1));
}

if ($sidebar_initial === "") {
    $sidebar_initial = "A";
}

// Unread notifications count
$unread_notifications = 0;

$notif_count_query = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$admin_id'
    AND is_read = 0
");

if ($notif_count_query) {
    $notif_count_row = mysqli_fetch_assoc($notif_count_query);
    $unread_notifications = $notif_count_row['total'];
}

// Unread contact messages count for sidebar badge
$unread_messages = 0;
$cm_result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM contact_messages WHERE status = 'unread'");
if ($cm_result) {
    $cm_row = mysqli_fetch_assoc($cm_result);
    $unread_messages = $cm_row['total'];
}

// Ban user
if (isset($_GET['ban'])) {
    $user_id = intval($_GET['ban']);

    if ($user_id == $admin_id) {
        $error = "You cannot ban your own account.";
    } else {
        $check_sql = "SELECT id, role FROM users WHERE id = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "i", $user_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $user_data = mysqli_fetch_assoc($check_result);

            if ($user_data['role'] === 'creator') {
                $error = "Creator account is protected and cannot be banned.";
            } else {
                $update_sql = "UPDATE users SET status = 'banned' WHERE id = ?";
                $update_stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "i", $user_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    mysqli_query($conn, "
                        INSERT INTO admin_logs (admin_id, action) 
                        VALUES ('$admin_id', 'Banned user ID: $user_id')
                    ");

                    $message = "User banned successfully.";
                } else {
                    $error = "Failed to ban user.";
                }
            }
        } else {
            $error = "User not found.";
        }
    }
}

// Unban user
if (isset($_GET['unban'])) {
    $user_id = intval($_GET['unban']);

    $update_sql = "UPDATE users SET status = 'active' WHERE id = ?";
    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "i", $user_id);

    if (mysqli_stmt_execute($update_stmt)) {
        mysqli_query($conn, "
            INSERT INTO admin_logs (admin_id, action) 
            VALUES ('$admin_id', 'Unbanned user ID: $user_id')
        ");

        $message = "User unbanned successfully.";
    } else {
        $error = "Failed to unban user.";
    }
}

// Filters
$role_filter = isset($_GET['role']) ? trim($_GET['role']) : "all";
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : "all";
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";

// Counts
$total_users = 0;
$total_applicants = 0;
$total_recruiters = 0;
$total_admins = 0;
$active_users = 0;
$banned_users = 0;

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_users = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'applicant'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_applicants = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'recruiter'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_recruiters = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_admins = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE status = 'active'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $active_users = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE status = 'banned'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $banned_users = $row['total'];
}

// Users query
$sql = "
    SELECT *
    FROM users
    WHERE 1
";

$params = [];
$types = "";

if ($role_filter !== "all" && !empty($role_filter)) {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

if ($status_filter !== "all" && !empty($status_filter)) {
    $sql .= " AND status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($keyword)) {
    $sql .= " AND (
        full_name LIKE ?
        OR email LIKE ?
        OR role LIKE ?
        OR status LIKE ?
    )";

    $search_value = "%" . $keyword . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "ssss";
}

$sql .= " ORDER BY created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$users = mysqli_stmt_get_result($stmt);

function role_class($role) {
    $role = strtolower(trim($role));

    if ($role === "admin") {
        return "admin";
    }

    if ($role === "creator") {
        return "creator";
    }

    if ($role === "recruiter") {
        return "recruiter";
    }

    return "applicant";
}

function status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "banned") {
        return "banned";
    }

    return "active";
}

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Users | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --bg-deep: #0d1630;
            --panel: rgba(15, 23, 42, 0.72);
            --panel-strong: rgba(15, 23, 42, 0.86);
            --glass: rgba(255, 255, 255, 0.09);
            --glass-strong: rgba(255, 255, 255, 0.14);
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

        html {
            scroll-behavior: smooth;
        }

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

        body::before {
            left: -150px;
            top: -130px;
            background: var(--cyan);
        }

        body::after {
            right: -150px;
            bottom: -145px;
            background: var(--purple);
            animation-delay: -2s;
        }

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
        body::-webkit-scrollbar {
            width: 9px;
        }

        .sidebar::-webkit-scrollbar-track,
        body::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.04);
        }

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

        .logout {
            margin-top: 26px;
            color: #ff9bad;
        }

        .logout:hover {
            color: #ffffff;
            border-color: rgba(255, 62, 165, 0.25);
        }

        .main {
            margin-left: 275px;
            width: calc(100% - 275px);
            padding: 28px;
        }

        .topbar,
        .card,
        .filter-panel,
        .panel {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.075);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .topbar::before,
        .card::before,
        .filter-panel::before,
        .panel::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .card > *,
        .filter-panel > *,
        .panel > * {
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
            grid-template-columns: repeat(6, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 24px;
            padding: 22px;
            min-height: 118px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.52s ease both;
        }

        .card::after {
            content: "";
            position: absolute;
            right: -38px;
            top: -45px;
            width: 120px;
            height: 120px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(18, 215, 255, 0.20), transparent 65%);
            pointer-events: none;
        }

        .card:hover,
        .panel:hover {
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
            letter-spacing: -0.7px;
            text-shadow: 0 0 22px rgba(18, 215, 255, 0.28);
        }

        .filter-panel {
            border-radius: 26px;
            padding: 18px;
            margin-bottom: 24px;
            animation: fadeUp 0.58s ease both;
        }

        .filter-box {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: center;
        }

        input,
        select {
            width: 100%;
            padding: 13px 15px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 15px;
            outline: none;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }

        input::placeholder { color: rgba(220, 231, 255, 0.6); }

        select option {
            color: #101827;
            background: #ffffff;
        }

        input:focus,
        select:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.64s ease both;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel-header h2 {
            margin: 0;
            font-size: 23px;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 13px 12px;
            color: rgba(248, 251, 255, 0.66);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        td {
            padding: 14px 12px;
            font-size: 14px;
            color: var(--soft);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.045);
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            text-transform: capitalize;
            white-space: nowrap;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .role.applicant {
            background: rgba(18, 215, 255, 0.13);
            color: #8df0ff;
            border-color: rgba(18, 215, 255, 0.27);
        }

        .role.recruiter {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .role.admin {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .role.creator {
            background: rgba(139, 92, 246, 0.16);
            color: #dccbff;
            border-color: rgba(139, 92, 246, 0.30);
        }

        .status.active {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .status.banned {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 14px;
            border-radius: 13px;
            text-decoration: none;
            border: 1px solid transparent;
            font-size: 13px;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            transition: transform 0.2s ease, box-shadow 0.2s ease, border-color 0.2s ease, background 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--cyan-2), #2356e8);
            color: #ffffff;
            box-shadow: 0 16px 34px rgba(18, 215, 255, 0.18);
        }

        .btn-primary:hover {
            box-shadow: 0 20px 42px rgba(18, 215, 255, 0.28);
        }

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
            border-color: rgba(255, 59, 95, 0.28);
        }

        .btn-danger:hover {
            background: rgba(255, 59, 95, 0.20);
            box-shadow: 0 14px 30px rgba(255, 59, 95, 0.12);
        }

        .btn-success {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .btn-success:hover {
            background: rgba(38, 224, 168, 0.19);
            box-shadow: 0 14px 30px rgba(38, 224, 168, 0.12);
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

        .card:nth-child(2) { animation-delay: 0.05s; }
        .card:nth-child(3) { animation-delay: 0.10s; }
        .card:nth-child(4) { animation-delay: 0.15s; }
        .card:nth-child(5) { animation-delay: 0.20s; }
        .card:nth-child(6) { animation-delay: 0.25s; }



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

        .confirm-icon.unban {
            background: rgba(38, 224, 168, 0.13);
            border-color: rgba(38, 224, 168, 0.28);
            box-shadow: 0 18px 40px rgba(38, 224, 168, 0.12);
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

        @media (max-width: 1350px) {
            .cards {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1200px) {
            .filter-box {
                grid-template-columns: 1fr 1fr;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }
        }

        @media (max-width: 750px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .cards,
            .filter-box {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid var(--line);
            }

            .layout {
                flex-direction: column;
            }

            .main {
                margin-left: 0;
                width: 100%;
                padding: 18px;
            }

            .topbar,
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div class="brand">
            <?php if (!empty($admin_profile_picture)) { ?>
                <img src="<?php echo htmlspecialchars($admin_profile_picture); ?>" class="brand-img" alt="Admin Profile">
            <?php } else { ?>
                <div class="brand-icon"><?php echo htmlspecialchars($sidebar_initial); ?></div>
            <?php } ?>

            <div class="brand-text-wrap">
                <div class="brand-text">Job Portal</div>
                <div class="brand-subtext"><?php echo htmlspecialchars($full_name); ?></div>
            </div>
        </div>

        <div class="nav-title">Admin Menu</div>

        <a href="dashboard.php" class="nav-link <?php echo active_link('dashboard.php', $current_page); ?>">
            <span class="nav-icon">▦</span> Dashboard
        </a>

        <a href="approve_jobs.php" class="nav-link <?php echo active_link('approve_jobs.php', $current_page); ?>">
            <span class="nav-icon">✓</span> Job Approval
        </a>

        <a href="manage_users.php" class="nav-link <?php echo active_link('manage_users.php', $current_page); ?>">
            <span class="nav-icon">👥</span> Manage Users
        </a>

        <a href="notifications.php" class="nav-link <?php echo active_link('notifications.php', $current_page); ?>">
            <span class="nav-icon">🔔</span> Notifications

            <?php if ($unread_notifications > 0) { ?>
                <span class="notif-badge"><?php echo $unread_notifications; ?></span>
            <?php } ?>
        </a>

        <a href="contact_messages.php" class="nav-link <?php echo active_link('contact_messages.php', $current_page); ?>">
            <span class="nav-icon">✉️</span> Contact Messages

            <?php if ($unread_messages > 0) { ?>
                <span class="notif-badge"><?php echo $unread_messages; ?></span>
            <?php } ?>
        </a>

        <a href="reports.php" class="nav-link <?php echo active_link('reports.php', $current_page); ?>">
            <span class="nav-icon">📊</span> Reports
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
                <h1>Manage Users</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name ?? 'Admin'); ?>. View, filter, ban, and unban user accounts.</p>
            </div>

            <div class="user-pill">Admin</div>
        </div>

        <?php if (!empty($message)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Users</p>
                <p class="card-number"><?php echo $total_users; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Applicants</p>
                <p class="card-number"><?php echo $total_applicants; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Recruiters</p>
                <p class="card-number"><?php echo $total_recruiters; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Admins</p>
                <p class="card-number"><?php echo $total_admins; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Active Users</p>
                <p class="card-number"><?php echo $active_users; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Banned Users</p>
                <p class="card-number"><?php echo $banned_users; ?></p>
            </div>
        </section>

        <div class="filter-panel">
            <form method="GET" class="filter-box">
                <input 
                    type="text" 
                    name="keyword" 
                    placeholder="Search name, email, role, or status"
                    value="<?php echo htmlspecialchars($keyword); ?>"
                >

                <select name="role">
                    <option value="all" <?php if ($role_filter == 'all') echo 'selected'; ?>>All Roles</option>
                    <option value="applicant" <?php if ($role_filter == 'applicant') echo 'selected'; ?>>Applicant</option>
                    <option value="recruiter" <?php if ($role_filter == 'recruiter') echo 'selected'; ?>>Recruiter</option>
                    <option value="admin" <?php if ($role_filter == 'admin') echo 'selected'; ?>>Admin</option>
                    <option value="creator" <?php if ($role_filter == 'creator') echo 'selected'; ?>>Creator</option>
                </select>

                <select name="status">
                    <option value="all" <?php if ($status_filter == 'all') echo 'selected'; ?>>All Status</option>
                    <option value="active" <?php if ($status_filter == 'active') echo 'selected'; ?>>Active</option>
                    <option value="banned" <?php if ($status_filter == 'banned') echo 'selected'; ?>>Banned</option>
                </select>

                <button type="submit" class="btn btn-primary">Filter</button>

                <a href="manage_users.php" class="btn btn-light">Reset</a>
            </form>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2>User Accounts</h2>
            </div>

            <?php if ($users && mysqli_num_rows($users) > 0) { ?>
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while ($user = mysqli_fetch_assoc($users)) { ?>
                            <?php
                                $role_css = role_class($user['role'] ?? '');
                                $status_css = status_class($user['status'] ?? 'active');
                                $is_self = intval($user['id']) === intval($admin_id);
                                $is_creator = strtolower($user['role'] ?? '') === 'creator';
                            ?>

                            <tr>
                                <td><?php echo htmlspecialchars($user['full_name'] ?? ''); ?></td>

                                <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>

                                <td>
                                    <span class="badge role <?php echo htmlspecialchars($role_css); ?>">
                                        <?php echo htmlspecialchars($user['role'] ?? ''); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="badge status <?php echo htmlspecialchars($status_css); ?>">
                                        <?php echo htmlspecialchars(ucwords($user['status'] ?? 'active')); ?>
                                    </span>
                                </td>

                                <td><?php echo htmlspecialchars($user['created_at'] ?? ''); ?></td>

                                <td>
                                    <div class="actions">
                                        <?php if (!$is_self && !$is_creator) { ?>
                                            <?php if (($user['status'] ?? 'active') === 'banned') { ?>
                                                <a href="manage_users.php?unban=<?php echo $user['id']; ?>" 
                                                   class="btn btn-success js-confirm-user-action"
                                                   data-action="unban"
                                                   data-user="<?php echo htmlspecialchars($user['full_name'] ?? 'this user', ENT_QUOTES); ?>"
                                                   data-message="This user will be able to login again.">
                                                    Unban
                                                </a>
                                            <?php } else { ?>
                                                <a href="manage_users.php?ban=<?php echo $user['id']; ?>" 
                                                   class="btn btn-danger js-confirm-user-action"
                                                   data-action="ban"
                                                   data-user="<?php echo htmlspecialchars($user['full_name'] ?? 'this user', ENT_QUOTES); ?>"
                                                   data-message="This user will not be able to login until the account is unbanned.">
                                                    Ban
                                                </a>
                                            <?php } ?>
                                        <?php } else { ?>
                                            <span class="btn btn-light">Protected</span>
                                        <?php } ?>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <p class="empty">No users found.</p>
            <?php } ?>
        </div>
    </main>
</div>

<div class="confirm-backdrop" id="confirmBackdrop" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="confirm-icon" id="confirmIcon">⚠️</div>
        <h2 class="confirm-title" id="confirmTitle">Confirm Action</h2>
        <p class="confirm-text" id="confirmMessage">Are you sure you want to continue?</p>
        <div class="confirm-user" id="confirmUser">User account</div>

        <div class="confirm-actions">
            <button type="button" class="btn btn-light" id="confirmCancel">Cancel</button>
            <a href="#" class="btn btn-danger" id="confirmProceed">Continue</a>
        </div>
    </div>
</div>

<script>
    const confirmBackdrop = document.getElementById('confirmBackdrop');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmUser = document.getElementById('confirmUser');
    const confirmIcon = document.getElementById('confirmIcon');
    const confirmProceed = document.getElementById('confirmProceed');
    const confirmCancel = document.getElementById('confirmCancel');

    document.querySelectorAll('.js-confirm-user-action').forEach((button) => {
        button.addEventListener('click', function(event) {
            event.preventDefault();

            const action = this.dataset.action || 'continue';
            const user = this.dataset.user || 'this user';
            const message = this.dataset.message || 'Are you sure you want to continue?';
            const href = this.getAttribute('href');

            const isBan = action === 'ban';

            confirmTitle.textContent = isBan ? 'Ban this user?' : 'Unban this user?';
            confirmMessage.textContent = message;
            confirmUser.textContent = user;
            confirmIcon.textContent = isBan ? '🚫' : '✅';
            confirmIcon.classList.toggle('unban', !isBan);
            confirmProceed.textContent = isBan ? 'Ban User' : 'Unban User';
            confirmProceed.href = href;
            confirmProceed.className = isBan ? 'btn btn-danger' : 'btn btn-success';

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