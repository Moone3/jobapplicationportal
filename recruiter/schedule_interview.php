<?php
// recruiter/schedule_interview.php
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

$error = "";
$success = "";

// Notification badge count
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

// Company/profile info
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

if (!isset($_GET['application_id'])) {
    header("Location: applicants.php");
    exit();
}

$application_id = intval($_GET['application_id']);

// Get application details and make sure it belongs to the logged-in recruiter
$sql = "
    SELECT 
        applications.id AS application_id,
        applications.status AS application_status,
        applications.cover_letter,
        applications.resume_file,
        applications.applied_at,

        jobs.id AS job_id,
        jobs.title AS job_title,
        jobs.recruiter_id,

        users.id AS applicant_id,
        users.full_name AS applicant_name,
        users.email AS applicant_email,

        applicant_profiles.phone,
        applicant_profiles.address,
        applicant_profiles.skills,
        applicant_profiles.education,
        applicant_profiles.experience
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON applications.applicant_id = users.id
    LEFT JOIN applicant_profiles ON users.id = applicant_profiles.user_id
    WHERE applications.id = ?
    AND jobs.recruiter_id = ?
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "ii", $application_id, $recruiter_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (!$result || mysqli_num_rows($result) !== 1) {
    header("Location: applicants.php");
    exit();
}

$application = mysqli_fetch_assoc($result);

$applicant_id = intval($application['applicant_id']);
$job_title = $application['job_title'];
$applicant_name = $application['applicant_name'];

// Get existing interview
$interview_sql = "
    SELECT *
    FROM interviews
    WHERE application_id = ?
    LIMIT 1
";

$interview_stmt = mysqli_prepare($conn, $interview_sql);

