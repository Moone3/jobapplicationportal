<?php
// admin/approve_jobs.php
include "../includes/auth_check.php";
include "../config/database.php";
include "../includes/create_notification.php";

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$full_name = $_SESSION['full_name'] ?? 'Admin';
$current_page = basename($_SERVER['PHP_SELF']);

$message = "";
$error = "";

// Admin profile picture for sidebar
$admin_profile_picture = "";

$admin_query = mysqli_query($conn, "
    SELECT full_name, profile_picture
    FROM users 
    WHERE id = '$admin_id' 
    LIMIT 1
");

if ($admin_query && mysqli_num_rows($admin_query) > 0) {
    $admin_data = mysqli_fetch_assoc($admin_query);

    if (!empty($admin_data['full_name'])) {
        $full_name = $admin_data['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    if (!empty($admin_data['profile_picture'])) {
        $picture_name = $admin_data['profile_picture'];

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

// Approve job
if (isset($_GET['approve'])) {
    $job_id = intval($_GET['approve']);

    $job_sql = "
        SELECT jobs.id, jobs.title, jobs.recruiter_id
        FROM jobs
        WHERE jobs.id = ?
        LIMIT 1
    ";

    $job_stmt = mysqli_prepare($conn, $job_sql);
    mysqli_stmt_bind_param($job_stmt, "i", $job_id);
    mysqli_stmt_execute($job_stmt);
    $job_result = mysqli_stmt_get_result($job_stmt);

    if ($job_result && mysqli_num_rows($job_result) === 1) {
        $job = mysqli_fetch_assoc($job_result);

        $update_sql = "
            UPDATE jobs 
            SET status = 'approved',
                reject_reason = NULL
            WHERE id = ?
        ";

        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "i", $job_id);

        if (mysqli_stmt_execute($update_stmt)) {
            create_notification(
                $conn,
                $job['recruiter_id'],
                "Job Approved",
                "Your job post '" . $job['title'] . "' has been approved by the admin.",
                "../recruiter/manage_jobs.php"
            );

            mysqli_query($conn, "
                INSERT INTO admin_logs (admin_id, action) 
                VALUES ('$admin_id', 'Approved job post ID: $job_id')
            ");

            $message = "Job approved successfully.";
        } else {
            $error = "Failed to approve job.";
        }
    } else {
        $error = "Job not found.";
    }
}

// Reject job with reason
if (isset($_POST['reject_job'])) {
    $job_id = intval($_POST['job_id']);
    $reject_reason = trim($_POST['reject_reason']);

    if (empty($reject_reason)) {
        $error = "Reject reason is required.";
    } else {
        $job_sql = "
            SELECT jobs.id, jobs.title, jobs.recruiter_id
            FROM jobs
            WHERE jobs.id = ?
            LIMIT 1
        ";

        $job_stmt = mysqli_prepare($conn, $job_sql);
        mysqli_stmt_bind_param($job_stmt, "i", $job_id);
        mysqli_stmt_execute($job_stmt);
        $job_result = mysqli_stmt_get_result($job_stmt);

        if ($job_result && mysqli_num_rows($job_result) === 1) {
            $job = mysqli_fetch_assoc($job_result);

            $update_sql = "
                UPDATE jobs 
                SET status = 'rejected',
                    reject_reason = ?
                WHERE id = ?
            ";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "si", $reject_reason, $job_id);

            if (mysqli_stmt_execute($update_stmt)) {
                create_notification(
                    $conn,
                    $job['recruiter_id'],
                    "Job Rejected",
                    "Your job post '" . $job['title'] . "' was rejected. Reason: " . $reject_reason,
                    "../recruiter/manage_jobs.php"
                );

                mysqli_query($conn, "
                    INSERT INTO admin_logs (admin_id, action) 
                    VALUES ('$admin_id', 'Rejected job post ID: $job_id')
                ");

                $message = "Job rejected successfully.";
            } else {
                $error = "Failed to reject job.";
            }
        } else {
            $error = "Job not found.";
        }
    }
}

// Filters
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : "pending";
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";

// Counts
$total_jobs = 0;
$pending_jobs = 0;
$approved_jobs = 0;
$rejected_jobs = 0;

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM jobs");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_jobs = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'pending'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $pending_jobs = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'approved'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $approved_jobs = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'rejected'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $rejected_jobs = $row['total'];
}

// Jobs query
$sql = "
    SELECT 
        jobs.*,
        job_categories.category_name,
        users.full_name AS recruiter_name,
        users.email AS recruiter_email,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo
    FROM jobs
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON jobs.recruiter_id = recruiter_profiles.user_id
    WHERE 1
";

$params = [];
$types = "";

if ($status_filter !== "all" && !empty($status_filter)) {
    $sql .= " AND jobs.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($keyword)) {
    $sql .= " AND (
        jobs.title LIKE ?
        OR jobs.description LIKE ?
        OR jobs.requirements LIKE ?
        OR jobs.location LIKE ?
        OR users.full_name LIKE ?
        OR users.email LIKE ?
        OR recruiter_profiles.company_name LIKE ?
        OR job_categories.category_name LIKE ?
    )";

    $search_value = "%" . $keyword . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "ssssssss";
}

$sql .= " ORDER BY jobs.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$jobs = mysqli_stmt_get_result($stmt);

function status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "approved") {
        return "approved";
    }

    if ($status === "rejected") {
        return "rejected";
    }

    return "pending";
}

function archive_status_text($is_archived) {
    return intval($is_archived) === 1 ? "Archived" : "Not Archived";
}

function date_status_text($expiry_date) {
    if (empty($expiry_date)) {
        return "No Expiry";
    }

    return strtotime($expiry_date) < strtotime(date("Y-m-d")) ? "Expired" : "Active Date";
}

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Job Approval | Job Application System</title>
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
        .filter-panel,
        .panel,
        .job-card,
        .meta-box,
        .description-box,
        .reject-reason-view,
        .reject-box {
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
        .filter-panel::before,
        .panel::before,
        .job-card::before,
        .meta-box::before,
        .description-box::before,
        .reject-reason-view::before,
        .reject-box::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .card > *,
        .filter-panel > *,
        .panel > *,
        .job-card > *,
        .meta-box > *,
        .description-box > *,
        .reject-reason-view > *,
        .reject-box > * {
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
        .job-card:hover,
        .meta-box:hover {
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

        .filter-panel {
            border-radius: 26px;
            padding: 18px;
            margin-bottom: 24px;
            animation: fadeUp 0.58s ease both;
        }

        .filter-box {
            display: grid;
            grid-template-columns: 2fr 1fr auto auto;
            gap: 12px;
            align-items: center;
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
            min-height: 110px;
            resize: vertical;
            line-height: 1.65;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
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

        .job-card {
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 18px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .job-header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .job-title {
            margin: 0;
            color: var(--text);
            font-size: 23px;
            letter-spacing: -0.3px;
        }

        .job-sub {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
        }

        .badges {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
            text-transform: capitalize;
            border: 1px solid transparent;
        }

        .status.pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .status.approved {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .status.rejected {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .badge-light {
            background: rgba(18, 215, 255, 0.12);
            color: #bff5ff;
            border-color: rgba(18, 215, 255, 0.18);
        }

        .badge-danger {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .meta-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 14px;
        }

        .meta-box {
            border-radius: 17px;
            padding: 15px;
            min-height: 82px;
            box-shadow: none;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .meta-box strong,
        .description-box strong,
        .reject-reason-view strong,
        .reject-box label {
            display: block;
            margin-bottom: 8px;
            color: #eef5ff;
            font-size: 13px;
            font-weight: 900;
        }

        .meta-box span,
        .description-box,
        .reject-reason-view {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .description-box,
        .reject-reason-view {
            border-radius: 17px;
            padding: 16px;
            margin-top: 14px;
            box-shadow: none;
        }

        .reject-reason-view,
        .reject-box {
            border-color: rgba(255, 59, 95, 0.28);
            background: rgba(255, 59, 95, 0.08);
            color: #ffd3dc;
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

        .btn-approve {
            background: rgba(38, 224, 168, 0.16);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.30);
        }

        .btn-approve:hover {
            background: rgba(38, 224, 168, 0.24);
            box-shadow: 0 14px 30px rgba(38, 224, 168, 0.12);
        }

        .btn-reject,
        .btn-reject-submit {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.30);
        }

        .btn-reject:hover,
        .btn-reject-submit:hover {
            background: rgba(255, 59, 95, 0.22);
            box-shadow: 0 14px 30px rgba(255, 59, 95, 0.12);
        }

        .btn-reject-submit { margin-top: 14px; }

        .reject-box {
            display: none;
            margin-top: 18px;
            border-radius: 18px;
            padding: 18px;
            box-shadow: none;
        }

        .reject-box textarea:focus {
            border-color: rgba(255, 59, 95, 0.65);
            box-shadow: 0 0 0 4px rgba(255, 59, 95, 0.12);
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

        @media (max-width: 1150px) {
            .cards,
            .meta-grid { grid-template-columns: repeat(2, 1fr); }

            .filter-box { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 750px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .cards,
            .meta-grid,
            .filter-box { grid-template-columns: 1fr; }

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
            .job-header,
            .panel-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .badges { justify-content: flex-start; }

            .btn { width: 100%; }
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
                <h1>Job Approval</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name ?? 'Admin'); ?>. Review, approve, or reject recruiter job posts.</p>
            </div>

            <div class="user-pill">Admin</div>
        </div>

        <?php if (!empty($message)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <section class="cards">
            <div class="card">
                <p class="card-title">Total Jobs</p>
                <p class="card-number"><?php echo $total_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending Jobs</p>
                <p class="card-number"><?php echo $pending_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Approved Jobs</p>
                <p class="card-number"><?php echo $approved_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Rejected Jobs</p>
                <p class="card-number"><?php echo $rejected_jobs; ?></p>
            </div>
        </section>

        <div class="filter-panel">
            <form method="GET" class="filter-box">
                <input 
                    type="text" 
                    name="keyword" 
                    placeholder="Search job, recruiter, company, category, or location"
                    value="<?php echo htmlspecialchars($keyword); ?>"
                >

                <select name="status">
                    <option value="all" <?php if ($status_filter == 'all') echo 'selected'; ?>>All Status</option>
                    <option value="pending" <?php if ($status_filter == 'pending') echo 'selected'; ?>>Pending</option>
                    <option value="approved" <?php if ($status_filter == 'approved') echo 'selected'; ?>>Approved</option>
                    <option value="rejected" <?php if ($status_filter == 'rejected') echo 'selected'; ?>>Rejected</option>
                </select>

                <button type="submit" class="btn btn-primary">Filter</button>

                <a href="approve_jobs.php" class="btn btn-light">Reset</a>
            </form>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2>Job Posts for Review</h2>
            </div>

            <?php if ($jobs && mysqli_num_rows($jobs) > 0) { ?>
                <?php while ($job = mysqli_fetch_assoc($jobs)) { ?>
                    <?php
                        $status_css = status_class($job['status']);
                        $company_name = !empty($job['company_name']) ? $job['company_name'] : $job['recruiter_name'];
                        $date_status = date_status_text($job['expiry_date']);
                        $archive_status = archive_status_text($job['is_archived']);
                    ?>

                    <div class="job-card">
                        <div class="job-header">
                            <div>
                                <h2 class="job-title"><?php echo htmlspecialchars($job['title'] ?? ''); ?></h2>
                                <p class="job-sub">
                                    Recruiter: <?php echo htmlspecialchars($job['recruiter_name'] ?? ''); ?><br>
                                    Company: <?php echo htmlspecialchars($company_name ?? ''); ?><br>
                                    Email: <?php echo htmlspecialchars($job['recruiter_email'] ?? ''); ?>
                                </p>
                            </div>

                            <div class="badges">
                                <span class="badge status <?php echo htmlspecialchars($status_css); ?>">
                                    <?php echo htmlspecialchars($job['status'] ?? ''); ?>
                                </span>

                                <span class="badge badge-light">
                                    <?php echo htmlspecialchars($archive_status); ?>
                                </span>

                                <span class="badge <?php echo $date_status === 'Expired' ? 'badge-danger' : 'badge-light'; ?>">
                                    <?php echo htmlspecialchars($date_status); ?>
                                </span>
                            </div>
                        </div>

                        <div class="meta-grid">
                            <div class="meta-box">
                                <strong>Category</strong>
                                <span><?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Location</strong>
                                <span><?php echo htmlspecialchars($job['location'] ?? ''); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Job Type</strong>
                                <span><?php echo htmlspecialchars($job['job_type'] ?? ''); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Expiry Date</strong>
                                <span><?php echo htmlspecialchars($job['expiry_date'] ?? ''); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Salary Range</strong>
                                <span>
                                    ₱<?php echo number_format($job['salary_min'] ?? 0, 2); ?>
                                    -
                                    ₱<?php echo number_format($job['salary_max'] ?? 0, 2); ?>
                                </span>
                            </div>

                            <div class="meta-box">
                                <strong>Created At</strong>
                                <span><?php echo htmlspecialchars($job['created_at'] ?? ''); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Archive Status</strong>
                                <span><?php echo htmlspecialchars($archive_status); ?></span>
                            </div>

                            <div class="meta-box">
                                <strong>Date Status</strong>
                                <span><?php echo htmlspecialchars($date_status); ?></span>
                            </div>
                        </div>

                        <div class="description-box">
                            <strong>Description:</strong><br>
                            <?php echo nl2br(htmlspecialchars($job['description'] ?? '')); ?>
                        </div>

                        <div class="description-box">
                            <strong>Requirements:</strong><br>
                            <?php echo nl2br(htmlspecialchars($job['requirements'] ?? '')); ?>
                        </div>

                        <?php if (!empty($job['reject_reason'])) { ?>
                            <div class="reject-reason-view">
                                <strong>Previous Reject Reason:</strong><br>
                                <?php echo nl2br(htmlspecialchars($job['reject_reason'])); ?>
                            </div>
                        <?php } ?>

                        <?php if ($job['status'] === 'pending') { ?>
                            <div class="actions">
                                <a href="approve_jobs.php?approve=<?php echo $job['id']; ?>" 
                                   class="btn btn-approve"
                                   onclick="return confirm('Approve this job post?');">
                                    Approve
                                </a>

                                <button type="button" 
                                        class="btn btn-reject"
                                        onclick="showRejectBox(<?php echo $job['id']; ?>)">
                                    Reject
                                </button>
                            </div>

                            <div class="reject-box" id="reject-box-<?php echo $job['id']; ?>">
                                <form method="POST">
                                    <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">

                                    <label>Reject Reason</label>
                                    <textarea 
                                        name="reject_reason" 
                                        placeholder="Enter reason for rejecting this job post..." 
                                        required></textarea>

                                    <button type="submit" name="reject_job" class="btn btn-reject-submit">
                                        Submit Rejection
                                    </button>
                                </form>
                            </div>
                        <?php } else { ?>
                            <div class="actions">
                                <a href="../job_details.php?job_id=<?php echo $job['id']; ?>" class="btn btn-light">
                                    View Public Page
                                </a>
                            </div>
                        <?php } ?>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <p class="empty">No job posts found.</p>
            <?php } ?>
        </div>
    </main>
</div>

<script>
function showRejectBox(jobId) {
    const box = document.getElementById("reject-box-" + jobId);

    if (!box) {
        return;
    }

    if (box.style.display === "block") {
        box.style.display = "none";
    } else {
        box.style.display = "block";
    }
}
</script>

</body>
</html>