<?php
// applicant/saved_jobs.php
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

function job_availability_class($job) {
    if (strtolower(trim($job['status'])) !== 'approved') {
        return 'unavailable';
    }

    if (intval($job['is_archived']) === 1) {
        return 'unavailable';
    }

    if (!empty($job['expiry_date']) && strtotime($job['expiry_date']) < strtotime(date("Y-m-d"))) {
        return 'unavailable';
    }

    return 'available';
}

function job_availability_text($job) {
    if (strtolower(trim($job['status'])) !== 'approved') {
        return 'Not Approved';
    }

    if (intval($job['is_archived']) === 1) {
        return 'Archived';
    }

    if (!empty($job['expiry_date']) && strtotime($job['expiry_date']) < strtotime(date("Y-m-d"))) {
        return 'Expired';
    }

    return 'Available';
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

// Unsave job
if (isset($_GET['unsave'])) {
    $job_id = intval($_GET['unsave']);

    $delete_sql = "
        DELETE FROM saved_jobs 
        WHERE applicant_id = ? 
        AND job_id = ?
    ";

    $delete_stmt = mysqli_prepare($conn, $delete_sql);

    if (!$delete_stmt) {
        header("Location: saved_jobs.php?error=Database error.");
        exit();
    }

    mysqli_stmt_bind_param($delete_stmt, "ii", $applicant_id, $job_id);

    if (mysqli_stmt_execute($delete_stmt)) {
        header("Location: saved_jobs.php?message=Job removed from saved jobs.");
        exit();
    } else {
        header("Location: saved_jobs.php?error=Failed to remove saved job.");
        exit();
    }
}

// Compatibility with old remove parameter
if (isset($_GET['remove'])) {
    $saved_id = intval($_GET['remove']);

    $delete_sql = "
        DELETE FROM saved_jobs 
        WHERE id = ? 
        AND applicant_id = ?
    ";

    $delete_stmt = mysqli_prepare($conn, $delete_sql);

    if (!$delete_stmt) {
        header("Location: saved_jobs.php?error=Database error.");
        exit();
    }

    mysqli_stmt_bind_param($delete_stmt, "ii", $saved_id, $applicant_id);

    if (mysqli_stmt_execute($delete_stmt)) {
        header("Location: saved_jobs.php?message=Job removed from saved jobs.");
        exit();
    } else {
        header("Location: saved_jobs.php?error=Failed to remove saved job.");
        exit();
    }
}

// Counts
$total_saved_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM saved_jobs
    WHERE applicant_id = '$applicant_id'
");

$available_saved_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM saved_jobs
    JOIN jobs ON saved_jobs.job_id = jobs.id
    WHERE saved_jobs.applicant_id = '$applicant_id'
    AND jobs.status = 'approved'
    AND jobs.is_archived = 0
    AND jobs.expiry_date >= CURDATE()
");

$unavailable_saved_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM saved_jobs
    JOIN jobs ON saved_jobs.job_id = jobs.id
    WHERE saved_jobs.applicant_id = '$applicant_id'
    AND (
        jobs.status != 'approved'
        OR jobs.is_archived = 1
        OR jobs.expiry_date < CURDATE()
    )
");

$applied_saved_jobs = get_count($conn, "
    SELECT COUNT(*) AS total
    FROM saved_jobs
    JOIN applications ON saved_jobs.job_id = applications.job_id
    WHERE saved_jobs.applicant_id = '$applicant_id'
    AND applications.applicant_id = '$applicant_id'
");

// Saved jobs query
$sql = "
    SELECT 
        saved_jobs.id AS saved_id,
        saved_jobs.saved_at,

        jobs.id AS job_id,
        jobs.title,
        jobs.description,
        jobs.requirements,
        jobs.location,
        jobs.job_type,
        jobs.salary_min,
        jobs.salary_max,
        jobs.expiry_date,
        jobs.status,
        jobs.is_archived,
        jobs.created_at,

        job_categories.category_name,

        users.full_name AS recruiter_name,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo,

        applications.id AS application_id
    FROM saved_jobs
    JOIN jobs ON saved_jobs.job_id = jobs.id
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON jobs.recruiter_id = recruiter_profiles.user_id
    LEFT JOIN applications 
        ON jobs.id = applications.job_id 
        AND applications.applicant_id = saved_jobs.applicant_id
    WHERE saved_jobs.applicant_id = ?
    ORDER BY saved_jobs.saved_at DESC
";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, "i", $applicant_id);
mysqli_stmt_execute($stmt);
$saved_jobs = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Saved Jobs | Job Application System</title>
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

        .brand-icon,
        .company-placeholder {
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
        .company-logo {
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

        .cards {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 24px;
            padding: 22px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.52s ease both;
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
            text-shadow: 0 0 22px rgba(18, 215, 255, 0.28);
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            animation: fadeUp 0.64s ease both;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
        }

        .panel-header h2 {
            margin: 0;
            font-size: 23px;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .jobs-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .job-card {
            border-radius: 24px;
            padding: 20px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .job-card.unavailable {
            opacity: 0.82;
            border-color: rgba(255, 59, 95, 0.22);
            background: rgba(255, 59, 95, 0.055);
        }

        .company-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 14px;
        }

        .company-logo,
        .company-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            flex-shrink: 0;
        }

        .company-placeholder { font-size: 16px; }

        .company-name {
            margin: 0;
            color: var(--text);
            font-size: 15px;
            font-weight: 900;
        }

        .company-role {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.4;
        }

        .job-title {
            margin: 0 0 10px;
            color: var(--text);
            font-size: 22px;
            letter-spacing: -0.3px;
        }

        .job-meta {
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .description {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 16px;
            padding: 13px;
            color: rgba(248, 251, 255, 0.82);
            line-height: 1.65;
            font-size: 14px;
            margin-top: 12px;
        }

        .badge {
            display: inline-block;
            padding: 7px 11px;
            border-radius: 999px;
            background: rgba(18, 215, 255, 0.12);
            color: #bff5ff;
            border: 1px solid rgba(18, 215, 255, 0.18);
            font-size: 12px;
            font-weight: 900;
            margin-right: 6px;
            margin-bottom: 8px;
        }

        .badge.saved {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .badge.applied,
        .badge.available {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .badge.unavailable {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 16px;
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

        @media (max-width: 1150px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .jobs-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 750px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .cards {
                grid-template-columns: 1fr;
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
            .panel-header {
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
                <h1>Saved Jobs</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Review your saved job opportunities and apply when ready.</p>
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
                <p class="card-title">Total Saved</p>
                <p class="card-number"><?php echo $total_saved_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Available Saved</p>
                <p class="card-number"><?php echo $available_saved_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Unavailable Saved</p>
                <p class="card-number"><?php echo $unavailable_saved_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Already Applied</p>
                <p class="card-number"><?php echo $applied_saved_jobs; ?></p>
            </div>
        </section>

        <div class="panel">
            <div class="panel-header">
                <h2>Your Saved Jobs</h2>
                <a href="jobs.php" class="btn btn-light">Browse More Jobs</a>
            </div>

            <?php if ($saved_jobs && mysqli_num_rows($saved_jobs) > 0) { ?>
                <div class="jobs-grid">
                    <?php while ($job = mysqli_fetch_assoc($saved_jobs)) { ?>
                        <?php
                            $company_name = !empty($job['company_name']) ? $job['company_name'] : $job['recruiter_name'];
                            $company_initial = strtoupper(substr($company_name, 0, 1));

                            if ($company_initial === "") {
                                $company_initial = "C";
                            }

                            $company_logo_path = "";

                            if (!empty($job['company_logo'])) {
                                $company_logo_file = __DIR__ . "/../assets/uploads/company_logos/" . $job['company_logo'];

                                if (file_exists($company_logo_file)) {
                                    $company_logo_path = "../assets/uploads/company_logos/" . $job['company_logo'];
                                }
                            }

                            $has_applied = !empty($job['application_id']);
                            $availability_class = job_availability_class($job);
                            $availability_text = job_availability_text($job);
                        ?>

                        <div class="job-card <?php echo htmlspecialchars($availability_class === 'unavailable' ? 'unavailable' : ''); ?>">
                            <div class="company-row">
                                <?php if (!empty($company_logo_path)) { ?>
                                    <img src="<?php echo htmlspecialchars($company_logo_path); ?>" class="company-logo" alt="Company Logo">
                                <?php } else { ?>
                                    <div class="company-placeholder">
                                        <?php echo htmlspecialchars($company_initial); ?>
                                    </div>
                                <?php } ?>

                                <div>
                                    <p class="company-name"><?php echo htmlspecialchars($company_name); ?></p>
                                    <p class="company-role">Recruiter: <?php echo htmlspecialchars($job['recruiter_name']); ?></p>
                                </div>
                            </div>

                            <h2 class="job-title"><?php echo htmlspecialchars($job['title']); ?></h2>

                            <p class="job-meta">
                                Location: <?php echo htmlspecialchars($job['location']); ?><br>
                                Expiry Date: <?php echo htmlspecialchars($job['expiry_date']); ?><br>
                                Saved At: <?php echo htmlspecialchars($job['saved_at']); ?>
                            </p>

                            <span class="badge <?php echo htmlspecialchars($availability_class); ?>">
                                <?php echo htmlspecialchars($availability_text); ?>
                            </span>

                            <span class="badge saved">Saved</span>

                            <?php if ($has_applied) { ?>
                                <span class="badge applied">Already Applied</span>
                            <?php } ?>

                            <span class="badge"><?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?></span>
                            <span class="badge"><?php echo htmlspecialchars($job['job_type']); ?></span>

                            <span class="badge">
                                ₱<?php echo number_format(floatval($job['salary_min'] ?? 0), 2); ?>
                                -
                                ₱<?php echo number_format(floatval($job['salary_max'] ?? 0), 2); ?>
                            </span>

                            <div class="description">
                                <?php
                                    $description = $job['description'] ?? '';
                                    echo nl2br(htmlspecialchars(substr($description, 0, 180)));

                                    if (strlen($description) > 180) {
                                        echo "...";
                                    }
                                ?>
                            </div>

                            <div class="actions">
                                <a href="../job_details.php?job_id=<?php echo intval($job['job_id']); ?>" class="btn btn-light">
                                    View Details
                                </a>

                                <?php if ($availability_class === "available") { ?>
                                    <?php if ($has_applied) { ?>
                                        <a href="my_applications.php" class="btn btn-light">
                                            View Application
                                        </a>
                                    <?php } else { ?>
                                        <a href="apply_job.php?job_id=<?php echo intval($job['job_id']); ?>" class="btn btn-primary">
                                            Apply Now
                                        </a>
                                    <?php } ?>
                                <?php } ?>

                                <a href="saved_jobs.php?unsave=<?php echo intval($job['job_id']); ?>" 
                                   class="btn btn-danger"
                                   onclick="return confirm('Remove this job from saved jobs?');">
                                    Unsave
                                </a>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p class="empty">You have no saved jobs yet.</p>
                <a href="jobs.php" class="btn btn-primary">Browse Jobs</a>
            <?php } ?>
        </div>
    </main>
</div>

</body>
</html>