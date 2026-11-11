<?php
// auth/logout.php
session_start();

$_SESSION = [];

if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out | Job Application System</title>
    <meta http-equiv="refresh" content="1.4;url=login.php">
    <style>
        :root {
            --bg-main: #070b18;
            --bg-card: rgba(13, 18, 36, 0.78);
            --text-main: #f8fbff;
            --text-muted: #aeb9cd;
            --cyan: #19d3ff;
            --blue: #4a7dff;
            --purple: #8e5cff;
            --pink: #ff4f9f;
            --border: rgba(255, 255, 255, 0.14);
            --shadow: 0 30px 90px rgba(0, 0, 0, 0.45);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            font-family: Inter, Segoe UI, Arial, sans-serif;
            color: var(--text-main);
            background:
                radial-gradient(circle at 15% 20%, rgba(25, 211, 255, 0.22), transparent 30%),
                radial-gradient(circle at 82% 18%, rgba(142, 92, 255, 0.30), transparent 32%),
                radial-gradient(circle at 70% 82%, rgba(255, 79, 159, 0.22), transparent 28%),
                linear-gradient(135deg, #050816 0%, #081023 45%, #10132e 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            overflow: hidden;
        }

        body::before,
        body::after {
            content: "";
            position: fixed;
            width: 420px;
            height: 420px;
            border-radius: 999px;
            filter: blur(12px);
            opacity: 0.28;
            pointer-events: none;
            animation: floatGlow 8s ease-in-out infinite alternate;
        }

        body::before {
            top: -170px;
            left: -130px;
            background: linear-gradient(135deg, var(--cyan), var(--blue));
        }

        body::after {
            right: -150px;
            bottom: -170px;
            background: linear-gradient(135deg, var(--purple), var(--pink));
            animation-delay: 1.2s;
        }

        .logout-card {
            width: min(100%, 480px);
            position: relative;
            z-index: 1;
            padding: 44px 40px;
            border: 1px solid var(--border);
            border-radius: 28px;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.06)), var(--bg-card);
            backdrop-filter: blur(18px);
            box-shadow: var(--shadow);
            text-align: center;
            animation: cardIn 0.65s ease both;
        }

        .brand {
            display: inline-flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 28px;
        }

        .brand-icon {
            width: 52px;
            height: 52px;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--cyan), var(--purple));
            display: grid;
            place-items: center;
            color: white;
            font-size: 24px;
            font-weight: 900;
            box-shadow: 0 14px 30px rgba(25, 211, 255, 0.25);
        }

        .brand-text {
            font-size: 26px;
            font-weight: 900;
            letter-spacing: 0.2px;
        }

        h1 {
            font-size: clamp(30px, 5vw, 42px);
            line-height: 1.1;
            margin-bottom: 12px;
        }

        p {
            color: var(--text-muted);
            font-size: 16px;
            line-height: 1.7;
            margin-bottom: 28px;
        }

        .loader {
            width: 74px;
            height: 74px;
            margin: 0 auto 26px;
            border-radius: 50%;
            border: 5px solid rgba(255, 255, 255, 0.12);
            border-top-color: var(--cyan);
            border-right-color: var(--purple);
            animation: spin 0.9s linear infinite;
        }

        .progress {
            height: 8px;
            width: 100%;
            overflow: hidden;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.10);
            border: 1px solid rgba(255, 255, 255, 0.10);
        }

        .progress span {
            display: block;
            width: 0;
            height: 100%;
            border-radius: inherit;
            background: linear-gradient(90deg, var(--cyan), var(--blue), var(--purple), var(--pink));
            animation: loadingBar 1.35s ease forwards;
        }

        .fallback-link {
            display: inline-block;
            margin-top: 24px;
            color: #72e4ff;
            font-weight: 800;
            text-decoration: none;
            transition: 0.25s ease;
        }

        .fallback-link:hover {
            color: white;
            transform: translateY(-1px);
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @keyframes loadingBar {
            to { width: 100%; }
        }

        @keyframes cardIn {
            from {
                opacity: 0;
                transform: translateY(20px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes floatGlow {
            from { transform: translate3d(0, 0, 0) scale(1); }
            to { transform: translate3d(28px, 22px, 0) scale(1.05); }
        }

        @media (max-width: 520px) {
            .logout-card {
                padding: 34px 24px;
                border-radius: 22px;
            }

            .brand-text {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <main class="logout-card">
        <div class="brand">
            <div class="brand-icon">J</div>
            <div class="brand-text">Job Portal</div>
        </div>

        <div class="loader" aria-label="Logging out"></div>

        <h1>Logging Out</h1>
        <p>Your session has been safely ended. Redirecting you back to the login page.</p>

        <div class="progress" aria-hidden="true">
            <span></span>
        </div>

        <a href="login.php" class="fallback-link">Back to Login</a>
    </main>

    <script>
        setTimeout(function () {
            window.location.href = 'login.php';
        }, 1400);
    </script>
</body>
</html>
