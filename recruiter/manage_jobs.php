<?php
// recruiter/manage_jobs.php
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

    if ($status === "approved") {
        return "approved";
    }

    if ($status === "rejected") {
        return "rejected";
    }

    if ($status === "pending") {
        return "pending";
    }

    return "neutral";
}

function expiry_class($expiry_date) {
    if (empty($expiry_date)) {
        return "neutral";
    }

    if (strtotime($expiry_date) < strtotime(date("Y-m-d"))) {
        return "expired";
    }

    return "active";
}

function expiry_text($expiry_date) {
    if (empty($expiry_date)) {
        return "No expiry";
    }

    if (strtotime($expiry_date) < strtotime(date("Y-m-d"))) {
        return "Expired";
    }

    return "Active";
}

$message = "";
$error = "";

if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

if (isset($_GET['error'])) {
    $error = trim($_GET['error']);
}

// Company/profile info
$company_name = "Job Portal";
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

// Archive job
if (isset($_GET['archive'])) {
    $job_id = intval($_GET['archive']);

    $check_sql = "
        SELECT id 
        FROM jobs 
        WHERE id = ? 
        AND recruiter_id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, "ii", $job_id, $recruiter_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $archive_sql = "
                UPDATE jobs
                SET is_archived = 1
                WHERE id = ?
                AND recruiter_id = ?
            ";

            $archive_stmt = mysqli_prepare($conn, $archive_sql);

            if ($archive_stmt) {
                mysqli_stmt_bind_param($archive_stmt, "ii", $job_id, $recruiter_id);

                if (mysqli_stmt_execute($archive_stmt)) {
                    header("Location: manage_jobs.php?message=Job archived successfully.");
                    exit();
                }
            }

            header("Location: manage_jobs.php?error=Failed to archive job.");
            exit();
        }
    }

    header("Location: manage_jobs.php?error=Job not found.");
    exit();
}

// Unarchive job
if (isset($_GET['unarchive'])) {
    $job_id = intval($_GET['unarchive']);

    $check_sql = "
        SELECT id 
        FROM jobs 
        WHERE id = ? 
        AND recruiter_id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);

    if ($check_stmt) {
        mysqli_stmt_bind_param($check_stmt, "ii", $job_id, $recruiter_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $unarchive_sql = "
                UPDATE jobs
                SET is_archived = 0
                WHERE id = ?
                AND recruiter_id = ?
            ";

            $unarchive_stmt = mysqli_prepare($conn, $unarchive_sql);

            if ($unarchive_stmt) {
                mysqli_stmt_bind_param($unarchive_stmt, "ii", $job_id, $recruiter_id);

                if (mysqli_stmt_execute($unarchive_stmt)) {
                    header("Location: manage_jobs.php?message=Job unarchived successfully.");
                    exit();
                }
            }

            header("Location: manage_jobs.php?error=Failed to unarchive job.");
            exit();
        }
    }

    header("Location: manage_jobs.php?error=Job not found.");
    exit();
}

