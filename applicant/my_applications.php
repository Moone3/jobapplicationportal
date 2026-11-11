<?php
// applicant/my_applications.php
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

$message = "";
$error = "";

if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

if (isset($_GET['error'])) {
    $error = trim($_GET['error']);
}

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

function status_class($status) {
    $status_lower = strtolower(trim($status));

    if (in_array($status_lower, ['accepted', 'candidate accepted offer'])) {
        return 'accepted';
    }

    if (in_array($status_lower, ['rejected', 'rejected by client', 'declined by client', 'candidate declined offer'])) {
        return 'rejected';
    }

    if (in_array($status_lower, ['schedule interview', 'interview'])) {
        return 'interview';
    }

    if ($status_lower === 'withdrawn') {
        return 'withdrawn';
    }

    return 'pending';
}

function can_withdraw($status) {
    $status_lower = strtolower(trim($status));
    return in_array($status_lower, ['pending', 'application received']);
}

function is_accepted_status($status) {
    $status_lower = strtolower(trim($status));
    return in_array($status_lower, ['accepted', 'candidate accepted offer']);
}

function is_rejected_status($status) {
    $status_lower = strtolower(trim($status));
    return in_array($status_lower, ['rejected', 'rejected by client', 'declined by client', 'candidate declined offer']);
}

function is_interview_status($status) {
    $status_lower = strtolower(trim($status));
    return in_array($status_lower, ['schedule interview', 'interview']);
}

function readable_status($status) {
    return ucwords(strtolower(trim($status)));
}

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

// Withdraw application
if (isset($_GET['withdraw'])) {
    $application_id = intval($_GET['withdraw']);

    $check_sql = "
        SELECT 
            applications.id,
            applications.status,
            applications.applicant_id,
            jobs.id AS job_id,
            jobs.title AS job_title,
            jobs.recruiter_id
        FROM applications
        JOIN jobs ON applications.job_id = jobs.id
        WHERE applications.id = ?
        AND applications.applicant_id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if (!$check_stmt) {
        $error = "Database prepare failed: " . mysqli_error($conn);
    } else {
        mysqli_stmt_bind_param($check_stmt, "ii", $application_id, $applicant_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $application = mysqli_fetch_assoc($check_result);
            $current_status = strtolower(trim($application['status']));

            if (in_array($current_status, ['pending', 'application received'])) {
                $update_sql = "
                    UPDATE applications
                    SET status = 'withdrawn'
                    WHERE id = ?
                    AND applicant_id = ?
                ";

                $update_stmt = mysqli_prepare($conn, $update_sql);

                if (!$update_stmt) {
                    $error = "Database prepare failed: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param($update_stmt, "ii", $application_id, $applicant_id);

                    if (mysqli_stmt_execute($update_stmt)) {
                        create_notification(
                            $conn,
                            $application['recruiter_id'],
                            "Application Withdrawn",
                            $full_name . " withdrew their application for " . $application['job_title'] . ".",
                            "../recruiter/applicants.php"
                        );

                        header("Location: my_applications.php?message=Application withdrawn successfully.");
                        exit();
                    } else {
                        $error = "Failed to withdraw application.";
                    }
                }
            } else {
                $error = "You can only withdraw applications that are still pending.";
            }
        } else {
            $error = "Application not found.";
        }
    }
}

// Get applications
$sql = "
    SELECT 
        applications.*,
        jobs.id AS job_id,
        jobs.title AS job_title,
        jobs.location,
        jobs.job_type,
        jobs.salary_min,
        jobs.salary_max,
        jobs.expiry_date,
        users.full_name AS recruiter_name,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo,
        job_categories.category_name
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON jobs.recruiter_id = recruiter_profiles.user_id
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    WHERE applications.applicant_id = ?
    ORDER BY applications.applied_at DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $applicant_id);
mysqli_stmt_execute($stmt);
$applications = mysqli_stmt_get_result($stmt);

// Counts
$total_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE applicant_id = '$applicant_id'
");

$pending_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE applicant_id = '$applicant_id'
    AND LOWER(status) IN (
        'pending',
        'application received',
        'application review complete',
        'phone screen complete',
        'submit resume for client feedback',
        'submit to client',
        'send offer to candidate'
    )
");

$accepted_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE applicant_id = '$applicant_id'
    AND LOWER(status) IN ('accepted', 'candidate accepted offer')
");

$rejected_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE applicant_id = '$applicant_id'
    AND LOWER(status) IN ('rejected', 'rejected by client', 'declined by client', 'candidate declined offer')
");

$interview_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE applicant_id = '$applicant_id'
    AND LOWER(status) IN ('schedule interview', 'interview')
");

