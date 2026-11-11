<?php
// admin/profile.php
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

// Get admin data
$user_sql = "
    SELECT *
    FROM users
    WHERE id = ?
    LIMIT 1
";

$user_stmt = mysqli_prepare($conn, $user_sql);
mysqli_stmt_bind_param($user_stmt, "i", $admin_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);

if (!$user_result || mysqli_num_rows($user_result) !== 1) {
    header("Location: ../auth/logout.php");
    exit();
}

$user = mysqli_fetch_assoc($user_result);

if (!empty($user['full_name'])) {
    $full_name = $user['full_name'];
    $_SESSION['full_name'] = $full_name;
}

// Admin profile picture for sidebar and profile card
$admin_profile_picture = "";

if (!empty($user['profile_picture'])) {
    $picture_name = $user['profile_picture'];

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

// Update profile
if (isset($_POST['update_profile'])) {
    $new_full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);

    if (empty($new_full_name) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address.";
    } else {
        $check_sql = "
            SELECT id 
            FROM users 
            WHERE email = ? 
            AND id != ?
            LIMIT 1
        ";

        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "si", $email, $admin_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $error = "Email is already used by another account.";
        } else {
            $profile_picture_name = $user['profile_picture'] ?? "";

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
                        $new_file_name = "admin_" . $admin_id . "_" . time() . "." . $file_ext;
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
                ";

                $update_stmt = mysqli_prepare($conn, $update_sql);
                mysqli_stmt_bind_param($update_stmt, "sssi", $new_full_name, $email, $profile_picture_name, $admin_id);

                if (mysqli_stmt_execute($update_stmt)) {
                    $_SESSION['full_name'] = $new_full_name;
                    $full_name = $new_full_name;

                    mysqli_query($conn, "
                        INSERT INTO admin_logs (admin_id, action) 
                        VALUES ('$admin_id', 'Updated admin profile')
                    ");

                    $message = "Profile updated successfully.";

                    $user['full_name'] = $new_full_name;
                    $user['email'] = $email;
                    $user['profile_picture'] = $profile_picture_name;
                } else {
                    $error = "Failed to update profile.";
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
        $error = "Password must be at least 6 characters.";
    } else {
        if (password_verify($current_password, $user['password'])) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            $update_sql = "
                UPDATE users
                SET password = ?
                WHERE id = ?
            ";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $admin_id);

            if (mysqli_stmt_execute($update_stmt)) {
                mysqli_query($conn, "
                    INSERT INTO admin_logs (admin_id, action) 
                    VALUES ('$admin_id', 'Changed admin password')
                ");

                $message = "Password changed successfully.";
            } else {
                $error = "Failed to change password.";
            }
        } else {
            $error = "Current password is incorrect.";
        }
    }
}

// Refresh user data
$user_stmt = mysqli_prepare($conn, $user_sql);
mysqli_stmt_bind_param($user_stmt, "i", $admin_id);
mysqli_stmt_execute($user_stmt);
$user_result = mysqli_stmt_get_result($user_stmt);
$user = mysqli_fetch_assoc($user_result);

if (!empty($user['full_name'])) {
    $full_name = $user['full_name'];
}

// Refresh profile picture after update
$admin_profile_picture = "";

if (!empty($user['profile_picture'])) {
    $picture_name = $user['profile_picture'];

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

// Refresh initials after update
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

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Profile | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --panel: rgba(15, 23, 42, 0.72);
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

        .brand-icon,
        .avatar {
            background: linear-gradient(135deg, var(--cyan-2), var(--blue));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            letter-spacing: 0.5px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

        .brand-icon { font-size: 15px; }

        .brand-img,
        .avatar-img {
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
        .panel::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
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

        .profile-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            animation: fadeUp 0.55s ease both;
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .panel:hover {
            transform: translateY(-5px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .panel h2 {
            margin: 0 0 18px;
            color: var(--text);
            font-size: 22px;
            letter-spacing: -0.3px;
        }

        .profile-card {
            grid-column: span 2;
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .avatar,
        .avatar-img {
            width: 92px;
            height: 92px;
            border-radius: 26px;
            flex-shrink: 0;
        }

        .avatar {
            font-size: 34px;
        }

        .profile-info h2 {
            margin: 0 0 8px;
            color: var(--text);
            font-size: 25px;
        }

        .profile-info p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            color: var(--soft);
            font-weight: 900;
            font-size: 14px;
        }

        input {
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

        input[type="file"] {
            padding: 11px 14px;
        }

        input::file-selector-button {
            border: 0;
            border-radius: 11px;
            padding: 9px 12px;
            margin-right: 12px;
            background: rgba(18, 215, 255, 0.13);
            color: var(--text);
            font-weight: 900;
            cursor: pointer;
        }

        input:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .hint {
            margin-top: 7px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
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

        @media (max-width: 900px) {
            .profile-grid {
                grid-template-columns: 1fr;
            }

            .profile-card {
                grid-column: span 1;
            }
        }

        @media (max-width: 750px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
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
                padding: 18px;
            }

            .topbar,
            .profile-card {
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
                <h1>Admin Profile</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name ?? 'Admin'); ?>. Manage your account details, profile picture, and password.</p>
            </div>

            <div class="user-pill">Admin</div>
        </div>

        <?php if (!empty($message)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <div class="profile-grid">
            <div class="panel profile-card">
                <?php if (!empty($admin_profile_picture)) { ?>
                    <img src="<?php echo htmlspecialchars($admin_profile_picture); ?>" class="avatar-img" alt="Admin Profile">
                <?php } else { ?>
                    <div class="avatar">
                        <?php echo htmlspecialchars($sidebar_initial); ?>
                    </div>
                <?php } ?>

                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($user['full_name'] ?? 'Admin'); ?></h2>
                    <p>
                        Email: <?php echo htmlspecialchars($user['email'] ?? ''); ?><br>
                        Role: <?php echo htmlspecialchars($user['role'] ?? 'admin'); ?><br>
                        Status: <?php echo htmlspecialchars($user['status'] ?? 'active'); ?><br>
                        Registered: <?php echo htmlspecialchars($user['created_at'] ?? ''); ?>
                    </p>
                </div>
            </div>

            <div class="panel">
                <h2>Edit Profile</h2>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input 
                            type="text" 
                            name="full_name" 
                            value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Email</label>
                        <input 
                            type="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($user['email'] ?? ''); ?>" 
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
                        Save Profile
                    </button>
                </form>
            </div>

            <div class="panel">
                <h2>Change Password</h2>

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
    </main>
</div>

</body>
</html>