<?php
// =====================================================================
// verify_2fa.php (HRMS side) — second step of login for Admin.
// Mirrors the POS side's verify_2fa.php; see that file for the fuller
// explanation of the flow. The one HRMS-specific piece is
// finalizeHrmsLogin(), which also mirrors the login into the POS
// `users` table exactly like HRMS/login.php normally does directly,
// since Admin needs both sessions to work.
// =====================================================================

require_once 'includes/config.php';
require_once 'includes/twofactor.php';

if (!empty($_SESSION['user_id'])) {
    if ($_SESSION['role'] == 'employee') {
        redirect('employee_dashboard.php');
    } else {
        redirect(dashboardUrlForRole($_SESSION['role']));
    }
}

if (empty($_SESSION['tfa_pending']) || $_SESSION['tfa_pending']['stage'] !== 'otp') {
    header('Location: login.php');
    exit;
}

$pending = $_SESSION['tfa_pending'];
$error = '';
$info = '';

// Look up the destination email on file so the page can tell the user
// exactly where to check — not just "the email on file".
$stmt = $pdo->prepare("SELECT two_factor_email FROM users WHERE id = ?");
$stmt->execute([$pending['user_id']]);
$tfaEmailMasked = twofaMaskEmail($stmt->fetchColumn());

if (time() - $pending['started_at'] > 600) {
    unset($_SESSION['tfa_pending']);
    twofaClearPending();
    header('Location: login.php?expired=1');
    exit;
}

