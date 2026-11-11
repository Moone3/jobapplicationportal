<?php
// creator/manage_admins.php
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

function add_creator_log($conn, $creator_id, $action) {
    $safe_action = mysqli_real_escape_string($conn, $action);

    mysqli_query($conn, "
        INSERT INTO admin_logs (admin_id, action)
        VALUES ('$creator_id', '$safe_action')
    ");
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

// Add new admin
if (isset($_POST['add_admin'])) {
    $new_full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($new_full_name) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $check_sql = "SELECT id FROM users WHERE email = ? LIMIT 1";
        $check_stmt = mysqli_prepare($conn, $check_sql);

        if (!$check_stmt) {
            $error = "Database prepare failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($check_stmt, "s", $email);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);

            if ($check_result && mysqli_num_rows($check_result) > 0) {
                $error = "Email already exists.";
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                $insert_sql = "
                    INSERT INTO users (full_name, email, password, role, status) 
                    VALUES (?, ?, ?, 'admin', 'active')
                ";

                $insert_stmt = mysqli_prepare($conn, $insert_sql);

                if (!$insert_stmt) {
                    $error = "Database prepare failed: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($insert_stmt, "sss", $new_full_name, $email, $hashed_password);

                    if (mysqli_stmt_execute($insert_stmt)) {
                        $new_admin_id = mysqli_insert_id($conn);

                        add_creator_log($conn, $creator_id, "Created new admin account ID: $new_admin_id");

                        $success = "Admin account created successfully.";
                    } else {
                        $error = "Failed to create admin account: " . mysqli_error($conn);
                    }
                }
            }
        }
    }
}

// Promote existing user to admin
if (isset($_GET['promote'])) {
    $user_id = intval($_GET['promote']);

    if ($user_id === $creator_id) {
        $error = "You cannot change your own creator account.";
    } else {
        $check_sql = "
            SELECT id, role, full_name 
            FROM users 
            WHERE id = ?
            LIMIT 1
        ";

        $check_stmt = mysqli_prepare($conn, $check_sql);

        if (!$check_stmt) {
            $error = "Database prepare failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($check_stmt, "i", $user_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);

            if ($check_result && mysqli_num_rows($check_result) === 1) {
                $user = mysqli_fetch_assoc($check_result);

                if ($user['role'] === 'creator') {
                    $error = "Creator account is protected and cannot be changed.";
                } elseif ($user['role'] === 'admin') {
                    $error = "This user is already an admin.";
                } else {
                    $update_sql = "
                        UPDATE users
                        SET role = 'admin',
                            status = 'active'
                        WHERE id = ?
                        AND role != 'creator'
                    ";

                    $update_stmt = mysqli_prepare($conn, $update_sql);

                    if (!$update_stmt) {
                        $error = "Database prepare failed: " . mysqli_error($conn);
                    } else {
                        mysqli_stmt_bind_param($update_stmt, "i", $user_id);

                        if (mysqli_stmt_execute($update_stmt)) {
                            add_creator_log($conn, $creator_id, "Promoted user ID: $user_id to admin");

                            $success = "User promoted to admin successfully.";
                        } else {
                            $error = "Failed to promote user.";
                        }
                    }
                }
            } else {
                $error = "User not found.";
            }
        }
    }
}

// Activate admin
if (isset($_GET['activate'])) {
    $admin_id = intval($_GET['activate']);

    $update_sql = "
        UPDATE users 
        SET status = 'active' 
        WHERE id = ? 
        AND role = 'admin'
    ";

    $update_stmt = mysqli_prepare($conn, $update_sql);

    if (!$update_stmt) {
        $error = "Database prepare failed: " . mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($update_stmt, "i", $admin_id);

        if (mysqli_stmt_execute($update_stmt)) {
            add_creator_log($conn, $creator_id, "Activated admin ID: $admin_id");

            $success = "Admin account activated successfully.";
        } else {
            $error = "Failed to activate admin.";
        }
    }
}

// Ban admin
if (isset($_GET['ban'])) {
    $admin_id = intval($_GET['ban']);

    $update_sql = "
        UPDATE users 
        SET status = 'banned' 
        WHERE id = ? 
        AND role = 'admin'
    ";

    $update_stmt = mysqli_prepare($conn, $update_sql);

    if (!$update_stmt) {
        $error = "Database prepare failed: " . mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($update_stmt, "i", $admin_id);

        if (mysqli_stmt_execute($update_stmt)) {
            add_creator_log($conn, $creator_id, "Banned admin ID: $admin_id");

            $success = "Admin account banned successfully.";
        } else {
            $error = "Failed to ban admin.";
        }
    }
}

// Demote admin to applicant
if (isset($_GET['demote'])) {
    $admin_id = intval($_GET['demote']);

    $check_sql = "
        SELECT id, role 
        FROM users
        WHERE id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if (!$check_stmt) {
        $error = "Database prepare failed: " . mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($check_stmt, "i", $admin_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $admin = mysqli_fetch_assoc($check_result);

            if ($admin['role'] === 'creator') {
                $error = "Creator account is protected and cannot be demoted.";
            } elseif ($admin['role'] !== 'admin') {
                $error = "Only admin accounts can be demoted.";
            } else {
                $update_sql = "
                    UPDATE users 
                    SET role = 'applicant',
                        status = 'active'
                    WHERE id = ?
                    AND role = 'admin'
                ";

                $update_stmt = mysqli_prepare($conn, $update_sql);

                if (!$update_stmt) {
                    $error = "Database prepare failed: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($update_stmt, "i", $admin_id);

                    if (mysqli_stmt_execute($update_stmt)) {
                        $profile_check = mysqli_query($conn, "
                            SELECT id 
                            FROM applicant_profiles 
                            WHERE user_id = '$admin_id' 
                            LIMIT 1
                        ");

                        if ($profile_check && mysqli_num_rows($profile_check) == 0) {
                            mysqli_query($conn, "
                                INSERT INTO applicant_profiles (user_id) 
                                VALUES ('$admin_id')
                            ");
                        }

                        add_creator_log($conn, $creator_id, "Demoted admin ID: $admin_id to applicant");

                        $success = "Admin demoted to applicant successfully.";
                    } else {
                        $error = "Failed to demote admin.";
                    }
                }
            }
        } else {
            $error = "Admin account not found.";
        }
    }
}

// Delete admin account
if (isset($_GET['delete'])) {
    $admin_id = intval($_GET['delete']);

    $check_sql = "
        SELECT id, role
        FROM users
        WHERE id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if (!$check_stmt) {
        $error = "Database prepare failed: " . mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($check_stmt, "i", $admin_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $admin = mysqli_fetch_assoc($check_result);

            if ($admin['role'] === 'creator') {
                $error = "Creator account is protected and cannot be deleted.";
            } elseif ($admin['role'] !== 'admin') {
                $error = "Only admin accounts can be deleted here.";
            } else {
                $delete_sql = "
                    DELETE FROM users 
                    WHERE id = ? 
                    AND role = 'admin'
                ";

                $delete_stmt = mysqli_prepare($conn, $delete_sql);

                if (!$delete_stmt) {
                    $error = "Database prepare failed: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($delete_stmt, "i", $admin_id);

                    if (mysqli_stmt_execute($delete_stmt)) {
                        add_creator_log($conn, $creator_id, "Deleted admin account ID: $admin_id");

                        $success = "Admin account deleted successfully.";
                    } else {
                        $error = "Failed to delete admin account.";
                    }
                }
            }
        } else {
            $error = "Admin account not found.";
        }
    }
}

// Filters
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";
$role_filter = isset($_GET['role']) ? trim($_GET['role']) : "all";

// Admin list
$admins = mysqli_query($conn, "
    SELECT *
    FROM users
    WHERE role = 'admin'
    ORDER BY created_at DESC
");

// Users that can be promoted
$sql = "
    SELECT *
    FROM users
    WHERE role != 'creator'
";

$params = [];
$types = "";

if ($role_filter !== "all" && !empty($role_filter)) {
    $sql .= " AND role = ?";
    $params[] = $role_filter;
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

$user_stmt = mysqli_prepare($conn, $sql);

if (!$user_stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

if (!empty($params)) {
    mysqli_stmt_bind_param($user_stmt, $types, ...$params);
}

mysqli_stmt_execute($user_stmt);
$users = mysqli_stmt_get_result($user_stmt);

// Counts
$total_admins = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin'");
$active_admins = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND status = 'active'");
$banned_admins = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'admin' AND status = 'banned'");
$total_promotable_users = get_count($conn, "SELECT COUNT(*) AS total FROM users WHERE role IN ('applicant', 'recruiter')");

function status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "active") {
        return "active";
    }

    if ($status === "banned") {
        return "banned";
    }

    return "neutral";
}

function role_class($role) {
    $role = strtolower(trim($role));

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
    <title>Manage Admins | Job Application System</title>
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
            grid-template-columns: repeat(4, 1fr);
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

        .content-grid {
            display: grid;
            grid-template-columns: 0.85fr 1.15fr;
            gap: 18px;
            margin-bottom: 24px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e0eaed;
            border-radius: 22px;
            padding: 24px;
            box-shadow: 0 8px 24px rgba(30, 60, 70, 0.05);
            margin-bottom: 24px;
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
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 700;
            color: #344b50;
        }

        input,
        select {
            width: 100%;
            padding: 13px 14px;
            border: 1px solid #d9e3e6;
            border-radius: 12px;
            outline: none;
            font-size: 14px;
            background: #fbfdfe;
            color: #243b3f;
        }

        input:focus,
        select:focus {
            border-color: #6d28d9;
            box-shadow: 0 0 0 3px rgba(109, 40, 217, 0.12);
            background: #ffffff;
        }

        .filter-box {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
            gap: 12px;
            align-items: center;
            margin-bottom: 18px;
        }

        .btn {
            padding: 11px 15px;
            border-radius: 12px;
            text-decoration: none;
            border: none;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            display: inline-block;
            white-space: nowrap;
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

        .btn-warning {
            background: #fff7e6;
            color: #b76e00;
            border: 1px solid #ffe0a3;
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

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 14px 12px;
            color: #6f8187;
            font-size: 13px;
            border-bottom: 1px solid #e5eef0;
        }

        td {
            padding: 15px 12px;
            font-size: 14px;
            color: #344b50;
            border-bottom: 1px solid #eef4f5;
            vertical-align: middle;
        }

        .admin-name {
            font-weight: 800;
            color: #193b40;
        }

        .muted {
            color: #7a8b91;
            font-size: 13px;
            margin-top: 5px;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .status.active {
            background: #e9fbf3;
            color: #027a48;
        }

        .status.banned {
            background: #ffecec;
            color: #b42318;
        }

        .status.neutral {
            background: #eef0f2;
            color: #5f6b70;
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

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .empty {
            color: #7a8b91;
            font-size: 14px;
            padding: 20px 0;
        }

        @media (max-width: 1150px) {
            .content-grid {
                grid-template-columns: 1fr;
            }

            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-box {
                grid-template-columns: 1fr 1fr;
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

            .cards,
            .filter-box {
                grid-template-columns: 1fr;
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
                <h1>Manage Admins</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Create, promote, demote, ban, or delete admin accounts.</p>
            </div>

            <div class="user-pill">Creator</div>
        </div>

        <?php if (!empty($success)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Admins</p>
                <p class="card-number"><?php echo $total_admins; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Active Admins</p>
                <p class="card-number"><?php echo $active_admins; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Banned Admins</p>
                <p class="card-number"><?php echo $banned_admins; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Promotable Users</p>
                <p class="card-number"><?php echo $total_promotable_users; ?></p>
            </div>
        </section>

        <section class="content-grid">
            <div class="panel">
                <h2>Add New Admin</h2>
                <p class="panel-subtitle">Create a new admin account that can approve jobs and manage users.</p>

                <form method="POST">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" placeholder="Enter admin full name" required>
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" placeholder="Enter admin email" required>
                    </div>

                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" placeholder="Enter password" required>
                    </div>

                    <div class="form-group">
                        <label>Confirm Password</label>
                        <input type="password" name="confirm_password" placeholder="Confirm password" required>
                    </div>

                    <button type="submit" name="add_admin" class="btn btn-primary">Create Admin</button>
                </form>
            </div>

            <div class="panel">
                <h2>Admin Accounts</h2>
                <p class="panel-subtitle">Manage current admin accounts.</p>

                <?php if ($admins && mysqli_num_rows($admins) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Admin</th>
                                <th>Status</th>
                                <th>Created At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($admin = mysqli_fetch_assoc($admins)) { ?>
                                <?php $admin_status_class = status_class($admin['status'] ?? 'active'); ?>

                                <tr>
                                    <td>
                                        <div class="admin-name">
                                            <?php echo htmlspecialchars($admin['full_name']); ?>
                                        </div>
                                        <div class="muted">
                                            <?php echo htmlspecialchars($admin['email']); ?>
                                        </div>
                                    </td>

                                    <td>
                                        <span class="badge status <?php echo htmlspecialchars($admin_status_class); ?>">
                                            <?php echo htmlspecialchars($admin['status']); ?>
                                        </span>
                                    </td>

                                    <td><?php echo htmlspecialchars($admin['created_at']); ?></td>

                                    <td>
                                        <div class="actions">
                                            <?php if ($admin['status'] === 'active') { ?>
                                                <a href="manage_admins.php?ban=<?php echo $admin['id']; ?>"
                                                   class="btn btn-warning"
                                                   onclick="return confirm('Ban this admin account?');">
                                                    Ban
                                                </a>
                                            <?php } else { ?>
                                                <a href="manage_admins.php?activate=<?php echo $admin['id']; ?>"
                                                   class="btn btn-primary"
                                                   onclick="return confirm('Activate this admin account?');">
                                                    Activate
                                                </a>
                                            <?php } ?>

                                            <a href="manage_admins.php?demote=<?php echo $admin['id']; ?>"
                                               class="btn btn-light"
                                               onclick="return confirm('Demote this admin to applicant?');">
                                                Demote
                                            </a>

                                            <a href="manage_admins.php?delete=<?php echo $admin['id']; ?>"
                                               class="btn btn-danger"
                                               onclick="return confirm('Delete this admin account permanently?');">
                                                Delete
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No admin accounts found.</p>
                <?php } ?>
            </div>
        </section>

        <div class="panel">
            <h2>Promote Existing User to Admin</h2>
            <p class="panel-subtitle">Search applicant or recruiter accounts and promote them to admin.</p>

            <form method="GET" class="filter-box">
                <input 
                    type="text" 
                    name="keyword" 
                    placeholder="Search user by name, email, role, or status"
                    value="<?php echo htmlspecialchars($keyword); ?>"
                >

                <select name="role">
                    <option value="all" <?php if ($role_filter === 'all') echo 'selected'; ?>>All Roles</option>
                    <option value="applicant" <?php if ($role_filter === 'applicant') echo 'selected'; ?>>Applicant</option>
                    <option value="recruiter" <?php if ($role_filter === 'recruiter') echo 'selected'; ?>>Recruiter</option>
                    <option value="admin" <?php if ($role_filter === 'admin') echo 'selected'; ?>>Admin</option>
                </select>

                <button type="submit" class="btn btn-primary">Filter</button>

                <a href="manage_admins.php" class="btn btn-light">Reset</a>
            </form>

            <?php if ($users && mysqli_num_rows($users) > 0) { ?>
                <table>
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php while ($user = mysqli_fetch_assoc($users)) { ?>
                            <?php
                                $role_css = role_class($user['role'] ?? '');
                                $status_css = status_class($user['status'] ?? 'active');
                            ?>

                            <tr>
                                <td>
                                    <div class="admin-name">
                                        <?php echo htmlspecialchars($user['full_name'] ?? ''); ?>
                                    </div>
                                    <div class="muted">
                                        <?php echo htmlspecialchars($user['email'] ?? ''); ?>
                                    </div>
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

                                <td><?php echo htmlspecialchars($user['created_at'] ?? ''); ?></td>

                                <td>
                                    <?php if ($user['role'] !== 'admin') { ?>
                                        <a href="manage_admins.php?promote=<?php echo $user['id']; ?>"
                                           class="btn btn-primary"
                                           onclick="return confirm('Promote this user to admin?');">
                                            Promote
                                        </a>
                                    <?php } else { ?>
                                        <span class="btn btn-light">Already Admin</span>
                                    <?php } ?>
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

</body>
</html>