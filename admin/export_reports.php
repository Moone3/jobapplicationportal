<?php
// admin/export_reports.php
include "../includes/auth_check.php";
include "../config/database.php";

if ($_SESSION['role'] !== 'admin') {
    header("Location: ../auth/login.php");
    exit();
}

$admin_id = intval($_SESSION['user_id']);
$type = isset($_GET['type']) ? trim($_GET['type']) : "";

$allowed_types = ["users", "jobs", "applications", "interviews"];

if (!in_array($type, $allowed_types)) {
    header("Location: reports.php");
    exit();
}

function clean_csv_value($value) {
    $value = $value ?? "";
    $value = str_replace(["\r", "\n"], " ", $value);
    return $value;
}

function export_csv($filename, $headers, $rows) {
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=" . $filename);
    header("Pragma: no-cache");
    header("Expires: 0");

    $output = fopen("php://output", "w");

    // UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    fputcsv($output, $headers);

    foreach ($rows as $row) {
        $clean_row = [];

        foreach ($row as $value) {
            $clean_row[] = clean_csv_value($value);
        }

        fputcsv($output, $clean_row);
    }

    fclose($output);
    exit();
}

function add_export_log($conn, $admin_id, $type) {
    $safe_type = mysqli_real_escape_string($conn, $type);

    mysqli_query($conn, "
        INSERT INTO admin_logs (admin_id, action)
        VALUES ('$admin_id', 'Exported $safe_type report as CSV')
    ");
}

if ($type === "users") {
    $rows = [];

    $query = mysqli_query($conn, "
        SELECT 
            id,
            full_name,
            email,
            role,
            status,
            created_at
        FROM users
        ORDER BY created_at DESC
    ");

    if ($query) {
        while ($row = mysqli_fetch_assoc($query)) {
            $rows[] = [
                $row['id'],
                $row['full_name'],
                $row['email'],
                $row['role'],
                $row['status'],
                $row['created_at']
            ];
        }
    }

    add_export_log($conn, $admin_id, "users");

    export_csv(
        "users_report.csv",
        ["User ID", "Full Name", "Email", "Role", "Status", "Created At"],
        $rows
    );
}

if ($type === "jobs") {
    $rows = [];

    $query = mysqli_query($conn, "
        SELECT 
            jobs.id,
            jobs.title,
            job_categories.category_name,
            users.full_name AS recruiter_name,
            users.email AS recruiter_email,
            jobs.location,
            jobs.job_type,
            jobs.salary_min,
            jobs.salary_max,
            jobs.status,
            jobs.is_archived,
            jobs.expiry_date,
            jobs.view_count,
            jobs.reject_reason,
            jobs.created_at,
            CASE 
                WHEN jobs.expiry_date IS NULL THEN 'No Expiry'
                WHEN jobs.expiry_date < CURDATE() THEN 'Expired'
                ELSE 'Active'
            END AS expiry_status
        FROM jobs
        LEFT JOIN job_categories ON jobs.category_id = job_categories.id
        JOIN users ON jobs.recruiter_id = users.id
        ORDER BY jobs.created_at DESC
    ");

    if (!$query) {
        die("Export query failed: " . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($query)) {
        $archive_status = intval($row['is_archived']) === 1 ? "Archived" : "Not Archived";

        $rows[] = [
            $row['id'],
            $row['title'],
            $row['category_name'] ?? "Uncategorized",
            $row['recruiter_name'],
            $row['recruiter_email'],
            $row['location'],
            $row['job_type'],
            $row['salary_min'],
            $row['salary_max'],
            $row['status'],
            $archive_status,
            $row['expiry_date'],
            $row['expiry_status'],
            $row['view_count'] ?? 0,
            $row['reject_reason'] ?? "",
            $row['created_at']
        ];
    }

    add_export_log($conn, $admin_id, "jobs");

    export_csv(
        "jobs_report.csv",
        [
            "Job ID",
            "Title",
            "Category",
            "Recruiter Name",
            "Recruiter Email",
            "Location",
            "Job Type",
            "Salary Min",
            "Salary Max",
            "Status",
            "Archive Status",
            "Expiry Date",
            "Expiry Status",
            "Views",
            "Reject Reason",
            "Created At"
        ],
        $rows
    );
}

if ($type === "applications") {
    $rows = [];

    $query = mysqli_query($conn, "
        SELECT 
            applications.id,
            jobs.title AS job_title,
            applicant.full_name AS applicant_name,
            applicant.email AS applicant_email,
            recruiter.full_name AS recruiter_name,
            recruiter.email AS recruiter_email,
            applications.status,
            applications.recruiter_remarks,
            applications.applied_at
        FROM applications
        JOIN jobs ON applications.job_id = jobs.id
        JOIN users AS applicant ON applications.applicant_id = applicant.id
        JOIN users AS recruiter ON jobs.recruiter_id = recruiter.id
        ORDER BY applications.applied_at DESC
    ");

    if (!$query) {
        die("Export query failed: " . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = [
            $row['id'],
            $row['job_title'],
            $row['applicant_name'],
            $row['applicant_email'],
            $row['recruiter_name'],
            $row['recruiter_email'],
            $row['status'],
            $row['recruiter_remarks'],
            $row['applied_at']
        ];
    }

    add_export_log($conn, $admin_id, "applications");

    export_csv(
        "applications_report.csv",
        [
            "Application ID",
            "Job Title",
            "Applicant Name",
            "Applicant Email",
            "Recruiter Name",
            "Recruiter Email",
            "Status",
            "Recruiter Remarks",
            "Applied At"
        ],
        $rows
    );
}

if ($type === "interviews") {
    $rows = [];

    $query = mysqli_query($conn, "
        SELECT 
            interviews.id,
            jobs.title AS job_title,
            applicant.full_name AS applicant_name,
            applicant.email AS applicant_email,
            recruiter.full_name AS recruiter_name,
            recruiter.email AS recruiter_email,
            interviews.interview_date,
            interviews.interview_time,
            interviews.interview_mode,
            interviews.interview_location,
            interviews.status,
            interviews.created_at
        FROM interviews
        JOIN applications ON interviews.application_id = applications.id
        JOIN jobs ON applications.job_id = jobs.id
        JOIN users AS applicant ON interviews.applicant_id = applicant.id
        JOIN users AS recruiter ON interviews.recruiter_id = recruiter.id
        ORDER BY interviews.created_at DESC
    ");

    if (!$query) {
        die("Export query failed: " . mysqli_error($conn));
    }

    while ($row = mysqli_fetch_assoc($query)) {
        $rows[] = [
            $row['id'],
            $row['job_title'],
            $row['applicant_name'],
            $row['applicant_email'],
            $row['recruiter_name'],
            $row['recruiter_email'],
            $row['interview_date'],
            $row['interview_time'],
            $row['interview_mode'],
            $row['interview_location'],
            $row['status'],
            $row['created_at']
        ];
    }

    add_export_log($conn, $admin_id, "interviews");

    export_csv(
        "interviews_report.csv",
        [
            "Interview ID",
            "Job Title",
            "Applicant Name",
            "Applicant Email",
            "Recruiter Name",
            "Recruiter Email",
            "Interview Date",
            "Interview Time",
            "Interview Mode",
            "Location / Link",
            "Status",
            "Created At"
        ],
        $rows
    );
}

header("Location: reports.php");
exit();
?>