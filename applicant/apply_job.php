<?php
// applicant/apply_job.php
include "../includes/auth_check.php";
include "../config/database.php";
include "../includes/create_notification.php";

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

$error = "";
$success = "";

// Applicant profile picture/name
$applicant_profile_picture = "";

$applicant_query = mysqli_query($conn, "
    SELECT full_name, profile_picture
    FROM users 
    WHERE id = '$applicant_id' 
    LIMIT 1
");

if ($applicant_query && mysqli_num_rows($applicant_query) > 0) {
    $applicant_data = mysqli_fetch_assoc($applicant_query);

    if (!empty($applicant_data['full_name'])) {
        $full_name = $applicant_data['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    if (!empty($applicant_data['profile_picture'])) {
        $picture_name = $applicant_data['profile_picture'];

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
                $applicant_profile_picture = $picture['web'];
                break;
            }
        }
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

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}

// Notification badge count
$unread_notifications = 0;

$notif_count_query = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$applicant_id'
    AND is_read = 0
");

if ($notif_count_query) {
    $notif_count_row = mysqli_fetch_assoc($notif_count_query);
    $unread_notifications = $notif_count_row['total'];
}

// Message badge count
$unread_messages = 0;

$msg_count_query = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM messages 
    WHERE receiver_id = '$applicant_id'
    AND is_read = 0
");

if ($msg_count_query) {
    $msg_count_row = mysqli_fetch_assoc($msg_count_query);
    $unread_messages = $msg_count_row['total'];
}

if (!isset($_GET['job_id'])) {
    header("Location: jobs.php");
    exit();
}

$job_id = intval($_GET['job_id']);

// Get job details
$sql = "
    SELECT 
        jobs.*, 
        job_categories.category_name, 
        users.full_name AS recruiter_name,
        users.id AS recruiter_id,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo
    FROM jobs
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON jobs.recruiter_id = recruiter_profiles.user_id
    WHERE jobs.id = ?
    AND jobs.status = 'approved'
    AND jobs.is_archived = 0
    AND jobs.expiry_date >= CURDATE()
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $job_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) !== 1) {
    header("Location: jobs.php");
    exit();
}

$job = mysqli_fetch_assoc($result);

// Check duplicate application
$check_sql = "
    SELECT id 
    FROM applications 
    WHERE job_id = ? 
    AND applicant_id = ? 
    LIMIT 1
";

$check_stmt = mysqli_prepare($conn, $check_sql);

