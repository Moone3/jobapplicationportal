<?php
// jobs.php
session_start();
include "config/database.php";

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

$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : "";
$category_id = isset($_GET['category_id']) ? trim($_GET['category_id']) : "";
$job_type = isset($_GET['job_type']) ? trim($_GET['job_type']) : "";

$categories = mysqli_query($conn, "SELECT * FROM job_categories ORDER BY category_name ASC");

$sql = "
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
";

$params = [];
$types = "";

if (!empty($keyword)) {
    $sql .= " AND (
        jobs.title LIKE ? 
        OR jobs.description LIKE ? 
        OR jobs.requirements LIKE ? 
        OR jobs.location LIKE ?
        OR users.full_name LIKE ?
        OR recruiter_profiles.company_name LIKE ?
    )";

    $search_value = "%" . $keyword . "%";

    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;
    $params[] = $search_value;

    $types .= "ssssss";
}

if (!empty($category_id)) {
    $sql .= " AND jobs.category_id = ?";
    $params[] = $category_id;
    $types .= "i";
}

if (!empty($job_type)) {
    $sql .= " AND jobs.job_type = ?";
    $params[] = $job_type;
    $types .= "s";
}

$sql .= " ORDER BY jobs.created_at DESC";

$stmt = mysqli_prepare($conn, $sql);

if (!empty($params)) {
    mysqli_stmt_bind_param($stmt, $types, ...$params);
}

mysqli_stmt_execute($stmt);
$jobs = mysqli_stmt_get_result($stmt);

$total_approved_jobs = 0;

$result = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM jobs 
    WHERE status = 'approved' 
    AND is_archived = 0
");

