<?php
// recruiter/dashboard.php
include "../includes/auth_check.php";
include "../config/database.php";

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

$company_name = "Job Portal";
$recruiter_profile_picture = "";
$sidebar_initial = "R";

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

    if ($status === "approved" || $status === "accepted" || $status === "candidate accepted offer") {
        return "accepted";
    }

    if ($status === "rejected" || $status === "rejected by client" || $status === "declined by client" || $status === "candidate declined offer") {
        return "rejected";
    }

    if ($status === "interview" || $status === "schedule interview" || $status === "scheduled") {
        return "interview";
    }

    if ($status === "archived" || $status === "expired") {
        return "archived";
    }

    return "pending";
}

// Recruiter profile/company info
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

// Dashboard counts
$total_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
");

$pending_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND status = 'pending'
");

$approved_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND status = 'approved'
");

$rejected_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND status = 'rejected'
");

$archived_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND is_archived = 1
");

$active_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND status = 'approved'
    AND is_archived = 0
    AND expiry_date >= CURDATE()
");

$expired_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND expiry_date < CURDATE()
");

$total_applicants = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
");

$pending_applications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    AND LOWER(applications.status) IN ('pending', 'application received')
");

$accepted_applications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    AND LOWER(applications.status) IN ('accepted', 'candidate accepted offer')
");

$rejected_applications = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    AND LOWER(applications.status) IN ('rejected', 'rejected by client', 'declined by client')
");

$interviews = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM interviews
    JOIN applications ON interviews.application_id = applications.id
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
");

