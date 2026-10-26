<?php

require_once 'includes/config.php';

if (!empty($_SESSION['user_id'])) {
    redirectToRoleHome();
}

$finance_login = false; // Kept only so old ?role=finance URLs remain valid.
$expired_message = "";
if (isset($_GET['expired'])) {
    $expired_message = "Your session has expired due to inactivity. Please log in again.";
}
if (isset($_GET['deactivated'])) {
    $expired_message = "This account has been deactivated. Please contact your Admin or HR.";
}
if (isset($_GET['mail_failed'])) {
    $expired_message = $_SESSION['tfa_mail_error'] ?? "The verification email could not be sent. Please try again or contact your Admin.";
    unset($_SESSION['tfa_mail_error']);
}
if (isset($_GET['mfa_locked'])) {
    $expired_message = $_SESSION['tfa_mail_error'] ?? 'Too many incorrect verification codes. Please wait 15 minutes before trying again.';
    unset($_SESSION['tfa_mail_error']);
}

$error = "";
$show_lockout_popup = false;

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
        $stmt = $pdo->prepare("SELECT * FROM users WHERE BINARY username = ? LIMIT 1");
        $stmt->execute([$username]);
        $posUser = $stmt->fetch();
        $hrmsUser = null;

        // Read both stores when a username is mirrored. POS roles use the
        // POS account as their canonical login record; Admin/HR roles use
        // HRMS so its lockout, status, password, and Gmail 2FA rules apply.
        if ($hrmsPdo) {
            $hrmsStmt = $hrmsPdo->prepare("
                SELECT u.*, e.status AS employee_status, e.position AS employee_position,
                       e.suspension_start_date, e.suspension_end_date, e.suspension_reason
                FROM users u
                LEFT JOIN employees e ON u.employee_id = e.employee_id
                WHERE BINARY u.username = ? LIMIT 1
            ");
            $hrmsStmt->execute([$username]);
            $hrmsUser = $hrmsStmt->fetch() ?: null;
        }
        $posRoles = ['cashier', 'inventory_staff', 'finance'];
        if ($posUser && in_array($posUser['role'], $posRoles, true)) {
            $user = $posUser;
            $authSource = 'pos';
        } elseif ($hrmsUser) {
            $user = $hrmsUser;
            $authSource = 'hrms';
        } else {
            $user = $posUser;
            $authSource = 'pos';
        }

        if ($authSource === 'hrms') {
            // HRMS uses its own temporary lockout columns; do not call the
            // POS lockout helpers against the other schema.
            $hrmsLocked = $hrmsPdo->prepare("SELECT locked_until FROM users WHERE id = ?");
            $hrmsLocked->execute([$user['id']]);
            $lockedUntil = $hrmsLocked->fetchColumn();
            if ($lockedUntil && strtotime($lockedUntil) > time()) {
                $error = "Too many failed login attempts. Please try again in a few minutes.";
            } elseif (password_verify($password, $user['password'])) {
                $hrmsPdo->prepare("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?")
                    ->execute([$user['id']]);
                if (in_array($user['employee_status'] ?? '', ['resigned', 'terminated'], true)) {
                    $error = "This account can no longer be used because the employee has been "
                        . $user['employee_status'] . ".";
                } elseif (($susp = getEmployeeSuspensionStatus($user['suspension_start_date'], $user['suspension_end_date']))['active']) {
                    $error = "This account is currently suspended. Please contact Admin/HR.";
                } elseif ((int) $user['is_active'] === 0) {
                    $error = "This account has been deactivated. Please contact Admin/HR.";
                } elseif (
                    $user['role'] === 'employee'
                    && in_array($user['employee_position'] ?? '', ['Cashier', 'Inventory Staff', 'Accountant'], true)
                ) {
                    $error = "This account is managed through its POS staff profile. Please contact Admin/HR.";
                } elseif (!in_array($user['role'], ['admin', 'hr', 'hr_manager', 'supervisor', 'accountant', 'employee'], true)) {
                    $error = "This account is not permitted to use staff login.";
                } else {
                    require_once 'HRMS/includes/twofactor.php';
                    $hrmsDashboard = [
                        'admin' => 'admin_dashboard.php',
                        'hr' => 'hr_dashboard.php',
                        'hr_manager' => 'hr_manager_dashboard.php',
                        'supervisor' => 'supervisor_dashboard.php',
                        'accountant' => 'payroll/accountant.php',
                        'employee' => 'employee_dashboard.php',
                    ];
                    // The 2FA pages are entered through the HRMS folder,
                    // but their final redirect is resolved from that folder.
                    // Store only the dashboard filename here; including
                    // "HRMS/" would produce HRMS/HRMS/<dashboard>.
                    $destination = $hrmsDashboard[$user['role']] ?? 'hr_dashboard.php';
                    if (twofaRequired($user)) {
                        twofaStartLogin($hrmsPdo, $user, $destination, 'HRMS/');
                    }
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['employee_id'] = $user['employee_id'];
                    $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
                    $_SESSION['last_activity'] = time();
                    redirect(systemUrl('HRMS/' . $destination));
                }
            } else {
                recordFailedLoginIp($hrmsPdo, $clientIp);
                $hrmsAttempts = $hrmsPdo->prepare("SELECT failed_attempts FROM users WHERE id = ?");
                $hrmsAttempts->execute([$user['id']]);
                $attempts = (int) $hrmsAttempts->fetchColumn() + 1;
                $hrmsLock = $attempts >= MAX_LOGIN_ATTEMPTS
                    ? date('Y-m-d H:i:s', time() + (15 * 60))
                    : null;
                $hrmsPdo->prepare("UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?")
                    ->execute([$attempts, $hrmsLock, $user['id']]);
                $error = "Incorrect username or password. Please try again.";
            }
        } elseif ($user && $user['role'] !== 'admin' && !empty($user['is_locked'])) {
            // Naka-lock na ang account (5 failed attempts na) — kailangan
            // munang i-reset ng Admin ang password bago makapag-login ulit,
            // kahit tama na ang password na ilagay dito.
            $error = "This account has been locked after " . MAX_LOGIN_ATTEMPTS . " failed login attempts. Please see your Administrator to have your password reset.";
            $show_lockout_popup = true;

        } elseif ($user && password_verify($password, $user['password'])) {

            // This is the single staff login. The account's stored role
            // determines the correct workspace after authentication.
            if (isset($user['is_active']) && (int) $user['is_active'] === 0) {
                $error = "This account has been deactivated. Please contact your Admin or HR.";
            } elseif (!empty($user['employee_id']) && !employeeStillEligibleForPosLogin($hrmsPdo, $user['employee_id'])) {
                $error = "This account is no longer set up as a Cashier or Inventory Staff in HRMS, so it can no longer log in here. Please contact your Admin or HR.";
            } else {

                // ----- TWO-FACTOR AUTHENTICATION GATE (all POS roles) -----
                // Runs BEFORE any of the normal "logged in" session keys
                // below are set. twofaStartLogin() redirects to
                // setup_2fa.php (first time) or verify_2fa.php (send OTP,
                // ask for code) and exits — so a Finance account never
                // reaches the real session below without completing this.
                require_once 'includes/twofactor.php';
                if (twofaRequired($user)) {
                    $destination = ($user['role'] === 'admin') ? 'dashboard.php'
                        : (($user['role'] === 'inventory_staff') ? 'inventory_dashboard.php'
                            : (($user['role'] === 'finance') ? 'finance_dashboard.php' : 'pos.php'));
                    clearFailedLogins($pdo, $user['id']);
                    twofaStartLogin($pdo, $user, $destination);
                    // twofaStartLogin() always redirects + exits.
                }

                clearFailedLogins($pdo, $user['id']);
                session_regenerate_id(true);

                $_SESSION['pos_user_id'] = (int) $user['id'];
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['full_name'] = $user['full_name'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['employee_id'] = $user['employee_id'] ?? null;
                $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
                $_SESSION['last_activity'] = time();

                if ($user['role'] === 'admin') {
                    redirect(systemUrl('dashboard.php'));
                } elseif ($user['role'] === 'inventory_staff') {
                    redirect(systemUrl('inventory_dashboard.php'));
                } elseif ($user['role'] === 'finance') {
                    redirect(systemUrl('finance_dashboard.php'));
                } else {
                    redirect(systemUrl('pos.php'));
                }

            }
        } else {

            // Recorded regardless of whether $user exists, so cycling
            // through nonexistent usernames from one IP still counts
            // toward the IP throttle above (account lockout alone can't
            // catch that, since it needs a matching user row).
            recordFailedLoginIp($pdo, $clientIp);

            if ($authSource === 'hrms' && $user) {
                recordFailedLogin($hrmsPdo, $user['id']);
            } elseif ($user && $user['role'] !== 'admin') {
                $attempts = recordFailedLogin($pdo, $user['id']);
                $remaining = MAX_LOGIN_ATTEMPTS - $attempts;

                if ($remaining <= 0) {
                    $error = "This account has been locked after " . MAX_LOGIN_ATTEMPTS . " failed login attempts. Please see your Administrator to have your password reset.";
                    $show_lockout_popup = true;
                } else {
                    $error = "Incorrect username or password. Please try again. ($remaining attempt" . ($remaining === 1 ? '' : 's') . " remaining before this account is locked.)";
                }
            } else {
                $error = "Incorrect username or password. Please try again.";
            }
        }
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="tl">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log In — HCI GroceryOne</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

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
            --text-muted: #475569;
            --border: #e2e8f0;
            --border-strong: #cbd5e1;
            --accent: #2563eb;
            --accent-hover: #1d4ed8;
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
            --bg: #0b1120;
            --surface: #111827;
            --surface-soft: #1e293b;
            --text-heading: #f1f5f9;
            --text-body: #e2e8f0;
            --text-muted: #aab8ca;
            --border: #334155;
            --border-strong: #475569;
            --accent: #2563eb;
            --accent-hover: #3b82f6;
            --accent-soft: rgba(37, 99, 235, .12);
            --accent-glow: rgba(59, 130, 246, .07);
            --grid-line: rgba(166, 172, 181, .055);
            --orb-1: radial-gradient(circle at 30% 30%, rgba(96, 165, 250, .48), rgba(47, 128, 237, .18) 70%);
            --orb-2: radial-gradient(circle at 30% 30%, rgba(76, 141, 255, .4), rgba(47, 128, 237, .14) 70%);
            --orb-3: radial-gradient(circle at 30% 30%, rgba(96, 165, 250, .42), rgba(47, 128, 237, .14) 70%);
            --card-bg: #111827;
            --card-border: #334155;
            --card-shadow: 0 30px 80px rgba(0, 0, 0, .28);
            --card-top-bar: linear-gradient(90deg, transparent, #2563eb, #3b82f6, transparent);
            --input-bg: #0b1120;
            --input-border: #475569;
            --input-color: #f1f5f9;
            --input-placeholder: #aab8ca;
            --input-focus-ring: rgba(96, 165, 250, .18);
            --btn-bg: #2563eb;
            --btn-bg-hover: #3b82f6;
            --btn-shadow: 0 12px 32px rgba(37, 99, 235, .18);
            --btn-shadow-hover: 0 16px 38px rgba(37, 99, 235, .26);
            --alert-error-bg: #3a1524;
            --alert-error-color: #ffc4d4;
            --alert-error-border: rgba(255, 114, 153, .33);
            --alert-warn-bg: #3a2e0f;
            --alert-warn-color: #f5d9a8;
            --alert-warn-border: rgba(217, 119, 6, .33);
            --crest-bg: linear-gradient(135deg, rgba(59, 130, 246, .12), rgba(96, 165, 250, .08));
            --crest-border: #2a2e35;
            --feat-bg: rgba(255, 255, 255, .035);
            --feat-border: #2a2e35;
            --feat-color: #7ab2ff;
            --foot-btn-color: #a6acb5;
            --foot-btn-hover-bg: rgba(59, 130, 246, .12);
            --foot-btn-hover-color: #ecedee;
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

        /* ============================================================
   SHELL + GRID BACKGROUND
   ============================================================ */
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

        /* ============================================================
   BRAND PANEL (LEFT)
   ============================================================ */
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
            margin: auto 0;
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

        /* ============================================================
   LOGIN PANEL (RIGHT)
   ============================================================ */
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
            transition: color .2s ease;
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

        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none;
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

        .btn-submit::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(115deg, transparent 20%, rgba(255, 255, 255, .3) 50%, transparent 80%);
            transform: translateX(-130%) skewX(-15deg);
            transition: transform .6s ease;
            pointer-events: none;
        }

        .btn-submit:hover::before {
            transform: translateX(130%) skewX(-15deg);
        }

        .password-toggle {
            transition: color .2s ease, background .2s ease, transform .2s var(--ease);
        }

        .password-toggle:hover {
            transform: translateY(-50%);
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

        @media (max-width: 420px) {
            .brand-panel {
                padding: 24px 18px 30px;
            }

            .login-panel {
                padding: 14px;
            }

            .login-card {
                padding: 32px 20px;
            }

            .login-subtitle {
                margin-bottom: 24px;
            }

            .field-group {
                margin-bottom: 16px;
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
    <link rel="stylesheet" href="theme-toggle.css?v=4">
    <link rel="stylesheet" href="assets/css/page-controls.css?v=4">
    <script src="theme-sync.js?v=4" defer></script>
</head>

<body class="login-page">

    <div class="login-shell">

        <!-- ── LEFT — BRAND / DESCRIPTION ── -->
        <aside class="brand-panel">
            <span class="brand-orb brand-orb--1"></span>
            <span class="brand-orb brand-orb--2"></span>
            <span class="brand-orb brand-orb--3"></span>
            <div class="brand-crest">
                <div class="crest-mark" tabindex="0" role="img" aria-label="Grocery Store shopping bag logo">
                    <svg viewBox="0 0 48 48" aria-hidden="true">
                        <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#4d8dff" />
                        <path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#FFFFFF" fill-opacity=".9" />
                        <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#FFFFFF" stroke-width="2.5"
                            stroke-linecap="round" />
                    </svg>
                </div>
                <div>
                    <div class="crest-text">Grocery Store</div>
                    <div class="crest-tag">Point of Sale</div>
                </div>
            </div>

            <div class="brand-body">
                <div class="brand-eyebrow">Secure Staff Access</div>
                <h1 class="brand-heading">One login for<br>every <span>team</span>,<br>every workspace.</h1>
                <p class="brand-desc">Sign in once with your staff account. Your role determines whether you are routed
                    to POS, Finance, HR, Payroll, or your employee workspace.</p>

                <ul class="brand-features">
                    <li><span class="feat-icon">$</span> POS, inventory &amp; finance workspaces</li>
                    <li><span class="feat-icon">+</span> HR, payroll &amp; employee services</li>
                    <li><span class="feat-icon">=</span> Role-based access with 2FA</li>
                </ul>
            </div>

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
                        <span>Dark mode</span>
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

                    <form method="POST" action="<?= htmlspecialchars(systemUrl('login'), ENT_QUOTES, 'UTF-8') ?>"
                        autocomplete="off">
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
                    <a class="reset-password-link" href="password_reset_request.php">Forgot your password?</a>

                    <p class="login-footer-note">Grocery Store &amp; Point of Sale</p>
                </div>
            </div>
        </main>

    </div>

    <script>
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

        <?php if ($show_lockout_popup): ?>
            Swal.fire({
                icon: 'error',
                title: 'Account Locked',
                text: 'This account has been locked after <?= MAX_LOGIN_ATTEMPTS ?> failed login attempts. Please see your Administrator to have your password reset.',
                confirmButtonText: 'Okay',
                confirmButtonColor: '#1d4ed8'
            });
        <?php endif; ?>
    </script>
</body>

</html>