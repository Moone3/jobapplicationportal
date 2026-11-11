<?php
// creator/admin_logs.php
include "../includes/auth_check.php";
include "../config/database.php";

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'creator') {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'recruiter') {
        header("Location: ../recruiter/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'applicant') {
        header("Location: ../applicant/dashboard.php");
        exit();
    }

    header("Location: ../auth/login.php");
    exit();
}

$creator_id = intval($_SESSION['user_id']);
$full_name = $_SESSION['full_name'] ?? 'Creator';
$current_page = basename($_SERVER['PHP_SELF']);

$search = isset($_GET['search']) ? trim($_GET['search']) : "";
$user_filter = isset($_GET['user_id']) ? intval($_GET['user_id']) : 0;
$role_filter = isset($_GET['role']) ? trim($_GET['role']) : "all";

// Creator profile picture
$creator_profile_picture = "";

$creator_query = mysqli_query($conn, "
    SELECT full_name, profile_picture
    FROM users 
    WHERE id = '$creator_id' 
    LIMIT 1
");

if ($creator_query && mysqli_num_rows($creator_query) > 0) {
    $creator_data = mysqli_fetch_assoc($creator_query);

    if (!empty($creator_data['full_name'])) {
        $full_name = $creator_data['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    if (!empty($creator_data['profile_picture'])) {
        $picture_name = $creator_data['profile_picture'];

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
                $creator_profile_picture = $picture['web'];
                break;
            }
        }
    }
}

// Creator initials
$name_parts = preg_split('/\s+/', trim($full_name));
$sidebar_initial = "";

if (!empty($name_parts[0])) {
    $sidebar_initial .= strtoupper(substr($name_parts[0], 0, 1));
}

if (!empty($name_parts[1])) {
    $sidebar_initial .= strtoupper(substr($name_parts[1], 0, 1));
}

if ($sidebar_initial === "") {
    $sidebar_initial = "C";
}

// Creator notification badge
$unread_notifications = 0;

$notif_count_query = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$creator_id'
    AND is_read = 0
");

if ($notif_count_query) {
    $notif_count_row = mysqli_fetch_assoc($notif_count_query);
    $unread_notifications = $notif_count_row['total'];
}

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}

function get_count($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return $row['total'] ?? 0;
    }

    return 0;
}

function role_class($role) {
    $role = strtolower(trim($role));

    if ($role === "creator") {
        return "creator";
    }

    if ($role === "admin") {
        return "admin";
    }

    if ($role === "recruiter") {
        return "recruiter";
    }

    if ($role === "applicant") {
        return "applicant";
    }

    return "neutral";
}

// Users for filter
$log_users = mysqli_query($conn, "
    SELECT id, full_name, email, role
    FROM users
    WHERE role IN ('admin', 'creator')
    ORDER BY role DESC, full_name ASC
");

// Counts
$total_logs = get_count($conn, "SELECT COUNT(*) AS total FROM admin_logs");
$total_admins = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
$total_creators = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'creator'");
$today_logs = get_count($conn, "SELECT COUNT(*) AS total FROM admin_logs WHERE DATE(created_at) = CURDATE()");
$this_week_logs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM admin_logs 
    WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)
");

// Logs query
$sql = "
    SELECT 
        admin_logs.id,
        admin_logs.admin_id,
        admin_logs.action,
        admin_logs.created_at,
        users.full_name AS admin_name,
        users.email AS admin_email,
        users.role AS admin_role
    FROM admin_logs
    LEFT JOIN users ON admin_logs.admin_id = users.id
    WHERE 1
";

$params = [];
$types = "";

if (!empty($search)) {
    $sql .= " AND (
        admin_logs.action LIKE ?
        OR users.full_name LIKE ?
        OR users.email LIKE ?
        OR users.role LIKE ?
        OR admin_logs.created_at LIKE ?
    )";

    $search_value = "%" . $search . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "sssss";
}

