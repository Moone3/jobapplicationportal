<?php
// creator/system_analytics.php
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

function get_count($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return $row['total'] ?? 0;
    }

    return 0;
}

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}

function clean_status_class($status) {
    $status = strtolower(trim($status));
    $status = str_replace(" ", "-", $status);
    return $status;
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

// User analytics
$total_users = get_count($conn, "SELECT COUNT(*) AS total FROM users");
$total_applicants = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'applicant'");
$total_recruiters = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'recruiter'");
$total_admins = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
$total_creators = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'creator'");
$active_users = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE status = 'active'");
$banned_users = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE status = 'banned'");

// Job analytics
$total_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs");
$pending_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'pending'");
$approved_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'approved'");
$rejected_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'rejected'");
$archived_jobs = get_count($conn, "SELECT COUNT(*) AS total FROM jobs WHERE is_archived = 1");
$active_live_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE status = 'approved'
    AND is_archived = 0
    AND expiry_date >= CURDATE()
");

// Application analytics
$total_applications = get_count($conn, "SELECT COUNT(*) AS total FROM applications");
$pending_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE status IN ('pending', 'application received')
");
$reviewed_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE status = 'application review complete'
");
$interview_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE status IN ('schedule interview', 'interview')
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

// Interview analytics
$total_interviews = get_count($conn, "SELECT COUNT(*) AS total FROM interviews");
$scheduled_interviews = get_count($conn, "SELECT COUNT(*) AS total FROM interviews WHERE status = 'scheduled'");
$cancelled_interviews = get_count($conn, "SELECT COUNT(*) AS total FROM interviews WHERE status = 'cancelled'");
$completed_interviews = get_count($conn, "SELECT COUNT(*) AS total FROM interviews WHERE status = 'completed'");

// Notification analytics
$total_notifications = get_count($conn, "SELECT COUNT(*) AS total FROM notifications");
$total_unread_notifications = get_count($conn, "SELECT COUNT(*) AS total FROM notifications WHERE is_read = 0");

// Logs
$total_logs = get_count($conn, "SELECT COUNT(*) AS total FROM admin_logs");
$today_logs = get_count($conn, "SELECT COUNT(*) AS total FROM admin_logs WHERE DATE(created_at) = CURDATE()");

// Contact messages
$total_contact_messages = get_count($conn, "SELECT COUNT(*) AS total FROM contact_messages");
$unread_contact_messages = get_count($conn, "SELECT COUNT(*) AS total FROM contact_messages WHERE status = 'unread'");

// Top applied jobs
$top_jobs = mysqli_query($conn, "
    SELECT 
        jobs.title,
        jobs.status,
        users.full_name AS recruiter_name,
        COUNT(applications.id) AS total_applications
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON jobs.recruiter_id = users.id
    GROUP BY jobs.id
    ORDER BY total_applications DESC
    LIMIT 8
");

// Top recruiters by job posts
$top_recruiters = mysqli_query($conn, "
    SELECT 
        users.full_name,
        users.email,
        users.status,
        COUNT(jobs.id) AS total_jobs
    FROM users
    LEFT JOIN jobs ON users.id = jobs.recruiter_id
    WHERE users.role = 'recruiter'
    GROUP BY users.id
    ORDER BY total_jobs DESC
    LIMIT 8
");

// Recent users
$recent_users = mysqli_query($conn, "
    SELECT id, full_name, email, role, status, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 8
");

// Recent jobs
$recent_jobs = mysqli_query($conn, "
    SELECT 
        jobs.title,
        jobs.status,
        jobs.created_at,
        users.full_name AS recruiter_name
    FROM jobs
    JOIN users ON jobs.recruiter_id = users.id
    ORDER BY jobs.created_at DESC
    LIMIT 8
");

// Recent applications
$recent_applications = mysqli_query($conn, "
    SELECT 
        applications.status,
        applications.applied_at,
        jobs.title AS job_title,
        users.full_name AS applicant_name
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON applications.applicant_id = users.id
    ORDER BY applications.applied_at DESC
    LIMIT 8
");

// Recent admin logs
$recent_logs = mysqli_query($conn, "
    SELECT 
        admin_logs.*,
        users.full_name AS admin_name,
        users.role AS admin_role
    FROM admin_logs
    LEFT JOIN users ON admin_logs.admin_id = users.id
    ORDER BY admin_logs.created_at DESC
    LIMIT 8
");

// Category analytics
$category_stats = mysqli_query($conn, "
    SELECT 
        job_categories.category_name,
        COUNT(jobs.id) AS total_jobs
    FROM job_categories
    LEFT JOIN jobs ON job_categories.id = jobs.category_id
    GROUP BY job_categories.id
    ORDER BY total_jobs DESC
    LIMIT 8
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>System Analytics | Job Application System</title>
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

        .section-title {
            margin: 26px 0 14px;
            color: #193b40;
            font-size: 20px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 10px;
        }

        .card {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 22px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
        }

        .card-title {
            margin: 0 0 12px;
            color: #7a8b91;
            font-size: 14px;
            font-weight: 700;
        }

        .card-number {
            font-size: 32px;
            font-weight: 800;
            color: #6d28d9;
            margin: 0;
        }

        .panel-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            margin-top: 24px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
        }

        .panel h2 {
            margin: 0 0 18px;
            color: #193b40;
            font-size: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 12px;
            color: #6f8187;
            font-size: 13px;
            border-bottom: 1px solid #e5eef0;
        }

        td {
            padding: 13px 12px;
            font-size: 14px;
            color: #344b50;
            border-bottom: 1px solid #eef4f5;
            vertical-align: top;
        }

        small {
            color: #7a8b91;
        }

        .badge,
        .status {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            background: #eef7f8;
            color: #0b6f7b;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .status.pending,
        .status.application-received,
        .status.application-review-complete,
        .status.schedule-interview {
            background: #fff7e6;
            color: #b76e00;
        }

        .status.approved,
        .status.active,
        .status.scheduled,
        .status.accepted,
        .status.completed,
        .status.candidate-accepted-offer {
            background: #e9fbf3;
            color: #027a48;
        }

        .status.rejected,
        .status.cancelled,
        .status.banned,
        .status.rejected-by-client,
        .status.declined-by-client {
            background: #ffecec;
            color: #b42318;
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
            padding: 10px 0;
        }

        @media (max-width: 1100px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .panel-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 750px) {
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

            .cards {
                grid-template-columns: 1fr;
            }

            .topbar {
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
                <h1>System Analytics</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Monitor users, jobs, applications, interviews, and admin activity.</p>
            </div>

            <div class="user-pill">Creator</div>
        </div>

        <h2 class="section-title">User Overview</h2>

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
        </section>

        <section class="cards">
            <div class="card">
                <p class="card-title">Creators</p>
                <p class="card-number"><?php echo $total_creators; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Active Users</p>
                <p class="card-number"><?php echo $active_users; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Banned Users</p>
                <p class="card-number"><?php echo $banned_users; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Unread Notifications</p>
                <p class="card-number"><?php echo $total_unread_notifications; ?></p>
            </div>
        </section>

        <h2 class="section-title">Job Overview</h2>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Jobs</p>
                <p class="card-number"><?php echo $total_jobs; ?></p>
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

        <section class="cards">
            <div class="card">
                <p class="card-title">Live Active Jobs</p>
                <p class="card-number"><?php echo $active_live_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Archived Jobs</p>
                <p class="card-number"><?php echo $archived_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Contact Messages</p>
                <p class="card-number"><?php echo $total_contact_messages; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Unread Messages</p>
                <p class="card-number"><?php echo $unread_contact_messages; ?></p>
            </div>
        </section>

        <h2 class="section-title">Applications and Interviews</h2>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Applications</p>
                <p class="card-number"><?php echo $total_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending</p>
                <p class="card-number"><?php echo $pending_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Reviewed</p>
                <p class="card-number"><?php echo $reviewed_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">For Interview</p>
                <p class="card-number"><?php echo $interview_applications; ?></p>
            </div>
        </section>

        <section class="cards">
            <div class="card">
                <p class="card-title">Accepted</p>
                <p class="card-number"><?php echo $accepted_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Rejected</p>
                <p class="card-number"><?php echo $rejected_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Scheduled Interviews</p>
                <p class="card-number"><?php echo $scheduled_interviews; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Completed Interviews</p>
                <p class="card-number"><?php echo $completed_interviews; ?></p>
            </div>
        </section>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Interviews</p>
                <p class="card-number"><?php echo $total_interviews; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Cancelled Interviews</p>
                <p class="card-number"><?php echo $cancelled_interviews; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Admin Logs</p>
                <p class="card-number"><?php echo $total_logs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Today Logs</p>
                <p class="card-number"><?php echo $today_logs; ?></p>
            </div>
        </section>

        <div class="panel-grid">
            <div class="panel">
                <h2>Top Applied Jobs</h2>

                <?php if ($top_jobs && mysqli_num_rows($top_jobs) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Recruiter</th>
                                <th>Applications</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($job = mysqli_fetch_assoc($top_jobs)) { ?>
                                <?php $job_status_class = clean_status_class($job['status'] ?? ''); ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($job['title'] ?? ''); ?><br>
                                        <span class="status <?php echo htmlspecialchars($job_status_class); ?>">
                                            <?php echo htmlspecialchars($job['status'] ?? ''); ?>
                                        </span>
                                    </td>
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

            <div class="panel">
                <h2>Top Recruiters by Job Posts</h2>

                <?php if ($top_recruiters && mysqli_num_rows($top_recruiters) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Recruiter</th>
                                <th>Status</th>
                                <th>Jobs</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($recruiter = mysqli_fetch_assoc($top_recruiters)) { ?>
                                <?php $recruiter_status_class = clean_status_class($recruiter['status'] ?? ''); ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($recruiter['full_name'] ?? ''); ?><br>
                                        <small><?php echo htmlspecialchars($recruiter['email'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <span class="status <?php echo htmlspecialchars($recruiter_status_class); ?>">
                                            <?php echo htmlspecialchars($recruiter['status'] ?? 'active'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($recruiter['total_jobs'] ?? '0'); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No recruiter data yet.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <h2>Job Categories</h2>

                <?php if ($category_stats && mysqli_num_rows($category_stats) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Total Jobs</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($category = mysqli_fetch_assoc($category_stats)) { ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($category['category_name'] ?? 'Uncategorized'); ?></td>
                                    <td><?php echo htmlspecialchars($category['total_jobs'] ?? '0'); ?></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No category data yet.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <h2>Recent Users</h2>

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
                                    $user_status_class = clean_status_class($user['status'] ?? '');
                                    $user_role_class = role_class($user['role'] ?? '');
                                ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($user['full_name'] ?? ''); ?><br>
                                        <small><?php echo htmlspecialchars($user['email'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge role <?php echo htmlspecialchars($user_role_class); ?>">
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
                    <p class="empty">No users found.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <h2>Recent Job Posts</h2>

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
                                <?php $job_status_class = clean_status_class($job['status'] ?? ''); ?>

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
                    <p class="empty">No recent jobs found.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <h2>Recent Applications</h2>

                <?php if ($recent_applications && mysqli_num_rows($recent_applications) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Job</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($application = mysqli_fetch_assoc($recent_applications)) { ?>
                                <?php $application_status_class = clean_status_class($application['status'] ?? ''); ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($application['applicant_name'] ?? ''); ?><br>
                                        <small><?php echo htmlspecialchars($application['applied_at'] ?? ''); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($application['job_title'] ?? ''); ?></td>
                                    <td>
                                        <span class="status <?php echo htmlspecialchars($application_status_class); ?>">
                                            <?php echo htmlspecialchars($application['status'] ?? ''); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No applications found.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <h2>Recent Admin Activity</h2>

                <?php if ($recent_logs && mysqli_num_rows($recent_logs) > 0) { ?>
                    <?php while ($log = mysqli_fetch_assoc($recent_logs)) { ?>
                        <div class="log-item">
                            <strong>
                                <?php echo htmlspecialchars($log['admin_name'] ?? 'Admin'); ?>

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
        </div>
    </main>
</div>

</body>
</html>