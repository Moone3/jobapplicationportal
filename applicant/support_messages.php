<?php
// applicant/support_messages.php
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

function support_status_class($row) {
    if (!empty($row['admin_reply'])) {
        return "replied";
    }

    if (!empty($row['status']) && strtolower(trim($row['status'])) === "read") {
        return "read";
    }

    return "pending";
}

function support_status_text($row) {
    if (!empty($row['admin_reply'])) {
        return "Replied";
    }

    if (!empty($row['status']) && strtolower(trim($row['status'])) === "read") {
        return "Seen by Admin";
    }

    return "Waiting for Reply";
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

// Send new support message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_support'])) {
    $subject = trim($_POST['subject']);
    $support_message = trim($_POST['support_message']);

    if (empty($subject) || empty($support_message)) {
        $error = "Subject and message are required.";
    } else {
        $insert_sql = "
            INSERT INTO contact_messages 
                (user_id, email, subject, message, status)
            SELECT 
                users.id,
                users.email,
                ?,
                ?,
                'unread'
            FROM users
            WHERE users.id = ?
            LIMIT 1
        ";

        $insert_stmt = mysqli_prepare($conn, $insert_sql);

        if (!$insert_stmt) {
            $error = "Database prepare failed: " . mysqli_error($conn);
        } else {
            mysqli_stmt_bind_param($insert_stmt, "ssi", $subject, $support_message, $applicant_id);

            if (mysqli_stmt_execute($insert_stmt)) {
                header("Location: support_messages.php?message=Support message sent successfully.");
                exit();
            } else {
                $error = "Failed to send support message.";
            }
        }
    }
}

// Filter
$status_filter = isset($_GET['status']) ? trim($_GET['status']) : "all";

// Support messages query
$sql = "
    SELECT *
    FROM contact_messages
    WHERE user_id = ?
";

$params = [$applicant_id];
$types = "i";

if ($status_filter === "replied") {
    $sql .= " AND admin_reply IS NOT NULL AND admin_reply != ''";
} elseif ($status_filter === "not_replied") {
    $sql .= " AND (admin_reply IS NULL OR admin_reply = '')";
} elseif ($status_filter === "read") {
    $sql .= " AND status = 'read' AND (admin_reply IS NULL OR admin_reply = '')";
} elseif ($status_filter === "unread") {
    $sql .= " AND status = 'unread' AND (admin_reply IS NULL OR admin_reply = '')";
}

$sql .= " ORDER BY created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!$stmt) {
    die("Database prepare failed: " . mysqli_error($conn));
}

mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($support_messages = $stmt);
$messages_result = mysqli_stmt_get_result($stmt);

// Counts
$total_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM contact_messages 
    WHERE user_id = '$applicant_id'
");

$replied_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM contact_messages 
    WHERE user_id = '$applicant_id' 
    AND admin_reply IS NOT NULL 
    AND admin_reply != ''
");

$pending_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM contact_messages 
    WHERE user_id = '$applicant_id' 
    AND (admin_reply IS NULL OR admin_reply = '')
");

