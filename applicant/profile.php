<?php
// applicant/profile.php
include "../includes/auth_check.php";
include "../config/database.php";

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'applicant') {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'creator') {
        header("Location: ../creator/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'recruiter') {
        header("Location: ../recruiter/dashboard.php");
        exit();
    }

    header("Location: ../auth/login.php");
    exit();
}

$applicant_id = intval($_SESSION['user_id']);
$full_name = $_SESSION['full_name'] ?? 'Applicant';
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

$error = "";
$success = "";

// Notification badge count
$unread_notifications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$applicant_id'
    AND is_read = 0
");

// Message badge count
$unread_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM messages 
    WHERE receiver_id = '$applicant_id'
    AND is_read = 0
");

// Create applicant profile row if missing
$profile_check = mysqli_query($conn, "
    SELECT id 
    FROM applicant_profiles 
    WHERE user_id = '$applicant_id' 
    LIMIT 1
");

if ($profile_check && mysqli_num_rows($profile_check) == 0) {
    mysqli_query($conn, "
        INSERT INTO applicant_profiles (user_id, profile_views) 
        VALUES ('$applicant_id', 0)
    ");
}

// Get applicant profile
$sql = "
    SELECT 
        users.full_name,
        users.email,
        users.profile_picture,
        users.created_at,

        applicant_profiles.phone,
        applicant_profiles.address,
        applicant_profiles.skills,
        applicant_profiles.education,
        applicant_profiles.experience,
        applicant_profiles.resume_file,
        applicant_profiles.portfolio_link,
        applicant_profiles.profile_views
    FROM users
    LEFT JOIN applicant_profiles ON users.id = applicant_profiles.user_id
    WHERE users.id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $applicant_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$profile = mysqli_fetch_assoc($result);

if (!$profile) {
    header("Location: dashboard.php");
    exit();
}

if (!empty($profile['full_name'])) {
    $full_name = $profile['full_name'];
    $_SESSION['full_name'] = $full_name;
}

// Update profile
if (isset($_POST['update_profile'])) {
    $full_name_input = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $address = trim($_POST['address']);
    $skills = trim($_POST['skills']);
    $education = trim($_POST['education']);
    $experience = trim($_POST['experience']);
    $portfolio_link = trim($_POST['portfolio_link']);

    if (empty($full_name_input) || empty($email)) {
        $error = "Full name and email are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (!empty($portfolio_link) && !filter_var($portfolio_link, FILTER_VALIDATE_URL)) {
        $error = "Please enter a valid portfolio URL.";
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
            mysqli_stmt_bind_param($check_stmt, "si", $email, $applicant_id);
            mysqli_stmt_execute($check_stmt);
            $check_result = mysqli_stmt_get_result($check_stmt);

            if ($check_result && mysqli_num_rows($check_result) > 0) {
                $error = "Email address already exists.";
            } else {
                $profile_picture = $profile['profile_picture'];
                $resume_file = $profile['resume_file'];

                // Upload profile picture
                if (!empty($_FILES['profile_picture']['name'])) {
                    $allowed_images = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    $image_name = $_FILES['profile_picture']['name'];
                    $image_tmp = $_FILES['profile_picture']['tmp_name'];
                    $image_size = $_FILES['profile_picture']['size'];
                    $image_ext = strtolower(pathinfo($image_name, PATHINFO_EXTENSION));

                    if (!in_array($image_ext, $allowed_images)) {
                        $error = "Profile picture must be JPG, JPEG, PNG, GIF, or WEBP.";
                    } elseif ($image_size > 5 * 1024 * 1024) {
                        $error = "Profile picture must not exceed 5MB.";
                    } else {
                        $upload_dir = __DIR__ . "/../assets/uploads/profiles/";

                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }

                        $safe_image_name = preg_replace("/[^a-zA-Z0-9._-]/", "_", $image_name);
                        $profile_picture = time() . "_" . $applicant_id . "_" . $safe_image_name;
                        $image_path = $upload_dir . $profile_picture;

                        if (!move_uploaded_file($image_tmp, $image_path)) {
                            $error = "Failed to upload profile picture. Please check the profiles folder.";
                        }
                    }
                }

                // Upload resume
                if (empty($error) && !empty($_FILES['resume_file']['name'])) {
                    $allowed_resume = ['pdf', 'doc', 'docx'];
                    $resume_name = $_FILES['resume_file']['name'];
                    $resume_tmp = $_FILES['resume_file']['tmp_name'];
                    $resume_size = $_FILES['resume_file']['size'];
                    $resume_ext = strtolower(pathinfo($resume_name, PATHINFO_EXTENSION));

                    if (!in_array($resume_ext, $allowed_resume)) {
                        $error = "Resume must be PDF, DOC, or DOCX only.";
                    } elseif ($resume_size > 5 * 1024 * 1024) {
                        $error = "Resume must not exceed 5MB.";
                    } else {
                        $upload_dir = __DIR__ . "/../assets/uploads/resumes/";

                        if (!is_dir($upload_dir)) {
                            mkdir($upload_dir, 0777, true);
                        }

                        $safe_resume_name = preg_replace("/[^a-zA-Z0-9._-]/", "_", $resume_name);
                        $resume_file = time() . "_" . $applicant_id . "_" . $safe_resume_name;
                        $resume_path = $upload_dir . $resume_file;

                        if (!move_uploaded_file($resume_tmp, $resume_path)) {
                            $error = "Failed to upload resume. Please check the resumes folder.";
                        }
                    }
                }

                if (empty($error)) {
                    $update_user_sql = "
                        UPDATE users 
                        SET full_name = ?, 
                            email = ?, 
                            profile_picture = ?
                        WHERE id = ?
                    ";

                    $update_user_stmt = mysqli_prepare($conn, $update_user_sql);

                    if (!$update_user_stmt) {
                        $error = "Database prepare failed: " . mysqli_error($conn);
                    } else {
                        mysqli_stmt_bind_param(
                            $update_user_stmt,
                            "sssi",
                            $full_name_input,
                            $email,
                            $profile_picture,
                            $applicant_id
                        );

                        $update_profile_sql = "
                            UPDATE applicant_profiles
                            SET phone = ?,
                                address = ?,
                                skills = ?,
                                education = ?,
                                experience = ?,
                                resume_file = ?,
                                portfolio_link = ?
                            WHERE user_id = ?
                        ";

                        $update_profile_stmt = mysqli_prepare($conn, $update_profile_sql);

                        if (!$update_profile_stmt) {
                            $error = "Database prepare failed: " . mysqli_error($conn);
                        } else {
                            mysqli_stmt_bind_param(
                                $update_profile_stmt,
                                "sssssssi",
                                $phone,
                                $address,
                                $skills,
                                $education,
                                $experience,
                                $resume_file,
                                $portfolio_link,
                                $applicant_id
                            );

                            if (mysqli_stmt_execute($update_user_stmt) && mysqli_stmt_execute($update_profile_stmt)) {
                                $_SESSION['full_name'] = $full_name_input;
                                $_SESSION['email'] = $email;

                                $success = "Profile updated successfully.";

                                mysqli_stmt_execute($stmt);
                                $result = mysqli_stmt_get_result($stmt);
                                $profile = mysqli_fetch_assoc($result);

                                $full_name = $full_name_input;
                            } else {
                                $error = "Failed to update profile.";
                            }
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
        $error = "New passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters.";
    } else {
        $pass_sql = "
            SELECT password 
            FROM users 
            WHERE id = ? 
            LIMIT 1
        ";

        $pass_stmt = mysqli_prepare($conn, $pass_sql);

        if (!$pass_stmt) {
            $error = "Database prepare failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($pass_stmt, "i", $applicant_id);
            mysqli_stmt_execute($pass_stmt);
            $pass_result = mysqli_stmt_get_result($pass_stmt);
            $user_pass = mysqli_fetch_assoc($pass_result);

            if (!$user_pass || !password_verify($current_password, $user_pass['password'])) {
                $error = "Current password is incorrect.";
            } else {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

                $update_pass_sql = "
                    UPDATE users 
                    SET password = ? 
                    WHERE id = ?
                ";

                $update_pass_stmt = mysqli_prepare($conn, $update_pass_sql);

                if (!$update_pass_stmt) {
                    $error = "Database prepare failed: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($update_pass_stmt, "si", $hashed_password, $applicant_id);

                    if (mysqli_stmt_execute($update_pass_stmt)) {
                        $success = "Password changed successfully.";
                    } else {
                        $error = "Failed to change password.";
                    }
                }
            }
        }
    }
}

// Display profile picture
$applicant_profile_picture = "";

if (!empty($profile['profile_picture'])) {
    $picture_file = __DIR__ . "/../assets/uploads/profiles/" . $profile['profile_picture'];

    if (file_exists($picture_file)) {
        $applicant_profile_picture = "../assets/uploads/profiles/" . $profile['profile_picture'];
    }
}

// Initials fallback
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

$profile_initial = strtoupper(substr($full_name, 0, 1));

if ($profile_initial === "") {
    $profile_initial = "A";
}

$resume_web_path = "";

if (!empty($profile['resume_file'])) {
    $resume_file_path = __DIR__ . "/../assets/uploads/resumes/" . $profile['resume_file'];

    if (file_exists($resume_file_path)) {
        $resume_web_path = "../assets/uploads/resumes/" . $profile['resume_file'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Applicant Profile | Job Application System</title>
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
            --line-soft: rgba(255, 255, 255, 0.10);
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

        a { color: inherit; }

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

        .brand-icon,
        .preview-placeholder {
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
        .preview-img {
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
        .panel,
        .info-box {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.075);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .topbar::before,
        .panel::before,
        .info-box::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .panel > *,
        .info-box > * {
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

        .grid {
            display: grid;
            grid-template-columns: 1fr 0.75fr;
            gap: 18px;
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            animation: fadeUp 0.58s ease both;
            margin-bottom: 18px;
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 22px;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .panel-subtitle {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group { margin-bottom: 16px; }

        .form-group.full { grid-column: span 2; }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 900;
            color: #eef5ff;
        }

        input,
        textarea {
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

        input::placeholder,
        textarea::placeholder { color: rgba(220, 231, 255, 0.58); }

        textarea {
            min-height: 110px;
            resize: vertical;
            line-height: 1.6;
        }

        input[type="file"] {
            background: rgba(255, 255, 255, 0.07);
            cursor: pointer;
        }

        input:focus,
        textarea:focus {
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

        .form-actions {
            margin-top: 6px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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

        .preview-card { text-align: center; }

        .preview-img,
        .preview-placeholder {
            width: 128px;
            height: 128px;
            border-radius: 36px;
            margin: 0 auto 16px;
        }

        .preview-placeholder {
            font-size: 42px;
            font-weight: 950;
        }

        .preview-card h3 {
            margin: 0;
            color: var(--text);
            font-size: 22px;
            letter-spacing: -0.3px;
        }

        .preview-card p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        .info-box {
            border-radius: 17px;
            padding: 15px;
            margin-top: 14px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .info-box:hover,
        .panel:hover {
            border-color: rgba(18, 215, 255, 0.30);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.44), 0 0 0 1px rgba(18, 215, 255, 0.06);
        }

        .info-box:hover { transform: translateY(-3px); }

        .info-box strong {
            display: block;
            margin-bottom: 8px;
            color: #eef5ff;
            font-size: 13px;
            font-weight: 900;
        }

        .info-box p,
        .info-box a {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
            word-break: break-word;
        }

        .info-box a {
            color: #73e9ff;
            font-weight: 900;
            text-decoration: none;
        }

        .info-box a:hover { text-decoration: underline; }

        @media (max-width: 1050px) {
            .grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 850px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .form-grid { grid-template-columns: 1fr; }
            .form-group.full { grid-column: span 1; }
        }

        @media (max-width: 750px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid var(--line);
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
            <?php if (!empty($applicant_profile_picture)) { ?>
                <img src="<?php echo htmlspecialchars($applicant_profile_picture); ?>" class="brand-img" alt="Applicant Profile">
            <?php } else { ?>
                <div class="brand-icon"><?php echo htmlspecialchars($sidebar_initial); ?></div>
            <?php } ?>

            <div class="brand-text-wrap">
                <div class="brand-text">Job Portal</div>
                <div class="brand-subtext"><?php echo htmlspecialchars($full_name); ?></div>
            </div>
        </div>

        <div class="nav-title">Applicant Menu</div>

        <a href="dashboard.php" class="nav-link <?php echo active_link('dashboard.php', $current_page); ?>">
            <span class="nav-icon">▦</span> Dashboard
        </a>

        <a href="jobs.php" class="nav-link <?php echo active_link('jobs.php', $current_page); ?>">
            <span class="nav-icon">🔎</span> Browse Jobs
        </a>

        <a href="my_applications.php" class="nav-link <?php echo active_link('my_applications.php', $current_page); ?>">
            <span class="nav-icon">📄</span> My Applications
        </a>

        <a href="interviews.php" class="nav-link <?php echo active_link('interviews.php', $current_page); ?>">
            <span class="nav-icon">🗓️</span> Interviews
        </a>

        <a href="saved_jobs.php" class="nav-link <?php echo active_link('saved_jobs.php', $current_page); ?>">
            <span class="nav-icon">★</span> Saved Jobs
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
                <h1>Applicant Profile</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Update your personal information, resume, portfolio, and password.</p>
            </div>

            <div class="user-pill">Applicant</div>
        </div>

        <?php if (!empty($success)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <div class="grid">
            <div class="panel">
                <h2>Personal Information</h2>
                <p class="panel-subtitle">Complete your applicant profile so recruiters can review your background.</p>

                <form method="POST" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Full Name</label>
                            <input 
                                type="text" 
                                name="full_name" 
                                value="<?php echo htmlspecialchars($profile['full_name'] ?? ''); ?>" 
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Email Address</label>
                            <input 
                                type="email" 
                                name="email" 
                                value="<?php echo htmlspecialchars($profile['email'] ?? ''); ?>" 
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Phone Number</label>
                            <input 
                                type="text" 
                                name="phone" 
                                value="<?php echo htmlspecialchars($profile['phone'] ?? ''); ?>" 
                                placeholder="Example: 09123456789"
                            >
                        </div>

                        <div class="form-group">
                            <label>Portfolio Link</label>
                            <input 
                                type="url" 
                                name="portfolio_link" 
                                value="<?php echo htmlspecialchars($profile['portfolio_link'] ?? ''); ?>" 
                                placeholder="https://example.com"
                            >
                        </div>

                        <div class="form-group full">
                            <label>Address</label>
                            <textarea name="address" placeholder="Enter your address"><?php echo htmlspecialchars($profile['address'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Skills</label>
                            <textarea name="skills" placeholder="Example: PHP, MySQL, Communication, Customer Service"><?php echo htmlspecialchars($profile['skills'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Education</label>
                            <textarea name="education" placeholder="Enter your educational background"><?php echo htmlspecialchars($profile['education'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group full">
                            <label>Experience</label>
                            <textarea name="experience" placeholder="Enter your work experience"><?php echo htmlspecialchars($profile['experience'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Profile Picture</label>
                            <input type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.gif,.webp">
                            <div class="hint">Allowed: JPG, JPEG, PNG, GIF, WEBP. Maximum size: 5MB.</div>
                        </div>

                        <div class="form-group">
                            <label>Resume File</label>
                            <input type="file" name="resume_file" accept=".pdf,.doc,.docx">
                            <div class="hint">Allowed: PDF, DOC, DOCX. Maximum size: 5MB.</div>
                        </div>
                    </div>

                    <div class="form-actions">
                        <button type="submit" name="update_profile" class="btn btn-primary">
                            Update Profile
                        </button>
                    </div>
                </form>
            </div>

            <div>
                <div class="panel preview-card">
                    <?php if (!empty($applicant_profile_picture)) { ?>
                        <img src="<?php echo htmlspecialchars($applicant_profile_picture); ?>" class="preview-img" alt="Applicant Profile">
                    <?php } else { ?>
                        <div class="preview-placeholder">
                            <?php echo htmlspecialchars($profile_initial); ?>
                        </div>
                    <?php } ?>

                    <h3><?php echo htmlspecialchars($profile['full_name'] ?? 'Applicant'); ?></h3>
                    <p><?php echo htmlspecialchars($profile['email'] ?? ''); ?></p>
                    <p>Profile Views: <?php echo intval($profile['profile_views'] ?? 0); ?></p>
                </div>

                <div class="panel" style="margin-top:18px;">
                    <h2>Profile Summary</h2>
                    <p class="panel-subtitle">Current information visible to recruiters.</p>

                    <div class="info-box">
                        <strong>Phone</strong>
                        <p><?php echo !empty($profile['phone']) ? htmlspecialchars($profile['phone']) : 'No phone number provided.'; ?></p>
                    </div>

                    <div class="info-box">
                        <strong>Address</strong>
                        <p><?php echo !empty($profile['address']) ? nl2br(htmlspecialchars($profile['address'])) : 'No address provided.'; ?></p>
                    </div>

                    <div class="info-box">
                        <strong>Portfolio</strong>
                        <?php if (!empty($profile['portfolio_link'])) { ?>
                            <a href="<?php echo htmlspecialchars($profile['portfolio_link']); ?>" target="_blank">
                                <?php echo htmlspecialchars($profile['portfolio_link']); ?>
                            </a>
                        <?php } else { ?>
                            <p>No portfolio link provided.</p>
                        <?php } ?>
                    </div>

                    <div class="info-box">
                        <strong>Resume</strong>
                        <?php if (!empty($resume_web_path)) { ?>
                            <a href="<?php echo htmlspecialchars($resume_web_path); ?>" target="_blank">
                                View Current Resume
                            </a>
                        <?php } elseif (!empty($profile['resume_file'])) { ?>
                            <p>Resume saved but file not found in uploads folder.</p>
                        <?php } else { ?>
                            <p>No resume uploaded.</p>
                        <?php } ?>
                    </div>

                    <div class="info-box">
                        <strong>Skills</strong>
                        <p><?php echo !empty($profile['skills']) ? nl2br(htmlspecialchars($profile['skills'])) : 'No skills provided.'; ?></p>
                    </div>

                    <div class="info-box">
                        <strong>Education</strong>
                        <p><?php echo !empty($profile['education']) ? nl2br(htmlspecialchars($profile['education'])) : 'No education provided.'; ?></p>
                    </div>

                    <div class="info-box">
                        <strong>Experience</strong>
                        <p><?php echo !empty($profile['experience']) ? nl2br(htmlspecialchars($profile['experience'])) : 'No experience provided.'; ?></p>
                    </div>
                </div>

                <div class="panel" style="margin-top:18px;">
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

                        <div class="form-actions">
                            <button type="submit" name="change_password" class="btn btn-primary">
                                Change Password
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </main>
</div>

</body>
</html>