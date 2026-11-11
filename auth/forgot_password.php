<?php
// auth/forgot_password.php
include "../config/database.php";

$error = "";
$success = "";
$reset_link = "";

if (isset($_POST['send_reset'])) {
    $email = trim($_POST['email']);

    if (empty($email)) {
        $error = "Please enter your email address.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        $sql = "
            SELECT id, full_name, email 
            FROM users 
            WHERE email = ? 
            LIMIT 1
        ";

        $stmt = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);

            $token = bin2hex(random_bytes(32));
            $expires = date("Y-m-d H:i:s", strtotime("+30 minutes"));

            $update_sql = "
                UPDATE users 
                SET reset_token = ?, 
                    reset_expires = ? 
                WHERE id = ?
            ";

            $update_stmt = mysqli_prepare($conn, $update_sql);
            mysqli_stmt_bind_param($update_stmt, "ssi", $token, $expires, $user['id']);

            if (mysqli_stmt_execute($update_stmt)) {
                $reset_link = "http://localhost/job_application/auth/reset_password.php?token=" . $token;
                $success = "Password reset link generated successfully. Since this is local WAMP, copy the reset link below.";
            } else {
                $error = "Failed to create reset link.";
            }
        } else {
            $error = "No account found with that email address.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Forgot Password | Job Application System</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-main: #070b18;
            --bg-deep: #0b1024;
            --card: rgba(15, 23, 42, 0.74);
            --card-strong: rgba(15, 23, 42, 0.92);
            --line: rgba(148, 163, 184, 0.22);
            --text: #f8fafc;
            --muted: #a8b3cf;
            --muted-2: #cbd5e1;
            --cyan: #22d3ee;
            --blue: #3b82f6;
            --purple: #8b5cf6;
            --pink: #ec4899;
            --danger: #fb7185;
            --success: #34d399;
            --shadow: 0 28px 90px rgba(0, 0, 0, 0.45);
        }

        * {
            box-sizing: border-box;
            font-family: Inter, Arial, Helvetica, sans-serif;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            color: var(--text);
            background:
                radial-gradient(circle at 15% 15%, rgba(34, 211, 238, 0.28), transparent 28%),
                radial-gradient(circle at 88% 12%, rgba(236, 72, 153, 0.26), transparent 30%),
                radial-gradient(circle at 50% 95%, rgba(139, 92, 246, 0.28), transparent 36%),
                linear-gradient(135deg, var(--bg-main), var(--bg-deep) 52%, #111827);
            overflow-x: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 420px;
            height: 420px;
            border-radius: 999px;
            filter: blur(16px);
            opacity: 0.42;
            pointer-events: none;
            z-index: 0;
            animation: floatGlow 9s ease-in-out infinite;
        }

        body::before {
            top: -140px;
            left: -110px;
            background: linear-gradient(135deg, rgba(34, 211, 238, 0.45), rgba(59, 130, 246, 0.12));
        }

        body::after {
            right: -150px;
            bottom: -170px;
            background: linear-gradient(135deg, rgba(236, 72, 153, 0.38), rgba(139, 92, 246, 0.22));
            animation-delay: -3s;
        }

        @keyframes floatGlow {
            0%, 100% { transform: translate3d(0, 0, 0) scale(1); }
            50% { transform: translate3d(18px, -16px, 0) scale(1.06); }
        }

        .auth-card {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 480px;
            padding: 38px;
            border-radius: 30px;
            background: linear-gradient(180deg, rgba(15, 23, 42, 0.88), rgba(15, 23, 42, 0.72));
            border: 1px solid var(--line);
            box-shadow: var(--shadow);
            backdrop-filter: blur(22px);
            animation: cardIn 0.65s ease both;
            overflow: hidden;
        }

        .auth-card::before {
            content: "";
            position: absolute;
            inset: 0;
            padding: 1px;
            border-radius: inherit;
            background: linear-gradient(135deg, rgba(34, 211, 238, 0.65), rgba(139, 92, 246, 0.25), rgba(236, 72, 153, 0.5));
            -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            -webkit-mask-composite: xor;
            mask-composite: exclude;
            pointer-events: none;
        }

        .auth-card::after {
            content: "";
            position: absolute;
            width: 170px;
            height: 170px;
            right: -80px;
            top: -70px;
            background: rgba(34, 211, 238, 0.14);
            border-radius: 999px;
            filter: blur(2px);
            pointer-events: none;
        }

        @keyframes cardIn {
            from { opacity: 0; transform: translateY(18px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
        }

        .brand-icon {
            width: 48px;
            height: 48px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--cyan), var(--blue) 52%, var(--purple));
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 900;
            font-size: 20px;
            box-shadow: 0 14px 30px rgba(34, 211, 238, 0.26);
        }

        .brand-text {
            font-size: 24px;
            font-weight: 900;
            letter-spacing: 0.2px;
            color: #ffffff;
        }

        h1 {
            margin: 0;
            font-size: clamp(28px, 4vw, 34px);
            line-height: 1.12;
            color: #ffffff;
            letter-spacing: -0.8px;
        }

        .subtitle {
            margin: 12px 0 26px;
            color: var(--muted);
            font-size: 15px;
            line-height: 1.65;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: 800;
            color: var(--muted-2);
        }

        input {
            width: 100%;
            padding: 15px 16px;
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 16px;
            outline: none;
            font-size: 15px;
            background: rgba(2, 6, 23, 0.48);
            color: #ffffff;
            transition: border-color 0.25s ease, box-shadow 0.25s ease, background 0.25s ease, transform 0.25s ease;
        }

        input::placeholder {
            color: rgba(203, 213, 225, 0.58);
        }

        input:focus {
            border-color: rgba(34, 211, 238, 0.88);
            box-shadow: 0 0 0 4px rgba(34, 211, 238, 0.15), 0 16px 36px rgba(0, 0, 0, 0.18);
            background: rgba(2, 6, 23, 0.68);
            transform: translateY(-1px);
        }

        .btn {
            width: 100%;
            padding: 15px 18px;
            border-radius: 16px;
            border: none;
            background: linear-gradient(135deg, var(--cyan), var(--blue) 48%, var(--purple));
            color: #ffffff;
            font-size: 15px;
            font-weight: 900;
            cursor: pointer;
            box-shadow: 0 18px 38px rgba(59, 130, 246, 0.28);
            transition: transform 0.25s ease, box-shadow 0.25s ease, filter 0.25s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 22px 48px rgba(34, 211, 238, 0.30);
            filter: brightness(1.05);
        }

        .btn:active {
            transform: translateY(0);
        }

        .alert {
            padding: 14px 16px;
            border-radius: 16px;
            margin-bottom: 18px;
            font-size: 14px;
            line-height: 1.5;
            backdrop-filter: blur(12px);
        }

        .alert-success {
            background: rgba(16, 185, 129, 0.12);
            color: #a7f3d0;
            border: 1px solid rgba(52, 211, 153, 0.28);
        }

        .alert-error {
            background: rgba(244, 63, 94, 0.13);
            color: #fecdd3;
            border: 1px solid rgba(251, 113, 133, 0.30);
        }

        .reset-box {
            background: rgba(2, 6, 23, 0.42);
            border: 1px solid rgba(148, 163, 184, 0.22);
            border-radius: 18px;
            padding: 16px;
            margin-top: 18px;
            word-break: break-all;
            font-size: 14px;
            line-height: 1.65;
            color: var(--muted-2);
        }

        .reset-box strong {
            color: #ffffff;
        }

        .reset-box a {
            color: var(--cyan);
            font-weight: 900;
            text-decoration: none;
        }

        .reset-box a:hover {
            text-decoration: underline;
        }

        .bottom-link {
            margin-top: 24px;
            text-align: center;
            font-size: 14px;
            color: var(--muted);
        }

        .bottom-link a {
            color: var(--cyan);
            text-decoration: none;
            font-weight: 900;
        }

        .bottom-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 520px) {
            body {
                padding: 16px;
                align-items: flex-start;
                padding-top: 42px;
            }

            .auth-card {
                padding: 28px 22px;
                border-radius: 24px;
            }

            .brand-text {
                font-size: 21px;
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

    <h1>Forgot Password</h1>
    <p class="subtitle">
        Enter your email address and the system will generate a reset link for local testing.
    </p>

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

    <form method="POST">
        <div class="form-group">
            <label>Email Address</label>
            <input 
                type="email" 
                name="email" 
                placeholder="Enter your registered email"
                required
            >
        </div>

        <button type="submit" name="send_reset" class="btn">
            Generate Reset Link
        </button>
    </form>

    <?php if (!empty($reset_link)) { ?>
        <div class="reset-box">
            <strong>Reset Link:</strong><br>
            <a href="<?php echo htmlspecialchars($reset_link); ?>">
                <?php echo htmlspecialchars($reset_link); ?>
            </a>
        </div>
    <?php } ?>

    <div class="bottom-link">
        Remembered your password?
        <a href="login.php">Back to Login</a>
    </div>
</div>

</body>
</html>