$seen_messages = get_count($conn, "
    SELECT COUNT(*) AS total 
    FROM contact_messages 
    WHERE user_id = '$applicant_id'
    AND status = 'read'
    AND (admin_reply IS NULL OR admin_reply = '')
");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Applicant Support Messages | Job Application System</title>
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
        .filter-box,
        .message-card,
        .box {
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
        .filter-box::before,
        .message-card::before,
        .box::before {
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
        .filter-box > *,
        .message-card > *,
        .box > * {
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
        .message-card:hover {
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

        .grid {
            display: grid;
            grid-template-columns: 0.85fr 1.15fr;
            gap: 18px;
        }

        .panel {
            border-radius: 28px;
            padding: 24px;
            animation: fadeUp 0.64s ease both;
        }

        .panel h2 {
            margin: 0 0 8px;
            font-size: 23px;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .panel-subtitle {
            margin: 0 0 20px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.6;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 900;
            color: #eef5ff;
        }

        input,
        textarea,
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

        textarea {
            min-height: 140px;
            resize: vertical;
            line-height: 1.6;
        }

        input::placeholder,
        textarea::placeholder {
            color: rgba(220, 231, 255, 0.6);
        }

        select option {
            color: #101827;
            background: #ffffff;
        }

        input:focus,
        textarea:focus,
        select:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .form-group {
            margin-bottom: 16px;
        }

        .filter-box {
            display: flex;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            margin-bottom: 22px;
            border-radius: 18px;
            padding: 16px;
        }

        .message-card {
            border-radius: 22px;
            padding: 20px;
            margin-bottom: 16px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .message-card.replied {
            border-color: rgba(38, 224, 168, 0.26);
            background: linear-gradient(135deg, rgba(38, 224, 168, 0.10), rgba(255, 255, 255, 0.05));
        }

        .message-card.read {
            border-color: rgba(255, 255, 255, 0.16);
        }

        .message-card.pending {
            border-color: rgba(255, 180, 84, 0.30);
            background: linear-gradient(135deg, rgba(255, 180, 84, 0.10), rgba(255, 255, 255, 0.05));
        }

        .message-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .message-title {
            margin: 0;
            font-size: 20px;
            color: var(--text);
            letter-spacing: -0.2px;
        }

        .meta {
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
            margin: 6px 0 0;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border: 1px solid rgba(255, 180, 84, 0.28);
            font-size: 12px;
            font-weight: 900;
            white-space: nowrap;
        }

        .badge.replied {
            background: rgba(38, 224, 168, 0.13);
            color: #adffe6;
            border-color: rgba(38, 224, 168, 0.26);
        }

        .badge.read {
            background: rgba(255, 255, 255, 0.10);
            color: #e7edff;
            border-color: rgba(255, 255, 255, 0.14);
        }

        .badge.pending {
            background: rgba(255, 180, 84, 0.14);
            color: #ffe2b1;
            border-color: rgba(255, 180, 84, 0.28);
        }

        .box {
            border-radius: 16px;
            padding: 14px;
            margin-top: 12px;
            box-shadow: none;
        }

        .box strong {
            display: block;
            margin-bottom: 7px;
            color: #eef5ff;
            font-size: 14px;
            font-weight: 900;
        }

        .box p {
            margin: 0;
            color: var(--muted);
            line-height: 1.7;
            font-size: 14px;
        }

        .reply-box {
            background: rgba(18, 215, 255, 0.09);
            border-color: rgba(18, 215, 255, 0.20);
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

        .card:nth-child(2) { animation-delay: 0.05s; }
        .card:nth-child(3) { animation-delay: 0.10s; }
        .card:nth-child(4) { animation-delay: 0.15s; }

        @media (max-width: 1150px) {
            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .grid {
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
            .message-header {
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
                <h1>Support Messages</h1>
                <p>Welcome, <?php echo htmlspecialchars($full_name); ?>. Send support requests and view admin replies.</p>
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
                <p class="card-title">Total Messages</p>
                <p class="card-number"><?php echo $total_messages; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Replied Messages</p>
                <p class="card-number"><?php echo $replied_messages; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Pending Replies</p>
                <p class="card-number"><?php echo $pending_messages; ?></p>
            </div>

            <div class="card">
                <p class="card-title">Seen by Admin</p>
                <p class="card-number"><?php echo $seen_messages; ?></p>
            </div>
        </section>

        <div class="grid">
            <div class="panel">
                <h2>Send New Support Message</h2>
                <p class="panel-subtitle">
                    Use this form to contact the admin about account issues, application problems, or other system concerns.
                </p>

                <form method="POST">
                    <div class="form-group">
                        <label>Subject</label>
                        <input 
                            type="text" 
                            name="subject" 
                            placeholder="Example: Problem with my application"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Message</label>
                        <textarea 
                            name="support_message" 
                            placeholder="Write your support message here..."
                            required></textarea>
                    </div>

                    <button type="submit" name="send_support" class="btn btn-primary">
                        Send Message
                    </button>
                </form>
            </div>

            <div class="panel">
                <form method="GET" class="filter-box">
                    <div>
                        <strong>Filter:</strong>

                        <select name="status" onchange="this.form.submit()">
                            <option value="all" <?php if ($status_filter === 'all') echo 'selected'; ?>>All Messages</option>
                            <option value="replied" <?php if ($status_filter === 'replied') echo 'selected'; ?>>Replied</option>
                            <option value="not_replied" <?php if ($status_filter === 'not_replied') echo 'selected'; ?>>Not Replied</option>
                            <option value="read" <?php if ($status_filter === 'read') echo 'selected'; ?>>Seen by Admin</option>
                            <option value="unread" <?php if ($status_filter === 'unread') echo 'selected'; ?>>Unread by Admin</option>
                        </select>
                    </div>

                    <a href="support_messages.php" class="btn btn-light">Reset</a>
                </form>

                <?php if ($messages_result && mysqli_num_rows($messages_result) > 0) { ?>
                    <?php while ($row = mysqli_fetch_assoc($messages_result)) { ?>
                        <?php
                            $status_css = support_status_class($row);
                            $status_text = support_status_text($row);
                        ?>

                        <div class="message-card <?php echo htmlspecialchars($status_css); ?>">
                            <div class="message-header">
                                <div>
                                    <h2 class="message-title">
                                        <?php echo htmlspecialchars($row['subject'] ?? 'No Subject'); ?>
                                    </h2>

                                    <p class="meta">
                                        Sent At: <?php echo htmlspecialchars($row['created_at'] ?? ''); ?>
                                    </p>
                                </div>

                                <span class="badge <?php echo htmlspecialchars($status_css); ?>">
                                    <?php echo htmlspecialchars($status_text); ?>
                                </span>
                            </div>

                            <div class="box">
                                <strong>Your Message</strong>
                                <p><?php echo nl2br(htmlspecialchars($row['message'] ?? '')); ?></p>
                            </div>

                            <?php if (!empty($row['admin_reply'])) { ?>
                                <div class="box reply-box">
                                    <strong>Admin Reply</strong>
                                    <p><?php echo nl2br(htmlspecialchars($row['admin_reply'])); ?></p>

                                    <p class="meta">
                                        Replied At: <?php echo htmlspecialchars($row['replied_at'] ?? ''); ?>
                                    </p>
                                </div>
                            <?php } else { ?>
                                <div class="box">
                                    <strong>Admin Reply</strong>
                                    <p>The admin has not replied to this message yet.</p>
                                </div>
                            <?php } ?>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p class="empty">No support messages found.</p>
                <?php } ?>
            </div>
        </div>
    </main>
</div>

</body>
</html>