function finalizeHrmsLogin($pdo, $posPdo, $pending)
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$pending['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        header('Location: login.php');
        exit;
    }

    clearFailedLogins($pdo, $user['id']);
    session_regenerate_id(true);

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['employee_id'] = $user['employee_id'];
    $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
    $_SESSION['last_activity'] = time();

    // Same Admin -> POS `users` mirror sync HRMS/login.php normally
    // does directly — kept here too since 2FA now sits in front of
    // that step for Admin logins.
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

    redirect($pending['redirect'] ?: dashboardUrlForRole($user['role']));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    if (isset($_POST['resend'])) {
        if (!empty($_SESSION['tfa_otp_resend_after']) && time() < $_SESSION['tfa_otp_resend_after']) {
            $error = 'Please wait a few seconds before requesting another code.';
        } else {
            $stmt = $pdo->prepare("SELECT two_factor_email, full_name FROM users WHERE id = ?");
            $stmt->execute([$pending['user_id']]);
            $u = $stmt->fetch();
            twofaSendOtp($pdo, $pending['user_id'], $u['two_factor_email'], $u['full_name']);
            $info = 'A new code has been sent to ' . clean(twofaMaskEmail($u['two_factor_email'])) . '.';
        }
    } elseif (isset($_POST['use_backup'])) {
        $backupCode = trim($_POST['backup_code'] ?? '');
        if ($backupCode !== '' && twofaCheckAndConsumeBackupCode($pdo, $pending['user_id'], $backupCode)) {
            twofaClearPending();
            unset($_SESSION['tfa_pending']);
            finalizeHrmsLogin($pdo, $posPdo, $pending);
        } else {
            $error = 'That backup code is invalid or has already been used.';
        }
    } else {
        $code = trim($_POST['otp_code'] ?? '');
        $result = twofaCheckOtp($pdo, (int) $pending['user_id'], $code);

        if ($result['ok']) {
            twofaClearPending();
            unset($_SESSION['tfa_pending']);
            finalizeHrmsLogin($pdo, $posPdo, $pending);
        } elseif ($result['reason'] === 'too_many_attempts') {
            twofaClearPending();
            unset($_SESSION['tfa_pending']);
            $error = 'Too many incorrect attempts. Please log in again.';
        } elseif ($result['reason'] === 'expired') {
            $error = 'This code has expired. Request a new one below.';
        } else {
            $error = 'Incorrect code. Please try again.';
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
    <title>Verify It's You — HR &amp; Payroll</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">
    <link rel="stylesheet"
        href="../assets/css/theme.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
    <style>
        :root {
            --bg: #f8fafc;
            --panel: #ffffff;
            --text: #0f172a;
            --muted: #475569;
            --line: #e2e8f0;
            --accent: #2563eb;
            --accent-soft: #f1f5f9;
            --grid: rgba(226, 232, 240, .35)
        }

        html[data-theme="dark"] {
            --bg: #0b1120;
            --panel: #111827;
            --text: #f1f5f9;
            --muted: #aab8ca;
            --line: #334155;
            --accent: #2563eb;
            --accent-soft: #1e293b;
            --grid: rgba(51, 65, 85, .35)
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            min-height: 100vh;
            background-color: var(--bg);
            background-image: linear-gradient(var(--grid) 1px, transparent 1px), linear-gradient(90deg, var(--grid) 1px, transparent 1px);
            background-size: 54px 54px;
            color: var(--text);
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 72px 20px 20px;
            transition: background-color .25s ease, color .25s ease
        }

        .theme-toggle {
            position: absolute;
            top: 30px;
            right: 32px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 9px 13px;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--panel);
            color: var(--text);
            font: 600 12px 'Inter', sans-serif;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(15, 23, 42, .08);
            transition: .2s ease
        }

        .theme-toggle:hover {
            border-color: var(--accent);
            color: var(--accent);
            transform: translateY(-1px)
        }

        .theme-toggle svg {
            width: 16px;
            height: 16px
        }

        .theme-toggle .sun {
            display: none
        }

        html[data-theme="dark"] .theme-toggle .sun {
            display: block
        }

        html[data-theme="dark"] .theme-toggle .moon {
            display: none
        }

        .card {
            position: relative;
            width: 100%;
            max-width: 430px;
            padding: 30px 40px 40px;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: var(--panel);
            box-shadow: 0 24px 60px rgba(15, 23, 42, .12)
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 30px;
            padding-right: 116px
        }

        .brand-mark {
            width: 50px;
            height: 50px;
            display: grid;
            place-items: center;
            border: 1px solid #93c5fd;
            border-radius: 15px;
            background: linear-gradient(135deg, #2563eb, #0284c7);
            box-shadow: 0 7px 18px rgba(29, 78, 216, .28);
            transition: transform .25s cubic-bezier(.16, 1, .3, 1), box-shadow .25s ease, filter .25s ease
        }

        .brand-mark svg {
            width: 29px;
            transition: transform .25s cubic-bezier(.16, 1, .3, 1)
        }

        .brand-mark:hover,
        .brand-mark:focus-visible {
            transform: translateY(-3px) rotate(-3deg) scale(1.04);
            box-shadow: 0 12px 28px rgba(29, 78, 216, .36);
            filter: saturate(1.1);
            outline: none
        }

        .brand-mark:hover svg,
        .brand-mark:focus-visible svg {
            transform: rotate(4deg) scale(1.05)
        }

        html[data-theme="dark"] .brand-mark {
            background: linear-gradient(135deg, #1d4ed8, #0369a1);
            border-color: #60a5fa
        }

        .brand-name {
            font: 800 17px 'Sora', sans-serif
        }

        .brand-role {
            margin-top: 3px;
            color: var(--accent);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: .13em;
            text-transform: uppercase
        }

        .icon-badge {
            width: 56px;
            height: 56px;
            border-radius: 16px;
            display: grid;
            place-items: center;
            background: var(--accent-soft);
            border: 1px solid var(--line);
            margin-bottom: 18px
        }

        .icon-badge svg {
            width: 26px;
            color: var(--accent)
        }

        h1 {
            font-family: 'Sora', sans-serif;
            font-size: 24px;
            margin: 0 0 8px
        }

        p.sub {
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.6;
            margin: 0 0 26px
        }

        .alert {
            display: flex;
            gap: 10px;
            padding: 13px;
            border-radius: 13px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5
        }

        .alert svg {
            width: 19px;
            height: 19px;
            flex-shrink: 0;
            margin-top: 1px
        }

        .alert--error {
            background: #fff1f2;
            color: #be123c;
            border: 1px solid #fecdd3
        }

        .alert--info {
            background: var(--accent-soft);
            color: var(--accent);
            border: 1px solid #bfdbfe
        }

        .otp-input {
            width: 100%;
            height: 56px;
            text-align: center;
            font-size: 26px;
            letter-spacing: 12px;
            font-weight: 800;
            border-radius: 14px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--text);
            margin-bottom: 18px
        }

        .otp-input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 4px rgba(29, 78, 216, .1)
        }

        .btn-submit {
            width: 100%;
            height: 52px;
            border: 1px solid var(--accent);
            border-radius: 14px;
            background: linear-gradient(135deg, #1d4ed8, #0284c7);
            color: #fff;
            font-weight: 800;
            cursor: pointer;
            font-size: 14.5px;
            transition: .2s ease
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px rgba(29, 78, 216, .22)
        }

        .link-row {
            display: flex;
            justify-content: space-between;
            margin-top: 18px;
            font-size: 12.5px
        }

        .link-row button {
            background: var(--accent-soft);
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            color: var(--accent);
            cursor: pointer;
            font-size: 12.5px;
            font-weight: 700;
            padding: 8px 11px;
            transition: .2s ease
        }

        .backup-box {
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid var(--line)
        }

        .backup-box input {
            width: 100%;
            height: 44px;
            padding: 0 12px;
            border-radius: 11px;
            border: 1px solid var(--line);
            background: var(--bg);
            color: var(--text);
            margin-bottom: 10px;
            font-size: 14px
        }

        .backup-box .btn-secondary {
            width: 100%;
            height: 42px;
            border-radius: 11px;
            border: 1px solid var(--line);
            background: var(--accent-soft);
            color: var(--accent);
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            transition: .2s ease
        }

        .backup-box .btn-secondary:hover {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(29, 78, 216, .16)
        }

        details summary {
            cursor: pointer;
            color: var(--muted);
            font-size: 12.5px;
            margin-top: 16px
        }

        @media(max-width:560px) {
            body {
                padding: 20px 14px 14px
            }

            .theme-toggle {
                top: 24px;
                right: 22px;
                padding: 8px 10px
            }

            .theme-toggle span {
                display: none
            }

            .card {
                padding: 24px 22px 30px
            }

            .brand {
                padding-right: 82px
            }
        }
    </style>
    <link rel="stylesheet" href="../theme-toggle.css?v=4">
    <script src="../theme-sync.js?v=3" defer></script>
    <style>
        body {
            display: grid;
            min-height: 100vh;
            padding: 28px 20px;
            place-items: center;
            background-color: var(--background);
            background-image:
                linear-gradient(to right, color-mix(in srgb, var(--border) 35%, transparent) 1px, transparent 1px),
                linear-gradient(to bottom, color-mix(in srgb, var(--border) 35%, transparent) 1px, transparent 1px);
            background-size: 42px 42px;
            color: var(--text);
            font-family: "Plus Jakarta Sans", "Segoe UI", Arial, sans-serif;
        }

        .card {
            width: min(460px, 100%);
            padding: 34px;
            border-color: var(--border);
            border-radius: 18px;
            background: var(--surface);
            box-shadow: var(--shadow-surface);
        }

        .brand {
            gap: 12px;
            margin-bottom: 34px;
        }

        .brand-mark {
            width: 46px;
            height: 46px;
            flex: 0 0 auto;
            border-color: color-mix(in srgb, var(--accent) 28%, var(--border));
            border-radius: 13px;
            background: var(--accent);
            box-shadow: none;
        }

        html[data-theme="dark"] .brand-mark {
            border-color: color-mix(in srgb, var(--accent) 45%, var(--border));
            background: var(--accent);
        }

        .brand-mark svg {
            width: 27px;
        }

        .brand-name {
            font: 800 15px "Plus Jakarta Sans", "Segoe UI", Arial, sans-serif;
            letter-spacing: -.03em;
        }

        .brand-role {
            color: var(--accent);
        }

        .icon-badge {
            width: 48px;
            height: 48px;
            border-color: var(--border);
            border-radius: 13px;
            background: var(--surface-muted);
            color: var(--accent);
        }

        .icon-badge svg {
            width: 23px;
        }

        h1 {
            margin: 0 0 9px;
            color: var(--text);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(32px, 7vw, 40px);
            font-weight: 500;
            letter-spacing: -.055em;
            line-height: 1.08;
        }

        p.sub {
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.7;
        }

        p.sub strong {
            color: var(--text);
            font-weight: 700;
        }

        .alert {
            border-color: var(--border);
            border-radius: 12px;
            background: var(--surface-muted);
        }

        .alert--error {
            border-color: #fda4af;
            background: color-mix(in srgb, #e11d48 8%, var(--surface));
            color: #be123c;
        }

        html[data-theme="dark"] .alert--error {
            border-color: color-mix(in srgb, #fda4af 35%, var(--border));
            background: color-mix(in srgb, #e11d48 15%, var(--surface));
            color: #fecdd3;
        }

        .alert--info {
            border-color: color-mix(in srgb, var(--accent) 25%, var(--border));
            background: var(--surface-muted);
            color: var(--accent);
        }

        .otp-input,
        .backup-box input {
            border-color: var(--border);
            border-radius: 10px;
            background: var(--surface-muted);
            color: var(--text);
            font-family: inherit;
        }

        .otp-input {
            height: 58px;
            font-size: 25px;
        }

        .otp-input:focus,
        .backup-box input:focus {
            border-color: var(--accent);
            outline: 3px solid var(--focus-ring);
            outline-offset: 1px;
            box-shadow: none;
        }

        .btn-submit,
        .backup-box .btn-secondary {
            border-color: var(--accent);
            border-radius: 10px;
            background: var(--accent);
            color: var(--accent-foreground);
            font-family: inherit;
            font-weight: 700;
        }

        .btn-submit:hover,
        .backup-box .btn-secondary:hover {
            border-color: var(--accent-hover);
            background: var(--accent-hover);
            box-shadow: var(--shadow-surface);
        }

        .link-row {
            align-items: center;
            gap: 12px;
            font-family: inherit;
        }

        .link-row button {
            border-color: var(--border);
            border-radius: 9px;
            background: var(--surface);
            color: var(--accent);
            font-family: inherit;
        }

        html[data-theme="dark"] .link-row button {
            color: #93c5fd;
        }

        .link-row button:hover,
        .link-row button:focus-visible {
            border-color: var(--accent-hover);
            background: var(--accent-hover);
            color: var(--accent-foreground);
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(29, 78, 216, .2);
        }

        html[data-theme="dark"] .link-row button:hover,
        html[data-theme="dark"] .link-row button:focus-visible {
            border-color: #1d4ed8;
            background: #1d4ed8;
            color: #fff;
        }

        .link-row button:focus-visible {
            outline: 3px solid color-mix(in srgb, var(--accent) 35%, transparent);
            outline-offset: 2px;
        }

        .link-row a {
            color: var(--text-muted) !important;
            text-underline-offset: 3px;
        }

        .link-row a:hover {
            color: var(--accent) !important;
        }

        .backup-box {
            border-color: var(--border);
        }

        .backup-box input {
            border-radius: 9px;
        }

        .backup-box .btn-secondary {
            min-height: 42px;
        }

        .backup-box summary {
            color: var(--text-muted);
        }

        .backup-box summary:hover {
            color: var(--accent);
        }

        @media (max-width: 560px) {
            body {
                padding: 18px 14px;
            }

            .card {
                padding: 26px 22px;
            }

            .brand {
                margin-bottom: 28px;
            }
        }
    </style>
</head>

<body>
    <div class="card">
        <button type="button" class="theme-toggle" id="themeToggle" aria-label="Switch to dark mode"><svg class="moon"
                viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="M16.5 12.8A6.8 6.8 0 0 1 7.2 3.5 6.8 6.8 0 1 0 16.5 12.8z" stroke="currentColor"
                    stroke-width="1.6" stroke-linejoin="round" />
            </svg><svg class="sun" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <circle cx="10" cy="10" r="3.2" stroke="currentColor" stroke-width="1.5" />
                <path
                    d="M10 2.5v2M10 15.5v2M2.5 10h2M15.5 10h2M4.7 4.7l1.4 1.4M13.9 13.9l1.4 1.4M15.3 4.7l-1.4 1.4M6.1 13.9l-1.4 1.4"
                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
            </svg><span>Dark</span></button>
        <div class="brand">
            <div class="brand-mark" tabindex="0" role="img" aria-label="Grocery Store shopping bag logo"><svg
                    viewBox="0 0 48 48" aria-hidden="true">
                    <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#fff" />
                    <path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe" />
                    <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#fff" stroke-width="2.5"
                        stroke-linecap="round" />
                </svg></div>
            <div>
                <div class="brand-name">Grocery Store</div>
                <div class="brand-role">HR &amp; Payroll</div>
            </div>
        </div>
        <div class="icon-badge"><svg viewBox="0 0 20 20" fill="none">
                <rect x="4" y="9" width="12" height="8" rx="1.5" stroke="currentColor" stroke-width="1.6" />
                <path d="M6.5 9V6.5a3.5 3.5 0 017 0V9" stroke="currentColor" stroke-width="1.6" />
            </svg></div>
        <h1>Verify it's you</h1>
        <p class="sub">We sent a 6-digit code to <strong><?= clean($tfaEmailMasked) ?></strong> — the email on file for
            <strong><?= clean($pending['full_name']) ?></strong>
            (<?= clean(ucfirst(str_replace('_', ' ', $pending['role']))) ?>). Enter it below to finish logging in.</p>

        <?php if ($error): ?>
            <div class="alert alert--error">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                    <path d="M10 6.5v4M10 13.5v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <span><?= clean($error) ?></span>
            </div>
        <?php endif; ?>
        <?php if ($info): ?>
            <div class="alert alert--info">
                <svg viewBox="0 0 20 20" fill="none">
                    <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                    <path d="M10 9.5v4.5M10 6.5v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <span><?= clean($info) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="text" name="otp_code" class="otp-input" maxlength="6" inputmode="numeric" pattern="[0-9]*"
                placeholder="000000" autofocus required>
            <button type="submit" class="btn-submit">Verify &amp; Continue</button>
        </form>

        <div class="link-row">
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <button type="submit" name="resend" value="1">Resend code</button>
            </form>
            <a href="login.php" style="color:var(--muted);">Cancel &amp; log out</a>
        </div>

        <details class="backup-box">
            <summary>Lost access to your email? Use a backup code instead</summary>
            <form method="POST" style="margin-top:12px;">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="text" name="backup_code" placeholder="XXXX-XXXX">
                <button type="submit" name="use_backup" value="1" class="btn-secondary">Use backup code</button>
            </form>
        </details>
    </div>
</body>

</html>