$withdrawn_applications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM applications 
    WHERE applicant_id = '$applicant_id'
    AND LOWER(status) = 'withdrawn'
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>My Applications | Job Application System</title>
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

        a {
            color: inherit;
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
        .card,
        .panel,
        .application-card,
        .meta-box,
        .remarks-box,
        .final-message {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.075);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .topbar::before,
        .card::before,
        .panel::before,
        .application-card::before,
        .meta-box::before,
        .remarks-box::before,
        .final-message::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .card > *,
        .panel > *,
        .application-card > *,
        .meta-box > *,
        .remarks-box > *,
        .final-message > * {
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

        .cards {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 14px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 24px;
            padding: 20px;
            min-height: 112px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.52s ease both;
        }

        .card:hover,
        .application-card:hover,
        .meta-box:hover {
            transform: translateY(-5px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .card-title {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .card-number {
            font-size: 32px;
            font-weight: 950;
            color: var(--cyan);
            margin: 0;
            text-shadow: 0 0 22px rgba(18, 215, 255, 0.28);
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            animation: fadeUp 0.64s ease both;
        }

        .application-card {
            border-radius: 24px;
            padding: 22px;
            margin-bottom: 18px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .application-card.pending {
            border-color: rgba(255, 180, 84, 0.30);
            background: linear-gradient(135deg, rgba(255, 180, 84, 0.10), rgba(255, 255, 255, 0.05));
        }

        .application-card.accepted {
            border-color: rgba(38, 224, 168, 0.26);
            background: linear-gradient(135deg, rgba(38, 224, 168, 0.10), rgba(255, 255, 255, 0.05));
        }

        .application-card.rejected {
            border-color: rgba(255, 59, 95, 0.28);
            background: linear-gradient(135deg, rgba(255, 59, 95, 0.10), rgba(255, 255, 255, 0.05));
        }

        .application-card.interview {
            border-color: rgba(18, 215, 255, 0.30);
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.10), rgba(255, 255, 255, 0.05));
        }

        .application-card.withdrawn {
            border-color: rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.06);
        }

        .application-header {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .job-title {
            margin: 0;
            color: var(--text);
            font-size: 23px;
            letter-spacing: -0.3px;
        }

        .company {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
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
            white-space: nowrap;
            border: 1px solid transparent;
        }

        .status.pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .status.accepted {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .status.rejected {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .status.interview {
            background: rgba(18, 215, 255, 0.13);
            color: #bff5ff;
            border-color: rgba(18, 215, 255, 0.27);
        }

        .status.withdrawn {
            background: rgba(255, 255, 255, 0.10);
            color: #dce7ff;
            border-color: rgba(255, 255, 255, 0.16);
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 12px;
        }

        .meta-box {
            border-radius: 17px;
            padding: 15px;
            min-height: 82px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .meta-box strong,
        .remarks-box strong {
            display: block;
            margin-bottom: 8px;
            color: #eef5ff;
            font-size: 13px;
            font-weight: 900;
        }

        .meta-box span {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
            word-break: break-word;
        }

        .remarks-box {
            border-radius: 17px;
            padding: 16px;
            margin-top: 14px;
            color: var(--muted);
            line-height: 1.75;
            font-size: 14px;
        }

        .final-message {
            margin-top: 14px;
            border-radius: 17px;
            padding: 15px;
            font-size: 14px;
            font-weight: 850;
            line-height: 1.6;
        }

        .final-message.accepted {
            background: rgba(38, 224, 168, 0.10);
            color: #c6ffe8;
            border-color: rgba(38, 224, 168, 0.24);
        }

        .final-message.rejected {
            background: rgba(255, 59, 95, 0.11);
            color: #ffd3dc;
            border-color: rgba(255, 59, 95, 0.24);
        }

        .final-message.interview {
            background: rgba(18, 215, 255, 0.10);
            color: #bff5ff;
            border-color: rgba(18, 215, 255, 0.24);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 18px;
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

        .btn-danger {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .btn-danger:hover {
            background: rgba(255, 59, 95, 0.20);
            box-shadow: 0 14px 30px rgba(255, 59, 95, 0.12);
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

        .empty {
            color: var(--muted);
            font-size: 15px;
            padding: 22px 0;
            line-height: 1.6;
        }

        .card:nth-child(2) { animation-delay: 0.05s; }
        .card:nth-child(3) { animation-delay: 0.10s; }
        .card:nth-child(4) { animation-delay: 0.15s; }
        .card:nth-child(5) { animation-delay: 0.20s; }
        .card:nth-child(6) { animation-delay: 0.25s; }

        @media (max-width: 1300px) {
            .cards {
                grid-template-columns: repeat(3, 1fr);
            }

            .meta-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 850px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .cards,
            .meta-grid {
                grid-template-columns: 1fr;
            }

            .application-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }

        @media (max-width: 750px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid var(--line);
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
                <h1>My Applications</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Track your application status, recruiter remarks, interviews, and messages.</p>
            </div>

            <div class="user-pill">Applicant</div>
        </div>

        <?php if (!empty($message)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total</p>
                <p class="card-number"><?php echo $total_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending</p>
                <p class="card-number"><?php echo $pending_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Interview</p>
                <p class="card-number"><?php echo $interview_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Accepted</p>
                <p class="card-number"><?php echo $accepted_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Rejected</p>
                <p class="card-number"><?php echo $rejected_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Withdrawn</p>
                <p class="card-number"><?php echo $withdrawn_applications; ?></p>
            </div>
        </section>

        <div class="panel">
            <?php if ($applications && mysqli_num_rows($applications) > 0) { ?>
                <?php while ($row = mysqli_fetch_assoc($applications)) { ?>
                    <?php
                        $company_name = !empty($row['company_name']) ? $row['company_name'] : $row['recruiter_name'];
                        $status = $row['status'];
                        $status_css = status_class($status);
                        $accepted_status = is_accepted_status($status);
                        $rejected_status = is_rejected_status($status);
                        $interview_status = is_interview_status($status);

                        $resume_web_path = "";
                        if (!empty($row['resume_file'])) {
                            $resume_file_path = __DIR__ . "/../assets/uploads/resumes/" . $row['resume_file'];
                            if (file_exists($resume_file_path)) {
                                $resume_web_path = "../assets/uploads/resumes/" . $row['resume_file'];
                            }
                        }
                    ?>

                    <div class="application-card <?php echo htmlspecialchars($status_css); ?>">
                        <div class="application-header">
                            <div>
                                <h2 class="job-title"><?php echo htmlspecialchars($row['job_title']); ?></h2>
                                <p class="company">
                                    Recruiter: <?php echo htmlspecialchars($row['recruiter_name']); ?><br>
                                    Company: <?php echo htmlspecialchars($company_name); ?>
                                </p>
                            </div>

                            <span class="status <?php echo htmlspecialchars($status_css); ?>">
                                <?php echo htmlspecialchars(readable_status($status)); ?>
                            </span>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <strong>Location</strong>
                                <span><?php echo htmlspecialchars($row['location']); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Job Type</strong>
                                <span><?php echo htmlspecialchars($row['job_type']); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Category</strong>
                                <span><?php echo htmlspecialchars($row['category_name'] ?? 'Uncategorized'); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Salary Range</strong>
                                <span>
                                    ₱<?php echo number_format(floatval($row['salary_min'] ?? 0), 2); ?>
                                    -
                                    ₱<?php echo number_format(floatval($row['salary_max'] ?? 0), 2); ?>
                                </span>
                            </div>

                            <div class="meta-box">
                                <strong>Applied At</strong>
                                <span><?php echo htmlspecialchars($row['applied_at']); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Job Expiry</strong>
                                <span><?php echo htmlspecialchars($row['expiry_date']); ?></span>
                            </div>
                        </div>

                        <?php if (!empty($row['recruiter_remarks'])) { ?>
                            <div class="remarks-box">
                                <strong>Recruiter Remarks:</strong><br>
                                <?php echo nl2br(htmlspecialchars($row['recruiter_remarks'])); ?>
                            </div>
                        <?php } ?>

                        <?php if ($accepted_status) { ?>
                            <div class="final-message accepted">
                                Congratulations! Your application for this job has been accepted.
                            </div>
                        <?php } ?>

                        <?php if ($rejected_status) { ?>
                            <div class="final-message rejected">
                                Your application for this job has been rejected.
                            </div>
                        <?php } ?>

                        <?php if ($interview_status) { ?>
                            <div class="final-message interview">
                                You are scheduled for an interview. Please check the Interviews page for the date, time, mode, and location/link.
                            </div>
                        <?php } ?>

                        <div class="actions">
                            <a href="../job_details.php?job_id=<?php echo intval($row['job_id']); ?>" class="btn btn-light">
                                View Job
                            </a>

                            <a href="messages.php?application_id=<?php echo intval($row['id']); ?>" class="btn btn-primary">
                                Message Recruiter
                            </a>

                            <?php if ($interview_status) { ?>
                                <a href="interviews.php" class="btn btn-light">
                                    View Interview
                                </a>
                            <?php } ?>

                            <?php if (!empty($resume_web_path)) { ?>
                                <a href="<?php echo htmlspecialchars($resume_web_path); ?>" target="_blank" class="btn btn-light">
                                    View Resume
                                </a>
                            <?php } ?>

                            <?php if (can_withdraw($row['status'])) { ?>
                                <a href="my_applications.php?withdraw=<?php echo intval($row['id']); ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('Are you sure you want to withdraw this application?');">
                                    Withdraw Application
                                </a>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <p class="empty">You have not submitted any applications yet.</p>
                <a href="jobs.php" class="btn btn-primary">Browse Jobs</a>
            <?php } ?>
        </div>
    </main>
</div>

</body>
</html>