if (!$check_stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($check_stmt, "ii", $job_id, $applicant_id);
mysqli_stmt_execute($check_stmt);
$check_result = mysqli_stmt_get_result($check_stmt);

$already_applied = ($check_result && mysqli_num_rows($check_result) > 0);

// Submit application
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['apply'])) {
    $check_stmt = mysqli_prepare($conn, $check_sql);

    if (!$check_stmt) {
        $error = "Database prepare failed: " . mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($check_stmt, "ii", $job_id, $applicant_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) > 0) {
            $already_applied = true;
            $error = "You already applied for this job.";
        } else {
            $cover_letter = isset($_POST['cover_letter']) ? trim($_POST['cover_letter']) : "";
            $resume_file = "";

            if (empty($cover_letter)) {
                $error = "Please enter your cover letter.";
            } elseif (!isset($_FILES['resume']) || $_FILES['resume']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = "Please upload your resume.";
            } elseif ($_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
                $error = "Resume upload failed. Error code: " . $_FILES['resume']['error'];
            } else {
                $allowed = ['pdf', 'doc', 'docx'];
                $file_name = $_FILES['resume']['name'];
                $file_tmp = $_FILES['resume']['tmp_name'];
                $file_size = $_FILES['resume']['size'];
                $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));

                if (!in_array($file_ext, $allowed)) {
                    $error = "Resume must be PDF, DOC, or DOCX only.";
                } elseif ($file_size > 5 * 1024 * 1024) {
                    $error = "Resume file must not exceed 5MB.";
                } else {
                    $upload_dir = __DIR__ . "/../assets/uploads/resumes/";

                    if (!is_dir($upload_dir)) {
                        mkdir($upload_dir, 0777, true);
                    }

                    $safe_file_name = preg_replace("/[^a-zA-Z0-9._-]/", "_", $file_name);
                    $resume_file = time() . "_" . $applicant_id . "_" . $safe_file_name;
                    $upload_path = $upload_dir . $resume_file;

                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        $insert_sql = "
                            INSERT INTO applications 
                                (job_id, applicant_id, resume_file, cover_letter, status)
                            VALUES 
                                (?, ?, ?, ?, 'pending')
                        ";

                        $insert_stmt = mysqli_prepare($conn, $insert_sql);

                        if (!$insert_stmt) {
                            $error = "Database prepare failed: " . mysqli_error($conn);
                        } else {
                            mysqli_stmt_bind_param(
                                $insert_stmt,
                                "iiss",
                                $job_id,
                                $applicant_id,
                                $resume_file,
                                $cover_letter
                            );

                            if (mysqli_stmt_execute($insert_stmt)) {
                                $recruiter_id = intval($job['recruiter_id']);
                                $job_title = $job['title'];

                                create_notification(
                                    $conn,
                                    $recruiter_id,
                                    "New Job Application",
                                    $full_name . " applied for your job: " . $job_title,
                                    "../recruiter/applicants.php"
                                );

                                header("Location: my_applications.php?message=Application submitted successfully.");
                                exit();
                            } else {
                                $error = "Failed to submit application: " . mysqli_error($conn);
                            }
                        }
                    } else {
                        $error = "Failed to upload resume. Please check if assets/uploads/resumes folder exists and is writable.";
                    }
                }
            }
        }
    }
}

$company_name = !empty($job['company_name']) ? $job['company_name'] : $job['recruiter_name'];
$company_initial = strtoupper(substr($company_name, 0, 1));
$company_logo_path = "";

