<?php
// recruiter/applicants.php
include "../includes/auth_check.php";
include "../config/database.php";
include "../includes/create_notification.php";

if ($_SESSION['role'] !== 'recruiter') {
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

$message = "";
$error = "";

// Unread notification count
$unread_notifications = 0;

$notif_count_query = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$recruiter_id'
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
    WHERE receiver_id = '$recruiter_id'
    AND is_read = 0
");

if ($msg_count_query) {
    $msg_count_row = mysqli_fetch_assoc($msg_count_query);
    $unread_messages = $msg_count_row['total'];
}

// Company name and logo for sidebar
$company_name = "Job Portal";
$company_logo = "";

$company_query = mysqli_query($conn, "
    SELECT company_name, company_logo
    FROM recruiter_profiles 
    WHERE user_id = '$recruiter_id' 
    LIMIT 1
");

if ($company_query && mysqli_num_rows($company_query) > 0) {
    $company_data = mysqli_fetch_assoc($company_query);

    if (!empty($company_data['company_name'])) {
        $company_name = $company_data['company_name'];
    }

    if (!empty($company_data['company_logo'])) {
        $company_logo = "../assets/uploads/company_logos/" . $company_data['company_logo'];
    }
}

$sidebar_initial = strtoupper(substr($company_name, 0, 1));

$allowed_statuses = [
    'application received',
    'application review complete',
    'phone screen complete',
    'submit resume for client feedback',
    'declined by client',
    'submit to client',
    'schedule interview',
    'rejected by client',
    'send offer to candidate',
    'candidate accepted offer',
    'candidate declined offer',
    'interview',
    'accepted',
    'rejected'
];

$final_statuses = [
    'accepted',
    'rejected',
    'candidate accepted offer',
    'candidate declined offer',
    'rejected by client',
    'declined by client'
];

// Accept applicant
if (isset($_POST['accept_applicant'])) {
    $application_id = intval($_POST['application_id']);

    $check_sql = "
        SELECT 
            applications.id,
            applications.applicant_id,
            applications.status,
            jobs.title AS job_title
        FROM applications
        JOIN jobs ON applications.job_id = jobs.id
        WHERE applications.id = ?
        AND jobs.recruiter_id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $application_id, $recruiter_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if ($check_result && mysqli_num_rows($check_result) === 1) {
        $application_data = mysqli_fetch_assoc($check_result);

        $applicant_user_id = $application_data['applicant_id'];
        $job_title = $application_data['job_title'];
        $old_status = strtolower(trim($application_data['status']));

        if (in_array($old_status, $final_statuses)) {
            $error = "This application already has a final status.";
        } else {
            $update_sql = "
                UPDATE applications
                SET status = 'accepted',
                    recruiter_remarks = 'Applicant has been accepted.'
                WHERE id = ?
            ";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $application_id);

            if (mysqli_stmt_execute($update_stmt)) {
                create_notification(
                    $conn,
                    $applicant_user_id,
                    "Application Accepted",
                    "Congratulations! Your application for " . $job_title . " has been accepted.",
                    "../applicant/my_applications.php"
                );

                $message = "Applicant accepted successfully.";
            } else {
                $error = "Failed to accept applicant.";
            }
        }
    } else {
        $error = "Application not found.";
    }
}

// Reject applicant
if (isset($_POST['reject_applicant'])) {
    $application_id = intval($_POST['application_id']);

    $check_sql = "
        SELECT 
            applications.id,
            applications.applicant_id,
            applications.status,
            jobs.title AS job_title
        FROM applications
        JOIN jobs ON applications.job_id = jobs.id
        WHERE applications.id = ?
        AND jobs.recruiter_id = ?
        LIMIT 1
    ";

    $check_stmt = mysqli_prepare($conn, $check_sql);
    mysqli_stmt_bind_param($check_stmt, "ii", $application_id, $recruiter_id);
    mysqli_stmt_execute($check_stmt);
    $check_result = mysqli_stmt_get_result($check_stmt);

    if ($check_result && mysqli_num_rows($check_result) === 1) {
        $application_data = mysqli_fetch_assoc($check_result);

        $applicant_user_id = $application_data['applicant_id'];
        $job_title = $application_data['job_title'];
        $old_status = strtolower(trim($application_data['status']));

        if (in_array($old_status, $final_statuses)) {
            $error = "This application already has a final status.";
        } else {
            $update_sql = "
                UPDATE applications
                SET status = 'rejected',
                    recruiter_remarks = 'Applicant has been rejected.'
                WHERE id = ?
            ";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "i", $application_id);

            if (mysqli_stmt_execute($update_stmt)) {
                create_notification(
                    $conn,
                    $applicant_user_id,
                    "Application Rejected",
                    "Your application for " . $job_title . " has been rejected.",
                    "../applicant/my_applications.php"
                );

                $message = "Applicant rejected successfully.";
            } else {
                $error = "Failed to reject applicant.";
            }
        }
    } else {
        $error = "Application not found.";
    }
}

// Update application status
if (isset($_POST['update_status'])) {
    $application_id = intval($_POST['application_id']);
    $new_status = trim($_POST['status']);
    $remarks = trim($_POST['recruiter_remarks']);

    if (!in_array($new_status, $allowed_statuses)) {
        $error = "Invalid status selected.";
    } else {
        $check_sql = "
            SELECT 
                applications.id,
                applications.applicant_id,
                applications.status AS old_status,
                jobs.title AS job_title
            FROM applications
            JOIN jobs ON applications.job_id = jobs.id
            WHERE applications.id = ? 
            AND jobs.recruiter_id = ?
            LIMIT 1
        ";

        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "ii", $application_id, $recruiter_id);
        mysqli_stmt_execute($check_stmt);
        $check_result = mysqli_stmt_get_result($check_stmt);

        if ($check_result && mysqli_num_rows($check_result) === 1) {
            $application_data = mysqli_fetch_assoc($check_result);

            $applicant_user_id = $application_data['applicant_id'];
            $old_status = $application_data['old_status'];
            $job_title = $application_data['job_title'];

            $update_sql = "
                UPDATE applications 
                SET status = ?, 
                    recruiter_remarks = ? 
                WHERE id = ?
            ";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "ssi", $new_status, $remarks, $application_id);

            if (mysqli_stmt_execute($update_stmt)) {
                if ($old_status !== $new_status) {
                    create_notification(
                        $conn,
                        $applicant_user_id,
                        "Application Status Updated",
                        "Your application for " . $job_title . " has been updated to: " . ucwords($new_status) . ".",
                        "../applicant/my_applications.php"
                    );
                }

                $message = "Application status updated successfully.";
            } else {
                $error = "Failed to update application status.";
            }
        } else {
            $error = "Application not found.";
        }
    }
}

// Filters
$job_id_filter = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : "active";
$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";

// Jobs filter
$jobs_for_filter = mysqli_query($conn, "
    SELECT id, title
    FROM jobs
    WHERE recruiter_id = '$recruiter_id'
    ORDER BY created_at DESC
");

// Counts
$total_applications = 0;
$pending_applications = 0;
$interview_applications = 0;
$accepted_applications = 0;
$rejected_applications = 0;

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_applications = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    AND LOWER(applications.status) NOT IN ('accepted', 'rejected', 'rejected by client', 'declined by client', 'candidate accepted offer', 'candidate declined offer', 'interview', 'schedule interview')
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $pending_applications = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    AND LOWER(applications.status) IN ('interview', 'schedule interview')
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $interview_applications = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    AND LOWER(applications.status) IN ('accepted', 'candidate accepted offer')
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $accepted_applications = $row['total'];
}

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    WHERE jobs.recruiter_id = '$recruiter_id'
    AND LOWER(applications.status) IN ('rejected', 'rejected by client', 'declined by client', 'candidate declined offer')
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $rejected_applications = $row['total'];
}

