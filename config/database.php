<?php
// Database connection for Job Application System

$host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
$username = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
$password = getenv('MYSQLPASSWORD');
if ($password === false) {
    $password = getenv('DB_PASSWORD') ?: '';
}
$database = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'job_application';
$port = (int) (getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: 3306);

if ($port < 1 || $port > 65535) {
    error_log('Database connection failed: MYSQLPORT/DB_PORT must be between 1 and 65535.');
    die('Database connection failed. Check the service logs and database environment variables.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $username, $password, $database, $port);
    $conn->set_charset('utf8mb4');
} catch (mysqli_sql_exception $exception) {
    error_log('Database connection failed: ' . $exception->getMessage());
    die('Database connection failed. Check the service logs and database environment variables.');
}
?>