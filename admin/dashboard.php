<?php
// admin/dashboard.php
include "../includes/auth_check.php";
include "../config/database.php";

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$full_name = $_SESSION['full_name'] ?? 'Admin';

// Current page for active sidebar highlight
$current_page = basename($_SERVER['PHP_SELF']);

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

// Get initials if no profile picture
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

// Dashboard counts
$total_users = 0;
$total_applicants = 0;
$total_recruiters = 0;
$pending_jobs = 0;
$approved_jobs = 0;
$rejected_jobs = 0;
$unread_notifications = 0;

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM users
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_users = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM users 
    WHERE role = 'applicant'
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_applicants = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM users 
    WHERE role = 'recruiter'
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_recruiters = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE status = 'pending'
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $pending_jobs = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE status = 'approved'
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $approved_jobs = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE status = 'rejected'
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $rejected_jobs = $row['total'];
}

// Unread notifications count
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

// Recent job posts
$recent_jobs = mysqli_query($conn, "
    SELECT 
        jobs.id,
        jobs.title,
        jobs.status,
        jobs.created_at,
        users.full_name AS recruiter_name
    FROM jobs
    JOIN users ON jobs.recruiter_id = users.id
    ORDER BY jobs.created_at DESC
    LIMIT 5
");

// Recent users
$recent_users = mysqli_query($conn, "
    SELECT 
        id,
        full_name,
        email,
        role,
        status,
        created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 5
");

function status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "approved" || $status === "active") {
        return "approved";
    }

    if ($status === "rejected" || $status === "banned") {
        return "rejected";
    }

    if ($status === "pending") {
        return "pending";
    }

    return "neutral";
}

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --panel: rgba(15, 23, 42, 0.72);
            --panel-strong: rgba(15, 23, 42, 0.86);
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

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
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

        .panel h2 {
            margin: 0;
            font-size: 23px;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .small-link {
            color: var(--cyan);
            text-decoration: none;
            font-size: 14px;
            font-weight: 900;
            transition: color 0.2s ease, text-shadow 0.2s ease;
        }

        .small-link:hover {
            color: #ffffff;
            text-shadow: 0 0 18px rgba(18, 215, 255, 0.55);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 13px 11px;
            color: rgba(248, 251, 255, 0.66);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        td {
            padding: 14px 11px;
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

        small {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .status {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            text-transform: capitalize;
            white-space: nowrap;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .status.pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .status.approved {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .status.rejected {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .status.neutral {
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
        .card:nth-child(6) { animation-delay: 0.25s; }

        @media (max-width: 1350px) {
            .cards {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 1150px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 750px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .cards {
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

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
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
                <h1>Admin Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($full_name); ?>. Review job posts and manage users.</p>
            </div>

            <div class="user-pill">Admin</div>
        </div>

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
                <p class="card-title">Pending Jobs</p>
                <p class="card-number"><?php echo $pending_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Approved Jobs</p>
                <p class="card-number"><?php echo $approved_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Rejected Jobs</p>
                <p class="card-number"><?php echo $rejected_jobs; ?></p>
            </div>
        </section>

        <section class="content-grid">
            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Job Posts</h2>
                    <a href="approve_jobs.php" class="small-link">Review</a>
                </div>

                <?php if ($recent_jobs && mysqli_num_rows($recent_jobs) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Recruiter</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($job = mysqli_fetch_assoc($recent_jobs)) { ?>
                                <?php $job_status_class = status_class($job['status'] ?? 'pending'); ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($job['title'] ?? ''); ?><br>
                                        <small><?php echo htmlspecialchars($job['created_at'] ?? ''); ?></small>
                                    </td>

                                    <td><?php echo htmlspecialchars($job['recruiter_name'] ?? ''); ?></td>

                                    <td>
                                        <span class="status <?php echo htmlspecialchars($job_status_class); ?>">
                                            <?php echo htmlspecialchars($job['status'] ?? ''); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No recent job posts found.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Users</h2>
                    <a href="manage_users.php" class="small-link">View all</a>
                </div>

                <?php if ($recent_users && mysqli_num_rows($recent_users) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($user = mysqli_fetch_assoc($recent_users)) { ?>
                                <?php $user_status_class = status_class($user['status'] ?? 'neutral'); ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($user['full_name'] ?? ''); ?><br>
                                        <small><?php echo htmlspecialchars($user['email'] ?? ''); ?></small>
                                    </td>

                                    <td>
                                        <span class="status neutral">
                                            <?php echo htmlspecialchars($user['role'] ?? ''); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="status <?php echo htmlspecialchars($user_status_class); ?>">
                                            <?php echo htmlspecialchars($user['status'] ?? 'active'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No recent users found.</p>
                <?php } ?>
            </div>
        </section>
    </main>
</div>

</body>
</html>