$total_views = get_count($conn, "
    SELECT COALESCE(SUM(view_count), 0) AS total
    FROM jobs
    WHERE recruiter_id = '$recruiter_id'
");

// Recent applicants
$recent_applicants = mysqli_query($conn, "
    SELECT 
        applications.id AS application_id,
        applications.status,
        applications.applied_at,
        jobs.title AS job_title,
        users.full_name AS applicant_name,
        users.email AS applicant_email
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON applications.applicant_id = users.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    ORDER BY applications.applied_at DESC
    LIMIT 6
");

// Recent jobs
$recent_jobs = mysqli_query($conn, "
    SELECT 
        jobs.id,
        jobs.title,
        jobs.status,
        jobs.location,
        jobs.job_type,
        jobs.expiry_date,
        jobs.is_archived,
        jobs.view_count,
        job_categories.category_name
    FROM jobs
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    WHERE jobs.recruiter_id = '$recruiter_id'
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
        interviews.status,
        jobs.title AS job_title,
        users.full_name AS applicant_name
    FROM interviews
    JOIN applications ON interviews.application_id = applications.id
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON applications.applicant_id = users.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    ORDER BY interviews.interview_date ASC, interviews.interview_time ASC
    LIMIT 6
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Recruiter Dashboard | Job Application System</title>
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

        a { color: inherit; }

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
        .quick-actions,
        .card,
        .panel,
        .job-card {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.075);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .topbar::before,
        .quick-actions::before,
        .card::before,
        .panel::before,
        .job-card::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .quick-actions > *,
        .card > *,
        .panel > *,
        .job-card > * {
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

        .quick-actions {
            border-radius: 26px;
            padding: 18px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
            animation: fadeUp 0.50s ease both;
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

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 24px;
            padding: 22px;
            min-height: 120px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.52s ease both;
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

        .card:hover,
        .job-card:hover {
            transform: translateY(-5px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .card-title {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 14px;
            font-weight: 800;
        }

        .card-number {
            font-size: 34px;
            font-weight: 950;
            color: var(--cyan);
            margin: 0;
            letter-spacing: -0.7px;
            text-shadow: 0 0 22px rgba(18, 215, 255, 0.28);
        }

        .content-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .panel {
            border-radius: 28px;
            padding: 23px;
            animation: fadeUp 0.62s ease both;
        }

        .panel.full { grid-column: span 2; }

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

        tbody tr { transition: background 0.2s ease; }
        tbody tr:hover { background: rgba(255, 255, 255, 0.045); }

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
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .status.accepted {
            background: rgba(38, 224, 168, 0.14);
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
            color: #8df0ff;
            border-color: rgba(18, 215, 255, 0.27);
        }

        .status.archived {
            background: rgba(255, 255, 255, 0.10);
            color: #dce7ff;
            border-color: rgba(255, 255, 255, 0.16);
        }

        .job-list {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .job-card {
            border-radius: 22px;
            padding: 18px;
            transition: transform 0.23s ease, background 0.23s ease, border-color 0.23s ease, box-shadow 0.23s ease;
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
            .cards { grid-template-columns: repeat(2, 1fr); }
            .content-grid { grid-template-columns: 1fr; }
            .panel.full { grid-column: span 1; }
            .job-list { grid-template-columns: 1fr; }
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

            .layout { flex-direction: column; }

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

            .cards { grid-template-columns: 1fr; }
            .quick-actions .btn { width: 100%; }

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
            <?php if (!empty($recruiter_profile_picture)) { ?>
                <img src="<?php echo htmlspecialchars($recruiter_profile_picture); ?>" class="brand-img" alt="Recruiter Profile">
            <?php } else { ?>
                <div class="brand-icon"><?php echo htmlspecialchars($sidebar_initial); ?></div>
            <?php } ?>

            <div class="brand-text-wrap">
                <div class="brand-text">Job Portal</div>
                <div class="brand-subtext"><?php echo htmlspecialchars($company_name); ?></div>
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

        <a href="schedule_interview.php" class="nav-link <?php echo active_link('schedule_interview.php', $current_page); ?>">
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
                <h1>Recruiter Dashboard</h1>
                <p>Welcome back, <?php echo htmlspecialchars($full_name); ?>. Manage your jobs, applicants, interviews, and company activity.</p>
            </div>

            <div class="user-pill">Recruiter</div>
        </div>

        <div class="quick-actions">
            <a href="post_job.php" class="btn btn-primary">Post New Job</a>
            <a href="manage_jobs.php" class="btn btn-light">Manage Jobs</a>
            <a href="applicants.php" class="btn btn-light">View Applicants</a>
            <a href="schedule_interview.php" class="btn btn-light">Interviews</a>
            <a href="messages.php" class="btn btn-light">Messenger</a>
        </div>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Jobs</p>
                <p class="card-number"><?php echo $total_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Active Jobs</p>
                <p class="card-number"><?php echo $active_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending Jobs</p>
                <p class="card-number"><?php echo $pending_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Approved Jobs</p>
                <p class="card-number"><?php echo $approved_jobs; ?></p>
            </div>
        </section>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Applicants</p>
                <p class="card-number"><?php echo $total_applicants; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Accepted Applicants</p>
                <p class="card-number"><?php echo $accepted_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Interviews</p>
                <p class="card-number"><?php echo $interviews; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Profile Views</p>
                <p class="card-number"><?php echo $total_views; ?></p>
            </div>
        </section>

        <section class="content-grid">
            <div class="panel">
                <div class="panel-header">
                    <h2>Recent Applicants</h2>
                    <a href="applicants.php" class="small-link">View all</a>
                </div>

                <?php if ($recent_applicants && mysqli_num_rows($recent_applicants) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Job</th>
                                <th>Status</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($applicant = mysqli_fetch_assoc($recent_applicants)) { ?>
                                <?php $status_css = status_class($applicant['status']); ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($applicant['applicant_name']); ?><br>
                                        <small><?php echo htmlspecialchars($applicant['applicant_email']); ?></small>
                                    </td>

                                    <td>
                                        <?php echo htmlspecialchars($applicant['job_title']); ?><br>
                                        <small><?php echo htmlspecialchars($applicant['applied_at']); ?></small>
                                    </td>

                                    <td>
                                        <span class="status <?php echo htmlspecialchars($status_css); ?>">
                                            <?php echo htmlspecialchars($applicant['status']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                <?php } else { ?>
                    <p class="empty">No applicants yet.</p>
                <?php } ?>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2>Upcoming Interviews</h2>
                    <a href="schedule_interview.php" class="small-link">View all</a>
                </div>

                <?php if ($upcoming_interviews && mysqli_num_rows($upcoming_interviews) > 0) { ?>
                    <table>
                        <thead>
                            <tr>
                                <th>Applicant</th>
                                <th>Date</th>
                                <th>Mode</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php while ($interview = mysqli_fetch_assoc($upcoming_interviews)) { ?>
                                <tr>
                                    <td>
                                        <?php echo htmlspecialchars($interview['applicant_name']); ?><br>
                                        <small><?php echo htmlspecialchars($interview['job_title']); ?></small>
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
                    <p class="empty">No interviews scheduled yet.</p>
                <?php } ?>
            </div>

            <div class="panel full">
                <div class="panel-header">
                    <h2>Recent Job Posts</h2>
                    <a href="manage_jobs.php" class="small-link">Manage all</a>
                </div>

                <?php if ($recent_jobs && mysqli_num_rows($recent_jobs) > 0) { ?>
                    <div class="job-list">
                        <?php while ($job = mysqli_fetch_assoc($recent_jobs)) { ?>
                            <?php
                                $job_status = $job['status'];

                                if (!empty($job['is_archived'])) {
                                    $job_status = "archived";
                                } elseif (!empty($job['expiry_date']) && strtotime($job['expiry_date']) < strtotime(date('Y-m-d'))) {
                                    $job_status = "expired";
                                }

                                $job_status_css = status_class($job_status);
                            ?>

                            <div class="job-card">
                                <h3><?php echo htmlspecialchars($job['title']); ?></h3>

                                <p>
                                    Category: <?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?><br>
                                    Location: <?php echo htmlspecialchars($job['location']); ?> · <?php echo htmlspecialchars($job['job_type']); ?><br>
                                    Views: <?php echo intval($job['view_count']); ?> · Expiry: <?php echo htmlspecialchars($job['expiry_date']); ?><br>
                                    Status: <span class="status <?php echo htmlspecialchars($job_status_css); ?>"><?php echo htmlspecialchars(ucwords($job_status)); ?></span>
                                </p>

                                <div class="job-actions">
                                    <a href="edit_job.php?job_id=<?php echo intval($job['id']); ?>" class="btn btn-light">Edit Job</a>
                                    <a href="manage_jobs.php" class="btn btn-primary">Manage</a>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                <?php } else { ?>
                    <p class="empty">No job posts yet.</p>
                    <a href="post_job.php" class="btn btn-primary">Post Your First Job</a>
                <?php } ?>
            </div>
        </section>
    </main>
</div>

</body>
</html>