if (!$interview_stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($interview_stmt, "i", $application_id);
mysqli_stmt_execute($interview_stmt);
$interview_result = mysqli_stmt_get_result($interview_stmt);
$interview = mysqli_fetch_assoc($interview_result);

// Cancel interview
if (isset($_GET['cancel'])) {
    $cancel_id = intval($_GET['cancel']);

    $check_sql = "
        SELECT interviews.id
        FROM interviews
        JOIN applications ON interviews.application_id = applications.id
        JOIN jobs ON applications.job_id = jobs.id
        WHERE interviews.id = ?
        AND applications.id = ?
        AND jobs.recruiter_id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if (!$check_stmt) {
        $error = "Database error.";
    } else {
        mysqli_stmt_bind_param($check_stmt, "iii", $cancel_id, $application_id, $recruiter_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $cancel_sql = "
                UPDATE interviews
                SET status = 'cancelled'
                WHERE id = ?
            ";

            $cancel_stmt = mysqli_prepare($conn, $cancel_sql);

            if ($cancel_stmt) {
                mysqli_stmt_bind_param($cancel_stmt, "i", $cancel_id);

                if (mysqli_stmt_execute($cancel_stmt)) {
                    create_notification(
                        $conn,
                        $applicant_id,
                        "Interview Cancelled",
                        "Your interview for " . $job_title . " has been cancelled by the recruiter.",
                        "../applicant/interviews.php"
                    );

                    $success = "Interview cancelled successfully.";

                    mysqli_stmt_execute($interview_stmt);
                    $interview_result = mysqli_stmt_get_result($interview_stmt);
                    $interview = mysqli_fetch_assoc($interview_result);
                } else {
                    $error = "Failed to cancel interview.";
                }
            } else {
                $error = "Database error.";
            }
        } else {
            $error = "Interview not found.";
        }
    }
}

// Mark interview as completed
if (isset($_GET['complete'])) {
    $complete_id = intval($_GET['complete']);

    $check_sql = "
        SELECT interviews.id
        FROM interviews
        JOIN applications ON interviews.application_id = applications.id
        JOIN jobs ON applications.job_id = jobs.id
        WHERE interviews.id = ?
        AND applications.id = ?
        AND jobs.recruiter_id = ?
        AND interviews.status = 'scheduled'
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if (!$check_stmt) {
        $error = "Database error.";
    } else {
        mysqli_stmt_bind_param($check_stmt, "iii", $complete_id, $application_id, $recruiter_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $complete_sql = "
                UPDATE interviews
                SET status = 'completed'
                WHERE id = ?
            ";

            $complete_stmt = mysqli_prepare($conn, $complete_sql);

            if ($complete_stmt) {
                mysqli_stmt_bind_param($complete_stmt, "i", $complete_id);

                if (mysqli_stmt_execute($complete_stmt)) {
                    create_notification(
                        $conn,
                        $applicant_id,
                        "Interview Completed",
                        "Your interview for " . $job_title . " has been marked as completed.",
                        "../applicant/interviews.php"
                    );

                    $success = "Interview marked as completed successfully.";

                    mysqli_stmt_execute($interview_stmt);
                    $interview_result = mysqli_stmt_get_result($interview_stmt);
                    $interview = mysqli_fetch_assoc($interview_result);
                } else {
                    $error = "Failed to mark interview as completed.";
                }
            } else {
                $error = "Database error.";
            }
        } else {
            $error = "Interview not found or already not scheduled.";
        }
    }
}

// Schedule or update interview
if (isset($_POST['schedule_interview'])) {
    $interview_date = trim($_POST['interview_date']);
    $interview_time = trim($_POST['interview_time']);
    $interview_mode = trim($_POST['interview_mode']);
    $interview_location = trim($_POST['interview_location']);
    $recruiter_message = trim($_POST['message']);

    if (empty($interview_date) || empty($interview_time) || empty($interview_mode)) {
        $error = "Interview date, time, and mode are required.";
    } elseif (strtotime($interview_date) < strtotime(date("Y-m-d"))) {
        $error = "Interview date cannot be in the past.";
    } else {
        $saved = false;

        if ($interview) {
            $update_sql = "
                UPDATE interviews
                SET interview_date = ?,
                    interview_time = ?,
                    interview_mode = ?,
                    interview_location = ?,
                    message = ?,
                    status = 'scheduled'
                WHERE application_id = ?
                AND recruiter_id = ?
            ";

            $update_stmt = mysqli_prepare($conn, $update_sql);

            if ($update_stmt) {
                mysqli_stmt_bind_param(
                    $update_stmt,
                    "sssssii",
                    $interview_date,
                    $interview_time,
                    $interview_mode,
                    $interview_location,
                    $recruiter_message,
                    $application_id,
                    $recruiter_id
                );

                $saved = mysqli_stmt_execute($update_stmt);
            }
        } else {
            $insert_sql = "
                INSERT INTO interviews
                (
                    application_id,
                    applicant_id,
                    recruiter_id,
                    interview_date,
                    interview_time,
                    interview_mode,
                    interview_location,
                    message,
                    status
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
            ";

            $insert_stmt = mysqli_prepare($conn, $insert_sql);

            if ($insert_stmt) {
                mysqli_stmt_bind_param(
                    $insert_stmt,
                    "iiisssss",
                    $application_id,
                    $applicant_id,
                    $recruiter_id,
                    $interview_date,
                    $interview_time,
                    $interview_mode,
                    $interview_location,
                    $recruiter_message
                );

                $saved = mysqli_stmt_execute($insert_stmt);
            }
        }

        if ($saved) {
            $update_app_sql = "
                UPDATE applications
                SET status = 'interview',
                    recruiter_remarks = 'Interview scheduled.'
                WHERE id = ?
            ";

            $update_app_stmt = mysqli_prepare($conn, $update_app_sql);

            if ($update_app_stmt) {
                mysqli_stmt_bind_param($update_app_stmt, "i", $application_id);
                mysqli_stmt_execute($update_app_stmt);
            }

            create_notification(
                $conn,
                $applicant_id,
                "Interview Scheduled",
                "Your interview for " . $job_title . " is scheduled on " . date("F d, Y", strtotime($interview_date)) . " at " . date("h:i A", strtotime($interview_time)) . " via " . $interview_mode . ".",
                "../applicant/interviews.php"
            );

            $success = "Interview scheduled successfully.";

            mysqli_stmt_execute($interview_stmt);
            $interview_result = mysqli_stmt_get_result($interview_stmt);
            $interview = mysqli_fetch_assoc($interview_result);

            $application['application_status'] = "interview";
        } else {
            $error = "Failed to schedule interview.";
        }
    }
}

function interview_status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "scheduled") {
        return "scheduled";
    }

    if ($status === "completed") {
        return "completed";
    }

    if ($status === "cancelled") {
        return "cancelled";
    }

    return "pending";
}