if (!empty($job['company_logo'])) {
    $company_logo_file = __DIR__ . "/../assets/uploads/company_logos/" . $job['company_logo'];

    if (file_exists($company_logo_file)) {
        $company_logo_path = "../assets/uploads/company_logos/" . $job['company_logo'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Apply Job | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-1: #080b1f;
            --bg-2: #111a3d;
            --bg-3: #1a0f35;
            --card: rgba(255, 255, 255, 0.10);
            --card-strong: rgba(255, 255, 255, 0.15);
            --stroke: rgba(255, 255, 255, 0.18);
            --stroke-soft: rgba(255, 255, 255, 0.11);
            --text: #f8fbff;
            --muted: #aebad0;
            --muted-2: #d3d9e8;
            --cyan: #25d8ff;
            --blue: #4f7cff;
            --purple: #8f5bff;
            --pink: #ff4ecd;
            --red: #ff5b76;
            --green: #28f5aa;
            --shadow: 0 24px 80px rgba(0, 0, 0, 0.34);
            --shadow-soft: 0 14px 35px rgba(0, 0, 0, 0.25);
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
            background:
                radial-gradient(circle at 10% 10%, rgba(37, 216, 255, 0.22), transparent 28%),
                radial-gradient(circle at 85% 8%, rgba(255, 78, 205, 0.18), transparent 30%),
                radial-gradient(circle at 70% 95%, rgba(143, 91, 255, 0.22), transparent 34%),
                linear-gradient(135deg, var(--bg-1) 0%, var(--bg-2) 45%, var(--bg-3) 100%);
            background-attachment: fixed;
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.035) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,0.65), transparent 78%);
            z-index: 0;
        }

        .layout {
            display: flex;
            min-height: 100vh;
            position: relative;
            z-index: 1;
        }

        .sidebar {
            width: 280px;
            background: rgba(5, 10, 30, 0.72);
            border-right: 1px solid var(--stroke);
            padding: 24px 18px;
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            overflow-y: auto;
            backdrop-filter: blur(22px);
            box-shadow: 18px 0 55px rgba(0, 0, 0, 0.24);
        }

        .sidebar::-webkit-scrollbar,
        body::-webkit-scrollbar {
            width: 10px;
        }

        .sidebar::-webkit-scrollbar-thumb,
        body::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.18);
            border-radius: 999px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 34px;
            padding: 12px;
            border-radius: 22px;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid var(--stroke-soft);
        }

        .brand-icon,
        .brand-img {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            flex-shrink: 0;
        }

        .brand-icon {
            background: linear-gradient(135deg, var(--cyan), var(--blue), var(--purple));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 16px;
            letter-spacing: 0.6px;
            box-shadow: 0 14px 30px rgba(37, 216, 255, 0.22);
        }

        .brand-img {
            object-fit: cover;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.22);
            box-shadow: 0 14px 30px rgba(37, 216, 255, 0.18);
        }

        .brand-text-wrap {
            min-width: 0;
        }

        .brand-text {
            font-size: 19px;
            font-weight: 900;
            color: #ffffff;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            letter-spacing: -0.3px;
        }

        .brand-subtext {
            margin-top: 3px;
            font-size: 12px;
            color: var(--muted);
            font-weight: 700;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 170px;
        }

        .nav-title {
            font-size: 11px;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.52);
            margin: 18px 10px 10px;
            letter-spacing: 1.2px;
            font-weight: 800;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 13px 14px;
            margin-bottom: 8px;
            color: var(--muted-2);
            text-decoration: none;
            border-radius: 16px;
            font-size: 14px;
            font-weight: 750;
            border: 1px solid transparent;
            transition: transform 0.22s ease, background 0.22s ease, border-color 0.22s ease, color 0.22s ease, box-shadow 0.22s ease;
        }

        .nav-link:hover,
        .nav-link.active {
            transform: translateX(4px);
            background: linear-gradient(135deg, rgba(37, 216, 255, 0.18), rgba(143, 91, 255, 0.16));
            border-color: rgba(255, 255, 255, 0.16);
            color: #ffffff;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.18);
        }

        .nav-link.active {
            font-weight: 900;
        }

        .nav-icon {
            width: 24px;
            height: 24px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.08);
        }

        .notif-badge {
            margin-left: auto;
            background: linear-gradient(135deg, var(--pink), var(--red));
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
            box-shadow: 0 8px 20px rgba(255, 78, 205, 0.28);
        }

        .logout {
            margin-top: 26px;
            color: #ffd3dc;
        }

        .logout:hover {
            background: linear-gradient(135deg, rgba(255, 78, 116, 0.18), rgba(255, 78, 205, 0.15));
        }

        .main {
            margin-left: 280px;
            width: calc(100% - 280px);
            padding: 30px;
        }

        .topbar,
        .panel {
            background: var(--card);
            border: 1px solid var(--stroke);
            box-shadow: var(--shadow-soft);
            backdrop-filter: blur(22px);
            position: relative;
            overflow: hidden;
        }

        .topbar::before,
        .panel::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255, 255, 255, 0.16), transparent 38%);
            pointer-events: none;
        }

        .topbar > *,
        .panel > * {
            position: relative;
            z-index: 1;
        }

        .topbar {
            border-radius: 28px;
            padding: 26px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }

        .topbar h1 {
            margin: 0;
            font-size: clamp(26px, 3vw, 38px);
            color: #ffffff;
            letter-spacing: -1px;
        }

        .topbar p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .user-pill {
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.18);
            padding: 11px 16px;
            border-radius: 999px;
            color: #ffffff;
            font-size: 14px;
            font-weight: 900;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.16);
        }

        .grid {
            display: grid;
            grid-template-columns: 0.9fr 1.1fr;
            gap: 20px;
            align-items: start;
        }

        .panel {
            border-radius: 28px;
            padding: 26px;
            transition: transform 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
        }

        .panel:hover {
            transform: translateY(-3px);
            border-color: rgba(37, 216, 255, 0.32);
            box-shadow: var(--shadow);
        }

        .panel h2 {
            margin: 0 0 12px;
            font-size: 22px;
            color: #ffffff;
            letter-spacing: -0.4px;
        }

        .company-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--stroke-soft);
        }

        .company-logo,
        .company-placeholder {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            flex-shrink: 0;
        }

        .company-logo {
            object-fit: cover;
            background: rgba(255, 255, 255, 0.12);
            border: 1px solid rgba(255, 255, 255, 0.20);
            box-shadow: 0 14px 35px rgba(37, 216, 255, 0.18);
        }

        .company-placeholder {
            background: linear-gradient(135deg, var(--cyan), var(--blue), var(--purple));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 20px;
            box-shadow: 0 14px 35px rgba(37, 216, 255, 0.22);
        }

        .company-name {
            margin: 0;
            color: #ffffff;
            font-weight: 900;
            font-size: 17px;
        }

        .company-sub {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.5;
        }

        .meta {
            color: var(--muted-2);
            font-size: 14px;
            line-height: 1.8;
            margin-bottom: 16px;
            padding: 14px;
            border-radius: 18px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid var(--stroke-soft);
        }

        .section-title {
            font-weight: 900;
            color: #ffffff;
            margin-top: 20px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .section-title::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: linear-gradient(135deg, var(--cyan), var(--pink));
            box-shadow: 0 0 18px rgba(37, 216, 255, 0.45);
        }

        .content {
            color: var(--muted-2);
            font-size: 14px;
            line-height: 1.75;
        }

        label {
            display: block;
            margin-bottom: 9px;
            font-size: 14px;
            font-weight: 900;
            color: #ffffff;
        }

        textarea,
        input {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid var(--stroke);
            border-radius: 16px;
            outline: none;
            background: rgba(255, 255, 255, 0.09);
            color: #ffffff;
            font-size: 14px;
            transition: border-color 0.22s ease, box-shadow 0.22s ease, background 0.22s ease, transform 0.22s ease;
        }

        textarea::placeholder,
        input::placeholder {
            color: rgba(255, 255, 255, 0.48);
        }

        textarea {
            min-height: 190px;
            resize: vertical;
            line-height: 1.65;
        }

        input[type="file"] {
            cursor: pointer;
        }

        input[type="file"]::file-selector-button {
            border: 0;
            margin-right: 12px;
            padding: 10px 13px;
            border-radius: 12px;
            color: white;
            font-weight: 900;
            cursor: pointer;
            background: linear-gradient(135deg, var(--cyan), var(--blue));
        }

        textarea:focus,
        input:focus {
            border-color: rgba(37, 216, 255, 0.70);
            box-shadow: 0 0 0 4px rgba(37, 216, 255, 0.13), 0 14px 35px rgba(0, 0, 0, 0.20);
            background: rgba(255, 255, 255, 0.13);
            transform: translateY(-1px);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .hint {
            margin-top: 8px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.5;
        }

        .btn-row {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 13px 20px;
            border-radius: 16px;
            text-decoration: none;
            border: 1px solid transparent;
            font-size: 14px;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 48px;
            transition: transform 0.22s ease, box-shadow 0.22s ease, background 0.22s ease, border-color 0.22s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--cyan), var(--blue), var(--purple));
            color: white;
            box-shadow: 0 16px 35px rgba(37, 216, 255, 0.23);
        }

        .btn-primary:hover {
            box-shadow: 0 20px 45px rgba(143, 91, 255, 0.32);
        }

        .btn-light {
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            border-color: var(--stroke);
        }

        .btn-light:hover {
            background: rgba(255, 255, 255, 0.14);
            border-color: rgba(37, 216, 255, 0.35);
        }

        .alert {
            padding: 14px 15px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.55;
            border: 1px solid var(--stroke-soft);
        }

        .alert-success {
            background: rgba(40, 245, 170, 0.12);
            color: #c8ffec;
            border-color: rgba(40, 245, 170, 0.26);
        }

        .alert-error {
            background: rgba(255, 91, 118, 0.13);
            color: #ffd1d9;
            border-color: rgba(255, 91, 118, 0.28);
        }

        .badge {
            display: inline-flex;
            align-items: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(37, 216, 255, 0.12);
            color: #dff8ff;
            border: 1px solid rgba(37, 216, 255, 0.22);
            font-size: 12px;
            font-weight: 900;
            margin-right: 7px;
            margin-bottom: 8px;
        }

        @media (max-width: 1050px) {
            .grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 800px) {
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

            .topbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 14px;
            }

            .btn-row {
                justify-content: stretch;
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
                <h1>Apply for Job</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Review the job details before submitting your application.</p>
            </div>

            <div class="user-pill">Applicant</div>
        </div>

        <div class="grid">
            <div class="panel">
                <div class="company-row">
                    <?php if (!empty($company_logo_path)) { ?>
                        <img src="<?php echo htmlspecialchars($company_logo_path); ?>" class="company-logo" alt="Company Logo">
                    <?php } else { ?>
                        <div class="company-placeholder"><?php echo htmlspecialchars($company_initial); ?></div>
                    <?php } ?>

                    <div>
                        <p class="company-name"><?php echo htmlspecialchars($company_name); ?></p>
                        <p class="company-sub">Recruiter: <?php echo htmlspecialchars($job['recruiter_name']); ?></p>
                    </div>
                </div>

                <h2><?php echo htmlspecialchars($job['title']); ?></h2>

                <div class="meta">
                    Location: <?php echo htmlspecialchars($job['location']); ?><br>
                    Salary: ₱<?php echo number_format(floatval($job['salary_min'] ?? 0), 2); ?> -
                    ₱<?php echo number_format(floatval($job['salary_max'] ?? 0), 2); ?><br>
                    Expiry: <?php echo htmlspecialchars($job['expiry_date']); ?>
                </div>

                <span class="badge"><?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?></span>
                <span class="badge"><?php echo htmlspecialchars($job['job_type']); ?></span>

                <div class="section-title">Description</div>
                <div class="content">
                    <?php echo nl2br(htmlspecialchars($job['description'] ?? '')); ?>
                </div>

                <div class="section-title">Requirements</div>
                <div class="content">
                    <?php echo nl2br(htmlspecialchars($job['requirements'] ?? '')); ?>
                </div>
            </div>

            <div class="panel">
                <h2>Application Form</h2>

                <?php if (!empty($error)) { ?>
                    <div class="alert alert-error">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php } ?>

                <?php if ($already_applied) { ?>
                    <div class="alert alert-success">
                        You have already applied for this job.
                    </div>

                    <div class="btn-row">
                        <a href="my_applications.php" class="btn btn-primary">
                            View My Applications
                        </a>
                    </div>
                <?php } else { ?>
                    <form method="POST" enctype="multipart/form-data">
                        <div class="form-group">
                            <label>Cover Letter</label>
                            <textarea name="cover_letter" placeholder="Write your cover letter..." required><?php echo isset($_POST['cover_letter']) ? htmlspecialchars($_POST['cover_letter']) : ''; ?></textarea>
                        </div>

                        <div class="form-group">
                            <label>Upload Resume</label>
                            <input type="file" name="resume" accept=".pdf,.doc,.docx" required>
                            <div class="hint">Allowed files: PDF, DOC, DOCX. Maximum file size: 5MB.</div>
                        </div>

                        <div class="btn-row">
                            <a href="jobs.php" class="btn btn-light">
                                Cancel
                            </a>

                            <button type="submit" name="apply" class="btn btn-primary">
                                Submit Application
                            </button>
                        </div>
                    </form>
                <?php } ?>
            </div>
        </div>
    </main>
</div>

</body>
</html>