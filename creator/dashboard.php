<?php
// creator/dashboard.php
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

$creator_profile_picture = "";

// Get creator profile
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

// Creator initials fallback
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

// Creator notification badge count
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

function get_count($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return $row['total'] ?? 0;
    }

    return 0;
}

// Dashboard counts
$total_users = get_count($conn, "SELECT COUNT(*) AS total FROM users");
$total_admins = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
$total_creators = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'creator'");
$total_applicants = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'applicant'");
$total_recruiters = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'recruiter'");
$active_users = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE status = 'active'");
$banned_users = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE status = 'banned'");

$total_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs");
$pending_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'pending'");
$approved_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'approved'");
$rejected_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'rejected'");

$total_applications = get_count($conn, "SELECT COUNT(*) AS total FROM applications");
$pending_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE status IN ('pending', 'application received')
");
$accepted_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE status IN ('accepted', 'candidate accepted offer')
");
$rejected_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE status IN ('rejected', 'rejected by client', 'declined by client')
");

$total_interviews = get_count($conn, "SELECT COUNT(*) AS total FROM interviews");
$total_logs = get_count($conn, "SELECT COUNT(*) AS total FROM admin_logs");

// Recent admin and creator accounts
$recent_admins = mysqli_query($conn, "
    SELECT 
        id,
        full_name,
        email,
        role,
        status,
        created_at
    FROM users
    WHERE role IN ('admin', 'creator')
    ORDER BY created_at DESC
    LIMIT 6
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
    LIMIT 6
");

// Recent admin logs
$recent_logs = mysqli_query($conn, "
    SELECT 
        admin_logs.id,
        admin_logs.admin_id,
        admin_logs.action,
        admin_logs.created_at,
        users.full_name AS admin_name,
        users.role AS admin_role
    FROM admin_logs
    LEFT JOIN users ON admin_logs.admin_id = users.id
    ORDER BY admin_logs.created_at DESC
    LIMIT 8
");

// Top applied jobs
$top_jobs = mysqli_query($conn, "
    SELECT 
        jobs.title,
        users.full_name AS recruiter_name,
        COUNT(applications.id) AS total_applications
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON jobs.recruiter_id = users.id
    GROUP BY jobs.id
    ORDER BY total_applications DESC
    LIMIT 5
");

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}

function status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "active" || $status === "approved" || $status === "accepted") {
        return "approved";
    }

    if ($status === "banned" || $status === "rejected") {
        return "rejected";
    }

    if ($status === "pending") {
        return "pending";
    }

    return "neutral";
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

    return "applicant";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Creator Dashboard | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        * {
            box-sizing: border-box;
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            margin: 0;
            background: #f4f7f8;
            color: #243b3f;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 255px;
            background: #edf3f4;
            border-right: 1px solid #dce7ea;
            padding: 24px 18px;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 35px;
            padding: 0 8px;
        }

        .brand-icon,
        .brand-img {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            flex-shrink: 0;
        }

        .brand-icon {
            background: #6d28d9;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 15px;
            letter-spacing: 0.5px;
            border: 2px solid #ffffff;
            box-shadow: 0 4px 10px rgba(30, 60, 70, 0.12);
        }

        .brand-img {
            object-fit: cover;
            background: #efe7ff;
            border: 2px solid #ffffff;
            box-shadow: 0 4px 10px rgba(30, 60, 70, 0.12);
        }

        .brand-text-wrap {
            min-width: 0;
        }

        .brand-text {
            font-size: 18px;
            font-weight: 800;
            color: #183f45;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .brand-subtext {
            margin-top: 2px;
            font-size: 12px;
            color: #7a8b91;
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 155px;
        }

        .nav-title {
            font-size: 12px;
            text-transform: uppercase;
            color: #8a9da3;
            margin: 18px 8px 10px;
            letter-spacing: 0.7px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            margin-bottom: 7px;
            color: #4c6268;
            text-decoration: none;
            border-radius: 14px;
            font-size: 14px;
            font-weight: 600;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #efe7ff;
            color: #5b21b6;
        }

        .nav-link.active {
            font-weight: 800;
        }

        .nav-icon {
            width: 22px;
            text-align: center;
        }

        .notif-badge {
            margin-left: auto;
            background: #b42318;
            color: #ffffff;
            min-width: 22px;
            height: 22px;
            padding: 0 7px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
        }

        .logout {
            margin-top: 26px;
            color: #b42318;
        }

        .main {
            margin-left: 255px;
            width: calc(100% - 255px);
            padding: 28px;
        }

        .topbar {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 22px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
        }

        .topbar h1 {
            margin: 0;
            font-size: 26px;
            color: #193b40;
        }

        .topbar p {
            margin: 7px 0 0;
            color: #7a8b91;
            font-size: 14px;
        }

        .user-pill {
            background: #efe7ff;
            border: 1px solid #ddd0ff;
            padding: 10px 14px;
            border-radius: 999px;
            color: #5b21b6;
            font-size: 14px;
            font-weight: 800;
        }

        .quick-actions {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 20px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
        }

        .card-title {
            margin: 0 0 12px;
            color: #7a8b91;
            font-size: 13px;
            font-weight: 700;
        }

        .card-number {
            font-size: 30px;
            font-weight: 800;
            color: #6d28d9;
            margin: 0;
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 22px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
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
            font-size: 20px;
            color: #193b40;
        }

        .small-link {
            color: #6d28d9;
            text-decoration: none;
            font-size: 14px;
            font-weight: 800;
        }

        .small-link:hover {
            text-decoration: underline;
        }

        .btn {
            padding: 12px 16px;
            border-radius: 13px;
            text-decoration: none;
            border: none;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            display: inline-block;
        }

        .btn-primary {
            background: #6d28d9;
            color: #ffffff;
        }

        .btn-primary:hover {
            background: #5b21b6;
        }

        .btn-light {
            background: #f4f9fa;
            color: #345158;
            border: 1px solid #dce7ea;
        }

        .btn-light:hover {
            background: #edf3f4;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px 10px;
            color: #6f8187;
            font-size: 13px;
            border-bottom: 1px solid #e5eef0;
        }

        td {
            padding: 12px 10px;
            font-size: 14px;
            color: #344b50;
            border-bottom: 1px solid #eef4f5;
            vertical-align: middle;
        }

        small {
            color: #8a9da3;
            font-size: 12px;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .status.pending {
            background: #fff7e6;
            color: #b76e00;
        }

        .status.approved {
            background: #e9fbf3;
            color: #027a48;
        }

        .status.rejected {
            background: #ffecec;
            color: #b42318;
        }

        .status.neutral {
            background: #eef0f2;
            color: #5f6b70;
        }

        .role.creator {
            background: #efe7ff;
            color: #5b21b6;
        }

        .role.admin {
            background: #e9fbf3;
            color: #027a48;
        }

        .role.recruiter {
            background: #fff7e6;
            color: #b76e00;
        }

        .role.applicant {
            background: #eef7f8;
            color: #0b6f7b;
        }

        .log-item {
            border: 1px solid #e5eef0;
            background: #fbfdfe;
            border-radius: 16px;
            padding: 14px;
            margin-bottom: 12px;
        }

        .log-item strong {
            color: #193b40;
            display: block;
            margin-bottom: 6px;
        }

        .log-item p {
            margin: 0;
            color: #6f8187;
            font-size: 14px;
            line-height: 1.6;
        }

        .empty {
            color: #7a8b91;
            font-size: 14px;
            padding: 18px 0;
        }

        @media (max-width: 1250px) {
            .cards {
                grid-template-columns: repeat(3, 1fr);
            }

            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 750px) {
            .cards {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .layout {
                flex-direction: column;
            }

            .main {
                margin-left: 0;
                width: 100%;
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
                <h1>Creator Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($full_name); ?>. Manage admins and monitor the whole job application system.</p>
            </div>

            <div class="user-pill">Creator</div>
        </div>

        <div class="quick-actions">
            <a href="manage_admins.php" class="btn btn-primary">Manage Admins</a>
            <a href="admin_logs.php" class="btn btn-light">View Admin Logs</a>
            <a href="system_analytics.php" class="btn btn-light">View System Analytics</a>
        </div>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Users</p>
                <p class="card-number"><?php echo $total_users; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Admins</p>
                <p class="card-number"><?php echo $total_admins; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Creators</p>
                <p class="card-number"><?php echo $total_creators; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Applicants</p>
                <p class="card-number"><?php echo $total_applicants; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Recruiters</p>
                <p class="card-number"><?php echo $total_recruiters; ?></p>
            </div>
        </section>

        <section class="cards">
            <div class="card">
                <p class="card-title">Active Users</p>
                <p class="card-number"><?php echo $active_users; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Banned Users</p>
                <p class="card-number"><?php echo $banned_users; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Total Jobs</p>
                <p class="card-number"><?php echo $total_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Applications</p>
                <p class="card-number"><?php echo $total_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Admin Logs</p>
                <p class="card-number"><?php echo $total_logs; ?></p>
            </div>
        </section>

        <section class="cards">
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

            <div class="card">
                <p class="card-title">Pending Applications</p>
                <p class="card-number"><?php echo $pending_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Interviews</p>
                <p class="card-number"><?php echo $total_interviews; ?></p>
            </div>
        </section>

        <section class="content-grid">
            <div class="panel">
                <div class="panel-header">
                    <h2>Admin and Creator Accounts</h2>
                    <a href="manage_admins.php" class="small-link">Manage</a>
                </div>

                <?php if ($recent_admins && mysqli_num_rows($recent_admins) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($admin = mysqli_fetch_assoc($recent_admins)) { ?>
                                <?php
                                    $role_css = role_class($admin['role'] ?? '');
                                    $status_css = status_class($admin['status'] ?? 'active');
                                ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($admin['full_name'] ?? ''); ?><br>
                                        <small><?php echo htmlspecialchars($admin['email'] ?? ''); ?></small>
                                    </td>

                                    <td>
                                        <span class="badge role <?php echo htmlspecialchars($role_css); ?>">
                                            <?php echo htmlspecialchars($admin['role'] ?? ''); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge status <?php echo htmlspecialchars($status_css); ?>">
                                            <?php echo htmlspecialchars($admin['status'] ?? 'active'); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No admin or creator accounts found.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Users</h2>
                    <a href="manage_admins.php" class="small-link">View users</a>
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
                                <?php
                                    $role_css = role_class($user['role'] ?? '');
                                    $status_css = status_class($user['status'] ?? 'active');
                                ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($user['full_name'] ?? ''); ?><br>
                                        <small><?php echo htmlspecialchars($user['email'] ?? ''); ?></small>
                                    </td>

                                    <td>
                                        <span class="badge role <?php echo htmlspecialchars($role_css); ?>">
                                            <?php echo htmlspecialchars($user['role'] ?? ''); ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="badge status <?php echo htmlspecialchars($status_css); ?>">
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

            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Admin Activity</h2>
                    <a href="admin_logs.php" class="small-link">View all</a>
                </div>

                <?php if ($recent_logs && mysqli_num_rows($recent_logs) > 0) { ?>
                    <?php while ($log = mysqli_fetch_assoc($recent_logs)) { ?>
                        <div class="log-item">
                            <strong>
                                <?php echo htmlspecialchars($log['admin_name'] ?? 'Unknown User'); ?>

                                <?php if (!empty($log['admin_role'])) { ?>
                                    <small>(<?php echo htmlspecialchars($log['admin_role']); ?>)</small>
                                <?php } ?>
                            </strong>

                            <p>
                                <?php echo htmlspecialchars($log['action'] ?? ''); ?><br>
                                <small><?php echo htmlspecialchars($log['created_at'] ?? ''); ?></small>
                            </p>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p class="empty">No admin activity logs yet.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2>Top Applied Jobs</h2>
                    <a href="system_analytics.php" class="small-link">Analytics</a>
                </div>

                <?php if ($top_jobs && mysqli_num_rows($top_jobs) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Job Title</th>
                                <th>Recruiter</th>
                                <th>Applications</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($job = mysqli_fetch_assoc($top_jobs)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($job['title'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($job['recruiter_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($job['total_applications'] ?? '0'); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No job applications yet.</p>
                <?php } ?>
            </div>
        </section>
    </main>
</div>

</body>
</html>