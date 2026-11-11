<?php
// creator/profile.php
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

$error = "";
$success = "";

// Get creator data
$user_sql = "
    SELECT *
    FROM users
    WHERE id = ?
    AND role = 'creator'
    LIMIT 1
";

$user_stmt = mysqli_prepare($conn, $user_sql);

if (!$user_stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($user_stmt, "i", $creator_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);

if (!$user_result || mysqli_num_rows($user_result) !== 1) {
    header("Location: ../auth/logout.php");
    exit();
}

$creator = mysqli_fetch_assoc($user_result);

if (!empty($creator['full_name'])) {
    $full_name = $creator['full_name'];
    $_SESSION['full_name'] = $full_name;
}

// Creator profile picture
function get_profile_picture_path($picture_name) {
    if (empty($picture_name)) {
        return "";
    }

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
            return $picture['web'];
        }
    }

    return "";
}

$creator_profile_picture = get_profile_picture_path($creator['profile_picture'] ?? "");

// Creator initials fallback
function get_initials($name) {
    $name_parts = preg_split('/\s+/', trim($name));
    $initials = "";

    if (!empty($name_parts[0])) {
        $initials .= strtoupper(substr($name_parts[0], 0, 1));
    }

    if (!empty($name_parts[1])) {
        $initials .= strtoupper(substr($name_parts[1], 0, 1));
    }

    if ($initials === "") {
        $initials = "C";
    }

    return $initials;
}

$sidebar_initial = get_initials($full_name);

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

function add_creator_log($conn, $creator_id, $action) {
    $safe_action = mysqli_real_escape_string($conn, $action);

    mysqli_query($conn, "
        INSERT INTO admin_logs (admin_id, action)
        VALUES ('$creator_id', '$safe_action')
    ");
}

// Update profile
if (isset($_POST['update_profile'])) {
    $new_full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);

    if (empty($new_full_name) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $check_sql = "
            SELECT id 
            FROM users 
            WHERE email = ? 
            AND id != ? 
            LIMIT 1
        ";

        $check_stmt = mysqli_prepare($conn, $check_sql);

        if (!$check_stmt) {
            $error = "Database prepare failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($check_stmt, "si", $email, $creator_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);

            if ($check_result && mysqli_num_rows($check_result) > 0) {
                $error = "Email address already exists.";
            } else {
                $profile_picture_name = $creator['profile_picture'] ?? "";

                if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
                    if ($_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                        $upload_dir = __DIR__ . "/../assets/uploads/profiles/";

                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }

                        $file_tmp = $_FILES['profile_picture']['tmp_name'];
                        $file_name = $_FILES['profile_picture']['name'];
                        $file_size = $_FILES['profile_picture']['size'];
                        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                        $allowed_ext = ["jpg", "jpeg", "png", "gif", "webp"];
                        $max_size = 5 * 1024 * 1024;

                        if (!in_array($file_ext, $allowed_ext)) {
                            $error = "Only JPG, JPEG, PNG, GIF, and WEBP files are allowed.";
                        } elseif ($file_size > $max_size) {
                            $error = "Profile picture must not exceed 5MB.";
                        } else {
                            $new_file_name = "creator_" . $creator_id . "_" . time() . "." . $file_ext;
                            $upload_path = $upload_dir . $new_file_name;

                            if (move_uploaded_file($file_tmp, $upload_path)) {
                                $profile_picture_name = $new_file_name;
                            } else {
                                $error = "Failed to upload profile picture.";
                            }
                        }
                    } else {
                        $error = "Profile picture upload failed.";
                    }
                }

                if (empty($error)) {
                    $update_sql = "
                        UPDATE users 
                        SET full_name = ?,
                            email = ?,
                            profile_picture = ?
                        WHERE id = ?
                        AND role = 'creator'
                    ";

                    $update_stmt = mysqli_prepare($conn, $update_sql);

                    if (!$update_stmt) {
                        $error = "Database prepare failed: " . mysqli_error($conn);
                    } else {
                        mysqli_stmt_bind_param($update_stmt, "sssi", $new_full_name, $email, $profile_picture_name, $creator_id);

                        if (mysqli_stmt_execute($update_stmt)) {
                            $_SESSION['full_name'] = $new_full_name;
                            $_SESSION['email'] = $email;

                            add_creator_log($conn, $creator_id, "Updated creator profile");

                            $success = "Profile updated successfully.";

                            $creator['full_name'] = $new_full_name;
                            $creator['email'] = $email;
                            $creator['profile_picture'] = $profile_picture_name;

                            $full_name = $new_full_name;
                            $sidebar_initial = get_initials($full_name);
                            $creator_profile_picture = get_profile_picture_path($profile_picture_name);
                        } else {
                            $error = "Failed to update profile.";
                        }
                    }
                }
            }
        }
    }
}

// Change password
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "New password and confirm password do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {
        if (!password_verify($current_password, $creator['password'])) {
            $error = "Current password is incorrect.";
        } else {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $update_pass_sql = "
                UPDATE users 
                SET password = ? 
                WHERE id = ?
                AND role = 'creator'
            ";

            $update_pass_stmt = mysqli_prepare($conn, $update_pass_sql);

            if (!$update_pass_stmt) {
                $error = "Database prepare failed: " . mysqli_error($conn);
            } else {
                mysqli_stmt_bind_param($update_pass_stmt, "si", $hashed_password, $creator_id);

                if (mysqli_stmt_execute($update_pass_stmt)) {
                    add_creator_log($conn, $creator_id, "Changed creator password");

                    $success = "Password changed successfully.";
                    $creator['password'] = $hashed_password;
                } else {
                    $error = "Failed to change password.";
                }
            }
        }
    }
}

