<?php
// auth/reset_password.php
include "../config/database.php";

$error = "";
$success = "";

$token = isset($_GET['token']) ? trim($_GET['token']) : "";

if (empty($token)) {
    $error = "Invalid reset link.";
}

$user = null;

if (empty($error)) {
    $sql = "
        SELECT id, full_name, email, reset_token, reset_expires
        FROM users
        WHERE reset_token = ?
        LIMIT 1
    ";

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "s", $token);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) === 1) {
        $user = mysqli_fetch_assoc($result);

        if (empty($user['reset_expires']) || strtotime($user['reset_expires']) < time()) {
            $error = "This reset link has expired. Please request a new one.";
        }
    } else {
        $error = "Invalid reset link.";
    }
}

if (isset($_POST['reset_password']) && empty($error)) {
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($new_password) || empty($confirm_password)) {
        $error = "All password fields are required.";
    } elseif ($new_password !== $confirm_password) {
        $error = "Passwords do not match.";
    } elseif (strlen($new_password) < 6) {
        $error = "Password must be at least 6 characters.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $update_sql = "
            UPDATE users
            SET password = ?, 
                reset_token = NULL, 
                reset_expires = NULL
            WHERE id = ?
        ";

        $update_stmt = mysqli_prepare($conn, $update_sql);
        mysqli_stmt_bind_param($update_stmt, "si", $hashed_password, $user['id']);

        if (mysqli_stmt_execute($update_stmt)) {
            $success = "Password reset successfully. You can now login with your new password.";
            $user = null;
        } else {
            $error = "Failed to reset password.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reset Password | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-dark: #070b18;
            --bg-panel: rgba(15, 23, 42, 0.78);
            --card: rgba(255, 255, 255, 0.09);
            --card-strong: rgba(255, 255, 255, 0.13);
            --line: rgba(255, 255, 255, 0.16);
            --text: #f8fbff;
            --muted: #aab7cf;
            --cyan: #12d7ff;
            --blue: #3267ff;
            --purple: #8b5cf6;
            --pink: #ff3ea5;
            --red: #ff3b5f;
            --success: #26e0a8;
            --shadow: 0 28px 90px rgba(0, 0, 0, 0.42);
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
            padding: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            background:
                radial-gradient(circle at 15% 10%, rgba(18, 215, 255, 0.24), transparent 34%),
                radial-gradient(circle at 85% 16%, rgba(255, 62, 165, 0.22), transparent 30%),
                radial-gradient(circle at 48% 100%, rgba(50, 103, 255, 0.28), transparent 36%),
                linear-gradient(135deg, #070b18 0%, #0d1630 45%, #120817 100%);
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 420px;
            height: 420px;
            border-radius: 50%;
            pointer-events: none;
            filter: blur(20px);
            opacity: 0.35;
            animation: floatGlow 8s ease-in-out infinite alternate;
            z-index: 0;
        }

        body::before {
            left: -150px;
            top: -120px;
            background: linear-gradient(135deg, var(--cyan), var(--blue));
        }

        body::after {
            right: -160px;
            bottom: -150px;
            background: linear-gradient(135deg, var(--pink), var(--purple));
            animation-delay: -2s;
        }

        @keyframes floatGlow {
            from { transform: translate3d(0, 0, 0) scale(1); }
            to { transform: translate3d(22px, -18px, 0) scale(1.08); }
        }

        .auth-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 500px;
            padding: 38px;
            border-radius: 30px;
            background:
                linear-gradient(145deg, rgba(255, 255, 255, 0.13), rgba(255, 255, 255, 0.065)),
                rgba(9, 15, 33, 0.78);
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            overflow: hidden;
            animation: cardIn 0.7s ease both;
        }

        .auth-card::before {
            content: "";
            position: absolute;
            inset: 0;
            padding: 1px;
            border-radius: inherit;
            background: linear-gradient(135deg, rgba(18, 215, 255, 0.75), rgba(139, 92, 246, 0.35), rgba(255, 62, 165, 0.65));
            -webkit-mask: linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .auth-card::after {
            content: "";
            position: absolute;
            right: -80px;
            top: -80px;
            width: 190px;
            height: 190px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255, 62, 165, 0.38), transparent 66%);
            pointer-events: none;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(18px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 13px;
            margin-bottom: 30px;
            position: relative;
            z-index: 1;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 17px;
            background: linear-gradient(135deg, var(--cyan), var(--blue) 48%, var(--pink));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 20px;
            box-shadow: 0 14px 30px rgba(18, 215, 255, 0.28);
        }

        .brand-text {
            font-size: 23px;
            font-weight: 900;
            letter-spacing: 0.2px;
            color: #ffffff;
        }

        h1 {
            position: relative;
            z-index: 1;
            margin: 0;
            font-size: clamp(30px, 4vw, 38px);
            line-height: 1.08;
            color: #ffffff;
            letter-spacing: -0.8px;
        }

        .subtitle {
            position: relative;
            z-index: 1;
            margin: 12px 0 26px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.7;
        }

        .form-group {
            position: relative;
            z-index: 1;
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 800;
            color: #eaf2ff;
        }

        input {
            width: 100%;
            padding: 15px 16px;
            border: 1px solid rgba(255, 255, 255, 0.14);
            border-radius: 16px;
            outline: none;
            font-size: 14px;
            background: rgba(255, 255, 255, 0.08);
            color: #ffffff;
            transition: border-color 0.22s ease, box-shadow 0.22s ease, background 0.22s ease, transform 0.22s ease;
        }

        input::placeholder {
            color: rgba(220, 232, 255, 0.58);
        }

        input:focus {
            border-color: rgba(18, 215, 255, 0.8);
            box-shadow: 0 0 0 4px rgba(18, 215, 255, 0.14), 0 12px 24px rgba(0, 0, 0, 0.18);
            background: rgba(255, 255, 255, 0.12);
            transform: translateY(-1px);
        }

        form {
            position: relative;
            z-index: 1;
        }

        .btn {
            width: 100%;
            padding: 15px 18px;
            border-radius: 16px;
            border: 0;
            background: linear-gradient(135deg, var(--cyan), var(--blue) 52%, var(--pink));
            color: #ffffff;
            font-size: 15px;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 18px 35px rgba(50, 103, 255, 0.28);
            transition: transform 0.22s ease, box-shadow 0.22s ease, filter 0.22s ease;
        }

        .btn:hover {
            transform: translateY(-3px);
            filter: brightness(1.08);
            box-shadow: 0 24px 45px rgba(255, 62, 165, 0.24), 0 14px 35px rgba(18, 215, 255, 0.18);
        }

        .btn:active {
            transform: translateY(-1px) scale(0.99);
        }

        .alert {
            position: relative;
            z-index: 1;
            padding: 14px 15px;
            border-radius: 16px;
            margin-bottom: 20px;
            font-size: 14px;
            line-height: 1.5;
        }

        .alert-success {
            background: rgba(38, 224, 168, 0.13);
            color: #d7fff2;
            border: 1px solid rgba(38, 224, 168, 0.38);
            box-shadow: 0 14px 28px rgba(38, 224, 168, 0.08);
        }

        .alert-error {
            background: rgba(255, 59, 95, 0.13);
            color: #ffd7df;
            border: 1px solid rgba(255, 59, 95, 0.38);
            box-shadow: 0 14px 28px rgba(255, 59, 95, 0.08);
        }

        .bottom-link {
            position: relative;
            z-index: 1;
            margin-top: 22px;
            text-align: center;
            font-size: 14px;
            color: var(--muted);
        }

        .bottom-link a {
            color: #62e8ff;
            text-decoration: none;
            font-size: 14px;
            font-weight: 900;
            transition: color 0.22s ease, text-shadow 0.22s ease;
        }

        .bottom-link a:hover {
            color: #ffffff;
            text-shadow: 0 0 16px rgba(18, 215, 255, 0.65);
        }

        @media (max-width: 520px) {
            body {
                padding: 16px;
                align-items: flex-start;
            }

            .auth-card {
                margin-top: 20px;
                padding: 28px 22px;
                border-radius: 24px;
            }
        }
    </style>
</head>
<body>

<div class="auth-card">
    <div class="brand">
        <div class="brand-icon">J</div>
        <div class="brand-text">Job Portal</div>
    </div>

    <h1>Reset Password</h1>

    <?php if ($user && empty($success)) { ?>
        <p class="subtitle">
            Set a new password for <?php echo htmlspecialchars($user['email']); ?>.
        </p>
    <?php } else { ?>
        <p class="subtitle">
            Enter a valid reset link to change your password.
        </p>
    <?php } ?>

    <?php if (!empty($error)) { ?>
        <div class="alert alert-error">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php } ?>

    <?php if (!empty($success)) { ?>
        <div class="alert alert-success">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php } ?>

    <?php if ($user && empty($success) && empty($error)) { ?>
        <form method="POST">
            <div class="form-group">
                <label>New Password</label>
                <input 
                    type="password" 
                    name="new_password" 
                    placeholder="Enter new password" 
                    required
                >
            </div>

            <div class="form-group">
                <label>Confirm New Password</label>
                <input 
                    type="password" 
                    name="confirm_password" 
                    placeholder="Confirm new password" 
                    required
                >
            </div>

            <button type="submit" name="reset_password" class="btn">
                Reset Password
            </button>
        </form>
    <?php } ?>

    <div class="bottom-link">
        <a href="login.php">Back to Login</a>
    </div>
</div>

</body>
</html>