if ($result) {
    $row = mysqli_fetch_assoc($result);
    $total_approved_jobs = $row['total'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Browse Jobs | Job Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --bg-deep: #0d1630;
            --glass: rgba(255, 255, 255, 0.08);
            --glass-strong: rgba(255, 255, 255, 0.12);
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
            background: rgba(7, 11, 24, 0.78);
            border-bottom: 1px solid var(--line);
            padding: 18px 7%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            position: sticky;
            top: 0;
            z-index: 10;
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            box-shadow: 0 16px 45px rgba(0, 0, 0, 0.22);
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
            font-weight: 950;
            font-size: 20px;
            border: 1px solid rgba(255, 255, 255, 0.25);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
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
            padding: 42px 7% 70px;
        }

        .hero,
        .filter-panel,
        .job-card,
        .empty {
            position: relative;
            overflow: hidden;
            background: rgba(255, 255, 255, 0.075);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
        }

        .hero::before,
        .filter-panel::before,
        .job-card::before,
        .empty::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.055), transparent 38%);
            opacity: 0.75;
        }

        .hero > *,
        .filter-panel > *,
        .job-card > *,
        .empty > * {
            position: relative;
            z-index: 1;
        }

        .hero {
            border-radius: 30px;
            padding: 38px;
            margin-bottom: 24px;
            animation: fadeUp 0.45s ease both;
        }

        .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.09);
            color: var(--soft);
            padding: 9px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 900;
            margin-bottom: 18px;
            border: 1px solid rgba(18, 215, 255, 0.20);
        }

        .hero-label::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--cyan);
            box-shadow: 0 0 18px rgba(18, 215, 255, 0.65);
        }

        .hero h1 {
            margin: 0;
            font-size: clamp(34px, 5vw, 58px);
            line-height: 1.05;
            letter-spacing: -1.4px;
            color: var(--text);
            max-width: 900px;
        }

        .hero p {
            margin: 16px 0 0;
            color: var(--muted);
            font-size: 16px;
            line-height: 1.8;
            max-width: 820px;
        }

        .filter-panel {
            border-radius: 26px;
            padding: 18px;
            margin-bottom: 26px;
            animation: fadeUp 0.55s ease both;
        }

        .filter-box {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
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

        input::placeholder {
            color: rgba(220, 231, 255, 0.6);
        }

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

        .results-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 14px;
            margin-bottom: 20px;
            animation: fadeUp 0.62s ease both;
        }

        .results-header h2 {
            margin: 0;
            color: var(--text);
            font-size: 28px;
            letter-spacing: -0.4px;
        }

        .results-header p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .jobs-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .job-card {
            border-radius: 24px;
            padding: 22px;
            transition: transform 0.24s ease, border-color 0.24s ease, box-shadow 0.24s ease;
            animation: fadeUp 0.68s ease both;
        }

        .job-card:hover {
            transform: translateY(-5px);
            border-color: rgba(18, 215, 255, 0.32);
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.48), 0 0 0 1px rgba(18, 215, 255, 0.07);
        }

        .company-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }

        .company-logo,
        .company-placeholder {
            width: 48px;
            height: 48px;
            border-radius: 16px;
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
            letter-spacing: 0.5px;
            border: 1px solid rgba(255, 255, 255, 0.28);
            box-shadow: 0 16px 35px rgba(18, 215, 255, 0.18);
        }

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
        }

        .job-card h3 {
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
            margin-bottom: 14px;
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

        .job-actions {
            margin-top: 12px;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .empty {
            border-radius: 24px;
            padding: 24px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.6;
        }

        .footer {
            position: relative;
            z-index: 1;
            background: rgba(7, 11, 24, 0.82);
            color: var(--muted);
            border-top: 1px solid var(--line);
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

        @media (max-width: 1180px) {
            .jobs-grid {
                grid-template-columns: repeat(2, 1fr);
            }

            .filter-box {
                grid-template-columns: 1fr 1fr;
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
                padding: 16px 5%;
            }

            .nav-actions,
            .nav-actions .btn {
                width: 100%;
            }

            .page {
                padding: 28px 5% 50px;
            }

            .hero {
                padding: 28px;
            }

            .jobs-grid,
            .filter-box {
                grid-template-columns: 1fr;
            }

            .results-header {
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
        <a href="index.php" class="btn btn-light">Home</a>
        <a href="contact.php" class="btn btn-light">Contact</a>

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
    <section class="hero">
        <span class="hero-label">Browse Jobs</span>
        <h1>Explore approved job opportunities</h1>
        <p>
            Search jobs by keyword, category, or job type. You can view full job details first,
            then login as an applicant when you are ready to apply.
        </p>
    </section>

    <section class="filter-panel">
        <form method="GET" class="filter-box">
            <input 
                type="text" 
                name="keyword" 
                placeholder="Search job title, company, description, or location"
                value="<?php echo htmlspecialchars($keyword); ?>"
            >

            <select name="category_id">
                <option value="">All Categories</option>

                <?php if ($categories && mysqli_num_rows($categories) > 0) { ?>
                    <?php while ($category = mysqli_fetch_assoc($categories)) { ?>
                        <option value="<?php echo $category['id']; ?>" <?php if ($category_id == $category['id']) echo 'selected'; ?>>
                            <?php echo htmlspecialchars($category['category_name']); ?>
                        </option>
                    <?php } ?>
                <?php } ?>
            </select>

            <select name="job_type">
                <option value="">All Types</option>
                <option value="full-time" <?php if ($job_type == 'full-time') echo 'selected'; ?>>Full-time</option>
                <option value="part-time" <?php if ($job_type == 'part-time') echo 'selected'; ?>>Part-time</option>
                <option value="remote" <?php if ($job_type == 'remote') echo 'selected'; ?>>Remote</option>
                <option value="hybrid" <?php if ($job_type == 'hybrid') echo 'selected'; ?>>Hybrid</option>
                <option value="internship" <?php if ($job_type == 'internship') echo 'selected'; ?>>Internship</option>
            </select>

            <button type="submit" class="btn btn-primary">Search</button>

            <a href="jobs.php" class="btn btn-light">Reset</a>
        </form>
    </section>

    <section>
        <div class="results-header">
            <div>
                <h2>Approved Jobs</h2>
                <p>
                    Total available approved jobs:
                    <?php echo htmlspecialchars($total_approved_jobs); ?>
                </p>
            </div>

            <?php if (!isset($_SESSION['user_id'])) { ?>
                <a href="auth/login.php" class="btn btn-light">Login to Apply</a>
            <?php } elseif (isset($_SESSION['role']) && $_SESSION['role'] === 'applicant') { ?>
                <a href="applicant/dashboard.php" class="btn btn-light">Applicant Dashboard</a>
            <?php } else { ?>
                <a href="<?php echo htmlspecialchars($dashboard_link); ?>" class="btn btn-light">Dashboard</a>
            <?php } ?>
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
                No approved jobs found based on your search or filters.
            </div>
        <?php } ?>
    </section>
</main>

<footer class="footer">
    <div>© <?php echo date("Y"); ?> Job Application System. All rights reserved.</div>
    <div>
        <a href="index.php">Home</a>
        &nbsp; | &nbsp;
        <a href="jobs.php">Browse Jobs</a>
        &nbsp; | &nbsp;
        <a href="contact.php">Contact</a>
        &nbsp; | &nbsp;
        <a href="auth/login.php">Login</a>
        &nbsp; | &nbsp;
        <a href="auth/register.php">Register</a>
    </div>
</footer>

</body>
</html>