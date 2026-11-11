<?php
// recruiter/edit_job.php
include "../includes/auth_check.php";
include "../config/database.php";
include "../includes/create_notification.php";

if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}

if ($_SESSION['role'] !== 'recruiter') {
    if ($_SESSION['role'] === 'admin') {
        header("Location: ../admin/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'creator') {
        header("Location: ../creator/dashboard.php");
        exit();
    }

    if ($_SESSION['role'] === 'applicant') {
        header("Location: ../applicant/dashboard.php");
        exit();
    }

    header("Location: ../auth/login.php");
    exit();
}

$recruiter_id = intval($_SESSION['user_id']);
$full_name = $_SESSION['full_name'] ?? 'Recruiter';
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

$message = "";
$error = "";

// Company name and logo for sidebar
$company_name = "Job Portal";
$company_logo = "";
$recruiter_profile_picture = "";

$company_query = mysqli_query($conn, "
    SELECT 
        users.full_name,
        users.profile_picture,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo
    FROM users
    LEFT JOIN recruiter_profiles ON users.id = recruiter_profiles.user_id
    WHERE users.id = '$recruiter_id'
    LIMIT 1
");

if ($company_query && mysqli_num_rows($company_query) > 0) {
    $company_data = mysqli_fetch_assoc($company_query);

    if (!empty($company_data['full_name'])) {
        $full_name = $company_data['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    if (!empty($company_data['company_name'])) {
        $company_name = $company_data['company_name'];
    }

    if (!empty($company_data['profile_picture'])) {
        $picture_name = $company_data['profile_picture'];

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
                $recruiter_profile_picture = $picture['web'];
                break;
            }
        }
    } elseif (!empty($company_data['company_logo'])) {
        $logo_file = __DIR__ . "/../assets/uploads/company_logos/" . $company_data['company_logo'];

        if (file_exists($logo_file)) {
            $recruiter_profile_picture = "../assets/uploads/company_logos/" . $company_data['company_logo'];
        }
    }
}

$sidebar_initial = strtoupper(substr($company_name, 0, 1));

if ($sidebar_initial === "") {
    $sidebar_initial = "R";
}

// Unread notifications count
$unread_notifications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$recruiter_id'
    AND is_read = 0
");

// Message badge count
$unread_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM messages 
    WHERE receiver_id = '$recruiter_id'
    AND is_read = 0
");

// Validate job id
if (!isset($_GET['id']) || empty($_GET['id'])) {
    header("Location: manage_jobs.php?error=Invalid job selected.");
    exit();
}

$job_id = intval($_GET['id']);

// Get categories
$categories = mysqli_query($conn, "
    SELECT *
    FROM job_categories
    ORDER BY category_name ASC
");

// Get job data
$job_sql = "
    SELECT *
    FROM jobs
    WHERE id = ?
    AND recruiter_id = ?
    LIMIT 1
";

$job_stmt = mysqli_prepare($conn, $job_sql);

if (!$job_stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($job_stmt, "ii", $job_id, $recruiter_id);
mysqli_stmt_execute($job_stmt);
$job_result = mysqli_stmt_get_result($job_stmt);

if (!$job_result || mysqli_num_rows($job_result) !== 1) {
    header("Location: manage_jobs.php?error=Job not found or you do not have permission.");
    exit();
}

$job = mysqli_fetch_assoc($job_result);

// Update job
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_job'])) {
    $title = trim($_POST['title']);
    $category_id = intval($_POST['category_id']);
    $description = trim($_POST['description']);
    $requirements = trim($_POST['requirements']);
    $salary_min = trim($_POST['salary_min']);
    $salary_max = trim($_POST['salary_max']);
    $location = trim($_POST['location']);
    $job_type = trim($_POST['job_type']);
    $expiry_date = trim($_POST['expiry_date']);

    if (
        empty($title) ||
        empty($category_id) ||
        empty($description) ||
        empty($requirements) ||
        empty($salary_min) ||
        empty($salary_max) ||
        empty($location) ||
        empty($job_type) ||
        empty($expiry_date)
    ) {
        $error = "All fields are required.";
    } elseif (!is_numeric($salary_min) || !is_numeric($salary_max)) {
        $error = "Salary minimum and maximum must be valid numbers.";
    } elseif (floatval($salary_min) < 0 || floatval($salary_max) < 0) {
        $error = "Salary values cannot be negative.";
    } elseif (floatval($salary_min) > floatval($salary_max)) {
        $error = "Minimum salary cannot be greater than maximum salary.";
    } elseif (strtotime($expiry_date) < strtotime(date("Y-m-d"))) {
        $error = "Expiry date cannot be in the past.";
    } else {
        $old_status = strtolower(trim($job['status']));
        $new_status = $job['status'];
        $new_reject_reason = $job['reject_reason'] ?? "";

        // If rejected job is edited, send it back to pending for admin review
        if ($old_status === "rejected") {
            $new_status = "pending";
            $new_reject_reason = "";
        }

        $update_sql = "
            UPDATE jobs
            SET 
                title = ?,
                category_id = ?,
                description = ?,
                requirements = ?,
                salary_min = ?,
                salary_max = ?,
                location = ?,
                job_type = ?,
                expiry_date = ?,
                status = ?,
                reject_reason = ?
            WHERE id = ?
            AND recruiter_id = ?
        ";

        $update_stmt = mysqli_prepare($conn, $update_sql);

        if (!$update_stmt) {
            $error = "Database prepare failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param(
                $update_stmt,
                "sissddsssssii",
                $title,
                $category_id,
                $description,
                $requirements,
                $salary_min,
                $salary_max,
                $location,
                $job_type,
                $expiry_date,
                $new_status,
                $new_reject_reason,
                $job_id,
                $recruiter_id
            );

            if (mysqli_stmt_execute($update_stmt)) {
                if ($old_status === "rejected") {
                    $admins_query = mysqli_query($conn, "
                        SELECT id 
                        FROM users 
                        WHERE role = 'admin'
                        AND status = 'active'
                    ");

                    if ($admins_query && mysqli_num_rows($admins_query) > 0) {
                        while ($admin = mysqli_fetch_assoc($admins_query)) {
                            create_notification(
                                $conn,
                                intval($admin['id']),
                                "Rejected Job Resubmitted",
                                $company_name . " edited and resubmitted the job post: " . $title,
                                "../admin/approve_jobs.php"
                            );
                        }
                    }

                    header("Location: manage_jobs.php?message=Job updated and resubmitted for admin approval.");
                    exit();
                } else {
                    header("Location: manage_jobs.php?message=Job updated successfully.");
                    exit();
                }
            } else {
                $error = "Failed to update job: " . mysqli_error($conn);
            }
        }
    }

    // Keep submitted values after validation error
    $job['title'] = $title;
    $job['category_id'] = $category_id;
    $job['description'] = $description;
    $job['requirements'] = $requirements;
    $job['salary_min'] = $salary_min;
    $job['salary_max'] = $salary_max;
    $job['location'] = $location;
    $job['job_type'] = $job_type;
    $job['expiry_date'] = $expiry_date;
}

function selected_value($current, $value) {
    return strval($current) === strval($value) ? "selected" : "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit Job | Job Application System</title>
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

        .panel {
            border-radius: 28px;
            padding: 24px;
            max-width: 1100px;
            animation: fadeUp 0.58s ease both;
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 23px;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .panel-subtitle {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group.full {
            grid-column: span 2;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 900;
            color: var(--soft);
        }

        input,
        select,
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
        textarea::placeholder {
            color: rgba(220, 231, 255, 0.6);
        }

        select option {
            color: #101827;
            background: #ffffff;
        }

        textarea {
            min-height: 130px;
            resize: vertical;
            line-height: 1.7;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .btn-row {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 8px;
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

        .alert,
        .notice {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.6;
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

        .notice {
            background: rgba(255, 180, 84, 0.13);
            color: #ffe2b1;
            border: 1px solid rgba(255, 180, 84, 0.28);
        }



        .form-actions {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            margin-top: 22px;
            flex-wrap: wrap;
        }

        .warning-box,
        .status-box {
            position: relative;
            overflow: hidden;
            padding: 15px 16px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.7;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            animation: fadeUp 0.35s ease both;
        }

        .warning-box {
            background: rgba(255, 180, 84, 0.13);
            color: #ffe2b1;
            border: 1px solid rgba(255, 180, 84, 0.28);
        }

        .status-box {
            background: rgba(255, 255, 255, 0.075);
            color: var(--muted);
            border: 1px solid rgba(255, 255, 255, 0.14);
        }

        .status-box strong,
        .warning-box strong {
            color: var(--soft);
        }

        .status-label {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            text-transform: capitalize;
            border: 1px solid transparent;
            margin-left: 6px;
        }

        .status-pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .status-approved {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .status-rejected {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        @media (max-width: 850px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: span 1;
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
                padding: 18px;
            }

            .topbar {
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
            <?php if (!empty($recruiter_profile_picture)) { ?>
                <img src="<?php echo htmlspecialchars($recruiter_profile_picture); ?>" class="brand-img" alt="Recruiter Profile">
            <?php } else { ?>
                <div class="brand-icon"><?php echo htmlspecialchars($sidebar_initial); ?></div>
            <?php } ?>

            <div class="brand-text-wrap">
                <div class="brand-text"><?php echo htmlspecialchars($company_name); ?></div>
                <div class="brand-subtext"><?php echo htmlspecialchars($full_name); ?></div>
            </div>
        </div>

        <div class="nav-title">Recruiter Menu</div>

        <a href="dashboard.php" class="nav-link <?php echo active_link('dashboard.php', $current_page); ?>">
            <span class="nav-icon">▦</span> Dashboard
        </a>

        <a href="post_job.php" class="nav-link <?php echo active_link('post_job.php', $current_page); ?>">
            <span class="nav-icon">➕</span> Post Job
        </a>

        <a href="manage_jobs.php" class="nav-link <?php echo active_link('manage_jobs.php', $current_page); ?>">
            <span class="nav-icon">💼</span> Manage Jobs
        </a>

        <a href="applicants.php" class="nav-link <?php echo active_link('applicants.php', $current_page); ?>">
            <span class="nav-icon">👥</span> Applicants
        </a>

        <a href="rejected_applicants.php" class="nav-link <?php echo active_link('rejected_applicants.php', $current_page); ?>">
            <span class="nav-icon">🚫</span> Rejected Applicants
        </a>

        <a href="applicants.php" class="nav-link <?php echo active_link('schedule_interview.php', $current_page); ?>">
            <span class="nav-icon">🗓️</span> Schedule Interview
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
                <h1>Edit Job</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Update your job post details carefully.</p>
            </div>

            <div class="user-pill">Recruiter</div>
        </div>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <?php if (strtolower(trim($job['status'])) === "rejected") { ?>
            <div class="warning-box">
                This job was rejected by the admin.
                <?php if (!empty($job['reject_reason'])) { ?>
                    <br><strong>Reject Reason:</strong> <?php echo nl2br(htmlspecialchars($job['reject_reason'])); ?>
                <?php } ?>
                <br>After editing and saving, this job will be submitted again for admin approval.
            </div>
        <?php } ?>

        <div class="panel">
            <div class="status-box">
                <strong>Current Job Status:</strong>
                <span class="status-label status-<?php echo htmlspecialchars(strtolower($job['status'])); ?>">
                    <?php echo htmlspecialchars($job['status']); ?>
                </span>
                <br>
                <strong>Archived:</strong> <?php echo intval($job['is_archived'] ?? 0) === 1 ? "Yes" : "No"; ?>
            </div>

            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Job Title</label>
                        <input 
                            type="text" 
                            name="title" 
                            value="<?php echo htmlspecialchars($job['title'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Category</label>
                        <select name="category_id" required>
                            <option value="">Select Category</option>

                            <?php if ($categories && mysqli_num_rows($categories) > 0) { ?>
                                <?php while ($category = mysqli_fetch_assoc($categories)) { ?>
                                    <option value="<?php echo intval($category['id']); ?>" <?php echo selected_value($job['category_id'] ?? '', $category['id']); ?>>
                                        <?php echo htmlspecialchars($category['category_name']); ?>
                                    </option>
                                <?php } ?>
                            <?php } ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Salary Minimum</label>
                        <input 
                            type="number" 
                            name="salary_min" 
                            step="0.01" 
                            min="0"
                            value="<?php echo htmlspecialchars($job['salary_min'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Salary Maximum</label>
                        <input 
                            type="number" 
                            name="salary_max" 
                            step="0.01" 
                            min="0"
                            value="<?php echo htmlspecialchars($job['salary_max'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Location</label>
                        <input 
                            type="text" 
                            name="location" 
                            value="<?php echo htmlspecialchars($job['location'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Job Type</label>
                        <select name="job_type" required>
                            <option value="">Select Job Type</option>
                            <option value="full-time" <?php echo selected_value($job['job_type'] ?? '', 'full-time'); ?>>Full-time</option>
                            <option value="part-time" <?php echo selected_value($job['job_type'] ?? '', 'part-time'); ?>>Part-time</option>
                            <option value="remote" <?php echo selected_value($job['job_type'] ?? '', 'remote'); ?>>Remote</option>
                            <option value="hybrid" <?php echo selected_value($job['job_type'] ?? '', 'hybrid'); ?>>Hybrid</option>
                            <option value="internship" <?php echo selected_value($job['job_type'] ?? '', 'internship'); ?>>Internship</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input 
                            type="date" 
                            name="expiry_date" 
                            value="<?php echo htmlspecialchars($job['expiry_date'] ?? ''); ?>" 
                            required
                        >
                    </div>

                    <div class="form-group full">
                        <label>Description</label>
                        <textarea name="description" required><?php echo htmlspecialchars($job['description'] ?? ''); ?></textarea>
                    </div>

                    <div class="form-group full">
                        <label>Requirements</label>
                        <textarea name="requirements" required><?php echo htmlspecialchars($job['requirements'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <a href="manage_jobs.php" class="btn btn-light">Cancel</a>

                    <button type="submit" name="update_job" class="btn btn-primary">
                        Update Job
                    </button>
                </div>
            </form>
        </div>
    </main>
</div>

</body>
</html>