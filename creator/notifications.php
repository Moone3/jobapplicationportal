<?php
// creator/notifications.php
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

$message = "";
$error = "";

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
    mysqli_stmt_bind_param($notif_stmt, "ii", $notification_id, $creator_id);
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
        mysqli_stmt_bind_param($update_stmt, "ii", $notification_id, $creator_id);
        mysqli_stmt_execute($update_stmt);

        if (!empty($notif['link'])) {
            header("Location: " . $notif['link']);
            exit();
        }

        header("Location: notifications.php");
        exit();
    }

    header("Location: notifications.php");
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
    mysqli_stmt_bind_param($update_stmt, "ii", $notification_id, $creator_id);

    if (mysqli_stmt_execute($update_stmt)) {
        $message = "Notification marked as read.";
    } else {
        $error = "Failed to mark notification as read.";
    }
}

// Mark all as read
if (isset($_GET['mark_all_read'])) {
    $update_sql = "
        UPDATE notifications 
        SET is_read = 1 
        WHERE user_id = ?
    ";

    $update_stmt = mysqli_prepare($conn, $update_sql);
    mysqli_stmt_bind_param($update_stmt, "i", $creator_id);

    if (mysqli_stmt_execute($update_stmt)) {
        $message = "All notifications marked as read.";
    } else {
        $error = "Failed to mark all notifications as read.";
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
    mysqli_stmt_bind_param($delete_stmt, "ii", $notification_id, $creator_id);

    if (mysqli_stmt_execute($delete_stmt)) {
        $message = "Notification deleted successfully.";
    } else {
        $error = "Failed to delete notification.";
    }
}

// Filter
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : "all";

// Counts
$total_notifications = 0;
$unread_notifications = 0;
$read_notifications = 0;

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$creator_id'
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_notifications = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$creator_id' 
    AND is_read = 0
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $unread_notifications = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$creator_id' 
    AND is_read = 1
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $read_notifications = $row['total'];
}

// Notifications query
$sql = "
    SELECT *
    FROM notifications
    WHERE user_id = ?
";

$params = [$creator_id];
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

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Creator Notifications | Job Application System</title>
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

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 24px;
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

        .panel {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
        }

        .filter-box {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            background: #f8fbfc;
            border: 1px solid #e0eaed;
            border-radius: 16px;
            padding: 16px;
        }

        .filter-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        select {
            padding: 12px 14px;
            border: 1px solid #d9e3e6;
            border-radius: 12px;
            background: #ffffff;
            color: #243b3f;
            outline: none;
            font-size: 14px;
        }

        .notification-card {
            border: 1px solid #e0eaed;
            background: #fbfdfe;
            border-radius: 18px;
            padding: 20px;
            margin-bottom: 16px;
        }

        .notification-card.unread {
            background: #f4efff;
            border-color: #ddd0ff;
        }

        .notification-content {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 18px;
        }

        .notification-title {
            margin: 0 0 10px;
            font-size: 18px;
            color: #193b40;
        }

        .notification-message {
            margin: 0 0 12px;
            color: #4c6268;
            font-size: 14px;
            line-height: 1.6;
        }

        .notification-date {
            margin: 0;
            color: #7a8b91;
            font-size: 13px;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            margin-bottom: 10px;
        }

        .badge-unread {
            background: #efe7ff;
            color: #5b21b6;
        }

        .badge-read {
            background: #eef0f2;
            color: #5f6b70;
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
            border-radius: 12px;
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

        .btn-danger {
            background: #ffecec;
            color: #b42318;
            border: 1px solid #ffd1d1;
        }

        .alert {
            padding: 13px 15px;
            border-radius: 12px;
            margin-bottom: 18px;
            font-size: 14px;
        }

        .alert-success {
            background: #e9fbf3;
            color: #027a48;
            border: 1px solid #b7f0d4;
        }

        .alert-error {
            background: #ffecec;
            color: #b42318;
            border: 1px solid #ffd1d1;
        }

        .empty {
            color: #7a8b91;
            font-size: 14px;
            padding: 20px 0;
        }

        @media (max-width: 900px) {
            .cards {
                grid-template-columns: 1fr;
            }

            .notification-content {
                flex-direction: column;
            }

            .actions {
                justify-content: flex-start;
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

            .topbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
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
                <h1>Creator Notifications</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. View and manage your creator notifications.</p>
            </div>

            <div class="user-pill">Creator</div>
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
                   class="btn btn-primary"
                   onclick="return confirm('Mark all notifications as read?');">
                    Mark All as Read
                </a>
            </form>

            <?php if ($notifications && mysqli_num_rows($notifications) > 0) { ?>
                <?php while ($row = mysqli_fetch_assoc($notifications)) { ?>
                    <div class="notification-card <?php echo intval($row['is_read']) === 0 ? 'unread' : ''; ?>">
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
                                <a href="notifications.php?open=<?php echo $row['id']; ?>" class="btn btn-primary">
                                    Open
                                </a>

                                <?php if (intval($row['is_read']) === 0) { ?>
                                    <a href="notifications.php?mark_read=<?php echo $row['id']; ?>" class="btn btn-light">
                                        Mark as Read
                                    </a>
                                <?php } ?>

                                <a href="notifications.php?delete=<?php echo $row['id']; ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('Delete this notification?');">
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

</body>
</html>