<?php
// recruiter/view_applicant.php
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

    if ($status === 'accepted' || $status === 'candidate accepted offer') {
        return 'accepted';
    }

    if (
        $status === 'rejected' ||
        $status === 'rejected by client' ||
        $status === 'declined by client' ||
        $status === 'candidate declined offer'
    ) {
        return 'rejected';
    }

    if ($status === 'interview' || $status === 'schedule interview') {
        return 'interview';
    }

    return 'pending';
}

// Unread notification count
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

// Get applicant profile and make sure the application belongs to this recruiter
$sql = "
    SELECT 
        applications.id AS application_id,
        applications.status AS application_status,
        applications.cover_letter,
        applications.resume_file AS application_resume,
        applications.recruiter_remarks,
        applications.applied_at,

        jobs.id AS job_id,
        jobs.title AS job_title,
        jobs.location AS job_location,
        jobs.job_type,
        jobs.salary_min,
        jobs.salary_max,

        users.id AS applicant_id,
        users.full_name AS applicant_name,
        users.email AS applicant_email,
        users.profile_picture,
        users.created_at AS applicant_registered,

        applicant_profiles.phone,
        applicant_profiles.address,
        applicant_profiles.skills,
        applicant_profiles.education,
        applicant_profiles.experience,
        applicant_profiles.resume_file AS profile_resume,
        applicant_profiles.portfolio_link,
        applicant_profiles.profile_views
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
    header("Location: applicants.php?error=Applicant not found.");
    exit();
}

$applicant = mysqli_fetch_assoc($result);
$applicant_id = intval($applicant['applicant_id']);