if ($user_filter > 0) {
    $sql .= " AND admin_logs.admin_id = ?";
    $params[] = $user_filter;
    $types .= "i";
}

if ($role_filter !== "all" && !empty($role_filter)) {
    $sql .= " AND users.role = ?";
    $params[] = $role_filter;
    $types .= "s";
}

$sql .= " ORDER BY admin_logs.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$logs = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Logs | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --bg-deep: #0d1630;
            --panel: rgba(15, 23, 42, 0.72);
            --panel-strong: rgba(15, 23, 42, 0.86);
            --glass: rgba(255, 255, 255, 0.09);
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

        a {
            color: inherit;
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
            background: linear-gradient(135deg, var(--cyan-2), var(--purple));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 15px;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(139, 92, 246, 0.18);
        }

        .brand-img {
            object-fit: cover;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(139, 92, 246, 0.18);
        }

        .brand-text-wrap {
            min-width: 0;
        }

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
        .panel,
        .filter-box,
        .action-box {
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
        .panel::before,
        .filter-box::before,
        .action-box::before {
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
        .action-box > * {
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
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 24px;
            padding: 22px;
            min-height: 120px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.52s ease both;
        }

        .card:hover {
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
            animation: fadeUp 0.64s ease both;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel h2 {
            margin: 0;
            color: var(--text);
            font-size: 23px;
            letter-spacing: -0.3px;
        }

        .panel-subtitle {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .filter-box {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: center;
            margin-bottom: 22px;
            border-radius: 20px;
            padding: 18px;
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

        input::placeholder {
            color: rgba(220, 231, 255, 0.6);
        }

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
            white-space: nowrap;
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

        table {
            position: relative;
            z-index: 1;
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 14px 12px;
            color: rgba(248, 251, 255, 0.66);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        td {
            padding: 15px 12px;
            font-size: 14px;
            color: var(--soft);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            vertical-align: top;
        }

        tbody tr {
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.045);
        }

        .admin-name {
            font-weight: 900;
            color: var(--text);
        }

        .muted {
            color: var(--muted);
            font-size: 13px;
            margin-top: 5px;
            line-height: 1.5;
        }

        .action-box {
            border-radius: 16px;
            padding: 12px 13px;
            line-height: 1.6;
            box-shadow: none;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            text-transform: capitalize;
            border: 1px solid transparent;
        }

        .role.creator {
            background: rgba(139, 92, 246, 0.16);
            color: #dccbff;
            border-color: rgba(139, 92, 246, 0.30);
        }

        .role.admin {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .role.recruiter {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .role.applicant {
            background: rgba(18, 215, 255, 0.12);
            color: #bff5ff;
            border-color: rgba(18, 215, 255, 0.18);
        }

        .role.neutral {
            background: rgba(255, 255, 255, 0.10);
            color: #e7edff;
            border-color: rgba(255, 255, 255, 0.14);
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

        @media (max-width: 1350px) {
            .cards {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1150px) {
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
            <?php if (!empty($creator_profile_picture)) { ?>
                <img src="<?php echo htmlspecialchars($creator_profile_picture); ?>" class="brand-img" alt="Creator Profile">
            <?php } else { ?>
                <div class="brand-icon"><?php echo htmlspecialchars($sidebar_initial); ?></div>
            <?php } ?>

            <div class="brand-text-wrap">
                <div class="brand-text">Job Portal</div>
                <div class="brand-subtext"><?php echo htmlspecialchars($full_name); ?></div>
            </div>
        </div>

        <div class="nav-title">Creator Menu</div>

        <a href="dashboard.php" class="nav-link <?php echo active_link('dashboard.php', $current_page); ?>">
            <span class="nav-icon">▦</span> Dashboard
        </a>

        <a href="manage_admins.php" class="nav-link <?php echo active_link('manage_admins.php', $current_page); ?>">
            <span class="nav-icon">👑</span> Manage Admins
        </a>

        <a href="admin_logs.php" class="nav-link <?php echo active_link('admin_logs.php', $current_page); ?>">
            <span class="nav-icon">📜</span> Admin Logs
        </a>

        <a href="system_analytics.php" class="nav-link <?php echo active_link('system_analytics.php', $current_page); ?>">
            <span class="nav-icon">📊</span> System Analytics
        </a>

        <a href="profile.php" class="nav-link <?php echo active_link('profile.php', $current_page); ?>">
            <span class="nav-icon">👤</span> Profile
        </a>

        <a href="notifications.php" class="nav-link <?php echo active_link('notifications.php', $current_page); ?>">
            <span class="nav-icon">🔔</span> Notifications

            <?php if ($unread_notifications > 0) { ?>
                <span class="notif-badge"><?php echo $unread_notifications; ?></span>
            <?php } ?>
        </a>

        <a href="../auth/logout.php" class="nav-link logout">
            <span class="nav-icon">↪</span> Logout
        </a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>Admin Logs</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Monitor all recorded admin and creator activities.</p>
            </div>

            <div class="user-pill">Creator</div>
        </div>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Logs</p>
                <p class="card-number"><?php echo $total_logs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Total Admins</p>
                <p class="card-number"><?php echo $total_admins; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Total Creators</p>
                <p class="card-number"><?php echo $total_creators; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Today Logs</p>
                <p class="card-number"><?php echo $today_logs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">This Week</p>
                <p class="card-number"><?php echo $this_week_logs; ?></p>
            </div>
        </section>

        <div class="panel">
            <div class="panel-header">
                <div>
                    <h2>Activity Records</h2>
                    <p class="panel-subtitle">Search and filter actions performed by admins or creators.</p>
                </div>
            </div>

            <form method="GET" class="filter-box">
                <input 
                    type="text" 
                    name="search" 
                    placeholder="Search action, name, email, role, or date"
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="user_id">
                    <option value="0">All Users</option>

                    <?php if ($log_users && mysqli_num_rows($log_users) > 0) { ?>
                        <?php while ($log_user = mysqli_fetch_assoc($log_users)) { ?>
                            <option value="<?php echo $log_user['id']; ?>" <?php if ($user_filter == $log_user['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($log_user['full_name']); ?> - <?php echo htmlspecialchars($log_user['role']); ?>
                            </option>
                        <?php } ?>
                    <?php } ?>
                </select>

                <select name="role">
                    <option value="all" <?php if ($role_filter === 'all') echo 'selected'; ?>>All Roles</option>
                    <option value="creator" <?php if ($role_filter === 'creator') echo 'selected'; ?>>Creator</option>
                    <option value="admin" <?php if ($role_filter === 'admin') echo 'selected'; ?>>Admin</option>
                </select>

                <button type="submit" class="btn btn-primary">Filter</button>

                <a href="admin_logs.php" class="btn btn-light">Reset</a>
            </form>

            <?php if ($logs && mysqli_num_rows($logs) > 0) { ?>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Action</th>
                            <th>Date / Time</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while ($log = mysqli_fetch_assoc($logs)) { ?>
                            <?php $role_css = role_class($log['admin_role'] ?? 'neutral'); ?>

                            <tr>
                                <td>
                                    <div class="admin-name">
                                        <?php echo htmlspecialchars($log['admin_name'] ?? 'Unknown User'); ?>
                                    </div>

                                    <div class="muted">
                                        <?php echo htmlspecialchars($log['admin_email'] ?? 'No email'); ?>
                                    </div>
                                </td>

                                <td>
                                    <span class="badge role <?php echo htmlspecialchars($role_css); ?>">
                                        <?php echo htmlspecialchars($log['admin_role'] ?? 'Unknown'); ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-box">
                                        <?php echo htmlspecialchars($log['action'] ?? ''); ?>
                                    </div>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($log['created_at'] ?? ''); ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <p class="empty">No admin logs found.</p>
            <?php } ?>
        </div>
    </main>
</div>

</body>
</html>