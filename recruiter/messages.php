<?php
// recruiter/messages.php
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

$message = "";
$error = "";

// Recruiter profile picture/name
$recruiter_profile_picture = "";

$recruiter_query = mysqli_query($conn, "
    SELECT 
        users.full_name,
        users.profile_picture,
        recruiter_profiles.company_logo
    FROM users 
    LEFT JOIN recruiter_profiles ON users.id = recruiter_profiles.user_id
    WHERE users.id = '$recruiter_id' 
    LIMIT 1
");

if ($recruiter_query && mysqli_num_rows($recruiter_query) > 0) {
    $recruiter_data = mysqli_fetch_assoc($recruiter_query);

    if (!empty($recruiter_data['full_name'])) {
        $full_name = $recruiter_data['full_name'];
        $_SESSION['full_name'] = $full_name;
    }

    if (!empty($recruiter_data['profile_picture'])) {
        $picture_name = $recruiter_data['profile_picture'];

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
    } elseif (!empty($recruiter_data['company_logo'])) {
        $logo_file = __DIR__ . "/../assets/uploads/company_logos/" . $recruiter_data['company_logo'];

        if (file_exists($logo_file)) {
            $recruiter_profile_picture = "../assets/uploads/company_logos/" . $recruiter_data['company_logo'];
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
    $sidebar_initial = "R";
}

function active_link($page_name, $current_page) {
    return $page_name === $current_page ? "active" : "";
}

function get_count($conn, $sql) {
    $result = mysqli_query($conn, $sql);

    if ($result) {
        $row = mysqli_fetch_assoc($result);
        return $row['total'] ?? 0;
    }

    return 0;
}

// Notification badge count
$unread_notifications = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM notifications 
    WHERE user_id = '$recruiter_id'
    AND is_read = 0
");

// Unread message count
$unread_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM messages 
    WHERE receiver_id = '$recruiter_id'
    AND is_read = 0
");

// Selected application
$application_id = isset($_GET['application_id']) ? intval($_GET['application_id']) : 0;

$selected_application = null;
$applicant_id = 0;

// Get conversations/applications under this recruiter's jobs
$applications_sql = "
    SELECT 
        applications.id AS application_id,
        applications.status,
        applications.applied_at,
        applications.applicant_id,
        jobs.id AS job_id,
        jobs.title AS job_title,
        users.full_name AS applicant_name,
        users.email AS applicant_email,
        applicant_profiles.phone AS applicant_phone,
        (
            SELECT COUNT(*) 
            FROM messages 
            WHERE messages.application_id = applications.id
            AND messages.receiver_id = '$recruiter_id'
            AND messages.is_read = 0
        ) AS unread_count,
        (
            SELECT messages.message
            FROM messages 
            WHERE messages.application_id = applications.id
            ORDER BY messages.created_at DESC
            LIMIT 1
        ) AS last_message,
        (
            SELECT messages.created_at
            FROM messages 
            WHERE messages.application_id = applications.id
            ORDER BY messages.created_at DESC
            LIMIT 1
        ) AS last_message_at
    FROM applications
    JOIN jobs ON applications.job_id = jobs.id
    JOIN users ON applications.applicant_id = users.id
    LEFT JOIN applicant_profiles ON users.id = applicant_profiles.user_id
    WHERE jobs.recruiter_id = '$recruiter_id'
    ORDER BY 
        CASE 
            WHEN last_message_at IS NULL THEN 1
            ELSE 0
        END,
        last_message_at DESC,
        applications.applied_at DESC
";

$applications = mysqli_query($conn, $applications_sql);

// Auto-select first conversation
if ($application_id <= 0 && $applications && mysqli_num_rows($applications) > 0) {
    $first_application = mysqli_fetch_assoc($applications);
    $application_id = intval($first_application['application_id']);

    mysqli_data_seek($applications, 0);
}

// Validate selected application
if ($application_id > 0) {
    $selected_sql = "
        SELECT 
            applications.id AS application_id,
            applications.status,
            applications.applied_at,
            applications.applicant_id,
            applications.resume_file,
            applications.cover_letter,
            jobs.id AS job_id,
            jobs.title AS job_title,
            jobs.recruiter_id,
            users.full_name AS applicant_name,
            users.email AS applicant_email,
            applicant_profiles.phone AS applicant_phone,
            applicant_profiles.address AS applicant_address,
            applicant_profiles.skills AS applicant_skills,
            applicant_profiles.education AS applicant_education,
            applicant_profiles.experience AS applicant_experience
        FROM applications
        JOIN jobs ON applications.job_id = jobs.id
        JOIN users ON applications.applicant_id = users.id
        LEFT JOIN applicant_profiles ON users.id = applicant_profiles.user_id
        WHERE applications.id = ?
        AND jobs.recruiter_id = ?
        LIMIT 1
    ";

    $selected_stmt = mysqli_prepare($conn, $selected_sql);

    if ($selected_stmt) {
        mysqli_stmt_bind_param($selected_stmt, "ii", $application_id, $recruiter_id);
        mysqli_stmt_execute($selected_stmt);
        $selected_result = mysqli_stmt_get_result($selected_stmt);

        if ($selected_result && mysqli_num_rows($selected_result) === 1) {
            $selected_application = mysqli_fetch_assoc($selected_result);
            $applicant_id = intval($selected_application['applicant_id']);
        } else {
            $application_id = 0;
        }
    }
}

// Send message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    $application_id = intval($_POST['application_id']);
    $message_text = trim($_POST['message_text']);

    if ($application_id <= 0) {
        $error = "Please select an application conversation.";
    } elseif (empty($message_text)) {
        $error = "Message cannot be empty.";
    } else {
        $verify_sql = "
            SELECT 
                applications.id,
                applications.applicant_id,
                jobs.title AS job_title,
                jobs.recruiter_id
            FROM applications
            JOIN jobs ON applications.job_id = jobs.id
            WHERE applications.id = ?
            AND jobs.recruiter_id = ?
            LIMIT 1
        ";

        $verify_stmt = mysqli_prepare($conn, $verify_sql);

        if (!$verify_stmt) {
            $error = "Database prepare failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($verify_stmt, "ii", $application_id, $recruiter_id);
            mysqli_stmt_execute($verify_stmt);
            $verify_result = mysqli_stmt_get_result($verify_stmt);

            if ($verify_result && mysqli_num_rows($verify_result) === 1) {
                $verified = mysqli_fetch_assoc($verify_result);
                $receiver_id = intval($verified['applicant_id']);

                $insert_sql = "
                    INSERT INTO messages 
                        (sender_id, receiver_id, application_id, message, is_read)
                    VALUES 
                        (?, ?, ?, ?, 0)
                ";

                $insert_stmt = mysqli_prepare($conn, $insert_sql);

                if (!$insert_stmt) {
                    $error = "Database prepare failed: " . mysqli_error($conn);
                } else {
                    mysqli_stmt_bind_param(
                        $insert_stmt,
                        "iiis",
                        $recruiter_id,
                        $receiver_id,
                        $application_id,
                        $message_text
                    );

                    if (mysqli_stmt_execute($insert_stmt)) {
                        create_notification(
                            $conn,
                            $receiver_id,
                            "New Message from Recruiter",
                            $full_name . " sent you a message about " . $verified['job_title'] . ".",
                            "../applicant/messages.php?application_id=" . $application_id
                        );

                        header("Location: messages.php?application_id=" . $application_id . "&message=Message sent successfully.");
                        exit();
                    } else {
                        $error = "Failed to send message.";
                    }
                }
            } else {
                $error = "Invalid application conversation.";
            }
        }
    }
}

if (isset($_GET['message'])) {
    $message = trim($_GET['message']);
}

if (isset($_GET['error'])) {
    $error = trim($_GET['error']);
}

// Mark messages as read for selected conversation
if ($application_id > 0) {
    $mark_read_sql = "
        UPDATE messages
        SET is_read = 1
        WHERE application_id = ?
        AND receiver_id = ?
    ";

    $mark_read_stmt = mysqli_prepare($conn, $mark_read_sql);

    if ($mark_read_stmt) {
        mysqli_stmt_bind_param($mark_read_stmt, "ii", $application_id, $recruiter_id);
        mysqli_stmt_execute($mark_read_stmt);
    }
}

// Get conversation messages
$conversation_messages = null;

if ($application_id > 0 && $selected_application) {
    $messages_sql = "
        SELECT 
            messages.*,
            sender.full_name AS sender_name,
            sender.role AS sender_role
        FROM messages
        JOIN users AS sender ON messages.sender_id = sender.id
        WHERE messages.application_id = ?
        AND (
            messages.sender_id = ?
            OR messages.receiver_id = ?
        )
        ORDER BY messages.created_at ASC
    ";

    $messages_stmt = mysqli_prepare($conn, $messages_sql);

    if ($messages_stmt) {
        mysqli_stmt_bind_param($messages_stmt, "iii", $application_id, $recruiter_id, $recruiter_id);
        mysqli_stmt_execute($messages_stmt);
        $conversation_messages = mysqli_stmt_get_result($messages_stmt);
    }
}

// Refresh unread message count after marking read
$unread_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM messages 
    WHERE receiver_id = '$recruiter_id'
    AND is_read = 0
");

$resume_web_path = "";

if ($selected_application && !empty($selected_application['resume_file'])) {
    $resume_file = __DIR__ . "/../assets/uploads/resumes/" . $selected_application['resume_file'];

    if (file_exists($resume_file)) {
        $resume_web_path = "../assets/uploads/resumes/" . $selected_application['resume_file'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Recruiter Messages | Job Application System</title>
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
        body::-webkit-scrollbar,
        .conversation-list::-webkit-scrollbar,
        .messages-box::-webkit-scrollbar {
            width: 9px;
        }

        .sidebar::-webkit-scrollbar-track,
        body::-webkit-scrollbar-track,
        .conversation-list::-webkit-scrollbar-track,
        .messages-box::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.04);
        }

        .sidebar::-webkit-scrollbar-thumb,
        body::-webkit-scrollbar-thumb,
        .conversation-list::-webkit-scrollbar-thumb,
        .messages-box::-webkit-scrollbar-thumb {
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
        .panel,
        .conversation-item,
        .chat-header,
        .messages-box,
        .message-bubble,
        .info-box {
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
        .conversation-item::before,
        .chat-header::before,
        .messages-box::before,
        .message-bubble::before,
        .info-box::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .topbar > *,
        .panel > *,
        .conversation-item > *,
        .chat-header > *,
        .messages-box > *,
        .message-bubble > *,
        .info-box > * {
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

        .chat-layout {
            display: grid;
            grid-template-columns: 330px minmax(0, 1fr) 320px;
            gap: 18px;
            align-items: start;
        }

        .panel {
            border-radius: 28px;
            padding: 20px;
            animation: fadeUp 0.58s ease both;
        }

        .chat-panel {
            min-height: 660px;
        }

        .company-info-panel,
        .applicant-info-panel {
            min-height: 420px;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 15px;
        }

        .panel-header h2,
        .chat-header h2,
        .company-panel h2,
        .applicant-panel h2 {
            margin: 0;
            color: var(--text);
            font-size: 21px;
            letter-spacing: -0.25px;
        }

        .conversation-list {
            max-height: 660px;
            overflow-y: auto;
            padding-right: 4px;
        }

        .conversation-item {
            display: block;
            text-decoration: none;
            border-radius: 20px;
            padding: 15px;
            margin-bottom: 12px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease, background 0.24s ease;
        }

        .conversation-item:hover,
        .conversation-item.active {
            transform: translateY(-3px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 24px 70px rgba(0, 0, 0, 0.42), 0 0 0 1px rgba(18, 215, 255, 0.07);
            background: rgba(255, 255, 255, 0.105);
        }

        .conversation-item.active {
            border-color: rgba(18, 215, 255, 0.42);
        }

        .conversation-title {
            margin: 0 0 7px;
            color: var(--text);
            font-size: 15px;
            font-weight: 900;
        }

        .conversation-meta,
        .conversation-last {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.55;
        }

        .conversation-last {
            margin-top: 9px;
            color: rgba(220, 231, 255, 0.82);
        }

        .unread-dot {
            position: absolute;
            top: 13px;
            right: 13px;
            min-width: 23px;
            height: 23px;
            padding: 0 7px;
            border-radius: 999px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 62, 165, 0.88);
            color: #ffffff;
            font-size: 12px;
            font-weight: 900;
            box-shadow: 0 0 18px rgba(255, 62, 165, 0.36);
        }

        .chat-header {
            border-radius: 22px;
            padding: 17px;
            margin-bottom: 14px;
            box-shadow: 0 16px 38px rgba(0, 0, 0, 0.24);
        }

        .chat-header p {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
        }

        .messages-box {
            border-radius: 24px;
            height: 420px;
            overflow-y: auto;
            padding: 18px;
            box-shadow: inset 0 0 30px rgba(0, 0, 0, 0.16), var(--shadow);
        }

        .message-row {
            display: flex;
            margin-bottom: 13px;
        }

        .message-row.mine {
            justify-content: flex-end;
        }

        .message-bubble {
            max-width: 76%;
            border-radius: 20px;
            padding: 13px 15px;
            color: var(--soft);
            font-size: 14px;
            line-height: 1.65;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.24);
        }

        .message-row.mine .message-bubble {
            background: linear-gradient(135deg, var(--cyan-2), #2356e8);
            color: #ffffff;
            border-color: rgba(18, 215, 255, 0.34);
            box-shadow: 0 16px 34px rgba(18, 215, 255, 0.18);
        }

        .message-sender {
            font-size: 12px;
            font-weight: 900;
            margin-bottom: 5px;
            color: #eef5ff;
            opacity: 0.9;
        }

        .message-time {
            margin-top: 7px;
            font-size: 11px;
            color: rgba(220, 231, 255, 0.72);
        }

        .send-box {
            margin-top: 14px;
        }

        textarea {
            width: 100%;
            min-height: 105px;
            padding: 14px 15px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 18px;
            outline: none;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
            resize: vertical;
            font-size: 14px;
            line-height: 1.6;
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }

        textarea::placeholder {
            color: rgba(220, 231, 255, 0.58);
        }

        textarea:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .send-actions {
            display: flex;
            justify-content: flex-end;
            margin-top: 10px;
        }

        .company-logo-wrap {
            text-align: center;
            margin-bottom: 16px;
        }

        .company-logo,
        .company-placeholder {
            width: 88px;
            height: 88px;
            margin: 0 auto;
            border-radius: 24px;
        }

        .company-placeholder {
            font-size: 32px;
        }

        .info-box {
            border-radius: 17px;
            padding: 14px;
            margin-bottom: 12px;
            box-shadow: 0 14px 35px rgba(0, 0, 0, 0.18);
        }

        .info-box strong {
            display: block;
            color: #eef5ff;
            font-size: 13px;
            margin-bottom: 7px;
            font-weight: 900;
        }

        .info-box p,
        .info-box a {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
            word-break: break-word;
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
            .chat-layout {
                grid-template-columns: 310px 1fr;
            }

            .company-info-panel,
        .applicant-info-panel {
                grid-column: span 2;
            }
        }

        @media (max-width: 950px) {
            .chat-layout {
                grid-template-columns: 1fr;
            }

            .company-info-panel,
        .applicant-info-panel {
                grid-column: span 1;
            }

            .conversation-list {
                max-height: 360px;
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

            .message-bubble {
                max-width: 90%;
            }

            .btn {
                width: 100%;
            }
        }
    

        .applicant-panel {
            position: relative;
            z-index: 1;
        }

        .applicant-panel h2 {
            margin: 0 0 18px;
            color: var(--text);
            font-size: 22px;
            letter-spacing: -0.3px;
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
                <h1>Messages</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Chat with applicants based on their submitted applications.</p>
            </div>

            <div class="user-pill">Recruiter</div>
        </div>

        <?php if (!empty($message)) { ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
        <?php } ?>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php } ?>

        <div class="chat-layout">
            <div class="panel">
                <div class="panel-header">
                    <h2>Applicant Conversations</h2>
                </div>

                <div class="conversation-list">
                    <?php if ($applications && mysqli_num_rows($applications) > 0) { ?>
                        <?php while ($app = mysqli_fetch_assoc($applications)) { ?>
                            <?php $is_active = intval($app['application_id']) === intval($application_id); ?>

                            <a href="messages.php?application_id=<?php echo intval($app['application_id']); ?>" 
                               class="conversation-item <?php echo $is_active ? 'active' : ''; ?>">
                                <p class="conversation-title">
                                    <?php echo htmlspecialchars($app['applicant_name']); ?>
                                </p>

                                <p class="conversation-meta">
                                    Job: <?php echo htmlspecialchars($app['job_title']); ?><br>
                                    Status: <?php echo htmlspecialchars($app['status']); ?><br>
                                    Applied: <?php echo htmlspecialchars($app['applied_at']); ?>
                                </p>

                                <?php if (!empty($app['last_message'])) { ?>
                                    <p class="conversation-last">
                                        <?php echo htmlspecialchars(substr($app['last_message'], 0, 75)); ?>
                                        <?php if (strlen($app['last_message']) > 75) echo "..."; ?>
                                    </p>
                                <?php } else { ?>
                                    <p class="conversation-last">No messages yet.</p>
                                <?php } ?>

                                <?php if (intval($app['unread_count']) > 0) { ?>
                                    <span class="unread-dot"><?php echo intval($app['unread_count']); ?></span>
                                <?php } ?>
                            </a>
                        <?php } ?>
                    <?php } else { ?>
                        <p class="empty">No applicant conversations yet. Applications will appear here when applicants apply to your jobs.</p>
                    <?php } ?>
                </div>
            </div>

            <div class="panel chat-panel">
                <?php if ($selected_application) { ?>
                    <div class="chat-header">
                        <h2><?php echo htmlspecialchars($selected_application['applicant_name']); ?></h2>
                        <p>
                            Job: <?php echo htmlspecialchars($selected_application['job_title']); ?><br>
                            Email: <?php echo htmlspecialchars($selected_application['applicant_email']); ?><br>
                            Application Status: <?php echo htmlspecialchars($selected_application['status']); ?>
                        </p>
                    </div>

                    <div class="messages-box" id="messagesBox">
                        <?php if ($conversation_messages && mysqli_num_rows($conversation_messages) > 0) { ?>
                            <?php while ($chat = mysqli_fetch_assoc($conversation_messages)) { ?>
                                <?php $mine = intval($chat['sender_id']) === $recruiter_id; ?>

                                <div class="message-row <?php echo $mine ? 'mine' : ''; ?>">
                                    <div class="message-bubble">
                                        <div class="message-sender">
                                            <?php echo $mine ? 'You' : htmlspecialchars($chat['sender_name']); ?>
                                        </div>

                                        <div>
                                            <?php echo nl2br(htmlspecialchars($chat['message'])); ?>
                                        </div>

                                        <div class="message-time">
                                            <?php echo htmlspecialchars($chat['created_at']); ?>
                                            <?php if ($mine && intval($chat['is_read']) === 1) { ?>
                                                · Read
                                            <?php } elseif ($mine) { ?>
                                                · Sent
                                            <?php } ?>
                                        </div>
                                    </div>
                                </div>
                            <?php } ?>
                        <?php } else { ?>
                            <p class="empty">No messages yet. Start the conversation with the applicant.</p>
                        <?php } ?>
                    </div>

                    <div class="send-box">
                        <form method="POST">
                            <input type="hidden" name="application_id" value="<?php echo intval($application_id); ?>">

                            <textarea name="message_text" placeholder="Write your message to the applicant..." required></textarea>

                            <div class="send-actions">
                                <button type="submit" name="send_message" class="btn btn-primary">
                                    Send Message
                                </button>
                            </div>
                        </form>
                    </div>
                <?php } else { ?>
                    <p class="empty">Select an applicant conversation to start messaging.</p>
                <?php } ?>
            </div>

            <div class="panel applicant-info-panel">
                <div class="applicant-panel">
                    <h2>Applicant Details</h2>

                    <?php if ($selected_application) { ?>
                        <div class="info-box">
                            <strong>Name</strong>
                            <p><?php echo htmlspecialchars($selected_application['applicant_name']); ?></p>
                        </div>

                        <div class="info-box">
                            <strong>Email</strong>
                            <p><?php echo htmlspecialchars($selected_application['applicant_email']); ?></p>
                        </div>

                        <div class="info-box">
                            <strong>Phone</strong>
                            <p><?php echo htmlspecialchars($selected_application['applicant_phone'] ?? 'Not provided'); ?></p>
                        </div>

                        <div class="info-box">
                            <strong>Address</strong>
                            <p><?php echo htmlspecialchars($selected_application['applicant_address'] ?? 'Not provided'); ?></p>
                        </div>

                        <div class="info-box">
                            <strong>Skills</strong>
                            <p><?php echo nl2br(htmlspecialchars($selected_application['applicant_skills'] ?? 'Not provided')); ?></p>
                        </div>

                        <div class="info-box">
                            <strong>Education</strong>
                            <p><?php echo nl2br(htmlspecialchars($selected_application['applicant_education'] ?? 'Not provided')); ?></p>
                        </div>

                        <div class="info-box">
                            <strong>Experience</strong>
                            <p><?php echo nl2br(htmlspecialchars($selected_application['applicant_experience'] ?? 'Not provided')); ?></p>
                        </div>

                        <div class="info-box">
                            <strong>Cover Letter</strong>
                            <p><?php echo nl2br(htmlspecialchars($selected_application['cover_letter'] ?? 'Not provided')); ?></p>
                        </div>

                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-top:14px;">
                            <a href="view_applicant.php?application_id=<?php echo intval($application_id); ?>" class="btn btn-light">
                                View Full Profile
                            </a>

                            <?php if (!empty($resume_web_path)) { ?>
                                <a href="<?php echo htmlspecialchars($resume_web_path); ?>" target="_blank" class="btn btn-primary">
                                    View Resume
                                </a>
                            <?php } ?>
                        </div>
                    <?php } else { ?>
                        <p class="empty">No applicant selected.</p>
                    <?php } ?>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
    const messagesBox = document.getElementById("messagesBox");

    if (messagesBox) {
        messagesBox.scrollTop = messagesBox.scrollHeight;
    }
</script>

</body>
</html>