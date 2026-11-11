<?php
// job_details.php
session_start();
include "config/database.php";

if (!isset($_GET['job_id'])) {
    header("Location: index.php");
    exit();
}

$job_id = intval($_GET['job_id']);

$sql = "
    SELECT 
        jobs.*,
        job_categories.category_name,
        users.full_name AS recruiter_name,
        users.email AS recruiter_email,
        recruiter_profiles.company_name,
        recruiter_profiles.company_logo,
        recruiter_profiles.company_description,
        recruiter_profiles.company_website,
        recruiter_profiles.company_address
    FROM jobs
    LEFT JOIN job_categories ON jobs.category_id = job_categories.id
    JOIN users ON jobs.recruiter_id = users.id
    LEFT JOIN recruiter_profiles ON users.id = recruiter_profiles.user_id
    WHERE jobs.id = ?
    AND jobs.status = 'approved'
    AND jobs.is_archived = 0
    AND jobs.expiry_date >= CURDATE()
    LIMIT 1
";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $job_id);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

if (mysqli_num_rows($result) !== 1) {
    header("Location: index.php");
    exit();
}

$job = mysqli_fetch_assoc($result);

// Increase job view count only after confirming the job is approved, active, not archived, and not expired
mysqli_query($conn, "
    UPDATE jobs
    SET view_count = view_count + 1
    WHERE id = '$job_id'
");

$job['view_count'] = intval($job['view_count'] ?? 0) + 1;

$company_name = !empty($job['company_name']) ? $job['company_name'] : $job['recruiter_name'];
$company_initial = strtoupper(substr($company_name, 0, 1));
$company_logo_path = "";

if (!empty($job['company_logo'])) {
    $company_logo_path = "assets/uploads/company_logos/" . $job['company_logo'];
}

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

$apply_link = "auth/login.php";

if (isset($_SESSION['role']) && $_SESSION['role'] === 'applicant') {
    $apply_link = "applicant/apply_job.php?job_id=" . $job['id'];
} elseif (isset($_SESSION['role']) && $_SESSION['role'] !== 'applicant') {
    $apply_link = $dashboard_link;
}

// Related jobs query
$related_jobs_sql = "
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
    AND jobs.id != ?
    AND jobs.category_id = ?
    ORDER BY jobs.created_at DESC
    LIMIT 3
";

$related_stmt = mysqli_prepare($conn, $related_jobs_sql);
mysqli_stmt_bind_param($related_stmt, "ii", $job_id, $job['category_id']);
mysqli_stmt_execute($related_stmt);
$related_jobs = mysqli_stmt_get_result($related_stmt);

// If no same-category related jobs, show latest other approved jobs
if (!$related_jobs || mysqli_num_rows($related_jobs) === 0) {
    $related_jobs_sql = "
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
        AND jobs.id != ?
        ORDER BY jobs.created_at DESC
        LIMIT 3
    ";

    $related_stmt = mysqli_prepare($conn, $related_jobs_sql);
    mysqli_stmt_bind_param($related_stmt, "i", $job_id);
    mysqli_stmt_execute($related_stmt);
    $related_jobs = mysqli_stmt_get_result($related_stmt);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?php echo htmlspecialchars($job['title']); ?> | Job Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --bg-deep: #0d1630;
            --panel: rgba(15, 23, 42, 0.72);
            --glass: rgba(255, 255, 255, 0.09);
            --line: rgba(255, 255, 255, 0.15);
            --text: #f8fbff;
            --muted: #aab7cf;
            --soft: #dce7ff;
            --cyan: #12d7ff;
            --cyan-2: #00a6c8;
            --blue: #3267ff;
            --purple: #8b5cf6;
            --pink: #ff3ea5;
            --green: #26e0a8;
            --orange: #ffb454;
            --red: #ff3b5f;
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

        .navbar {
            width: 100%;
            background: rgba(7, 11, 24, 0.72);
            border-bottom: 1px solid var(--line);
            padding: 18px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            box-shadow: 0 18px 50px rgba(0, 0, 0, 0.22);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text);
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--cyan-2), var(--blue));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 18px;
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
            border: 1px solid rgba(255, 255, 255, 0.28);
        }

        .brand-text {
            font-size: 24px;
            font-weight: 950;
            color: var(--text);
            letter-spacing: -0.3px;
        }

        .nav-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
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

        .page {
            position: relative;
            z-index: 1;
            padding: 42px 7% 70px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 20px;
            color: #73e9ff;
            font-size: 14px;
            font-weight: 900;
            text-decoration: none;
            transition: color 0.2s ease, transform 0.2s ease;
        }

        .back-link:hover {
            color: #ffffff;
            transform: translateX(-3px);
        }

        .layout {
            display: grid;
            grid-template-columns: 1.1fr 0.55fr;
            gap: 22px;
            align-items: start;
        }

        .panel,
        .related-card,
        .empty {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.075);
            border: 1px solid var(--line);
            border-radius: 26px;
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            animation: fadeUp 0.55s ease both;
        }

        .panel::before,
        .related-card::before,
        .empty::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .panel > *,
        .related-card > *,
        .empty > * {
            position: relative;
            z-index: 1;
        }

        .panel {
            padding: 28px;
        }

        .company-row {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
        }

        .company-logo,
        .company-placeholder {
            width: 58px;
            height: 58px;
            border-radius: 18px;
            flex-shrink: 0;
        }

        .company-logo {
            object-fit: cover;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

        .company-placeholder {
            background: linear-gradient(135deg, var(--cyan-2), var(--blue));
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 22px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

        .company-name {
            margin: 0;
            color: var(--text);
            font-size: 18px;
            font-weight: 900;
        }

        .company-role {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        h1 {
            margin: 0 0 14px;
            color: var(--text);
            font-size: clamp(34px, 4.6vw, 56px);
            line-height: 1.08;
            letter-spacing: -1.2px;
        }

        .meta {
            color: var(--muted);
            font-size: 14px;
            line-height: 1.8;
            margin-bottom: 18px;
        }

        .badges {
            margin-bottom: 18px;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 8px 12px;
            border-radius: 999px;
            background: rgba(18, 215, 255, 0.12);
            color: #bff5ff;
            border: 1px solid rgba(18, 215, 255, 0.18);
            font-size: 12px;
            font-weight: 900;
            margin-right: 6px;
            margin-bottom: 8px;
        }

        .section-title {
            margin: 24px 0 10px;
            color: var(--text);
            font-size: 21px;
            letter-spacing: -0.2px;
        }

        .content {
            color: var(--soft);
            font-size: 15px;
            line-height: 1.85;
        }

        .side-title {
            margin: 0 0 14px;
            color: var(--text);
            font-size: 21px;
        }

        .info-box,
        .notice {
            background: rgba(255, 255, 255, 0.07);
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 17px;
            padding: 15px;
            margin-bottom: 12px;
        }

        .info-box strong {
            display: block;
            margin-bottom: 6px;
            color: #eef5ff;
            font-size: 14px;
            font-weight: 900;
        }

        .info-box span,
        .info-box a,
        .info-box p {
            margin: 0;
            color: var(--muted);
            font-size: 14px;
            line-height: 1.65;
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

        .apply-box {
            margin-top: 18px;
            display: grid;
            gap: 10px;
        }

        .notice {
            color: #bff5ff;
            border-color: rgba(18, 215, 255, 0.22);
            line-height: 1.6;
            margin-top: 16px;
        }

        .related-section {
            margin-top: 26px;
        }

        .related-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
        }

        .related-header h2 {
            margin: 0;
            color: var(--text);
            font-size: 28px;
            letter-spacing: -0.4px;
        }

        .related-header p {
            margin: 6px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .related-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .related-card {
            padding: 20px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
        }

        .related-card:hover {
            transform: translateY(-5px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .related-card h3 {
            margin: 0 0 10px;
            color: var(--text);
            font-size: 20px;
            letter-spacing: -0.25px;
        }

        .related-meta {
            margin: 0 0 12px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }

        .related-description {
            color: var(--soft);
            font-size: 14px;
            line-height: 1.7;
            margin-bottom: 14px;
        }

        .empty {
            padding: 18px;
            color: var(--muted);
            font-size: 14px;
        }

        @media (max-width: 1050px) {
            .related-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 950px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            body::before,
            body::after {
                width: 280px;
                height: 280px;
            }

            .navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 16px;
                padding: 18px 5%;
            }

            .page {
                padding: 28px 5% 50px;
            }

            .related-grid {
                grid-template-columns: 1fr;
            }

            .related-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .btn {
                width: 100%;
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
    <a href="index.php" class="back-link">← Back to Home</a>

    <div class="layout">
        <section class="panel">
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
                    <p class="company-role">Posted by <?php echo htmlspecialchars($job['recruiter_name']); ?></p>
                </div>
            </div>

            <h1><?php echo htmlspecialchars($job['title']); ?></h1>

            <div class="meta">
                Location: <?php echo htmlspecialchars($job['location']); ?> |
                Job Type: <?php echo htmlspecialchars($job['job_type']); ?> |
                Expiry Date: <?php echo htmlspecialchars($job['expiry_date']); ?> |
                Views: <?php echo number_format($job['view_count'] ?? 0); ?>
            </div>

            <div class="badges">
                <span class="badge"><?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?></span>
                <span class="badge"><?php echo htmlspecialchars($job['job_type']); ?></span>
                <span class="badge">
                    ₱<?php echo number_format($job['salary_min'], 2); ?> -
                    ₱<?php echo number_format($job['salary_max'], 2); ?>
                </span>
                <span class="badge"><?php echo number_format($job['view_count'] ?? 0); ?> views</span>
            </div>

            <h2 class="section-title">Job Description</h2>
            <div class="content">
                <?php echo nl2br(htmlspecialchars($job['description'])); ?>
            </div>

            <h2 class="section-title">Requirements</h2>
            <div class="content">
                <?php echo nl2br(htmlspecialchars($job['requirements'])); ?>
            </div>
        </section>

        <aside class="panel">
            <h2 class="side-title">Job Summary</h2>

            <div class="info-box">
                <strong>Company</strong>
                <span><?php echo htmlspecialchars($company_name); ?></span>
            </div>

            <div class="info-box">
                <strong>Recruiter Email</strong>
                <span><?php echo htmlspecialchars($job['recruiter_email']); ?></span>
            </div>

            <div class="info-box">
                <strong>Location</strong>
                <span><?php echo htmlspecialchars($job['location']); ?></span>
            </div>

            <div class="info-box">
                <strong>Salary Range</strong>
                <span>
                    ₱<?php echo number_format($job['salary_min'], 2); ?>
                    -
                    ₱<?php echo number_format($job['salary_max'], 2); ?>
                </span>
            </div>

            <div class="info-box">
                <strong>Category</strong>
                <span><?php echo htmlspecialchars($job['category_name'] ?? 'Uncategorized'); ?></span>
            </div>

            <div class="info-box">
                <strong>Expiry Date</strong>
                <span><?php echo htmlspecialchars($job['expiry_date']); ?></span>
            </div>

            <div class="info-box">
                <strong>Total Views</strong>
                <span><?php echo number_format($job['view_count'] ?? 0); ?></span>
            </div>

            <?php if (!empty($job['company_address'])) { ?>
                <div class="info-box">
                    <strong>Company Address</strong>
                    <p><?php echo nl2br(htmlspecialchars($job['company_address'])); ?></p>
                </div>
            <?php } ?>

            <?php if (!empty($job['company_website'])) { ?>
                <div class="info-box">
                    <strong>Company Website</strong>
                    <a href="<?php echo htmlspecialchars($job['company_website']); ?>" target="_blank">
                        <?php echo htmlspecialchars($job['company_website']); ?>
                    </a>
                </div>
            <?php } ?>

            <?php if (!empty($job['company_description'])) { ?>
                <div class="info-box">
                    <strong>Company Description</strong>
                    <p><?php echo nl2br(htmlspecialchars($job['company_description'])); ?></p>
                </div>
            <?php } ?>

            <div class="apply-box">
                <a href="<?php echo htmlspecialchars($apply_link); ?>" class="btn btn-primary">
                    <?php
                        if (!isset($_SESSION['role'])) {
                            echo "Login to Apply";
                        } elseif ($_SESSION['role'] === 'applicant') {
                            echo "Apply Now";
                        } else {
                            echo "Go to Dashboard";
                        }
                    ?>
                </a>

                <a href="index.php" class="btn btn-light">Back to Jobs</a>
            </div>

            <?php if (!isset($_SESSION['role'])) { ?>
                <div class="notice">
                    You need to login as an applicant before you can apply for this job.
                </div>
            <?php } elseif ($_SESSION['role'] !== 'applicant') { ?>
                <div class="notice">
                    Only applicant accounts can apply for jobs.
                </div>
            <?php } ?>
        </aside>
    </div>

    <section class="related-section">
        <div class="related-header">
            <div>
                <h2>Related Jobs</h2>
                <p>Explore other approved jobs that may match your interest.</p>
            </div>

            <a href="index.php" class="btn btn-light">View Homepage</a>
        </div>

        <?php if ($related_jobs && mysqli_num_rows($related_jobs) > 0) { ?>
            <div class="related-grid">
                <?php while ($related = mysqli_fetch_assoc($related_jobs)) { ?>
                    <?php
                        $related_company = !empty($related['company_name']) ? $related['company_name'] : $related['recruiter_name'];
                    ?>

                    <div class="related-card">
                        <h3><?php echo htmlspecialchars($related['title']); ?></h3>

                        <p class="related-meta">
                            Company: <?php echo htmlspecialchars($related_company); ?><br>
                            Location: <?php echo htmlspecialchars($related['location']); ?><br>
                            Expiry Date: <?php echo htmlspecialchars($related['expiry_date']); ?>
                        </p>

                        <div class="badges">
                            <span class="badge"><?php echo htmlspecialchars($related['category_name'] ?? 'Uncategorized'); ?></span>
                            <span class="badge"><?php echo htmlspecialchars($related['job_type']); ?></span>
                        </div>

                        <div class="related-description">
                            <?php echo nl2br(htmlspecialchars(substr($related['description'], 0, 130))); ?>...
                        </div>

                        <a href="job_details.php?job_id=<?php echo $related['id']; ?>" class="btn btn-primary">
                            View Job
                        </a>
                    </div>
                <?php } ?>
            </div>
        <?php } else { ?>
            <div class="empty">
                No related jobs found at the moment.
            </div>
        <?php } ?>
    </section>
</main>

</body>
</html>