// Applications query
$sql = "
    SELECT 
        applications.id AS application_id,
        applications.applicant_id,
        applications.status,
        applications.cover_letter,
        applications.resume_file,
        applications.recruiter_remarks,
        applications.applied_at,

        jobs.id AS job_id,
        jobs.title AS job_title,

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
    WHERE jobs.recruiter_id = ?
";

$params = [$recruiter_id];
$types = "i";

if ($job_id_filter > 0) {
    $sql .= " AND jobs.id = ?";
    $params[] = $job_id_filter;
    $types .= "i";
}

if ($status_filter === "active") {
    $sql .= "
        AND LOWER(applications.status) NOT IN (
            'rejected',
            'rejected by client',
            'declined by client',
            'candidate declined offer'
        )
    ";
} elseif ($status_filter === "pending") {
    $sql .= "
        AND LOWER(applications.status) NOT IN (
            'accepted',
            'rejected',
            'rejected by client',
            'declined by client',
            'candidate accepted offer',
            'candidate declined offer',
            'interview',
            'schedule interview'
        )
    ";
} elseif ($status_filter === "interview") {
    $sql .= " AND LOWER(applications.status) IN ('interview', 'schedule interview')";
} elseif ($status_filter === "accepted") {
    $sql .= " AND LOWER(applications.status) IN ('accepted', 'candidate accepted offer')";
} elseif ($status_filter === "rejected") {
    $sql .= "
        AND LOWER(applications.status) IN (
            'rejected',
            'rejected by client',
            'declined by client',
            'candidate declined offer'
        )
    ";
} elseif ($status_filter !== "all" && !empty($status_filter)) {
    $sql .= " AND applications.status = ?";
    $params[] = $status_filter;
    $types .= "s";
}