// Refresh creator data
$user_stmt = mysqli_prepare($conn, $user_sql);
mysqli_stmt_bind_param($user_stmt, "i", $creator_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);

if ($user_result && mysqli_num_rows($user_result) === 1) {
    $creator = mysqli_fetch_assoc($user_result);

    if (!empty($creator['full_name'])) {
        $full_name = $creator['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    $sidebar_initial = get_initials($full_name);
    $creator_profile_picture = get_profile_picture_path($creator['profile_picture'] ?? "");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Creator Profile | Job Application System</title>
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

        .grid {
            display: grid;
            grid-template-columns: 1fr 0.85fr;
            gap: 18px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 20px;
            color: #193b40;
        }

        .panel-subtitle {
            margin: 0 0 22px;
            color: #7a8b91;
            font-size: 14px;
            line-height: 1.5;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 800;
            color: #344b50;
        }

        input {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d9e3e6;
            border-radius: 12px;
            outline: none;
            font-size: 14px;
            background: #fbfdfe;
            color: #243b3f;
        }

        input[type="file"] {
            background: #ffffff;
        }

        input:focus {
            border-color: #6d28d9;
            box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.12);
            background: #ffffff;
        }

        .hint {
            margin-top: 7px;
            color: #7a8b91;
            font-size: 12px;
            line-height: 1.5;
        }

        .btn {
            padding: 12px 18px;
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

        .profile-card {
            text-align: center;
            margin-bottom: 18px;
        }

        .avatar-placeholder,
        .avatar-img {
            width: 120px;
            height: 120px;
            border-radius: 34px;
            margin: 0 auto 14px;
            box-shadow: 0 8px 20px rgba(30, 60, 70, 0.12);
        }

        .avatar-placeholder {
            background: #6d28d9;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: 800;
        }

        .avatar-img {
            object-fit: cover;
            background: #efe7ff;
            border: 3px solid #ffffff;
        }

        .profile-card h3 {
            margin: 0;
            color: #193b40;
            font-size: 20px;
        }

        .profile-card p {
            margin: 6px 0 0;
            color: #7a8b91;
            font-size: 14px;
            line-height: 1.6;
        }

        .info-box {
            background: #f8fbfc;
            border: 1px solid #e0eaed;
            border-radius: 16px;
            padding: 15px;
            margin-top: 18px;
            text-align: left;
        }

        .info-box strong {
            display: block;
            margin-bottom: 8px;
            color: #344b50;
        }

        .info-box p {
            margin: 0;
            color: #7a8b91;
            font-size: 14px;
            line-height: 1.6;
        }

        @media (max-width: 950px) {
            .grid {
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
                <h1>Creator Profile</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Update your creator account information, photo, and password.</p>
            </div>

            <div class="user-pill">Creator</div>
        </div>

        <?php if (!empty($success)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <div class="grid">
            <div class="panel">
                <h2>Account Information</h2>
                <p class="panel-subtitle">Update your creator name, email address, and profile picture.</p>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input 
                            type="text" 
                            name="full_name" 
                            value="<?php echo htmlspecialchars($creator['full_name'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input 
                            type="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($creator['email'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Profile Picture</label>
                        <input 
                            type="file" 
                            name="profile_picture" 
                            accept="image/jpeg,image/png,image/gif,image/webp"
                        >
                        <div class="hint">Allowed: JPG, JPEG, PNG, GIF, WEBP. Maximum size: 5MB.</div>
                    </div>

                    <button type="submit" name="update_profile" class="btn btn-primary">
                        Update Profile
                    </button>
                </form>
            </div>

            <div>
                <div class="panel profile-card">
                    <?php if (!empty($creator_profile_picture)) { ?>
                        <img src="<?php echo htmlspecialchars($creator_profile_picture); ?>" class="avatar-img" alt="Creator Profile">
                    <?php } else { ?>
                        <div class="avatar-placeholder">
                            <?php echo htmlspecialchars($sidebar_initial); ?>
                        </div>
                    <?php } ?>

                    <h3><?php echo htmlspecialchars($creator['full_name'] ?? 'Creator'); ?></h3>
                    <p><?php echo htmlspecialchars($creator['email'] ?? ''); ?></p>

                    <div class="info-box">
                        <strong>Role</strong>
                        <p>Creator</p>
                    </div>

                    <div class="info-box">
                        <strong>Status</strong>
                        <p><?php echo htmlspecialchars($creator['status'] ?? 'active'); ?></p>
                    </div>

                    <div class="info-box">
                        <strong>Registered</strong>
                        <p><?php echo htmlspecialchars($creator['created_at'] ?? ''); ?></p>
                    </div>
                </div>

                <div class="panel">
                    <h2>Change Password</h2>
                    <p class="panel-subtitle">Update your account password.</p>

                    <form method="POST">
                        <div class="form-group">
                            <label>Current Password</label>
                            <input type="password" name="current_password" required>
                        </div>

                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required>
                        </div>

                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>

                        <button type="submit" name="change_password" class="btn btn-primary">
                            Change Password
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

</body>
</html>