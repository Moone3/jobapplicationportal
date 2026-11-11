<?php
// contact.php
session_start();
include "config/database.php";

$error = "";
$success = "";

$dashboard_link = "auth/login.php";

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'applicant') {
        $dashboard_link = "applicant/dashboard.php";
    } elseif ($_SESSION['role'] === 'recruiter') {
        $dashboard_link = "recruiter/dashboard.php";
    } elseif ($_SESSION['role'] === 'admin') {
        $dashboard_link = "admin/dashboard.php";
    } elseif ($_SESSION['role'] === 'creator') {
        $dashboard_link = "creator/dashboard.php";
    }
}

$default_name = $_SESSION['full_name'] ?? "";
$default_email = $_SESSION['email'] ?? "";
$user_id = $_SESSION['user_id'] ?? null;

if (isset($_POST['send_message'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $subject = trim($_POST['subject']);
    $message_text = trim($_POST['message']);

    if (empty($full_name) || empty($email) || empty($subject) || empty($message_text)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
      $insert_sql = "
          INSERT INTO contact_messages (user_id, full_name, email, subject, message)
          VALUES (?, ?, ?, ?, ?)
      ";

      $stmt = mysqli_prepare($conn, $insert_sql);
      mysqli_stmt_bind_param($stmt, "issss", $user_id, $full_name, $email, $subject, $message_text);

        if (mysqli_stmt_execute($stmt)) {
            $success = "Your message has been sent successfully. Please wait for the admin to review it.";

            $default_name = "";
            $default_email = "";
            $subject = "";
            $message_text = "";
        } else {
            $error = "Failed to send message. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contact Support | Job Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --panel: rgba(255, 255, 255, 0.075);
            --panel-strong: rgba(255, 255, 255, 0.11);
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
                radial-gradient(circle at 14% 10%, rgba(18, 215, 255, 0.14), transparent 30%),
                radial-gradient(circle at 88% 18%, rgba(139, 92, 246, 0.12), transparent 30%),
                radial-gradient(circle at 88% 84%, rgba(255, 62, 165, 0.10), transparent 30%),
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

        a {
            color: inherit;
        }

        .navbar {
            position: sticky;
            top: 0;
            z-index: 20;
            width: 100%;
            padding: 20px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: rgba(7, 11, 24, 0.72);
            border-bottom: 1px solid rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            box-shadow: 0 14px 45px rgba(0, 0, 0, 0.18);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .brand-icon {
            width: 52px;
            height: 52px;
            border-radius: 17px;
            background: linear-gradient(135deg, var(--cyan-2), var(--blue), var(--pink));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 950;
            font-size: 20px;
            border: 1px solid rgba(255, 255, 255, 0.22);
            box-shadow: 0 18px 38px rgba(18, 215, 255, 0.18);
        }

        .brand-text {
            font-size: 24px;
            font-weight: 950;
            color: var(--text);
            letter-spacing: -0.4px;
        }

        .nav-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 18px;
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

        .page {
            position: relative;
            z-index: 1;
            padding: 58px 7% 78px;
        }

        .layout {
            display: grid;
            grid-template-columns: 0.78fr 1.22fr;
            gap: 24px;
            align-items: start;
            animation: fadeUp 0.55s ease both;
        }

        .panel {
            position: relative;
            overflow: hidden;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 28px;
            padding: 30px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .panel::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background:
                linear-gradient(135deg, rgba(18, 215, 255, 0.06), transparent 38%),
                radial-gradient(circle at 92% 0%, rgba(255, 62, 165, 0.12), transparent 32%);
        }

        .panel > * {
            position: relative;
            z-index: 1;
        }

        .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.09);
            color: var(--soft);
            border: 1px solid rgba(255, 255, 255, 0.16);
            padding: 9px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 18px;
        }

        .hero-label::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: var(--cyan);
            box-shadow: 0 0 18px rgba(18, 215, 255, 0.72);
        }

        h1 {
            margin: 0;
            font-size: clamp(36px, 4vw, 58px);
            color: var(--text);
            line-height: 1.05;
            letter-spacing: -1.4px;
        }

        .subtitle {
            margin: 16px 0 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.8;
        }

        .info-box {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 18px;
            padding: 17px;
            margin-top: 18px;
            transition: transform 0.23s ease, border-color 0.23s ease, background 0.23s ease;
        }

        .info-box:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, 0.10);
            border-color: rgba(18, 215, 255, 0.26);
        }

        .info-box strong {
            display: block;
            margin-bottom: 8px;
            color: #eef5ff;
            font-size: 14px;
            font-weight: 950;
        }

        .info-box p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .form-title {
            margin: 0 0 8px;
            color: var(--text);
            font-size: 28px;
            letter-spacing: -0.4px;
        }

        .form-subtitle {
            margin: 0 0 22px;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group.full {
            grid-column: span 2;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 900;
            color: #eef5ff;
        }

        input,
        textarea {
            width: 100%;
            padding: 14px 15px;
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

        textarea {
            min-height: 180px;
            resize: vertical;
        }

        input:focus,
        textarea:focus {
            border-color: rgba(18, 215, 255, 0.65);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.12);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            font-weight: 800;
            line-height: 1.5;
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

        .footer {
            position: relative;
            z-index: 1;
            background: rgba(7, 11, 24, 0.82);
            color: var(--muted);
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            padding: 24px 7%;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 14px;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .footer a {
            color: var(--soft);
            text-decoration: none;
            font-weight: 800;
        }

        .footer a:hover {
            color: var(--cyan);
        }

        @media (max-width: 950px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
                padding: 18px 5%;
            }

            .nav-actions,
            .nav-actions .btn {
                width: 100%;
            }

            .page {
                padding: 32px 5% 50px;
            }

            .panel {
                padding: 24px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .form-group.full {
                grid-column: span 1;
            }

            .footer {
                padding: 22px 5%;
            }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="brand">
        <div class="brand-icon">J</div>
        <div class="brand-text">Job Portal</div>
    </a>

    <div class="nav-actions">
        <a href="index.php" class="btn btn-light">Home</a>
        <a href="jobs.php" class="btn btn-light">Browse Jobs</a>

        <?php if (isset($_SESSION['user_id'])) { ?>
            <a href="<?php echo htmlspecialchars($dashboard_link); ?>" class="btn btn-light">Dashboard</a>
            <a href="auth/logout.php" class="btn btn-primary">Logout</a>
        <?php } else { ?>
            <a href="auth/login.php" class="btn btn-light">Login</a>
            <a href="auth/register.php" class="btn btn-primary">Register</a>
        <?php } ?>
    </div>
</nav>

<main class="page">
    <div class="layout">
        <section class="panel">
            <span class="hero-label">Contact Support</span>
            <h1>Need help with the Job Portal?</h1>

            <p class="subtitle">
                Send a message to the admin if you have questions, account concerns, job post issues,
                or application-related problems.
            </p>

            <div class="info-box">
                <strong>For Applicants</strong>
                <p>You may contact support for login issues, application concerns, interview problems, or resume upload issues.</p>
            </div>

            <div class="info-box">
                <strong>For Recruiters</strong>
                <p>You may contact support for job approval concerns, applicant management issues, or company profile updates.</p>
            </div>

            <div class="info-box">
                <strong>Response Notice</strong>
                <p>Your message will be reviewed by the admin. Please provide a clear subject and complete details.</p>
            </div>
        </section>

        <section class="panel">
            <h2 class="form-title">Send Message</h2>
            <p class="form-subtitle">Fill out the form below and your message will be saved for admin review.</p>

            <?php if (!empty($success)) { ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
            <?php } ?>

            <?php if (!empty($error)) { ?>
                <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
            <?php } ?>

            <form method="POST">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input 
                            type="text" 
                            name="full_name" 
                            value="<?php echo htmlspecialchars($default_name); ?>" 
                            placeholder="Enter your full name" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label>Email Address</label>
                        <input 
                            type="email" 
                            name="email" 
                            value="<?php echo htmlspecialchars($default_email); ?>" 
                            placeholder="Enter your email address" 
                            required
                        >
                    </div>

                    <div class="form-group full">
                        <label>Subject</label>
                        <input 
                            type="text" 
                            name="subject" 
                            value="<?php echo htmlspecialchars($subject ?? ''); ?>" 
                            placeholder="Example: Cannot login to my account" 
                            required
                        >
                    </div>

                    <div class="form-group full">
                        <label>Message</label>
                        <textarea 
                            name="message" 
                            placeholder="Write your concern or message here..." 
                            required><?php echo htmlspecialchars($message_text ?? ''); ?></textarea>
                    </div>
                </div>

                <button type="submit" name="send_message" class="btn btn-primary">
                    Send Message
                </button>
            </form>
        </section>
    </div>
</main>

<footer class="footer">
    <div>© <?php echo date("Y"); ?> Job Application System. All rights reserved.</div>
    <div>
        <a href="index.php">Home</a>
        &nbsp; | &nbsp;
        <a href="jobs.php">Browse Jobs</a>
        &nbsp; | &nbsp;
        <a href="contact.php">Contact</a>
    </div>
</footer>

</body>
</html>