// Create applicant profile row if missing, then count profile view
$profile_check = mysqli_query($conn, "
    SELECT id 
    FROM applicant_profiles 
    WHERE user_id = '$applicant_id'
    LIMIT 1
");

if ($profile_check && mysqli_num_rows($profile_check) > 0) {
    mysqli_query($conn, "
        UPDATE applicant_profiles
        SET profile_views = IFNULL(profile_views, 0) + 1
        WHERE user_id = '$applicant_id'
    ");
} else {
    mysqli_query($conn, "
        INSERT INTO applicant_profiles (user_id, profile_views)
        VALUES ('$applicant_id', 1)
    ");
}

// Refresh profile views
$views_query = mysqli_query($conn, "
    SELECT profile_views
    FROM applicant_profiles
    WHERE user_id = '$applicant_id'
    LIMIT 1
");

if ($views_query && mysqli_num_rows($views_query) > 0) {
    $views_data = mysqli_fetch_assoc($views_query);
    $applicant['profile_views'] = $views_data['profile_views'];
}

$status_css = status_class($applicant['application_status']);

$applicant_picture = "";
if (!empty($applicant['profile_picture'])) {
    $picture_file = __DIR__ . "/../assets/uploads/profiles/" . $applicant['profile_picture'];

    if (file_exists($picture_file)) {
        $applicant_picture = "../assets/uploads/profiles/" . $applicant['profile_picture'];
    }
}

$applicant_initial = strtoupper(substr($applicant['applicant_name'] ?? 'A', 0, 1));

if ($applicant_initial === "") {
    $applicant_initial = "A";
}

$resume_file = "";
$resume_path = "";

if (!empty($applicant['application_resume'])) {
    $resume_file = $applicant['application_resume'];
} elseif (!empty($applicant['profile_resume'])) {
    $resume_file = $applicant['profile_resume'];
}

if (!empty($resume_file)) {
    $resume_check = __DIR__ . "/../assets/uploads/resumes/" . $resume_file;

    if (file_exists($resume_check)) {
        $resume_path = "../assets/uploads/resumes/" . $resume_file;
    }
}

$salary_min = floatval($applicant['salary_min'] ?? 0);
$salary_max = floatval($applicant['salary_max'] ?? 0);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Applicant | Job Application System</title>
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
        .panel,
        .info-box,
        .text-box {
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
        .text-box::before {
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
        .text-box > * {
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
            grid-template-columns: 0.75fr 1.25fr;
            gap: 18px;
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            animation: fadeUp 0.55s ease both;
        }

        .panel.full {
            grid-column: span 2;
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

        .profile-card {
            text-align: center;
        }

        .profile-img,
        .profile-placeholder {
            width: 122px;
            height: 122px;
            border-radius: 34px;
            margin: 0 auto 14px;
        }

        .profile-img {
            object-fit: cover;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

        .profile-placeholder {
            background: linear-gradient(135deg, var(--cyan-2), var(--blue));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: 900;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

        .profile-card h2 {
            margin: 0;
            font-size: 24px;
            color: var(--text);
        }

        .profile-card p {
            margin: 6px 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
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
            margin-top: 10px;
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
            border-color: rgba(18, 215, 255, 0.27);
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

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .info-box {
            border-radius: 17px;
            padding: 15px;
            min-height: 82px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .info-box:hover,
        .text-box:hover,
        .panel:hover {
            border-color: rgba(18, 215, 255, 0.30);
        }

        .info-box strong,
        .text-box strong {
            display: block;
            margin-bottom: 8px;
            color: #eef5ff;
            font-size: 13px;
            font-weight: 900;
        }

        .info-box span,
        .info-box a {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
            word-break: break-word;
        }

        .info-box a {
            color: #73e9ff;
            font-weight: 900;
            text-decoration: none;
        }

        .info-box a:hover {
            text-decoration: underline;
        }

        .text-box {
            border-radius: 17px;
            padding: 16px;
            margin-bottom: 14px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.75;
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

        .btn-success {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .btn-danger {
            background: rgba(255, 59, 95, 0.14);
            color: #ffc4ce;
            border-color: rgba(255, 59, 95, 0.27);
        }

        @media (max-width: 1000px) {
            .grid,
            .info-grid {
                grid-template-columns: 1fr;
            }

            .panel.full {
                grid-column: span 1;
            }
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

            .actions .btn {
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
                <h1>Applicant Profile</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Review applicant details, resume, and application information.</p>
            </div>

            <div class="user-pill">Recruiter</div>
        </div>

        <div class="grid">
            <div class="panel profile-card">
                <?php if (!empty($applicant_picture)) { ?>
                    <img src="<?php echo htmlspecialchars($applicant_picture); ?>" class="profile-img" alt="Applicant Profile">
                <?php } else { ?>
                    <div class="profile-placeholder">
                        <?php echo htmlspecialchars($applicant_initial); ?>
                    </div>
                <?php } ?>

                <h2><?php echo htmlspecialchars($applicant['applicant_name']); ?></h2>
                <p><?php echo htmlspecialchars($applicant['applicant_email']); ?></p>

                <span class="status <?php echo htmlspecialchars($status_css); ?>">
                    <?php echo htmlspecialchars(ucwords($applicant['application_status'])); ?>
                </span>

                <p style="margin-top:14px;">
                    Profile Views: <?php echo intval($applicant['profile_views'] ?? 0); ?>
                </p>

                <div class="actions" style="justify-content:center;">
                    <?php if (!empty($resume_path)) { ?>
                        <a href="<?php echo htmlspecialchars($resume_path); ?>" download class="btn btn-primary">
                            Download Resume
                        </a>
                    <?php } elseif (!empty($resume_file)) { ?>
                        <span class="btn btn-light">Resume file not found</span>
                    <?php } else { ?>
                        <span class="btn btn-light">No Resume Uploaded</span>
                    <?php } ?>

                    <a href="messages.php?application_id=<?php echo intval($application_id); ?>" class="btn btn-light">
                        Message
                    </a>
                </div>
            </div>

            <div class="panel">
                <h2>Basic Information</h2>
                <p class="panel-subtitle">Applicant contact and profile information.</p>

                <div class="info-grid">
                    <div class="info-box">
                        <strong>Phone</strong>
                        <span><?php echo htmlspecialchars($applicant['phone'] ?? 'Not provided'); ?></span>
                    </div>

                    <div class="info-box">
                        <strong>Registered At</strong>
                        <span><?php echo htmlspecialchars($applicant['applicant_registered']); ?></span>
                    </div>

                    <div class="info-box">
                        <strong>Address</strong>
                        <span><?php echo !empty($applicant['address']) ? nl2br(htmlspecialchars($applicant['address'])) : 'Not provided'; ?></span>
                    </div>

                    <div class="info-box">
                        <strong>Portfolio Link</strong>
                        <?php if (!empty($applicant['portfolio_link'])) { ?>
                            <a href="<?php echo htmlspecialchars($applicant['portfolio_link']); ?>" target="_blank">
                                <?php echo htmlspecialchars($applicant['portfolio_link']); ?>
                            </a>
                        <?php } else { ?>
                            <span>Not provided</span>
                        <?php } ?>
                    </div>
                </div>
            </div>

            <div class="panel">
                <h2>Application Details</h2>
                <p class="panel-subtitle">Job and application information.</p>

                <div class="info-grid">
                    <div class="info-box">
                        <strong>Job Title</strong>
                        <span><?php echo htmlspecialchars($applicant['job_title']); ?></span>
                    </div>

                    <div class="info-box">
                        <strong>Job Location</strong>
                        <span><?php echo htmlspecialchars($applicant['job_location']); ?></span>
                    </div>

                    <div class="info-box">
                        <strong>Job Type</strong>
                        <span><?php echo htmlspecialchars($applicant['job_type']); ?></span>
                    </div>

                    <div class="info-box">
                        <strong>Salary Range</strong>
                        <span>
                            ₱<?php echo number_format($salary_min, 2); ?>
                            -
                            ₱<?php echo number_format($salary_max, 2); ?>
                        </span>
                    </div>

                    <div class="info-box">
                        <strong>Date Applied</strong>
                        <span><?php echo htmlspecialchars($applicant['applied_at']); ?></span>
                    </div>

                    <div class="info-box">
                        <strong>Application Status</strong>
                        <span><?php echo htmlspecialchars(ucwords($applicant['application_status'])); ?></span>
                    </div>
                </div>

                <div class="actions">
                    <a href="applicants.php" class="btn btn-light">Back to Applicants</a>
                    <a href="schedule_interview.php?application_id=<?php echo intval($application_id); ?>" class="btn btn-primary">Schedule Interview</a>
                    <a href="messages.php?application_id=<?php echo intval($application_id); ?>" class="btn btn-light">Message Applicant</a>
                </div>
            </div>

            <div class="panel">
                <h2>Skills and Background</h2>
                <p class="panel-subtitle">Applicant qualifications and experience.</p>

                <div class="text-box">
                    <strong>Skills</strong>
                    <?php echo !empty($applicant['skills']) ? nl2br(htmlspecialchars($applicant['skills'])) : 'Not provided'; ?>
                </div>

                <div class="text-box">
                    <strong>Education</strong>
                    <?php echo !empty($applicant['education']) ? nl2br(htmlspecialchars($applicant['education'])) : 'Not provided'; ?>
                </div>

                <div class="text-box">
                    <strong>Experience</strong>
                    <?php echo !empty($applicant['experience']) ? nl2br(htmlspecialchars($applicant['experience'])) : 'Not provided'; ?>
                </div>
            </div>

            <div class="panel full">
                <h2>Cover Letter and Recruiter Remarks</h2>
                <p class="panel-subtitle">Application message and recruiter notes.</p>

                <div class="text-box">
                    <strong>Cover Letter</strong>
                    <?php echo !empty($applicant['cover_letter']) ? nl2br(htmlspecialchars($applicant['cover_letter'])) : 'No cover letter provided.'; ?>
                </div>

                <div class="text-box">
                    <strong>Recruiter Remarks</strong>
                    <?php echo !empty($applicant['recruiter_remarks']) ? nl2br(htmlspecialchars($applicant['recruiter_remarks'])) : 'No remarks yet.'; ?>
                </div>
            </div>
        </div>
    </main>
</div>

</body>
</html>