if (!empty($keyword)) {
    $sql .= " AND (
        users.full_name LIKE ?
        OR users.email LIKE ?
        OR jobs.title LIKE ?
        OR applicant_profiles.phone LIKE ?
        OR applications.status LIKE ?
    )";

    $search_value = "%" . $keyword . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "sssss";
}

$sql .= " ORDER BY applications.applied_at DESC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$applications = mysqli_stmt_get_result($stmt);

function application_status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "accepted" || $status === "candidate accepted offer") {
        return "accepted";
    }

    if ($status === "rejected" || $status === "rejected by client" || $status === "declined by client" || $status === "candidate declined offer") {
        return "rejected";
    }

    if ($status === "interview" || $status === "schedule interview") {
        return "interview";
    }

    return "pending";
}

function is_final_application_status($status) {
    $final_statuses = [
        'accepted',
        'rejected',
        'candidate accepted offer',
        'candidate declined offer',
        'rejected by client',
        'declined by client'
    ];

    return in_array(strtolower(trim($status)), $final_statuses);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Applicants | Job Application System</title>
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
            color: #ffffff;
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

        .brand-text {
            min-width: 0;
            font-size: 20px;
            font-weight: 900;
            color: var(--text);
            letter-spacing: 0.2px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
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
        .app-card,
        .detail-box,
        .cover-box,
        .remarks-box,
        .status-form {
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
        .app-card::before,
        .detail-box::before,
        .cover-box::before,
        .remarks-box::before,
        .status-form::before {
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
        .app-card > *,
        .detail-box > *,
        .cover-box > *,
        .remarks-box > *,
        .status-form > * {
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
            background: rgba(255, 255, 255, 0.10);
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
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .card {
            border-radius: 24px;
            padding: 22px;
            min-height: 118px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.52s ease both;
        }

        .card:hover,
        .app-card:hover,
        .detail-box:hover {
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
            grid-template-columns: 2fr 1fr 1fr auto auto;
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
        textarea::placeholder {
            color: rgba(220, 231, 255, 0.60);
        }

        select option {
            color: #101827;
            background: #ffffff;
        }

        input:focus,
        select:focus,
        textarea:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        textarea {
            min-height: 92px;
            resize: vertical;
            line-height: 1.65;
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            animation: fadeUp 0.64s ease both;
        }

        .app-card {
            border-radius: 24px;
            padding: 20px;
            margin-bottom: 18px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .app-card.rejected {
            border-color: rgba(255, 59, 95, 0.28);
            background: linear-gradient(135deg, rgba(255, 59, 95, 0.10), rgba(255, 255, 255, 0.05));
        }

        .app-card.accepted {
            border-color: rgba(38, 224, 168, 0.26);
            background: linear-gradient(135deg, rgba(38, 224, 168, 0.10), rgba(255, 255, 255, 0.05));
        }

        .app-card.interview {
            border-color: rgba(18, 215, 255, 0.26);
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.09), rgba(255, 255, 255, 0.05));
        }

        .app-card.pending {
            border-color: rgba(255, 180, 84, 0.26);
        }

        .app-header {
            display: flex;
            justify-content: space-between;
            gap: 14px;
            align-items: flex-start;
            margin-bottom: 14px;
        }

        .app-name {
            margin: 0;
            font-size: 23px;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .meta {
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

        .status.interview {
            background: rgba(18, 215, 255, 0.13);
            color: #bff5ff;
            border-color: rgba(18, 215, 255, 0.24);
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

        .details-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 12px;
        }

        .detail-box {
            border-radius: 17px;
            padding: 15px;
            min-height: 82px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .detail-box strong,
        .cover-box strong,
        .remarks-box strong {
            display: block;
            margin-bottom: 8px;
            color: #eef5ff;
            font-size: 13px;
            font-weight: 900;
        }

        .detail-box span,
        .cover-box,
        .remarks-box {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            word-break: break-word;
        }

        .cover-box,
        .remarks-box,
        .status-form {
            border-radius: 17px;
            padding: 16px;
            margin-top: 14px;
        }

        .remarks-box {
            background: rgba(255, 255, 255, 0.055);
        }

        .status-form {
            background: rgba(255, 255, 255, 0.065);
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 2fr auto;
            gap: 10px;
            align-items: end;
        }

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

        .btn-success {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.27);
        }

        .btn-success:hover {
            background: rgba(38, 224, 168, 0.20);
        }

        .btn-danger {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        .btn-danger:hover {
            background: rgba(255, 59, 95, 0.20);
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


        .confirm-backdrop {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 22px;
            background: rgba(3, 7, 18, 0.68);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .confirm-backdrop.show {
            display: flex;
            animation: modalFade 0.18s ease both;
        }

        .confirm-modal {
            position: relative;
            width: min(440px, 100%);
            overflow: hidden;
            border-radius: 26px;
            padding: 24px;
            color: var(--text);
            background: rgba(15, 23, 42, 0.92);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 0 35px 110px rgba(0, 0, 0, 0.58), 0 0 0 1px rgba(18, 215, 255, 0.08);
            transform: translateY(10px) scale(0.98);
            animation: modalPop 0.2s ease forwards;
        }

        .confirm-modal::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                radial-gradient(circle at 18% 0%, rgba(18, 215, 255, 0.20), transparent 36%),
                radial-gradient(circle at 100% 10%, rgba(255, 62, 165, 0.13), transparent 34%);
        }

        .confirm-modal > * {
            position: relative;
            z-index: 1;
        }

        .confirm-icon {
            width: 54px;
            height: 54px;
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
            font-size: 24px;
            background: rgba(255, 59, 95, 0.14);
            border: 1px solid rgba(255, 59, 95, 0.28);
            box-shadow: 0 18px 40px rgba(255, 59, 95, 0.16);
        }

        .confirm-icon.accept {
            background: rgba(38, 224, 168, 0.13);
            border-color: rgba(38, 224, 168, 0.28);
            box-shadow: 0 18px 40px rgba(38, 224, 168, 0.12);
        }

        .confirm-title {
            margin: 0;
            font-size: 24px;
            line-height: 1.2;
            letter-spacing: -0.4px;
        }

        .confirm-text {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .confirm-user {
            margin-top: 14px;
            padding: 13px 14px;
            border-radius: 16px;
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.11);
            color: var(--soft);
            font-weight: 900;
            word-break: break-word;
        }

        .confirm-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 22px;
            flex-wrap: wrap;
        }

        .confirm-actions .btn {
            min-width: 120px;
        }

        @keyframes modalFade {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes modalPop {
            to { transform: translateY(0) scale(1); }
        }

        .card:nth-child(2) { animation-delay: 0.05s; }
        .card:nth-child(3) { animation-delay: 0.10s; }
        .card:nth-child(4) { animation-delay: 0.15s; }
        .card:nth-child(5) { animation-delay: 0.20s; }

        @media (max-width: 1200px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .details-grid {
                grid-template-columns: 1fr;
            }

            .filter-box,
            .form-row {
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
            .app-header {
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
            <?php if (!empty($company_logo) && file_exists($company_logo)) { ?>
                <img src="<?php echo htmlspecialchars($company_logo); ?>" class="brand-img" alt="Company Logo">
            <?php } else { ?>
                <div class="brand-icon"><?php echo htmlspecialchars($sidebar_initial); ?></div>
            <?php } ?>

            <div class="brand-text"><?php echo htmlspecialchars($company_name); ?></div>
        </div>

        <div class="nav-title">Recruiter Menu</div>

        <a href="dashboard.php" class="nav-link">
            <span class="nav-icon">▦</span> Dashboard
        </a>

        <a href="post_job.php" class="nav-link">
            <span class="nav-icon">＋</span> Post Job
        </a>

        <a href="manage_jobs.php" class="nav-link">
            <span class="nav-icon">💼</span> Manage Jobs
        </a>

        <a href="applicants.php" class="nav-link active">
            <span class="nav-icon">👥</span> Applicants
        </a>

        <a href="rejected_applicants.php" class="nav-link">
            <span class="nav-icon">🚫</span> Rejected Applicants
        </a>

        <a href="applicants.php" class="nav-link <?php echo active_link('schedule_interview.php', $current_page); ?>">
            <span class="nav-icon">🗓️</span> Schedule Interview
        </a>

        <a href="messages.php" class="nav-link <?php echo active_link('messages.php', $current_page); ?>">
            <span class="nav-icon">✉️</span> Messenger

            <?php if (isset($unread_messages) && $unread_messages > 0) { ?>
                <span class="notif-badge"><?php echo $unread_messages; ?></span>
            <?php } ?>
        </a>

        <a href="notifications.php" class="nav-link">
            <span class="nav-icon">🔔</span> Notifications

            <?php if ($unread_notifications > 0) { ?>
                <span class="notif-badge"><?php echo $unread_notifications; ?></span>
            <?php } ?>
        </a>

        <a href="support_messages.php" class="nav-link">
            <span class="nav-icon">💬</span> Support Messages
        </a>

        <a href="profile.php" class="nav-link">
            <span class="nav-icon">👤</span> Profile
        </a>

        <a href="../auth/logout.php" class="nav-link logout">
            <span class="nav-icon">↪</span> Logout
        </a>
    </aside>

    <main class="main">
        <div class="topbar">
            <div>
                <h1>Applicants</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Review and manage applicants for your job posts.</p>
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
                <p class="card-title">Total Applications</p>
                <p class="card-number"><?php echo $total_applications; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending / Active</p>
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
        </section>

        <div class="filter-panel">
            <form method="GET" class="filter-box">
                <input 
                    type="text" 
                    name="keyword" 
                    placeholder="Search applicant, email, phone, job title, or status"
                    value="<?php echo htmlspecialchars($keyword); ?>"
                >

                <select name="job_id">
                    <option value="0">All Jobs</option>

                    <?php if ($jobs_for_filter && mysqli_num_rows($jobs_for_filter) > 0) { ?>
                        <?php while ($job_filter = mysqli_fetch_assoc($jobs_for_filter)) { ?>
                            <option value="<?php echo $job_filter['id']; ?>" <?php if ($job_id_filter == $job_filter['id']) echo 'selected'; ?>>
                                <?php echo htmlspecialchars($job_filter['title']); ?>
                            </option>
                        <?php } ?>
                    <?php } ?>
                </select>

                <select name="status">
                    <option value="active" <?php if ($status_filter === 'active') echo 'selected'; ?>>Active Applicants</option>
                    <option value="all" <?php if ($status_filter === 'all') echo 'selected'; ?>>All Applicants</option>
                    <option value="pending" <?php if ($status_filter === 'pending') echo 'selected'; ?>>Pending / Active</option>
                    <option value="interview" <?php if ($status_filter === 'interview') echo 'selected'; ?>>Interview</option>
                    <option value="accepted" <?php if ($status_filter === 'accepted') echo 'selected'; ?>>Accepted</option>
                    <option value="rejected" <?php if ($status_filter === 'rejected') echo 'selected'; ?>>Rejected</option>
                </select>

                <button type="submit" class="btn btn-primary">Filter</button>
                <a href="applicants.php" class="btn btn-light">Reset</a>
            </form>
        </div>

        <div class="panel">
            <?php if ($applications && mysqli_num_rows($applications) > 0) { ?>
                <?php while ($app = mysqli_fetch_assoc($applications)) { ?>
                    <?php
                        $status_css = application_status_class($app['status']);
                        $is_final = is_final_application_status($app['status']);
                    ?>

                    <div class="app-card <?php echo htmlspecialchars($status_css); ?>">
                        <div class="app-header">
                            <div>
                                <h2 class="app-name"><?php echo htmlspecialchars($app['applicant_name']); ?></h2>
                                <p class="meta">
                                    Email: <?php echo htmlspecialchars($app['applicant_email']); ?><br>
                                    Phone: <?php echo htmlspecialchars($app['phone'] ?? 'Not provided'); ?><br>
                                    Applied For: <?php echo htmlspecialchars($app['job_title']); ?><br>
                                    Applied At: <?php echo htmlspecialchars($app['applied_at']); ?>
                                </p>
                            </div>

                            <span class="status <?php echo htmlspecialchars($status_css); ?>">
                                <?php echo htmlspecialchars(ucwords($app['status'])); ?>
                            </span>
                        </div>

                        <div class="details-grid">
                            <div class="detail-box">
                                <strong>Skills</strong>
                                <span><?php echo nl2br(htmlspecialchars($app['skills'] ?? 'Not provided')); ?></span>
                            </div>

                            <div class="detail-box">
                                <strong>Education</strong>
                                <span><?php echo nl2br(htmlspecialchars($app['education'] ?? 'Not provided')); ?></span>
                            </div>

                            <div class="detail-box">
                                <strong>Experience</strong>
                                <span><?php echo nl2br(htmlspecialchars($app['experience'] ?? 'Not provided')); ?></span>
                            </div>
                        </div>

                        <div class="cover-box">
                            <strong>Cover Letter:</strong><br>
                            <?php echo !empty($app['cover_letter']) ? nl2br(htmlspecialchars($app['cover_letter'])) : 'No cover letter provided.'; ?>
                        </div>

                        <div class="remarks-box">
                            <strong>Recruiter Remarks:</strong><br>
                            <?php echo !empty($app['recruiter_remarks']) ? nl2br(htmlspecialchars($app['recruiter_remarks'])) : 'No remarks yet.'; ?>
                        </div>

                        <?php if (!$is_final) { ?>
                            <div class="status-form">
                                <form method="POST">
                                    <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">

                                    <div class="form-row">
                                        <div>
                                            <label>Status</label>
                                            <select name="status" required>
                                                <?php foreach ($allowed_statuses as $status_option) { ?>
                                                    <option value="<?php echo htmlspecialchars($status_option); ?>" <?php if ($app['status'] === $status_option) echo 'selected'; ?>>
                                                        <?php echo htmlspecialchars(ucwords($status_option)); ?>
                                                    </option>
                                                <?php } ?>
                                            </select>
                                        </div>

                                        <div>
                                            <label>Remarks</label>
                                            <textarea name="recruiter_remarks" placeholder="Write remarks for this applicant..."><?php echo htmlspecialchars($app['recruiter_remarks'] ?? ''); ?></textarea>
                                        </div>

                                        <button type="submit" name="update_status" class="btn btn-primary">
                                            Update Status
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php } ?>

                        <div class="actions">
                            <a href="view_applicant.php?application_id=<?php echo $app['application_id']; ?>" class="btn btn-primary">
                                View Profile
                            </a>

                            <?php if (!empty($app['resume_file'])) { ?>
                                <a href="../assets/uploads/resumes/<?php echo htmlspecialchars($app['resume_file']); ?>" target="_blank" class="btn btn-light">
                                    View Resume
                                </a>
                            <?php } ?>

                            <?php if (!$is_final) { ?>
                                <a href="schedule_interview.php?application_id=<?php echo $app['application_id']; ?>" class="btn btn-light">
                                    Schedule Interview
                                </a>

                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                    <button type="submit" name="accept_applicant" class="btn btn-success js-confirm-applicant-action"
                                            data-action="accept"
                                            data-user="<?php echo htmlspecialchars($app['applicant_name']); ?>"
                                            data-message="This applicant will be marked as accepted and notified about the result.">
                                        Accept Applicant
                                    </button>
                                </form>

                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="application_id" value="<?php echo $app['application_id']; ?>">
                                    <button type="submit" name="reject_applicant" class="btn btn-danger js-confirm-applicant-action"
                                            data-action="reject"
                                            data-user="<?php echo htmlspecialchars($app['applicant_name']); ?>"
                                            data-message="This applicant will be marked as rejected and notified about the result.">
                                        Reject Applicant
                                    </button>
                                </form>
                            <?php } else { ?>
                                <span class="btn btn-light">Final Status</span>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
            <?php } else { ?>
                <p class="empty">No applicants found.</p>
            <?php } ?>
        </div>
    </main>
</div>


<div class="confirm-backdrop" id="confirmBackdrop" aria-hidden="true">
    <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
        <div class="confirm-icon" id="confirmIcon">⚠️</div>
        <h2 class="confirm-title" id="confirmTitle">Confirm Action</h2>
        <p class="confirm-text" id="confirmMessage">Are you sure you want to continue?</p>
        <div class="confirm-user" id="confirmUser">Applicant</div>

        <div class="confirm-actions">
            <button type="button" class="btn btn-light" id="confirmCancel">Cancel</button>
            <button type="button" class="btn btn-danger" id="confirmProceed">Continue</button>
        </div>
    </div>
</div>

<script>
    const confirmBackdrop = document.getElementById('confirmBackdrop');
    const confirmTitle = document.getElementById('confirmTitle');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmUser = document.getElementById('confirmUser');
    const confirmIcon = document.getElementById('confirmIcon');
    const confirmProceed = document.getElementById('confirmProceed');
    const confirmCancel = document.getElementById('confirmCancel');
    let pendingSubmitButton = null;

    document.querySelectorAll('.js-confirm-applicant-action').forEach((button) => {
        button.addEventListener('click', function(event) {
            event.preventDefault();

            const action = this.dataset.action || 'continue';
            const applicant = this.dataset.user || 'this applicant';
            const message = this.dataset.message || 'Are you sure you want to continue?';
            const isAccept = action === 'accept';

            pendingSubmitButton = this;
            confirmTitle.textContent = isAccept ? 'Accept this applicant?' : 'Reject this applicant?';
            confirmMessage.textContent = message;
            confirmUser.textContent = applicant;
            confirmIcon.textContent = isAccept ? '✅' : '🚫';
            confirmIcon.classList.toggle('accept', isAccept);
            confirmProceed.textContent = isAccept ? 'Accept Applicant' : 'Reject Applicant';
            confirmProceed.className = isAccept ? 'btn btn-success' : 'btn btn-danger';

            confirmBackdrop.classList.add('show');
            confirmBackdrop.setAttribute('aria-hidden', 'false');
        });
    });

    function closeConfirmModal() {
        confirmBackdrop.classList.remove('show');
        confirmBackdrop.setAttribute('aria-hidden', 'true');
        pendingSubmitButton = null;
    }

    confirmProceed.addEventListener('click', function() {
        if (!pendingSubmitButton || !pendingSubmitButton.form) {
            closeConfirmModal();
            return;
        }

        if (typeof pendingSubmitButton.form.requestSubmit === 'function') {
            pendingSubmitButton.form.requestSubmit(pendingSubmitButton);
        } else {
            const hiddenSubmit = document.createElement('input');
            hiddenSubmit.type = 'hidden';
            hiddenSubmit.name = pendingSubmitButton.name;
            hiddenSubmit.value = pendingSubmitButton.value || '1';
            pendingSubmitButton.form.appendChild(hiddenSubmit);
            pendingSubmitButton.form.submit();
        }
    });

    confirmCancel.addEventListener('click', closeConfirmModal);

    confirmBackdrop.addEventListener('click', function(event) {
        if (event.target === confirmBackdrop) {
            closeConfirmModal();
        }
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape' && confirmBackdrop.classList.contains('show')) {
            closeConfirmModal();
        }
    });
</script>

</body>
</html>