// Delete job only if no applications
if (isset($_GET['delete'])) {
    $job_id = intval($_GET['delete']);

    $check_job_sql = "
        SELECT id 
        FROM jobs 
        WHERE id = ? 
        AND recruiter_id = ?
        LIMIT 1
    ";

    $check_job_stmt = mysqli_prepare($conn, $check_job_sql);

    if ($check_job_stmt) {
        mysqli_stmt_bind_param($check_job_stmt, "ii", $job_id, $recruiter_id);
        mysqli_stmt_execute($check_job_stmt);
        $check_job_result = mysqli_stmt_get_result($check_job_stmt);

        if ($check_job_result && mysqli_num_rows($check_job_result) === 1) {
            $application_count = get_count($conn, "
                SELECT COUNT(*) AS total
                FROM applications
                WHERE job_id = '$job_id'
            ");

            if ($application_count > 0) {
                header("Location: manage_jobs.php?error=Cannot delete this job because it already has applications. You can archive it instead.");
                exit();
            }

            $delete_sql = "
                DELETE FROM jobs
                WHERE id = ?
                AND recruiter_id = ?
            ";

            $delete_stmt = mysqli_prepare($conn, $delete_sql);

            if ($delete_stmt) {
                mysqli_stmt_bind_param($delete_stmt, "ii", $job_id, $recruiter_id);

                if (mysqli_stmt_execute($delete_stmt)) {
                    header("Location: manage_jobs.php?message=Job deleted successfully.");
                    exit();
                }
            }

            header("Location: manage_jobs.php?error=Failed to delete job.");
            exit();
        }
    }

    header("Location: manage_jobs.php?error=Job not found.");
    exit();
}

// Filters
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : "all";
$archive_filter = isset($_GET['archive_status']) ? trim($_GET['archive_status']) : "all";
$expiry_filter = isset($_GET['expiry']) ? trim($_GET['expiry']) : "all";
$search = isset($_GET['search']) ? trim($_GET['search']) : "";

// Counts
$total_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
");

$approved_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND status = 'approved'
");

$pending_jobs = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE recruiter_id = '$recruiter_id'
    AND status = 'pending'
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

// Main jobs query
$sql = "
    SELECT 
        jobs.*,
        job_categories.category_name,
        (
            SELECT COUNT(*)
            FROM applications
            WHERE applications.job_id = jobs.id
        ) AS application_count
    FROM jobs
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    WHERE jobs.recruiter_id = ?
";

$params = [$recruiter_id];
$types = "i";

if ($status_filter !== "all" && !empty($status_filter)) {
    $sql .= " AND jobs.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if ($archive_filter === "archived") {
    $sql .= " AND jobs.is_archived = 1";
} elseif ($archive_filter === "not_archived") {
    $sql .= " AND jobs.is_archived = 0";
}

if ($expiry_filter === "active") {
    $sql .= " AND jobs.expiry_date >= CURDATE()";
} elseif ($expiry_filter === "expired") {
    $sql .= " AND jobs.expiry_date < CURDATE()";
}

if (!empty($search)) {
    $sql .= " AND (jobs.title LIKE ? OR jobs.location LIKE ? OR jobs.job_type LIKE ? OR job_categories.category_name LIKE ?)";
    $search_param = "%" . $search . "%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "ssss";
}

$sql .= " ORDER BY jobs.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$jobs = mysqli_stmt_get_result($stmt);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Manage Jobs | Job Application System</title>
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
        .card,
        .filter-panel,
        .panel,
        .job-card,
        .description-box,
        .reason-box {
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
        .description-box::before,
        .reason-box::before {
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
        .description-box > *,
        .reason-box > * {
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

        .filter-panel {
            border-radius: 26px;
            padding: 18px;
            margin-bottom: 24px;
            animation: fadeUp 0.58s ease both;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
            gap: 12px;
            align-items: center;
        }

        input,
        select {
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

        input::placeholder { color: rgba(220, 231, 255, 0.6); }

        select option {
            color: #101827;
            background: #ffffff;
        }

        input:focus,
        select:focus {
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

        .panel-header h2,
        .panel h2 {
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

        .job-card.archived {
            opacity: 0.78;
            border-color: rgba(170, 183, 207, 0.24);
        }

        .job-header {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .job-title {
            margin: 0 0 10px;
            color: var(--text);
            font-size: 22px;
            letter-spacing: -0.3px;
        }

        .job-meta {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
        }

        .description-box,
        .reason-box {
            border-radius: 16px;
            padding: 14px;
            color: rgba(248, 251, 255, 0.82);
            line-height: 1.65;
            font-size: 14px;
            margin-top: 12px;
            box-shadow: none;
        }

        .description-box strong,
        .reason-box strong {
            color: #eef5ff;
            font-size: 13px;
            font-weight: 900;
        }

        .reason-box {
            background: rgba(255, 59, 95, 0.11);
            border-color: rgba(255, 59, 95, 0.24);
            color: #ffd3dc;
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
            margin-left: 6px;
            margin-bottom: 8px;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .badge.approved,
        .badge.active {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .badge.pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .badge.rejected,
        .badge.expired {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .badge.archived,
        .badge.neutral {
            background: rgba(255, 255, 255, 0.10);
            color: var(--soft);
            border-color: rgba(255, 255, 255, 0.14);
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

        .btn-warning {
            background: rgba(255, 180, 84, 0.13);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.3);
        }

        .btn-warning:hover {
            background: rgba(255, 180, 84, 0.2);
            box-shadow: 0 14px 30px rgba(255, 180, 84, 0.12);
        }

        .btn-danger {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.28);
        }

        .btn-danger:hover {
            background: rgba(255, 59, 95, 0.22);
            box-shadow: 0 14px 30px rgba(255, 59, 95, 0.13);
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

        @media (max-width: 1250px) {
            .cards { grid-template-columns: repeat(2, 1fr); }
            .jobs-grid { grid-template-columns: 1fr; }
            .filter-grid { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 750px) {
            body::before,
            body::after { width: 280px; height: 280px; }

            .filter-grid { grid-template-columns: 1fr; }

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
            .panel-header,
            .job-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }

            .cards { grid-template-columns: 1fr; }

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
                <h1>Manage Jobs</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Manage your posted jobs, archive filled roles, and track applications.</p>
            </div>

            <div class="user-pill">Recruiter</div>
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
                <p class="card-number"><?php echo $total_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Active</p>
                <p class="card-number"><?php echo $active_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Approved</p>
                <p class="card-number"><?php echo $approved_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending</p>
                <p class="card-number"><?php echo $pending_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Rejected</p>
                <p class="card-number"><?php echo $rejected_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Archived</p>
                <p class="card-number"><?php echo $archived_jobs; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Expired</p>
                <p class="card-number"><?php echo $expired_jobs; ?></p>
            </div>
        </section>

        <div class="filter-panel">
            <form method="GET" class="filter-grid">
                <input 
                    type="text" 
                    name="search" 
                    placeholder="Search by title, location, type, or category..."
                    value="<?php echo htmlspecialchars($search); ?>"
                >

                <select name="status">
                    <option value="all" <?php if ($status_filter === 'all') echo 'selected'; ?>>All Status</option>
                    <option value="pending" <?php if ($status_filter === 'pending') echo 'selected'; ?>>Pending</option>
                    <option value="approved" <?php if ($status_filter === 'approved') echo 'selected'; ?>>Approved</option>
                    <option value="rejected" <?php if ($status_filter === 'rejected') echo 'selected'; ?>>Rejected</option>
                </select>

                <select name="archive_status">
                    <option value="all" <?php if ($archive_filter === 'all') echo 'selected'; ?>>All Archive</option>
                    <option value="not_archived" <?php if ($archive_filter === 'not_archived') echo 'selected'; ?>>Not Archived</option>
                    <option value="archived" <?php if ($archive_filter === 'archived') echo 'selected'; ?>>Archived</option>
                </select>

                <select name="expiry">
                    <option value="all" <?php if ($expiry_filter === 'all') echo 'selected'; ?>>All Expiry</option>
                    <option value="active" <?php if ($expiry_filter === 'active') echo 'selected'; ?>>Active</option>
                    <option value="expired" <?php if ($expiry_filter === 'expired') echo 'selected'; ?>>Expired</option>
                </select>

                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="manage_jobs.php" class="btn btn-light">Reset</a>
            </form>
        </div>

        <div class="panel">
            <div class="panel-header">
                <h2>Your Job Posts</h2>
                <a href="post_job.php" class="btn btn-primary">Post New Job</a>
            </div>

            <?php if ($jobs && mysqli_num_rows($jobs) > 0) { ?>
                <div class="jobs-grid">
                    <?php while ($job = mysqli_fetch_assoc($jobs)) { ?>
                        <?php
                            $job_status_css = status_class($job['status'] ?? '');
                            $job_expiry_css = expiry_class($job['expiry_date'] ?? '');
                            $job_expiry_text = expiry_text($job['expiry_date'] ?? '');
                            $is_archived = intval($job['is_archived'] ?? 0) === 1;
                        ?>

                        <div class="job-card <?php echo $is_archived ? 'archived' : ''; ?>">
                            <div class="job-header">
                                <div>
                                    <h2 class="job-title"><?php echo htmlspecialchars($job['title'] ?? ''); ?></h2>

                                    <p class="job-meta">
                                        Category: <?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?><br>
                                        Location: <?php echo htmlspecialchars($job['location'] ?? ''); ?><br>
                                        Job Type: <?php echo htmlspecialchars($job['job_type'] ?? ''); ?><br>
                                        Salary: ₱<?php echo number_format(floatval($job['salary_min'] ?? 0), 2); ?>
                                        -
                                        ₱<?php echo number_format(floatval($job['salary_max'] ?? 0), 2); ?><br>
                                        Expiry Date: <?php echo htmlspecialchars($job['expiry_date'] ?? ''); ?><br>
                                        Views: <?php echo intval($job['view_count'] ?? 0); ?> |
                                        Applications: <?php echo intval($job['application_count'] ?? 0); ?>
                                    </p>
                                </div>

                                <div>
                                    <span class="badge <?php echo htmlspecialchars($job_status_css); ?>">
                                        <?php echo htmlspecialchars($job['status'] ?? ''); ?>
                                    </span>

                                    <span class="badge <?php echo htmlspecialchars($job_expiry_css); ?>">
                                        <?php echo htmlspecialchars($job_expiry_text); ?>
                                    </span>

                                    <?php if ($is_archived) { ?>
                                        <span class="badge archived">Archived</span>
                                    <?php } ?>
                                </div>
                            </div>

                            <div class="description-box">
                                <strong>Description:</strong><br>
                                <?php
                                    $description = $job['description'] ?? '';
                                    echo nl2br(htmlspecialchars(substr($description, 0, 180)));

                                    if (strlen($description) > 180) {
                                        echo "...";
                                    }
                                ?>
                            </div>

                            <?php if (strtolower(trim($job['status'] ?? '')) === 'rejected' && !empty($job['reject_reason'])) { ?>
                                <div class="reason-box">
                                    <strong>Reject Reason:</strong><br>
                                    <?php echo nl2br(htmlspecialchars($job['reject_reason'])); ?>
                                </div>
                            <?php } ?>

                            <div class="actions">
                                <a href="../job_details.php?job_id=<?php echo intval($job['id']); ?>" class="btn btn-light">
                                    View
                                </a>

                                <a href="edit_job.php?id=<?php echo intval($job['id']); ?>" class="btn btn-primary">
                                    Edit
                                </a>

                                <?php if ($is_archived) { ?>
                                    <a href="manage_jobs.php?unarchive=<?php echo intval($job['id']); ?>" 
                                       class="btn btn-warning"
                                       onclick="return confirm('Unarchive this job?');">
                                        Unarchive
                                    </a>
                                <?php } else { ?>
                                    <a href="manage_jobs.php?archive=<?php echo intval($job['id']); ?>" 
                                       class="btn btn-warning"
                                       onclick="return confirm('Archive this job?');">
                                        Archive
                                    </a>
                                <?php } ?>

                                <?php if (intval($job['application_count'] ?? 0) > 0) { ?>
                                    <a href="applicants.php?job_id=<?php echo intval($job['id']); ?>" class="btn btn-light">
                                        Applicants
                                    </a>
                                <?php } ?>

                                <?php if (intval($job['application_count'] ?? 0) === 0) { ?>
                                    <a href="manage_jobs.php?delete=<?php echo intval($job['id']); ?>" 
                                       class="btn btn-danger"
                                       onclick="return confirm('Delete this job? This cannot be undone.');">
                                        Delete
                                    </a>
                                <?php } ?>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            <?php } else { ?>
                <p class="empty">No job posts found.</p>
                <a href="post_job.php" class="btn btn-primary">Post Your First Job</a>
            <?php } ?>
        </div>
    </main>
</div>

</body>
</html>