function format_date_text($date) {
    if (empty($date)) {
        return "Not set";
    }

    return date("F d, Y", strtotime($date));
}

function format_time_text($time) {
    if (empty($time)) {
        return "Not set";
    }

    return date("h:i A", strtotime($time));
}

$form_date = $interview['interview_date'] ?? "";
$form_time = $interview['interview_time'] ?? "";
$form_mode = $interview['interview_mode'] ?? "";
$form_location = $interview['interview_location'] ?? "";
$form_message = $interview['message'] ?? "";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Schedule Interview | Job Application System</title>
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
        .panel,
        .info-box,
        .current-box {
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
        .info-box::before,
        .current-box::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .panel > *,
        .info-box > *,
        .current-box > * {
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
            grid-template-columns: 0.85fr 1.15fr;
            gap: 18px;
            animation: fadeUp 0.58s ease both;
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
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
            line-height: 1.6;
        }

        .info-box {
            border-radius: 17px;
            padding: 15px;
            margin-bottom: 12px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .info-box:hover,
        .current-box:hover {
            transform: translateY(-4px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .info-box strong {
            display: block;
            margin-bottom: 6px;
            color: #eef5ff;
            font-size: 13px;
            font-weight: 900;
        }

        .info-box span {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
            word-break: break-word;
        }

        .status {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            text-transform: capitalize;
            border: 1px solid transparent;
        }

        .status.scheduled {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .status.cancelled {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .status.completed {
            background: rgba(255, 255, 255, 0.10);
            color: #e7edff;
            border-color: rgba(255, 255, 255, 0.14);
        }

        .status.pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 900;
            color: #eef5ff;
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
        textarea::placeholder { color: rgba(220, 231, 255, 0.6); }

        select option {
            color: #101827;
            background: #ffffff;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
            line-height: 1.6;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .form-group { margin-bottom: 16px; }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group.full { grid-column: span 2; }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 14px;
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

        .btn-warning {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .btn-danger {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .btn-success {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
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

        .current-box {
            border-radius: 18px;
            padding: 17px;
            margin-bottom: 18px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .current-box p {
            margin: 8px 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .current-box strong {
            color: #eef5ff;
            font-weight: 900;
        }

        @media (max-width: 1050px) {
            .grid,
            .form-grid { grid-template-columns: 1fr; }

            .form-group.full { grid-column: span 1; }
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
                <h1>Schedule Interview</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Schedule, cancel, or complete an interview.</p>
            </div>

            <div class="user-pill">Recruiter</div>
        </div>

        <?php if (!empty($success)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <div class="grid">
            <div class="panel">
                <h2>Applicant Details</h2>
                <p class="panel-subtitle">Review the applicant details before scheduling the interview.</p>

                <div class="info-box">
                    <strong>Applicant Name</strong>
                    <span><?php echo htmlspecialchars($application['applicant_name']); ?></span>
                </div>

                <div class="info-box">
                    <strong>Email</strong>
                    <span><?php echo htmlspecialchars($application['applicant_email']); ?></span>
                </div>

                <div class="info-box">
                    <strong>Phone</strong>
                    <span><?php echo htmlspecialchars($application['phone'] ?? 'Not provided'); ?></span>
                </div>

                <div class="info-box">
                    <strong>Job Title</strong>
                    <span><?php echo htmlspecialchars($application['job_title']); ?></span>
                </div>

                <div class="info-box">
                    <strong>Application Status</strong>
                    <span><?php echo htmlspecialchars(ucwords($application['application_status'])); ?></span>
                </div>

                <div class="info-box">
                    <strong>Applied At</strong>
                    <span><?php echo htmlspecialchars($application['applied_at']); ?></span>
                </div>

                <div class="actions">
                    <?php if (!empty($application['resume_file'])) { ?>
                        <a href="../assets/uploads/resumes/<?php echo htmlspecialchars($application['resume_file']); ?>" target="_blank" class="btn btn-light">
                            View Resume
                        </a>
                    <?php } ?>

                    <a href="view_applicant.php?application_id=<?php echo $application_id; ?>" class="btn btn-primary">
                        View Full Profile
                    </a>

                    <a href="messages.php?application_id=<?php echo $application_id; ?>" class="btn btn-light">
                        Message Applicant
                    </a>
                </div>
            </div>

            <div class="panel">
                <h2>Interview Information</h2>
                <p class="panel-subtitle">
                    Add interview details. The applicant will receive a notification after scheduling.
                </p>

                <?php if ($interview) { ?>
                    <?php $status_css = interview_status_class($interview['status']); ?>

                    <div class="current-box">
                        <strong>Current Interview Status:</strong>
                        <span class="status <?php echo htmlspecialchars($status_css); ?>">
                            <?php echo htmlspecialchars(ucwords($interview['status'])); ?>
                        </span>

                        <p><strong>Date:</strong> <?php echo htmlspecialchars(format_date_text($interview['interview_date'])); ?></p>
                        <p><strong>Time:</strong> <?php echo htmlspecialchars(format_time_text($interview['interview_time'])); ?></p>
                        <p><strong>Mode:</strong> <?php echo htmlspecialchars($interview['interview_mode']); ?></p>
                        <p><strong>Location / Link:</strong> <?php echo htmlspecialchars($interview['interview_location'] ?? 'Not provided'); ?></p>
                        <p><strong>Message:</strong> <?php echo !empty($interview['message']) ? nl2br(htmlspecialchars($interview['message'])) : 'No message provided.'; ?></p>

                        <div class="actions">
                            <?php if ($interview['status'] === 'scheduled') { ?>
                                <a href="schedule_interview.php?application_id=<?php echo $application_id; ?>&complete=<?php echo $interview['id']; ?>" 
                                   class="btn btn-success"
                                   onclick="return confirm('Mark this interview as completed?');">
                                    Mark as Completed
                                </a>

                                <a href="schedule_interview.php?application_id=<?php echo $application_id; ?>&cancel=<?php echo $interview['id']; ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('Cancel this interview?');">
                                    Cancel Interview
                                </a>
                            <?php } elseif ($interview['status'] === 'cancelled') { ?>
                                <span class="btn btn-warning">Cancelled Interview</span>
                            <?php } elseif ($interview['status'] === 'completed') { ?>
                                <span class="btn btn-light">Completed Interview</span>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>

                <form method="POST">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Interview Date</label>
                            <input 
                                type="date" 
                                name="interview_date" 
                                value="<?php echo htmlspecialchars($form_date); ?>" 
                                min="<?php echo date('Y-m-d'); ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Interview Time</label>
                            <input 
                                type="time" 
                                name="interview_time" 
                                value="<?php echo htmlspecialchars($form_time); ?>" 
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label>Interview Mode</label>
                            <select name="interview_mode" required>
                                <option value="">Select mode</option>
                                <option value="Online" <?php if ($form_mode === 'Online') echo 'selected'; ?>>Online</option>
                                <option value="Face to Face" <?php if ($form_mode === 'Face to Face') echo 'selected'; ?>>Face to Face</option>
                                <option value="Phone Call" <?php if ($form_mode === 'Phone Call') echo 'selected'; ?>>Phone Call</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Location / Meeting Link</label>
                            <input 
                                type="text" 
                                name="interview_location" 
                                value="<?php echo htmlspecialchars($form_location); ?>" 
                                placeholder="Office address or meeting link"
                            >
                        </div>

                        <div class="form-group full">
                            <label>Recruiter Message</label>
                            <textarea name="message" placeholder="Add instructions or reminders for the applicant..."><?php echo htmlspecialchars($form_message); ?></textarea>
                        </div>
                    </div>

                    <div class="actions">
                        <button type="submit" name="schedule_interview" class="btn btn-primary">
                            <?php echo $interview ? "Update / Reschedule Interview" : "Schedule Interview"; ?>
                        </button>

                        <a href="applicants.php" class="btn btn-light">Back to Applicants</a>
                    </div>
                </form>
            </div>
        </div>
    </main>
</div>

</body>
</html>