<?php
// index.php
session_start();
include "config/database.php";

// If user clicks dashboard and already logged in
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

// Latest approved jobs
$jobs = mysqli_query($conn, "
    SELECT 
        jobs.*,
        job_categories.category_name,
        users.full_name AS recruiter_name,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo
    FROM jobs
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON users.id = recruiter_profiles.user_id
    WHERE jobs.status = 'approved'
    AND jobs.is_archived = 0
    AND jobs.expiry_date >= CURDATE()
    ORDER BY jobs.created_at DESC
    LIMIT 6
");

$total_jobs = 0;
$total_recruiters = 0;
$total_applicants = 0;

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM jobs WHERE status = 'approved' AND is_archived = 0 AND expiry_date >= CURDATE()");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_jobs = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'recruiter' AND status = 'active'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_recruiters = $row['total'];
}

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM users WHERE role = 'applicant' AND status = 'active'");
if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_applicants = $row['total'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Job Portal | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg: #17192a;
            --bg-2: #202238;
            --card: rgba(36, 39, 63, 0.78);
            --card-solid: #24273f;
            --card-soft: rgba(255, 255, 255, 0.065);
            --line: rgba(255, 255, 255, 0.105);
            --text: #f7f8ff;
            --muted: #aeb4d3;
            --muted-2: #858daf;
            --blue: #176cff;
            --purple: #9b4dff;
            --pink: #f15cf5;
            --cyan: #18d5ff;
            --shadow: 0 24px 70px rgba(0, 0, 0, 0.36);
            --glow-blue: rgba(23, 108, 255, 0.35);
            --glow-pink: rgba(241, 92, 245, 0.32);
        }

        * {
            box-sizing: border-box;
            font-family: Arial, Helvetica, sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 16% 18%, rgba(23, 108, 255, 0.24), transparent 30%),
                radial-gradient(circle at 84% 15%, rgba(241, 92, 245, 0.22), transparent 32%),
                radial-gradient(circle at 55% 80%, rgba(155, 77, 255, 0.18), transparent 34%),
                linear-gradient(135deg, #17192a 0%, #1d1f35 45%, #24233a 100%);
            color: var(--text);
            overflow-x: hidden;
        }

        body::before {
            content: "";
            position: fixed;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(255,255,255,0.035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.035) 1px, transparent 1px);
            background-size: 42px 42px;
            mask-image: linear-gradient(to bottom, rgba(0,0,0,0.85), transparent 78%);
            z-index: -2;
        }

        body::after {
            content: "";
            position: fixed;
            width: 560px;
            height: 560px;
            right: -210px;
            top: 140px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(241, 92, 245, 0.18), transparent 68%);
            filter: blur(8px);
            z-index: -1;
            animation: floatGlow 8s ease-in-out infinite alternate;
        }

        @keyframes floatGlow {
            from { transform: translate3d(0, 0, 0) scale(1); }
            to { transform: translate3d(-26px, 24px, 0) scale(1.06); }
        }

        .navbar {
            width: 100%;
            background: rgba(24, 26, 43, 0.78);
            border-bottom: 1px solid var(--line);
            padding: 18px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 10;
            box-shadow: 0 12px 40px rgba(0, 0, 0, 0.22);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text);
        }

        .brand-icon {
            width: 46px;
            height: 46px;
            border-radius: 15px;
            background: linear-gradient(135deg, var(--blue), var(--purple), var(--pink));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 19px;
            box-shadow: 0 12px 26px rgba(155, 77, 255, 0.35);
        }

        .brand-text {
            font-size: 22px;
            font-weight: 900;
            color: var(--text);
            letter-spacing: 0.2px;
        }

        .nav-actions {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .btn {
            padding: 12px 18px;
            border-radius: 14px;
            text-decoration: none;
            border: none;
            font-size: 14px;
            font-weight: 900;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            transition: transform 0.22s ease, box-shadow 0.22s ease, border-color 0.22s ease, background 0.22s ease, color 0.22s ease;
        }

        .btn:hover {
            transform: translateY(-3px);
        }

        .btn-primary {
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, var(--blue), var(--purple) 48%, var(--pink));
            color: #ffffff;
            box-shadow: 0 14px 30px rgba(155, 77, 255, 0.34);
        }

        .btn-primary::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.22), transparent);
            transform: translateX(-120%);
            transition: transform 0.6s ease;
        }

        .btn-primary:hover::before {
            transform: translateX(120%);
        }

        .btn-primary:hover {
            box-shadow: 0 18px 42px rgba(241, 92, 245, 0.34);
        }

        .btn-light {
            background: rgba(255, 255, 255, 0.055);
            color: #eef1ff;
            border: 1px solid rgba(255, 255, 255, 0.12);
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.16);
        }

        .btn-light:hover {
            background: rgba(255, 255, 255, 0.105);
            border-color: rgba(241, 92, 245, 0.45);
            box-shadow: 0 14px 34px rgba(23, 108, 255, 0.16);
        }

        .hero {
            position: relative;
            padding: 88px 7% 58px;
            display: grid;
            grid-template-columns: 1.05fr 0.95fr;
            gap: 44px;
            align-items: center;
        }

        .hero::before {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            left: 5%;
            top: 120px;
            background: radial-gradient(circle, rgba(23, 108, 255, 0.28), transparent 66%);
            filter: blur(2px);
            z-index: -1;
            animation: floatGlow 7s ease-in-out infinite alternate;
        }

        .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.075);
            color: #ffffff;
            padding: 10px 15px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.12);
            box-shadow: inset 0 0 0 1px rgba(255,255,255,0.02);
        }

        .hero-label::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--blue), var(--pink));
            box-shadow: 0 0 18px var(--pink);
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(42px, 5vw, 76px);
            line-height: 1.02;
            color: var(--text);
            letter-spacing: -2.3px;
            max-width: 760px;
        }

        .hero h1::first-line {
            background: linear-gradient(135deg, #ffffff, #e7dcff 55%, #ffb7fb);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hero p {
            margin: 22px 0 30px;
            color: var(--muted);
            font-size: 17px;
            line-height: 1.75;
            max-width: 650px;
        }

        .hero-buttons {
            display: flex;
            gap: 14px;
            flex-wrap: wrap;
        }

        .hero-card {
            position: relative;
            overflow: hidden;
            background: linear-gradient(145deg, rgba(255,255,255,0.105), rgba(255,255,255,0.045));
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 32px;
            padding: 32px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            transform: translateY(0);
            animation: cardEntrance 0.8s ease both;
        }

        .hero-card::before {
            content: "";
            position: absolute;
            width: 180px;
            height: 180px;
            right: -70px;
            top: -70px;
            background: radial-gradient(circle, rgba(241, 92, 245, 0.38), transparent 68%);
        }

        .hero-card::after {
            content: "";
            position: absolute;
            width: 120px;
            height: 120px;
            left: -42px;
            bottom: -42px;
            background: radial-gradient(circle, rgba(23, 108, 255, 0.32), transparent 68%);
        }

        @keyframes cardEntrance {
            from { opacity: 0; transform: translateY(18px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .hero-card h2 {
            position: relative;
            margin: 0 0 12px;
            color: var(--text);
            font-size: 26px;
            z-index: 1;
        }

        .hero-card p {
            position: relative;
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.7;
            z-index: 1;
        }

        .stats {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            margin-top: 24px;
        }

        .stat-box {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.105);
            border-radius: 20px;
            padding: 20px;
            transition: transform 0.22s ease, background 0.22s ease, border-color 0.22s ease;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            background: rgba(255, 255, 255, 0.105);
            border-color: rgba(241, 92, 245, 0.35);
        }

        .stat-number {
            margin: 0;
            font-size: 34px;
            font-weight: 900;
            background: linear-gradient(135deg, var(--cyan), var(--purple), var(--pink));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .stat-label {
            margin: 8px 0 0;
            color: var(--muted);
            font-size: 13px;
            font-weight: 800;
        }

        .section {
            padding: 38px 7% 76px;
        }

        .section-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }

        .section-header h2 {
            margin: 0;
            font-size: 36px;
            color: var(--text);
            letter-spacing: -0.8px;
        }

        .section-header p {
            margin: 9px 0 0;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .jobs-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .job-card,
        .feature-card,
        .empty {
            background: linear-gradient(145deg, rgba(255,255,255,0.09), rgba(255,255,255,0.04));
            border: 1px solid rgba(255, 255, 255, 0.105);
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.22);
            backdrop-filter: blur(14px);
            -webkit-backdrop-filter: blur(14px);
        }

        .job-card {
            position: relative;
            overflow: hidden;
            border-radius: 26px;
            padding: 24px;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        .job-card::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(23,108,255,0.14), transparent 34%, rgba(241,92,245,0.12));
            opacity: 0;
            transition: opacity 0.25s ease;
            pointer-events: none;
        }

        .job-card:hover {
            transform: translateY(-8px);
            border-color: rgba(241, 92, 245, 0.4);
            box-shadow: 0 26px 60px rgba(0, 0, 0, 0.32), 0 0 36px rgba(155, 77, 255, 0.12);
        }

        .job-card:hover::before {
            opacity: 1;
        }

        .company-row {
            position: relative;
            z-index: 1;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .company-logo {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            object-fit: cover;
            background: #2d3150;
            border: 2px solid rgba(255,255,255,0.18);
            box-shadow: 0 10px 22px rgba(0, 0, 0, 0.24);
        }

        .company-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--blue), var(--purple), var(--pink));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            flex-shrink: 0;
            box-shadow: 0 12px 24px rgba(155, 77, 255, 0.3);
        }

        .company-name {
            margin: 0;
            color: var(--text);
            font-size: 15px;
            font-weight: 900;
        }

        .company-role {
            margin: 4px 0 0;
            color: var(--muted-2);
            font-size: 13px;
        }

        .job-card h3 {
            position: relative;
            z-index: 1;
            margin: 0 0 10px;
            color: var(--text);
            font-size: 21px;
            letter-spacing: -0.25px;
        }

        .job-meta {
            position: relative;
            z-index: 1;
            margin: 0 0 14px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.75;
        }

        .description {
            position: relative;
            z-index: 1;
            color: var(--muted);
            line-height: 1.65;
            font-size: 14px;
            margin-bottom: 14px;
        }

        .badge {
            position: relative;
            z-index: 1;
            display: inline-block;
            padding: 8px 11px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.08);
            color: #f3e8ff;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 12px;
            font-weight: 900;
            margin-right: 6px;
            margin-bottom: 8px;
        }

        .badge:nth-of-type(1) {
            color: #d9f0ff;
            border-color: rgba(23, 108, 255, 0.28);
        }

        .badge:nth-of-type(2) {
            color: #f9dcff;
            border-color: rgba(241, 92, 245, 0.28);
        }

        .job-actions {
            position: relative;
            z-index: 1;
            margin-top: 14px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .empty {
            border-radius: 24px;
            padding: 28px;
            color: var(--muted);
            font-size: 15px;
        }

        .features {
            padding: 18px 7% 84px;
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }

        .feature-card {
            border-radius: 26px;
            padding: 26px;
            transition: transform 0.25s ease, border-color 0.25s ease, box-shadow 0.25s ease;
        }

        .feature-card:hover {
            transform: translateY(-7px);
            border-color: rgba(23, 108, 255, 0.38);
            box-shadow: 0 25px 55px rgba(0, 0, 0, 0.3), 0 0 34px rgba(23, 108, 255, 0.1);
        }

        .feature-icon {
            width: 50px;
            height: 50px;
            border-radius: 16px;
            background: linear-gradient(135deg, rgba(23,108,255,0.22), rgba(241,92,245,0.22));
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.12);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 16px;
            box-shadow: inset 0 0 20px rgba(255,255,255,0.035), 0 14px 28px rgba(0,0,0,0.2);
        }

        .feature-card h3 {
            margin: 0 0 9px;
            color: var(--text);
            font-size: 20px;
        }

        .feature-card p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
        }

        .footer {
            background: rgba(13, 15, 28, 0.86);
            color: var(--muted);
            border-top: 1px solid var(--line);
            padding: 26px 7%;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 14px;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
        }

        .footer a {
            color: #ffffff;
            text-decoration: none;
            font-weight: 800;
            transition: color 0.22s ease;
        }

        .footer a:hover {
            color: #ff9bff;
        }

        @media (max-width: 1050px) {
            .hero {
                grid-template-columns: 1fr;
                padding-top: 70px;
            }

            .jobs-grid,
            .features {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 720px) {
            .navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
                padding: 16px 6%;
            }

            .nav-actions {
                width: 100%;
            }

            .nav-actions .btn {
                flex: 1;
            }

            .hero {
                padding: 46px 6% 40px;
            }

            .hero h1 {
                font-size: 40px;
                letter-spacing: -1.2px;
            }

            .hero-card {
                padding: 24px;
                border-radius: 26px;
            }

            .stats,
            .jobs-grid,
            .features {
                grid-template-columns: 1fr;
            }

            .section,
            .features {
                padding-left: 6%;
                padding-right: 6%;
            }

            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .section-header h2 {
                font-size: 30px;
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

<section class="hero">
    <div>
        <span class="hero-label">Job Application System</span>

        <h1>Find jobs, submit applications, and track hiring progress in one place.</h1>

        <p>
            A web-based job application system where applicants can search jobs, save opportunities,
            apply, and monitor interview schedules while recruiters manage job posts and applicants.
        </p>

        <div class="hero-buttons">
            <?php if (isset($_SESSION['user_id'])) { ?>
                <a href="<?php echo htmlspecialchars($dashboard_link); ?>" class="btn btn-primary">Go to Dashboard</a>
                <a href="jobs.php" class="btn btn-light">Browse Jobs</a>
            <?php } else { ?>
                <a href="auth/register.php" class="btn btn-primary">Get Started</a>
                <a href="jobs.php" class="btn btn-light">Browse Jobs</a>
            <?php } ?>
        </div>
    </div>

    <div class="hero-card">
        <h2>System Overview</h2>
        <p>
            The platform supports applicants, recruiters, admins, and creator-level management.
            Job posts are reviewed by admins before they become visible to applicants.
        </p>

        <div class="stats">
            <div class="stat-box">
                <p class="stat-number"><?php echo $total_jobs; ?></p>
                <p class="stat-label">Approved Jobs</p>
            </div>

            <div class="stat-box">
                <p class="stat-number"><?php echo $total_recruiters; ?></p>
                <p class="stat-label">Recruiters</p>
            </div>

            <div class="stat-box">
                <p class="stat-number"><?php echo $total_applicants; ?></p>
                <p class="stat-label">Applicants</p>
            </div>
        </div>
    </div>
</section>

<section class="section">
    <div class="section-header">
        <div>
            <h2>Latest Approved Jobs</h2>
            <p>Browse recent job opportunities approved by the admin.</p>
        </div>

        <a href="jobs.php" class="btn btn-light">View All Jobs</a>
    </div>

    <?php if ($jobs && mysqli_num_rows($jobs) > 0) { ?>
        <div class="jobs-grid">
            <?php while ($job = mysqli_fetch_assoc($jobs)) { ?>
                <?php
                    $company_name = !empty($job['company_name']) ? $job['company_name'] : $job['recruiter_name'];
                    $company_initial = strtoupper(substr($company_name, 0, 1));
                    $company_logo_path = "";

                    if (!empty($job['company_logo'])) {
                        $company_logo_path = "assets/uploads/company_logos/" . $job['company_logo'];
                    }
                ?>

                <div class="job-card">
                    <div class="company-row">
                        <?php if (!empty($company_logo_path) && file_exists($company_logo_path)) { ?>
                            <img src="<?php echo htmlspecialchars($company_logo_path); ?>" class="company-logo" alt="Company Logo">
                        <?php } else { ?>
                            <div class="company-placeholder">
                                <?php echo htmlspecialchars($company_initial); ?>
                            </div>
                        <?php } ?>

                        <div>
                            <p class="company-name"><?php echo htmlspecialchars($company_name); ?></p>
                            <p class="company-role">Recruiter</p>
                        </div>
                    </div>

                    <h3><?php echo htmlspecialchars($job['title']); ?></h3>

                    <p class="job-meta">
                        Location: <?php echo htmlspecialchars($job['location']); ?><br>
                        Expiry Date: <?php echo htmlspecialchars($job['expiry_date']); ?>
                    </p>

                    <span class="badge"><?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?></span>
                    <span class="badge"><?php echo htmlspecialchars($job['job_type']); ?></span>
                    <span class="badge">
                        ₱<?php echo number_format($job['salary_min'], 2); ?> -
                        ₱<?php echo number_format($job['salary_max'], 2); ?>
                    </span>

                    <div class="description">
                        <?php echo nl2br(htmlspecialchars(substr($job['description'], 0, 160))); ?>...
                    </div>

                    <div class="job-actions">
                        <a href="job_details.php?job_id=<?php echo $job['id']; ?>" class="btn btn-primary">
                            View Job
                        </a>
                    </div>
                </div>
            <?php } ?>
        </div>
    <?php } else { ?>
        <div class="empty">
            No approved jobs are available yet. Please check again later.
        </div>
    <?php } ?>
</section>

<section class="features">
    <div class="feature-card">
        <div class="feature-icon">🔎</div>
        <h3>Browse and Apply</h3>
        <p>Applicants can search available jobs, save job posts, submit resumes, and track application status.</p>
    </div>

    <div class="feature-card">
        <div class="feature-icon">💼</div>
        <h3>Recruiter Management</h3>
        <p>Recruiters can post jobs, manage applicants, update application status, and schedule interviews.</p>
    </div>

    <div class="feature-card">
        <div class="feature-icon">✓</div>
        <h3>Admin Approval</h3>
        <p>Admins review job posts before they go live to help prevent fake, spam, or scam job listings.</p>
    </div>
</section>

<footer class="footer">
    <div>© <?php echo date("Y"); ?> Job Application System. All rights reserved.</div>
    <div>
        <a href="index.php">Home</a>
        &nbsp; | &nbsp;
        <a href="jobs.php">Browse Jobs</a>
        &nbsp; | &nbsp;
        <a href="auth/login.php">Login</a>
        &nbsp; | &nbsp;
        <a href="auth/register.php">Register</a>
    </div>
</footer>

</body>
</html>