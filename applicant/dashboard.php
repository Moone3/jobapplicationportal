<?php
// applicant/dashboard.php
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

function status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "accepted" || $status === "candidate accepted offer") {
        return "accepted";
    }

    if (
        $status === "rejected" ||
        $status === "rejected by client" ||
        $status === "declined by client" ||
        $status === "candidate declined offer"
    ) {
        return "rejected";
    }

    if ($status === "interview" || $status === "schedule interview") {
        return "interview";
    }

    return "pending";
}

$applicant_profile_picture = "";

// Get applicant profile picture/name
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

// Dashboard counts
$available_jobs = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM jobs
    WHERE status = 'approved'
    AND is_archived = 0
    AND expiry_date >= CURDATE()
");

$total_applications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    WHERE applicant_id = '$applicant_id'
");

$pending_applications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    WHERE applicant_id = '$applicant_id'
    AND LOWER(status) IN ('pending', 'application received')
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
    AND LOWER(status) IN ('interview', 'schedule interview')
");

$saved_jobs = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM saved_jobs
    WHERE applicant_id = '$applicant_id'
");

$upcoming_interviews_count = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM interviews
    WHERE applicant_id = '$applicant_id'
    AND status = 'scheduled'
    AND interview_date >= CURDATE()
");

// Recent applications
$recent_applications = mysqli_query($conn, "
    SELECT 
        applications.id AS application_id,
        applications.status,
        applications.applied_at,
        jobs.title AS job_title,
        jobs.location,
        jobs.job_type,
        users.full_name AS recruiter_name,
        recruiter_profiles.company_name
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON jobs.recruiter_id = recruiter_profiles.user_id
    WHERE applications.applicant_id = '$applicant_id'
    ORDER BY applications.applied_at DESC
    LIMIT 6
");

// Latest available jobs
$latest_jobs = mysqli_query($conn, "
    SELECT 
        jobs.id,
        jobs.title,
        jobs.location,
        jobs.job_type,
        jobs.salary_min,
        jobs.salary_max,
        jobs.expiry_date,
        job_categories.category_name,
        users.full_name AS recruiter_name,
        recruiter_profiles.company_name
    FROM jobs
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON jobs.recruiter_id = recruiter_profiles.user_id
    WHERE jobs.status = 'approved'
    AND jobs.is_archived = 0
    AND jobs.expiry_date >= CURDATE()
    ORDER BY jobs.created_at DESC
    LIMIT 6
");

// Upcoming interviews
$upcoming_interviews = mysqli_query($conn, "
    SELECT 
        interviews.id,
        interviews.interview_date,
        interviews.interview_time,
        interviews.interview_mode,
        interviews.interview_location,
        interviews.status,
        jobs.title AS job_title,
        recruiter_profiles.company_name,
        users.full_name AS recruiter_name
    FROM interviews
    JOIN applications ON interviews.application_id = applications.id
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON jobs.recruiter_id = recruiter_profiles.user_id
    WHERE interviews.applicant_id = '$applicant_id'
    ORDER BY interviews.interview_date ASC, interviews.interview_time ASC
    LIMIT 6
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Applicant Dashboard | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --bg-deep: #0d1630;
            --panel: rgba(15, 23, 42, 0.72);
            --panel-strong: rgba(15, 23, 42, 0.86);
            --glass: rgba(255, 255, 255, 0.09);
            --glass-strong: rgba(255, 255, 255, 0.13);
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
            --shadow: 0 28px 90px rgba(0, 0, 0, 0.42);
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
            animation: floatGlow 8s ease-in-out infinite alternate;
            z-index: 0;
        }

        body::before {
            left: -160px;
            top: -135px;
            background: var(--cyan);
        }

        body::after {
            right: -160px;
            bottom: -150px;
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
            background: rgba(255, 255, 255, 0.12);
        }

        .main {
            margin-left: 275px;
            width: calc(100% - 275px);
            padding: 30px;
        }

        .topbar,
        .quick-actions,
        .card,
        .panel {
            position: relative;
            background: rgba(255, 255, 255, 0.075);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            overflow: hidden;
            animation: fadeUp 0.65s ease both;
        }

        .topbar::before,
        .quick-actions::before,
        .card::before,
        .panel::before {
            content: "";
            position: absolute;
            inset: 0;
            padding: 1px;
            border-radius: inherit;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.55), rgba(139, 92, 246, 0.18), rgba(255, 62, 165, 0.36));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
            opacity: 0.58;
        }

        .topbar {
            border-radius: 28px;
            padding: 26px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            margin-bottom: 22px;
        }

        .topbar h1 {
            margin: 0;
            font-size: clamp(26px, 2.5vw, 36px);
            color: var(--text);
            letter-spacing: -0.7px;
        }

        .topbar p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .user-pill {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(18, 215, 255, 0.24);
            padding: 11px 16px;
            border-radius: 999px;
            color: var(--text);
            font-size: 14px;
            font-weight: 900;
            box-shadow: 0 0 26px rgba(18, 215, 255, 0.12);
        }

        .quick-actions {
            border-radius: 26px;
            padding: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            animation-delay: 0.05s;
        }

        .btn {
            position: relative;
            padding: 12px 17px;
            border-radius: 14px;
            text-decoration: none;
            border: none;
            font-size: 14px;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: transform 0.22s ease, box-shadow 0.22s ease, background 0.22s ease, border-color 0.22s ease;
        }

        .btn:hover {
            transform: translateY(-3px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--cyan-2), #2356e8);
            color: #ffffff;
            box-shadow: 0 16px 34px rgba(18, 215, 255, 0.20);
        }

        .btn-primary:hover {
            box-shadow: 0 20px 42px rgba(18, 215, 255, 0.28);
        }

        .btn-light {
            background: rgba(255, 255, 255, 0.08);
            color: var(--soft);
            border: 1px solid rgba(255, 255, 255, 0.13);
        }

        .btn-light:hover {
            background: rgba(255, 255, 255, 0.13);
            border-color: rgba(18, 215, 255, 0.28);
            box-shadow: 0 14px 30px rgba(0, 0, 0, 0.20);
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 26px;
            padding: 22px;
            min-height: 125px;
            transition: transform 0.25s ease, box-shadow 0.25s ease, border-color 0.25s ease;
        }

        .card::after {
            content: "";
            position: absolute;
            right: -38px;
            top: -45px;
            width: 120px;
            height: 120px;
            border-radius: 999px;
            background: radial-gradient(circle, rgba(18, 215, 255, 0.20), transparent 65%);
            pointer-events: none;
        }

        .card:hover {
            transform: translateY(-6px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .card-title {
            position: relative;
            z-index: 1;
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .card-number {
            position: relative;
            z-index: 1;
            font-size: 34px;
            font-weight: 950;
            color: var(--cyan);
            margin: 0;
            letter-spacing: -0.7px;
            text-shadow: 0 0 22px rgba(18, 215, 255, 0.28);
            text-shadow: 0 0 24px rgba(18, 215, 255, 0.35);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .panel {
            border-radius: 28px;
            padding: 23px;
            animation-delay: 0.08s;
        }

        .panel.full {
            grid-column: span 2;
        }

        .panel-header {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel h2 {
            margin: 0;
            font-size: 21px;
            color: var(--text);
            letter-spacing: -0.25px;
        }

        .small-link {
            color: var(--cyan);
            text-decoration: none;
            font-size: 14px;
            font-weight: 900;
            transition: color 0.2s ease, text-shadow 0.2s ease;
        }

        .small-link:hover {
            color: #ffffff;
            text-shadow: 0 0 18px rgba(18, 215, 255, 0.55);
        }

        table {
            position: relative;
            z-index: 1;
            width: 100%;
            border-collapse: collapse;
        }

        th {
            text-align: left;
            padding: 13px 11px;
            color: rgba(248, 251, 255, 0.66);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
        }

        td {
            padding: 14px 11px;
            font-size: 14px;
            color: var(--soft);
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
            vertical-align: middle;
        }

        tbody tr {
            transition: background 0.2s ease;
        }

        tbody tr:hover {
            background: rgba(255, 255, 255, 0.045);
        }

        small {
            color: var(--muted);
            font-size: 12px;
            line-height: 1.6;
        }

        .status {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            text-transform: capitalize;
            white-space: nowrap;
            border: 1px solid rgba(255, 255, 255, 0.12);
        }

        .status.pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffd294;
            border-color: rgba(255, 180, 84, 0.24);
        }

        .status.accepted {
            background: rgba(38, 224, 168, 0.14);
            color: #81ffd7;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .status.rejected {
            background: rgba(255, 59, 95, 0.14);
            color: #ff9bad;
            border-color: rgba(255, 59, 95, 0.25);
        }

        .status.interview {
            background: rgba(18, 215, 255, 0.13);
            color: #8df0ff;
            border-color: rgba(18, 215, 255, 0.27);
        }

        .job-list {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .job-card {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 22px;
            padding: 18px;
            transition: transform 0.23s ease, background 0.23s ease, border-color 0.23s ease, box-shadow 0.23s ease;
        }

        .job-card:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.10);
            border-color: rgba(18, 215, 255, 0.28);
            box-shadow: 0 18px 40px rgba(0, 0, 0, 0.24);
        }

        .job-card h3 {
            margin: 0 0 9px;
            color: var(--text);
            font-size: 18px;
            letter-spacing: -0.2px;
        }

        .job-card p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
        }

        .job-actions {
            margin-top: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .empty {
            position: relative;
            z-index: 1;
            color: var(--muted);
            font-size: 14px;
            padding: 18px 0;
        }

        @media (max-width: 1250px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .content-grid {
                grid-template-columns: 1fr;
            }

            .panel.full {
                grid-column: span 1;
            }

            .job-list {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 780px) {
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

            .layout {
                flex-direction: column;
            }

            .main {
                margin-left: 0;
                width: 100%;
                padding: 18px;
            }

            .topbar,
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .quick-actions .btn {
                width: 100%;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
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
                <h1>Applicant Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($full_name); ?>. Track your applications, interviews, saved jobs, messages, and new job opportunities.</p>
            </div>

            <div class="user-pill">Applicant</div>
        </div>

        <div class="quick-actions">
            <a href="jobs.php" class="btn btn-primary">Browse Jobs</a>
            <a href="my_applications.php" class="btn btn-light">View Applications</a>
            <a href="messages.php" class="btn btn-light">Messenger</a>
            <a href="saved_jobs.php" class="btn btn-light">Saved Jobs</a>
            <a href="interviews.php" class="btn btn-light">Interviews</a>
        </div>

        <section class="cards">
            <div class="card">
                <p class="card-title">Available Jobs</p>
                <p class="card-number"><?php echo $available_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Total Applications</p>
                <p class="card-number"><?php echo $total_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending Applications</p>
                <p class="card-number"><?php echo $pending_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Accepted Applications</p>
                <p class="card-number"><?php echo $accepted_applications; ?></p>
            </div>
        </section>

        <section class="cards">
            <div class="card">
                <p class="card-title">Rejected Applications</p>
                <p class="card-number"><?php echo $rejected_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">For Interview</p>
                <p class="card-number"><?php echo $interview_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Saved Jobs</p>
                <p class="card-number"><?php echo $saved_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Unread Messages</p>
                <p class="card-number"><?php echo $unread_messages; ?></p>
            </div>
        </section>

        <section class="content-grid">
            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Applications</h2>
                    <a href="my_applications.php" class="small-link">View all</a>
                </div>

                <?php if ($recent_applications && mysqli_num_rows($recent_applications) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Company</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($app = mysqli_fetch_assoc($recent_applications)) { ?>
                                <?php
                                    $company = !empty($app['company_name']) ? $app['company_name'] : $app['recruiter_name'];
                                    $status_css = status_class($app['status']);
                                ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($app['job_title']); ?><br>
                                        <small><?php echo htmlspecialchars($app['applied_at']); ?></small>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($company); ?><br>
                                        <small><?php echo htmlspecialchars($app['location']); ?> · <?php echo htmlspecialchars($app['job_type']); ?></small>
                                    </td>

                                    <td>
                                        <span class="status <?php echo htmlspecialchars($status_css); ?>">
                                            <?php echo htmlspecialchars($app['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No applications yet.</p>
                    <a href="jobs.php" class="btn btn-primary">Browse Jobs</a>
                <?php } ?>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2>Upcoming Interviews</h2>
                    <a href="interviews.php" class="small-link">View all</a>
                </div>

                <?php if ($upcoming_interviews && mysqli_num_rows($upcoming_interviews) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Date</th>
                                <th>Mode</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($interview = mysqli_fetch_assoc($upcoming_interviews)) { ?>
                                <?php $company = !empty($interview['company_name']) ? $interview['company_name'] : $interview['recruiter_name']; ?>

                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($interview['job_title']); ?><br>
                                        <small><?php echo htmlspecialchars($company); ?></small>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($interview['interview_date']); ?><br>
                                        <small><?php echo htmlspecialchars($interview['interview_time']); ?></small>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($interview['interview_mode']); ?><br>
                                        <small><?php echo htmlspecialchars($interview['status']); ?></small>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No upcoming interviews yet.</p>
                <?php } ?>
            </div>

            <div class="panel full">
                <div class="panel-header">
                    <h2>Latest Job Opportunities</h2>
                    <a href="jobs.php" class="small-link">Browse all</a>
                </div>

                <?php if ($latest_jobs && mysqli_num_rows($latest_jobs) > 0) { ?>
                    <div class="job-list">
                        <?php while ($job = mysqli_fetch_assoc($latest_jobs)) { ?>
                            <?php $company = !empty($job['company_name']) ? $job['company_name'] : $job['recruiter_name']; ?>

                            <div class="job-card">
                                <h3><?php echo htmlspecialchars($job['title']); ?></h3>

                                <p>
                                    Company: <?php echo htmlspecialchars($company); ?><br>
                                    Category: <?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?><br>
                                    Location: <?php echo htmlspecialchars($job['location']); ?> · <?php echo htmlspecialchars($job['job_type']); ?><br>
                                    Salary: ₱<?php echo number_format(floatval($job['salary_min'] ?? 0), 2); ?> -
                                    ₱<?php echo number_format(floatval($job['salary_max'] ?? 0), 2); ?><br>
                                    Expiry: <?php echo htmlspecialchars($job['expiry_date']); ?>
                                </p>

                                <div class="job-actions">
                                    <a href="../job_details.php?job_id=<?php echo intval($job['id']); ?>" class="btn btn-light">
                                        View Details
                                    </a>

                                    <a href="apply_job.php?job_id=<?php echo intval($job['id']); ?>" class="btn btn-primary">
                                        Apply Now
                                    </a>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <p class="empty">No available jobs right now.</p>
                <?php } ?>
            </div>
        </section>
    </main>
</div>

</body>
</html>