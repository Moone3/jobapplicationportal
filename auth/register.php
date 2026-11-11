<?php
// auth/register.php
session_start();
include "../config/database.php";

$error = "";
$success = "";

if (isset($_POST['register'])) {
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $role = $_POST['role'];

    if (empty($full_name) || empty($email) || empty($password) || empty($confirm_password) || empty($role)) {
        $error = "All fields are required.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif ($password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($role !== "applicant" && $role !== "recruiter") {
        $error = "Invalid role selected.";
    } else {
        $check_sql = "SELECT id FROM users WHERE email = ?";
        $check_stmt = mysqli_prepare($conn, $check_sql);
        mysqli_stmt_bind_param($check_stmt, "s", $email);
        mysqli_stmt_execute($check_stmt);
        mysqli_stmt_store_result($check_stmt);

        if (mysqli_stmt_num_rows($check_stmt) > 0) {
            $error = "Email already exists.";
        } else {
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);

            $insert_sql = "INSERT INTO users (full_name, email, password, role, status) VALUES (?, ?, ?, ?, 'active')";
            $insert_stmt = mysqli_prepare($conn, $insert_sql);
            mysqli_stmt_bind_param($insert_stmt, "ssss", $full_name, $email, $hashed_password, $role);

            if (mysqli_stmt_execute($insert_stmt)) {
                $user_id = mysqli_insert_id($conn);

                if ($role === "applicant") {
                    $profile_sql = "INSERT INTO applicant_profiles (user_id) VALUES (?)";
                } else {
                    $profile_sql = "INSERT INTO recruiter_profiles (user_id) VALUES (?)";
                }

                $profile_stmt = mysqli_prepare($conn, $profile_sql);
                mysqli_stmt_bind_param($profile_stmt, "i", $user_id);
                mysqli_stmt_execute($profile_stmt);

                $success = "Registration successful. You can now login.";
            } else {
                $error = "Registration failed. Please try again.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Register | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-1: #050816;
            --bg-2: #0b1024;
            --card: rgba(255, 255, 255, 0.08);
            --card-strong: rgba(255, 255, 255, 0.12);
            --border: rgba(255, 255, 255, 0.16);
            --text: #f8fbff;
            --muted: #b8c3d9;
            --muted-2: #7f8da8;
            --cyan: #19d3ff;
            --blue: #4c7dff;
            --purple: #9b5cff;
            --pink: #ff4ecd;
            --danger-bg: rgba(255, 92, 122, 0.13);
            --danger-border: rgba(255, 92, 122, 0.35);
            --danger-text: #ffd4dc;
            --success-bg: rgba(46, 213, 115, 0.13);
            --success-border: rgba(46, 213, 115, 0.35);
            --success-text: #c7ffdf;
        }

        * {
            box-sizing: border-box;
            font-family: Inter, Arial, Helvetica, sans-serif;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 15% 15%, rgba(25, 211, 255, 0.22), transparent 34%),
                radial-gradient(circle at 82% 20%, rgba(155, 92, 255, 0.26), transparent 32%),
                radial-gradient(circle at 72% 86%, rgba(255, 78, 205, 0.18), transparent 35%),
                linear-gradient(135deg, var(--bg-1), var(--bg-2) 55%, #111631);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text);
            padding: 34px 18px;
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
            background-size: 48px 48px;
            mask-image: radial-gradient(circle at center, black, transparent 82%);
        }

        .auth-container {
            width: 100%;
            max-width: 1020px;
            min-height: 590px;
            display: grid;
            grid-template-columns: 0.95fr 1.15fr;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 30px;
            overflow: hidden;
            box-shadow: 0 34px 90px rgba(0, 0, 0, 0.36);
            backdrop-filter: blur(22px);
            position: relative;
            animation: cardIn 0.7s ease both;
        }

        .auth-container::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            padding: 1px;
            background: linear-gradient(135deg, rgba(25,211,255,0.65), rgba(155,92,255,0.55), rgba(255,78,205,0.45));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .auth-left {
            background:
                radial-gradient(circle at 20% 20%, rgba(25, 211, 255, 0.26), transparent 34%),
                radial-gradient(circle at 85% 85%, rgba(255, 78, 205, 0.20), transparent 42%),
                linear-gradient(160deg, rgba(21, 30, 66, 0.88), rgba(12, 17, 38, 0.9));
            padding: 56px 42px;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            isolation: isolate;
        }

        .auth-left::after {
            content: "";
            position: absolute;
            width: 210px;
            height: 210px;
            right: -70px;
            bottom: -70px;
            background: linear-gradient(135deg, var(--cyan), var(--purple), var(--pink));
            opacity: 0.22;
            filter: blur(3px);
            border-radius: 50%;
            z-index: -1;
            animation: floatGlow 6s ease-in-out infinite;
        }

        .auth-left h1 {
            font-size: clamp(34px, 5vw, 54px);
            line-height: 1.02;
            margin: 0 0 18px;
            letter-spacing: -1.8px;
        }

        .auth-left p {
            font-size: 15px;
            line-height: 1.8;
            color: var(--muted);
            margin: 0;
            max-width: 380px;
        }

        .auth-badge {
            width: max-content;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid rgba(255, 255, 255, 0.16);
            padding: 10px 15px;
            border-radius: 999px;
            margin-bottom: 28px;
            font-size: 13px;
            font-weight: 800;
            color: #eaf7ff;
            box-shadow: 0 12px 28px rgba(0,0,0,0.18);
        }

        .auth-badge::before {
            content: "J";
            width: 28px;
            height: 28px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            color: #ffffff;
            background: linear-gradient(135deg, var(--cyan), var(--blue), var(--purple));
            font-weight: 900;
        }

        .auth-right {
            padding: 52px 48px;
            background: rgba(255, 255, 255, 0.055);
        }

        .auth-right h2 {
            margin: 0 0 8px;
            font-size: 32px;
            color: var(--text);
            letter-spacing: -0.8px;
        }

        .auth-right .subtitle {
            margin: 0 0 28px;
            color: var(--muted);
            font-size: 15px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            font-weight: 800;
            color: #e6ecff;
        }

        input,
        select {
            width: 100%;
            padding: 14px 15px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 15px;
            outline: none;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.08);
            color: var(--text);
            transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease, transform 0.2s ease;
        }

        input::placeholder {
            color: rgba(226, 234, 255, 0.52);
        }

        select {
            cursor: pointer;
            appearance: none;
            background-image:
                linear-gradient(45deg, transparent 50%, #dce7ff 50%),
                linear-gradient(135deg, #dce7ff 50%, transparent 50%);
            background-position:
                calc(100% - 20px) 53%,
                calc(100% - 14px) 53%;
            background-size: 6px 6px, 6px 6px;
            background-repeat: no-repeat;
        }

        select option {
            color: #111827;
            background: #ffffff;
        }

        input:focus,
        select:focus {
            border-color: rgba(25, 211, 255, 0.75);
            box-shadow: 0 0 0 4px rgba(25, 211, 255, 0.12), 0 10px 24px rgba(0,0,0,0.18);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        .btn {
            width: 100%;
            border: none;
            padding: 15px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--cyan), var(--blue), var(--purple));
            color: #ffffff;
            font-size: 15px;
            font-weight: 900;
            cursor: pointer;
            margin-top: 8px;
            box-shadow: 0 18px 34px rgba(76, 125, 255, 0.28);
            transition: transform 0.22s ease, box-shadow 0.22s ease, filter 0.22s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 45px rgba(155, 92, 255, 0.34);
            filter: brightness(1.08);
        }

        .btn:active {
            transform: translateY(0);
        }

        .alert {
            padding: 13px 15px;
            border-radius: 15px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.45;
        }

        .alert-error {
            background: var(--danger-bg);
            color: var(--danger-text);
            border: 1px solid var(--danger-border);
        }

        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
            border: 1px solid var(--success-border);
        }

        .auth-link {
            text-align: center;
            margin-top: 22px;
            color: var(--muted);
            font-size: 14px;
        }

        .auth-link a {
            color: #8eeaff;
            text-decoration: none;
            font-weight: 900;
            transition: color 0.2s ease;
        }

        .auth-link a:hover {
            color: #ffffff;
            text-decoration: underline;
        }


        .back-home {
            position: fixed;
            top: 24px;
            left: 24px;
            z-index: 5;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 12px 16px;
            border-radius: 999px;
            color: #eaf2ff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 900;
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 0 16px 35px rgba(0, 0, 0, 0.22);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            transition: transform 0.22s ease, background 0.22s ease, border-color 0.22s ease, box-shadow 0.22s ease;
        }

        .back-home:hover {
            transform: translateY(-2px);
            background: rgba(25, 211, 255, 0.12);
            border-color: rgba(25, 211, 255, 0.35);
            box-shadow: 0 20px 45px rgba(25, 211, 255, 0.16), 0 16px 35px rgba(0, 0, 0, 0.22);
        }

        .back-home span {
            font-size: 18px;
            line-height: 1;
        }

        @keyframes cardIn {
            from {
                opacity: 0;
                transform: translateY(18px) scale(0.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes floatGlow {
            0%, 100% {
                transform: translateY(0) scale(1);
            }
            50% {
                transform: translateY(-16px) scale(1.05);
            }
        }

        @media (max-width: 880px) {
            body {
                align-items: flex-start;
                padding: 22px 14px;
            }

            .auth-container {
                grid-template-columns: 1fr;
                max-width: 560px;
                min-height: unset;
            }

            .auth-left {
                padding: 38px 30px;
            }

            .auth-right {
                padding: 34px 30px 38px;
            }
        }

        @media (max-width: 480px) {
            .back-home {
                top: 14px;
                left: 14px;
                padding: 10px 13px;
                font-size: 13px;
            }

            .auth-container {
                border-radius: 22px;
            }

            .auth-left,
            .auth-right {
                padding-left: 22px;
                padding-right: 22px;
            }
        }
    </style>
</head>
<body>

<a href="../index.php" class="back-home"><span>←</span> Back to Home</a>

<div class="auth-container">
    <div class="auth-left">
        <span class="auth-badge">Job Application System</span>
        <h1>Create your account</h1>
        <p>
            Register as an applicant to search and apply for jobs, or register as a recruiter
            to post job opportunities and manage applicants.
        </p>
    </div>

    <div class="auth-right">
        <h2>Register</h2>
        <p class="subtitle">Please fill in your details to continue.</p>

        <?php if (!empty($error)) { ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php } ?>

        <?php if (!empty($success)) { ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php } ?>

        <form method="POST" action="">
            <div class="form-group">
                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="Enter your full name" required>
            </div>

            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" placeholder="Enter your email address" required>
            </div>

            <div class="form-group">
                <label>Register As</label>
                <select name="role" required>
                    <option value="">Select role</option>
                    <option value="applicant">Applicant</option>
                    <option value="recruiter">Recruiter</option>
                </select>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>

            <div class="form-group">
                <label>Confirm Password</label>
                <input type="password" name="confirm_password" placeholder="Confirm password" required>
            </div>

            <button type="submit" name="register" class="btn">Create Account</button>
        </form>

        <div class="auth-link">
            Already have an account? <a href="login.php">Login here</a>
        </div>
    </div>
</div>

</body>
</html>