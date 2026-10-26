<?php

require_once 'includes/config.php';

// All staff accounts use the unified login in the parent SYSTEM directory.
// Keep this legacy URL as a compatibility redirect so no second login flow
// can split role handling or show outdated role-specific instructions.
header('Location: ../login.php' . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : ''));
exit;

if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'employee') {
        redirect('employee_dashboard.php');
    } else {
        redirect(dashboardUrlForRole($_SESSION['role']));
    }
}

$expired_message = "";
if (isset($_GET['expired'])) {
    $expired_message = "Your session has expired due to inactivity. Please log in again.";
}
if (isset($_GET['reset'])) {
    $expired_message = "Your password was reset. Please log in again.";
}
if (isset($_GET['deactivated'])) {
    $expired_message = "This account has been deactivated. Please contact Admin/HR.";
}
if (isset($_GET['suspended'])) {
    $expired_message = "This account is currently suspended. Please contact Admin/HR.";
}
if (isset($_GET['wrong_login'])) {
    $expired_message = "Cashier and Inventory Staff accounts now log in through the POS Login page, not here.";
}

$error = "";
if (isset($_GET['mail_failed'])) {
    $expired_message = $_SESSION['tfa_mail_error'] ?? 'The verification email could not be sent. Please try again or contact your Admin.';
    unset($_SESSION['tfa_mail_error']);
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();

    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $clientIp = getClientIp();

    if (isLoginIpThrottled($pdo, $clientIp)) {

        $error = "Too many login attempts from this network. Please try again in a few minutes.";

    } elseif (empty($username) || empty($password)) {
        $error = "Please fill in the username and password.";

    } else {

        // BINARY forces case-sensitive comparison so "Juan" and "juan" are treated as different usernames
        $stmt = $pdo->prepare("
            SELECT u.*, e.status AS employee_status, e.position AS employee_position,
                   e.suspension_start_date, e.suspension_end_date, e.suspension_reason
            FROM users u
            LEFT JOIN employees e ON u.employee_id = e.employee_id
            WHERE BINARY u.username = ? LIMIT 1
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && isAccountLockedOut($pdo, $user['id'])) {

            $error = "Too many failed login attempts. Please try again in a few minutes.";

        } elseif ($user && password_verify($password, $user['password'])) {

            clearFailedLogins($pdo, $user['id']);

            if (in_array($user['employee_status'] ?? '', ['resigned', 'terminated'], true)) {
                // Check the offboard status first BEFORE the generic is_active,
                // since is_active is also set to 0 when offboarded.
                if ((int) $user['is_active'] !== 0) {
                    $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$user['id']]);
                }
                $error = $user['employee_status'] === 'resigned'
                    ? "This account can no longer be used because the employee has resigned."
                    : "This account can no longer be used because the employee has been terminated.";
            } elseif (($susp = getEmployeeSuspensionStatus($user['suspension_start_date'], $user['suspension_end_date']))['active']) {
                $daysLabel = $susp['days_remaining'] . ' day' . ($susp['days_remaining'] == 1 ? '' : 's');
                $reasonLabel = !empty($user['suspension_reason']) ? $user['suspension_reason'] : 'No reason specified.';
                $error = "This account is suspended (" . $daysLabel . " remaining, until "
                    . date('M d, Y', strtotime($user['suspension_end_date'])) . "). Reason: " . $reasonLabel;
            } elseif ((int) $user['is_active'] === 0) {
                $error = "This account has been deactivated. Please contact Admin/HR.";
            } elseif ($user['role'] === 'employee' && in_array($user['employee_position'] ?? '', NO_HRMS_LOGIN_POSITIONS, true)) {
                // This HRMS account still exists and stays active — it's the
                // credential source that syncCashierAccountsFromHRMS() /
                // syncInventoryStaffAccountsFromHRMS() (POS includes/config.php)
                // mirror into the POS side's own `users` table. But logging in
                // HERE, on the HRMS side, would land them on the old, now-redundant
                // employee_dashboard.php — profile, time in/out, and payslip are
                // all already available on their synced POS account instead.
                $error = "Cashier and Inventory Staff accounts now log in through the POS Login page, not here.";
            } else {

                // ----- TWO-FACTOR AUTHENTICATION GATE (all HRMS roles) -----
                // Runs BEFORE any of the normal "logged in" session keys
                // below are set. twofaStartLogin() redirects to
                // setup_2fa.php (first time) or verify_2fa.php (send OTP,
                // ask for code) and exits — so an Admin account never reaches
                // the real session below (or the POS-account mirror sync)
                // without completing this first.
                require_once 'includes/twofactor.php';
                if (twofaRequired($user)) {
                    $destination = ($user['role'] === 'employee') ? 'employee_dashboard.php' : dashboardUrlForRole($user['role']);
                    twofaStartLogin($pdo, $user, $destination);
                    // twofaStartLogin() always redirects + exits.
                }

                session_regenerate_id(true);

                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['employee_id'] = $user['employee_id'];
                $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
                $_SESSION['last_activity'] = time();

                // Admin only logs in here now (not through the POS login page
                // directly), but still needs the POS dashboard/inventory/settings
                // to work correctly with this same session. Mirror the account
                // into the POS database's own `users` table (matched by
                // username) and remember its id separately — see
                // currentPosUserId() in the POS side's includes/config.php.
                if ($user['role'] === 'admin' && $posPdo) {
                    try {
                        $posStmt = $posPdo->prepare("SELECT id FROM users WHERE BINARY username = ? LIMIT 1");
                        $posStmt->execute([$user['username']]);
                        $posRow = $posStmt->fetch();

                        if ($posRow) {
                            $posPdo->prepare("UPDATE users SET password = ?, full_name = ?, role = 'admin' WHERE id = ?")
                                ->execute([$user['password'], $user['full_name'], $posRow['id']]);
                            $_SESSION['pos_user_id'] = (int) $posRow['id'];
                        } else {
                            $posPdo->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, 'admin')")
                                ->execute([$user['username'], $user['password'], $user['full_name']]);
                            $_SESSION['pos_user_id'] = (int) $posPdo->lastInsertId();
                        }
                    } catch (PDOException $e) {
                        error_log("Admin POS-account sync skipped: " . $e->getMessage());
                    }
                }

                if ($user['role'] == 'employee') {
                    redirect('employee_dashboard.php');
                } else {
                    redirect(dashboardUrlForRole($user['role']));
                }

            }

        } else {
            // Recorded regardless of whether $user exists, so cycling
            // through nonexistent usernames from one IP still counts
            // toward the IP throttle above (account lockout alone can't
            // catch that, since it needs a matching user row).
            recordFailedLoginIp($pdo, $clientIp);

            if ($user) {
                recordFailedLogin($pdo, $user['id']);
            }
            $error = "Incorrect username or password. Please try again.";
        }
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — HRMS &amp; Payroll</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <script>
        (function () {
            try {
                var savedTheme = localStorage.getItem('manage-grocery-theme');
                document.documentElement.dataset.theme = savedTheme ||
                    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            } catch (error) {
                document.documentElement.dataset.theme = 'light';
            }
        }());
    </script>

    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --surface-soft: #f1f5f9;
            --text-heading: #0f172a;
            --text-body: #334155;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-strong: #cbd5e1;
            --accent: #1d4ed8;
            --accent-hover: #1e40af;
            --accent-soft: #eff6ff;
            --accent-glow: rgba(29, 78, 216, .15);
            --grid-line: rgba(37, 99, 235, .05);
            --orb-1: radial-gradient(circle at 30% 30%, #93c5fd, #3b82f6 70%);
            --orb-2: radial-gradient(circle at 30% 30%, #a5b4fc, #6366f1 70%);
            --orb-3: radial-gradient(circle at 30% 30%, #60a5fa, #2563eb 70%);
            --card-bg: #ffffff;
            --card-border: #e2e8f0;
            --card-shadow: 0 24px 60px rgba(15, 23, 42, .08), 0 4px 12px rgba(15, 23, 42, .04);
            --card-top-bar: linear-gradient(90deg, transparent, #1d4ed8, #4d8dff, transparent);
            --input-bg: #ffffff;
            --input-border: #e2e8f0;
            --input-color: #0f172a;
            --input-placeholder: #94a3b8;
            --input-focus-ring: rgba(29, 78, 216, .12);
            --btn-bg: #1d4ed8;
            --btn-bg-hover: #1e40af;
            --btn-shadow: 0 14px 40px rgba(29, 78, 216, .22);
            --btn-shadow-hover: 0 20px 55px rgba(29, 78, 216, .32);
            --alert-error-bg: #fff1f2;
            --alert-error-color: #e11d48;
            --alert-error-border: rgba(225, 29, 72, .25);
            --alert-warn-bg: #fef3c7;
            --alert-warn-color: #b45309;
            --alert-warn-border: rgba(180, 83, 9, .25);
            --crest-bg: linear-gradient(135deg, #eff6ff, #dbeafe);
            --crest-border: #bfdbfe;
            --feat-bg: #eff6ff;
            --feat-border: #dbeafe;
            --feat-color: #1d4ed8;
            --foot-btn-color: #64748b;
            --foot-btn-hover-bg: #eff6ff;
            --foot-btn-hover-color: #1d4ed8;
            --ease: cubic-bezier(.16, 1, .3, 1);
            --font-display: 'Fraunces', Georgia, serif;
            --font-body: 'Plus Jakarta Sans', 'Inter', sans-serif;
        }

        html[data-theme="dark"] {
            --bg: #060812;
            --surface: #0d1430;
            --surface-soft: #090d20;
            --text-heading: #f5f7ff;
            --text-body: #dfe7ff;
            --text-muted: #9ca8c7;
            --border: rgba(148, 163, 255, .16);
            --border-strong: rgba(148, 163, 255, .3);
            --accent: #4d8dff;
            --accent-hover: #6ea3ff;
            --accent-soft: rgba(77, 141, 255, .12);
            --accent-glow: rgba(0, 81, 213, .3);
            --grid-line: rgba(128, 151, 255, .06);
            --orb-1: radial-gradient(circle at 30% 30%, #4d8dff, #2f6fe0 70%);
            --orb-2: radial-gradient(circle at 30% 30%, #0051d5, #0038a8 70%);
            --orb-3: radial-gradient(circle at 30% 30%, #0051d5, #0038a8 70%);
            --card-bg: rgba(15, 22, 48, .8);
            --card-border: rgba(148, 163, 255, .16);
            --card-shadow: 0 30px 90px rgba(0, 0, 0, .35);
            --card-top-bar: linear-gradient(90deg, transparent, #4d8dff, #0051d5, transparent);
            --input-bg: #080d20;
            --input-border: rgba(148, 163, 255, .16);
            --input-color: #ffffff;
            --input-placeholder: #6b76a0;
            --input-focus-ring: rgba(0, 81, 213, .12);
            --btn-bg: #0051d5;
            --btn-bg-hover: #1e40af;
            --btn-shadow: 0 14px 40px rgba(0, 81, 213, .2);
            --btn-shadow-hover: 0 20px 55px rgba(0, 81, 213, .3);
            --alert-error-bg: #3a1524;
            --alert-error-color: #ffc4d4;
            --alert-error-border: rgba(255, 114, 153, .33);
            --alert-warn-bg: #3a2e0f;
            --alert-warn-color: #f5d9a8;
            --alert-warn-border: rgba(217, 119, 6, .33);
            --crest-bg: linear-gradient(135deg, rgba(0, 81, 213, .15), rgba(0, 81, 213, .18));
            --crest-border: rgba(148, 163, 255, .16);
            --feat-bg: rgba(255, 255, 255, .035);
            --feat-border: rgba(148, 163, 255, .16);
            --feat-color: #4d8dff;
            --foot-btn-color: #9ca8c7;
            --foot-btn-hover-bg: rgba(255, 255, 255, .08);
            --foot-btn-hover-color: #ffffff;
        }

        html {
            color-scheme: light;
        }

        html[data-theme="dark"] {
            color-scheme: dark;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background-color: var(--bg);
            color: var(--text-body);
            font-family: var(--font-body);
            line-height: 1.55;
            -webkit-font-smoothing: antialiased;
            transition: background-color .3s ease, color .3s ease;
        }

        .login-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            position: relative;
            background-color: var(--bg);
            background-image:
                radial-gradient(circle at 65% 12%, var(--accent-glow), transparent 30%),
                radial-gradient(circle at 12% 88%, var(--accent-glow), transparent 32%),
                linear-gradient(var(--grid-line) 1px, transparent 1px),
                linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
            background-size: auto, auto, 54px 54px, 54px 54px;
            background-attachment: fixed;
        }

        .brand-panel {
            position: relative;
            overflow: hidden;
            padding: 48px 6%;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            background: transparent;
        }

        .brand-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(3px);
            pointer-events: none;
            opacity: .8;
            z-index: 0;
        }

        .brand-orb--1 {
            top: 12%;
            right: 14%;
            width: 44px;
            height: 44px;
            background: var(--orb-1);
            animation: floatSlow 6.5s ease-in-out infinite;
        }

        .brand-orb--2 {
            bottom: 22%;
            right: 8%;
            width: 26px;
            height: 26px;
            background: var(--orb-2);
            animation: floatSlow2 5.5s ease-in-out infinite;
        }

        .brand-orb--3 {
            bottom: 40%;
            left: 10%;
            width: 18px;
            height: 18px;
            background: var(--orb-3);
            animation: floatSlow 7.5s ease-in-out infinite;
        }

        @media (max-width: 980px) {
            .brand-orb {
                display: none;
            }
        }

        @keyframes floatSlow {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(-14px) rotate(4deg);
            }
        }

        @keyframes floatSlow2 {

            0%,
            100% {
                transform: translateY(0) rotate(0deg);
            }

            50% {
                transform: translateY(12px) rotate(-5deg);
            }
        }

        @keyframes riseIn {
            from {
                opacity: 0;
                transform: translateY(16px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .brand-crest,
        .brand-body,
        .brand-foot-btn {
            position: relative;
            z-index: 1;
        }

        .brand-crest {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .crest-mark {
            width: 52px;
            height: 52px;
            display: grid;
            place-items: center;
            border: 1px solid #93c5fd;
            border-radius: 16px;
            background: linear-gradient(135deg, #2563eb, #0284c7);
            box-shadow: 0 8px 22px rgba(29, 78, 216, .28);
            transition: transform .25s cubic-bezier(.16, 1, .3, 1), box-shadow .25s ease, filter .25s ease;
        }

        .crest-mark svg {
            width: 31px;
            transition: transform .25s cubic-bezier(.16, 1, .3, 1);
        }

        .crest-mark:hover,
        .crest-mark:focus-visible {
            transform: translateY(-3px) rotate(-3deg) scale(1.04);
            box-shadow: 0 12px 28px rgba(29, 78, 216, .36);
            filter: saturate(1.1);
            outline: none;
        }

        .crest-mark:hover svg,
        .crest-mark:focus-visible svg {
            transform: rotate(4deg) scale(1.05);
        }

        html[data-theme="dark"] .crest-mark {
            background: linear-gradient(135deg, #1d4ed8, #0369a1);
            border-color: #60a5fa;
            box-shadow: 0 8px 24px rgba(37, 99, 235, .38);
        }

        .crest-text {
            font-family: var(--font-display);
            font-weight: 800;
            color: var(--text-heading);
            font-size: 17px;
            letter-spacing: -.01em;
        }

        .crest-tag {
            font-size: 10px;
            color: var(--accent);
            letter-spacing: .13em;
            font-weight: 700;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .brand-body {
            max-width: 520px;
        }

        .brand-eyebrow {
            font-size: 11px;
            letter-spacing: .15em;
            text-transform: uppercase;
            color: var(--accent);
            margin-bottom: 18px;
            font-weight: 700;
        }

        .brand-heading {
            font-family: var(--font-display);
            font-size: clamp(38px, 5vw, 62px);
            line-height: 1.03;
            letter-spacing: -.04em;
            margin: 0 0 20px;
            color: var(--text-heading);
            font-weight: 800;
        }

        .brand-heading span {
            color: var(--accent);
        }

        .brand-desc {
            color: var(--text-muted);
            line-height: 1.7;
            max-width: 480px;
            font-size: 14.5px;
        }

        .brand-features {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-top: 30px;
            padding: 0;
            list-style: none;
        }

        .brand-features li {
            display: flex;
            align-items: center;
            gap: 11px;
            color: var(--text-body);
            font-size: 14px;
            font-weight: 500;
        }

        .feat-icon {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border: 1px solid var(--feat-border);
            border-radius: 11px;
            color: var(--feat-color);
            background: var(--feat-bg);
            flex-shrink: 0;
        }

        .feat-icon svg {
            width: 18px;
        }

        .brand-foot-btn {
            width: fit-content;
            max-width: 100%;
            padding: 10px 14px;
            border: 1px solid var(--accent);
            border-radius: 999px;
            color: var(--text-body);
            background: var(--accent-soft);
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            flex-wrap: wrap;
            text-decoration: none;
            box-shadow: 0 4px 14px rgba(29, 78, 216, .12);
            transition: background .2s ease, border-color .2s ease, color .2s ease, transform .2s var(--ease), box-shadow .2s ease;
        }

        .brand-foot-btn:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(29, 78, 216, .24);
        }

        .dot {
            display: inline-block;
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: var(--accent);
            margin-right: 7px;
            box-shadow: 0 0 6px var(--accent);
        }

        .login-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 28px;
            background: transparent;
        }

        .login-wrap {
            width: 100%;
            max-width: 430px;
        }

        .top-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }

        .back-link {
            display: inline-flex;
            gap: 7px;
            align-items: center;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: color .2s ease, background .2s ease, border-color .2s ease;
            padding: 8px 12px;
            border-radius: 10px;
            border: 1px solid transparent;
        }

        .back-link:hover {
            color: var(--accent);
            background: var(--accent-soft);
            border-color: var(--border);
        }

        .back-link svg {
            width: 17px;
        }

        .theme-toggle {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            height: 40px;
            padding: 0 14px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: var(--surface);
            color: var(--text-body);
            font-family: var(--font-body);
            font-size: .8125rem;
            font-weight: 600;
            cursor: pointer;
            transition: .2s var(--ease);
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .04);
        }

        .theme-toggle:hover {
            color: var(--accent);
            border-color: var(--accent);
            transform: translateY(-1px);
        }

        .theme-toggle svg {
            width: 16px;
            height: 16px;
            color: var(--accent);
            flex-shrink: 0;
        }

        .theme-toggle .sun-icon {
            display: none;
        }

        html[data-theme="dark"] .theme-toggle .moon-icon {
            display: none;
        }

        html[data-theme="dark"] .theme-toggle .sun-icon {
            display: block;
        }

        .login-card {
            position: relative;
            padding: 44px 40px;
            border: 1px solid var(--card-border);
            border-radius: 28px;
            background: var(--card-bg);
            box-shadow: var(--card-shadow);
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            animation: riseIn .5s var(--ease) both;
            animation-delay: .05s;
        }

        .login-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto;
            height: 2px;
            background: var(--card-top-bar);
            border-radius: 28px 28px 0 0;
        }

        .login-title,
        .login-subtitle,
        .alert,
        .field-group,
        .btn-submit,
        .login-footer-note {
            animation: riseIn .45s ease both;
        }

        .login-title {
            font-family: var(--font-display);
            font-size: 30px;
            font-weight: 800;
            text-align: center;
            margin: 0 0 6px;
            color: var(--text-heading);
            letter-spacing: -.02em;
            animation-delay: .1s;
        }

        .login-subtitle {
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
            margin: 0 0 30px;
            animation-delay: .15s;
        }

        .alert {
            display: flex;
            gap: 10px;
            padding: 13px;
            border-radius: 13px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5;
        }

        .alert svg {
            width: 19px;
            height: 19px;
            flex-shrink: 0;
            margin-top: 1px;
        }

        .alert--error {
            background: var(--alert-error-bg);
            color: var(--alert-error-color);
            border: 1px solid var(--alert-error-border);
        }

        .alert--warning {
            background: var(--alert-warn-bg);
            color: var(--alert-warn-color);
            border: 1px solid var(--alert-warn-border);
        }

        .field-group {
            margin-bottom: 18px;
        }

        .field-group:nth-of-type(1) {
            animation-delay: .2s;
        }

        .field-group:nth-of-type(2) {
            animation-delay: .27s;
        }

        .field-label {
            display: block;
            font-size: 12px;
            font-weight: 700;
            color: var(--text-heading);
            margin-bottom: 8px;
        }

        .field-input {
            width: 100%;
            height: 50px;
            padding: 0 14px;
            border-radius: 13px;
            border: 1.5px solid var(--input-border);
            background: var(--input-bg);
            color: var(--input-color);
            font-family: var(--font-body);
            font-size: 14px;
            transition: border-color .2s ease, box-shadow .2s ease, transform .15s ease;
        }

        .field-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--input-focus-ring);
            transform: translateY(-1px);
        }

        .field-input::placeholder {
            color: var(--input-placeholder);
        }

        .password-field {
            position: relative;
        }

        .password-field .field-input {
            padding-right: 48px;
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            border-radius: 8px;
            transition: color .2s ease, background .2s ease;
        }

        .password-toggle:hover {
            color: var(--accent);
            background: var(--accent-soft);
        }

        .password-toggle svg {
            width: 19px;
        }

        .btn-submit {
            position: relative;
            overflow: hidden;
            width: 100%;
            height: 52px;
            margin-top: 6px;
            border: 1px solid var(--accent);
            border-radius: 14px;
            background: var(--btn-bg);
            color: #fff;
            font-family: var(--font-body);
            font-size: 14.5px;
            font-weight: 800;
            cursor: pointer;
            transition: .3s var(--ease);
            box-shadow: var(--btn-shadow);
            animation-delay: .34s;
        }

        .btn-submit:hover {
            background: var(--btn-bg-hover);
            transform: translateY(-3px);
            box-shadow: var(--btn-shadow-hover);
        }

        .reset-password-link {
            display: block;
            margin: 16px 0 2px;
            color: var(--accent);
            font-size: 13px;
            font-weight: 700;
            text-align: center;
            text-decoration: none;
        }

        .reset-password-link:hover {
            text-decoration: underline;
        }

        .login-footer-note {
            text-align: center;
            color: var(--text-muted);
            font-size: 11px;
            margin-top: 22px;
            animation-delay: .4s;
        }

        @media (max-width: 900px) {
            .login-shell {
                grid-template-columns: 1fr;
            }

            .brand-panel {
                min-height: 430px;
                padding: 34px;
            }

            .brand-heading {
                font-size: 40px;
            }

            .brand-features {
                flex-direction: row;
                flex-wrap: wrap;
            }

            .brand-foot-btn {
                display: inline-flex;
            }
        }

        @media (max-width: 600px) {
            .brand-panel {
                min-height: auto;
                padding: 28px 22px 34px;
            }

            .brand-heading {
                font-size: 31px;
            }

            .brand-features {
                display: none;
            }

            .login-panel {
                padding: 18px;
            }

            .login-card {
                padding: 36px 24px;
                border-radius: 23px;
            }

            .theme-toggle span {
                display: none;
            }

            .theme-toggle {
                width: 40px;
                padding: 0;
                justify-content: center;
            }

            .brand-foot-btn {
                width: 100%;
                justify-content: center;
                text-align: center;
                line-height: 1.4;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation: none !important;
                transition: none !important;
            }
        }
    </style>
    <link rel="stylesheet" href="../theme-toggle.css?v=4">
    <script src="../theme-sync.js?v=3" defer></script>
</head>

<body>

    <div class="login-shell">

        <!-- ── LEFT — BRAND / DESCRIPTION ── -->
        <aside class="brand-panel">
            <span class="brand-orb brand-orb--1"></span>
            <span class="brand-orb brand-orb--2"></span>
            <span class="brand-orb brand-orb--3"></span>
            <div class="brand-crest">
                <div class="crest-mark" tabindex="0" role="img" aria-label="Grocery Store shopping bag logo">
                    <svg viewBox="0 0 48 48" aria-hidden="true">
                        <circle cx="24" cy="16" r="7" fill="#4d8dff" />
                        <path d="M10 39c0-8 6-13 14-13s14 5 14 13" fill="none" stroke="#FFFFFF" stroke-width="3"
                            stroke-linecap="round" />
                    </svg>
                </div>
                <div>
                    <div class="crest-text">Grocery Store</div>
                    <div class="crest-tag">HR &amp; Payroll</div>
                </div>
            </div>

            <div class="brand-body">
                <div class="brand-eyebrow">Admin, HR &amp; Employee Access</div>
                <h1 class="brand-heading">Your team,<br>your payroll,<br>your <span>whole company</span>.</h1>
                <p class="brand-desc">This login is for Admins, HR Staff, Supervisors, and Employees — manage records,
                    run payroll, and track attendance and leave, all from the same account.</p>

                <ul class="brand-features">
                    <li><span class="feat-icon"><svg viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="7" r="3.2" stroke="currentColor" stroke-width="1.6" />
                                <path d="M3.5 17c1-3.5 4-5.2 6.5-5.2s5.5 1.7 6.5 5.2" stroke="currentColor"
                                    stroke-width="1.6" stroke-linecap="round" />
                            </svg></span> Employee records &amp; profiles</li>
                    <li><span class="feat-icon"><svg viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.6" />
                                <path
                                    d="M10 6v8M12.3 8.1c0-1-1-1.7-2.3-1.7s-2.3.7-2.3 1.7c0 2.2 4.6 1.1 4.6 3.2 0 1-1 1.7-2.3 1.7s-2.3-.7-2.3-1.7"
                                    stroke="currentColor" stroke-width="1.4" stroke-linecap="round" />
                            </svg></span> Payroll &amp; payslips</li>
                    <li><span class="feat-icon"><svg viewBox="0 0 20 20" fill="none">
                                <path d="M3 6.5l7-3.5 7 3.5v7L10 17l-7-3.5v-7z" stroke="currentColor" stroke-width="1.5"
                                    stroke-linejoin="round" />
                                <path d="M3 6.5l7 3.5 7-3.5M10 10v7" stroke="currentColor" stroke-width="1.5"
                                    stroke-linejoin="round" />
                            </svg></span> Attendance &amp; leave</li>
                </ul>
            </div>

            <a href="../login.php" class="brand-foot-btn"><span class="dot"></span> Cashier or Inventory Staff? Use the
                POS Login instead.</a>
        </aside>

        <!-- ── RIGHT — LOGIN FORM ── -->
        <main class="login-panel">
            <div class="login-wrap">

                <div class="top-row">
                    <a href="index.php" class="back-link">
                        <svg viewBox="0 0 20 20" fill="none">
                            <path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        Back
                    </a>

                    <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode"
                        aria-pressed="false">
                        <svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <path d="M20.4 15.4A8.5 8.5 0 0 1 8.6 3.6 9 9 0 1 0 20.4 15.4Z" stroke-linecap="round"
                                stroke-linejoin="round" />
                        </svg>
                        <svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                            <circle cx="12" cy="12" r="4" />
                            <path
                                d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"
                                stroke-linecap="round" />
                        </svg>
                        <span>Dark</span>
                    </button>
                </div>

                <div class="login-card">

                    <h1 class="login-title">Welcome back</h1>
                    <p class="login-subtitle">Log in to continue</p>

                    <?php if (!empty($expired_message)): ?>
                        <div class="alert alert--warning">
                            <svg viewBox="0 0 20 20" fill="none">
                                <path d="M10 3l8 14H2L10 3z" stroke="currentColor" stroke-width="1.6"
                                    stroke-linejoin="round" />
                                <path d="M10 8v4M10 14.5v.01" stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round" />
                            </svg>
                            <span><?= clean($expired_message) ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert--error">
                            <svg viewBox="0 0 20 20" fill="none">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                                <path d="M10 6.5v4M10 13.5v.01" stroke="currentColor" stroke-width="1.6"
                                    stroke-linecap="round" />
                            </svg>
                            <span><?= clean($error) ?></span>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php" autocomplete="off">
                        <?php csrfField(); ?>

                        <div class="field-group">
                            <label class="field-label" for="username">Username</label>
                            <input type="text" id="username" name="username" class="field-input"
                                placeholder="Enter username."
                                value="<?= isset($_POST['username']) ? clean($_POST['username']) : '' ?>"
                                autocomplete="username" required>
                        </div>

                        <div class="field-group">
                            <label class="field-label" for="password">Password</label>
                            <div class="password-field">
                                <input type="password" id="password" name="password" class="field-input"
                                    placeholder="Enter password" autocomplete="current-password" required>
                                <button type="button" class="password-toggle" id="togglePassword"
                                    aria-label="Show password">
                                    <svg id="eyeIcon" viewBox="0 0 20 20" fill="none">
                                        <path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z"
                                            stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                        <circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <button type="submit" class="btn-submit">Login</button>
                    </form>
                    <a class="reset-password-link" href="../password_reset_request.php?system=hrms">Forgot your
                        password?</a>

                    <p class="login-footer-note">HR Management &amp; Payroll System</p>
                </div>
            </div>
        </main>

    </div>

    <script>
        // Theme toggle
        (function () {
            var toggle = document.getElementById('themeToggle');
            var label = toggle ? toggle.querySelector('span') : null;
            if (!toggle) return;
            function updateControl() {
                var isDark = document.documentElement.dataset.theme === 'dark';
                if (label) label.textContent = isDark ? 'Light' : 'Dark';
                toggle.setAttribute('aria-label', isDark ? 'Switch to light mode' : 'Switch to dark mode');
                toggle.setAttribute('aria-pressed', String(isDark));
            }
            updateControl();
            toggle.addEventListener('click', function () {
                var next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
                document.documentElement.dataset.theme = next;
                try { localStorage.setItem('manage-grocery-theme', next); } catch (e) { }
                updateControl();
            });
        }());

        // Password eye toggle
        const toggleBtn = document.getElementById('togglePassword');
        const passwordInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eyeIcon');

        // Ripple burst on submit button
        var submitBtn = document.querySelector('.btn-submit');
        if (submitBtn) {
            submitBtn.addEventListener('click', function (e) {
                var rect = submitBtn.getBoundingClientRect();
                var ripple = document.createElement('span');
                var size = Math.max(rect.width, rect.height) * 1.8;
                ripple.style.cssText =
                    'position:absolute;border-radius:50%;pointer-events:none;' +
                    'width:' + size + 'px;height:' + size + 'px;' +
                    'left:' + (e.clientX - rect.left - size / 2) + 'px;' +
                    'top:' + (e.clientY - rect.top - size / 2) + 'px;' +
                    'background:radial-gradient(circle, rgba(255,255,255,.5) 0%, rgba(255,255,255,0) 70%);' +
                    'transform:scale(0);opacity:1;transition:transform .6s ease, opacity .6s ease;';
                submitBtn.appendChild(ripple);
                requestAnimationFrame(function () {
                    ripple.style.transform = 'scale(1)';
                    ripple.style.opacity = '0';
                });
                setTimeout(function () { ripple.remove(); }, 650);
            });
        }

        toggleBtn.addEventListener('click', function () {
            const isHidden = passwordInput.type === 'password';
            passwordInput.type = isHidden ? 'text' : 'password';

            eyeIcon.innerHTML = isHidden
                ? '<path d="M2 2l16 16" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>'
                : '<path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/>';
        });
    </script>
</body>

</html>