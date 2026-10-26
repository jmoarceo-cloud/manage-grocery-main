<?php
// =====================================================================
// setup_2fa.php — first-time 2FA enrollment, reached only mid-login
// (via twofaStartLogin() in login.php) for an account that requires
// 2FA but has no verified two_factor_email on file yet. Two steps:
//   1. Enter an email address -> we send a code to it to prove they
//      actually own it (can't let someone register an inbox they
//      don't control as the recovery destination).
//   2. Enter that code -> we mark 2FA enabled, generate 8 one-time
//      backup codes, show them ONCE (this is the "secure recovery /
//      backup method" the account will need if they ever lose email
//      access), then finish logging them in.
// =====================================================================

require_once 'includes/config.php';
require_once 'includes/twofactor.php';

if (!empty($_SESSION['user_id'])) {
    redirectToRoleHome();
}

if (empty($_SESSION['tfa_pending']) || $_SESSION['tfa_pending']['stage'] !== 'need_setup') {
    header('Location: login.php');
    exit;
}

$pending = $_SESSION['tfa_pending'];
if (time() - $pending['started_at'] > 900) {
    unset($_SESSION['tfa_pending']);
    twofaClearPending();
    header('Location: login.php?expired=1');
    exit;
}

$error = '';
$setupEmail = trim((string) ($_SESSION['tfa_setup_email'] ?? ''));
$step = $setupEmail !== '' ? 'confirm' : 'email';
$backupCodesToShow = $_SESSION['tfa_setup_backup_codes_plain'] ?? null;
$prevEmail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();

    if (isset($_POST['submit_email'])) {
        $email = trim($_POST['email'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
            $step = 'email';
        } else {
            $_SESSION['tfa_setup_email'] = $email;
            $setupEmail = $email;
            if (twofaSendOtp($pdo, $pending['user_id'], $email, $pending['full_name'])) {
                $step = 'confirm';
            } else {
                unset($_SESSION['tfa_setup_email'], $_SESSION['tfa_otp_hash'], $_SESSION['tfa_otp_expires']);
                $setupEmail = '';
                $error = trim(SMTP_USERNAME) === '' || trim(SMTP_PASSWORD) === ''
                    ? 'No verification email was sent because SMTP credentials are missing. Configure SMTP in project/.env before setting up two-factor authentication.'
                    : 'The email server could not deliver the verification code. Ask the administrator to check the SMTP settings.';
                $step = 'email';
            }
        }
    } elseif (isset($_POST['submit_code'])) {
        $code = trim($_POST['otp_code'] ?? '');
        $result = twofaCheckOtp($pdo, (int) $pending['user_id'], $code);
        if ($result['ok']) {
            $email = trim((string) ($_SESSION['tfa_setup_email'] ?? ''));
            if ($email === '') {
                $error = 'Your verification session expired. Please enter your email address again.';
                $step = 'email';
            } else {
                $backupCodesPlain = twofaGenerateBackupCodes();
                $backupHashes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $backupCodesPlain);

                $pdo->prepare("UPDATE users SET two_factor_enabled = 1, two_factor_email = ?, two_factor_backup_codes = ? WHERE id = ?")
                    ->execute([$email, json_encode($backupHashes), $pending['user_id']]);

                twofaClearPending();
                unset($_SESSION['tfa_setup_email']);
                $_SESSION['tfa_setup_backup_codes_plain'] = $backupCodesPlain; // shown once, cleared after "I've saved these"
                $backupCodesToShow = $backupCodesPlain;
                $step = 'backup_codes';
            }
        } elseif ($result['reason'] === 'too_many_attempts') {
            unset($_SESSION['tfa_pending'], $_SESSION['tfa_setup_email']);
            twofaClearPending();
            header('Location: login.php');
            exit;
        } elseif ($result['reason'] === 'expired') {
            $error = 'This code expired. Go back and resend it.';
            $step = 'confirm';
        } else {
            $error = 'Incorrect code. Please try again.';
            $step = 'confirm';
        }
    } elseif (isset($_POST['resend'])) {
        if (!empty($_SESSION['tfa_otp_resend_after']) && time() < $_SESSION['tfa_otp_resend_after']) {
            $error = 'Please wait a few seconds before requesting another code.';
            $step = 'confirm';
        } else {
            $setupEmail = trim((string) ($_SESSION['tfa_setup_email'] ?? ''));
            if ($setupEmail === '') {
                $error = 'Enter your email address before requesting a new code.';
                $step = 'email';
            } else {
                if (!twofaSendOtp($pdo, $pending['user_id'], $setupEmail, $pending['full_name'])) {
                    unset($_SESSION['tfa_setup_email'], $_SESSION['tfa_otp_hash'], $_SESSION['tfa_otp_expires']);
                    $setupEmail = '';
                    $error = trim(SMTP_USERNAME) === '' || trim(SMTP_PASSWORD) === ''
                        ? 'Email delivery is unavailable because SMTP credentials are missing. Configure SMTP in project/.env, then try again.'
                        : 'Email delivery failed. Ask the administrator to check the SMTP settings.';
                } else {
                    $error = '';
                }
            }
            $step = $setupEmail !== '' && empty($error) ? 'confirm' : 'email';
        }
    } elseif (isset($_POST['back_to_email'])) {
        // User wants to change the email they entered in step 1 —
        // drop the pending OTP entirely so an old code can't still be
        // confirmed against a different address, then send them back.
        $prevEmail = $_SESSION['tfa_setup_email'] ?? '';
        unset($_SESSION['tfa_setup_email']);
        twofaClearPending();
        $step = 'email';
        $error = '';
    } elseif (isset($_POST['finish'])) {
        // "I've saved my backup codes" — finalize the actual login now.
        unset($_SESSION['tfa_setup_backup_codes_plain']);
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$pending['user_id']]);
        $user = $stmt->fetch();
        unset($_SESSION['tfa_pending']);

        clearFailedLogins($pdo, $user['id']);
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['employee_id'] = $user['employee_id'] ?? null;
        $_SESSION['profile_picture'] = $user['profile_picture'] ?? null;
        $_SESSION['last_activity'] = time();
        if (!empty($pending['catalog_private'])) {
            $_SESSION['catalog_private'] = true;
            unset($_SESSION['catalog_private_intent']);
        }
        $destination = $pending['redirect'] ?: 'pos.php';
        if (
            $destination === 'dashboard.php'
            || $destination === 'inventory_dashboard.php'
            || $destination === 'finance_dashboard.php'
            || $destination === 'pos.php'
        ) {
            $destination = systemUrl($destination);
        }
        redirect($destination);
    }
}

$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Up Two-Factor Authentication — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <style>
        :root {
            --bg: #f8fafc;
            --text: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --accent: #1d4ed8;
            --accent-2: #0284c7;
            --surface: #fff;
            --input: #f8fafc;
            --grid: rgba(37, 99, 235, .055)
        }

        * {
            box-sizing: border-box
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: var(--bg);
            background-image: linear-gradient(var(--grid) 1px, transparent 1px), linear-gradient(90deg, var(--grid) 1px, transparent 1px);
            background-size: 32px 32px;
            color: var(--text);
            font-family: 'Inter', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px
        }

        body:before,
        body:after {
            content: "";
            position: fixed;
            border-radius: 999px;
            filter: blur(4px);
            pointer-events: none;
            z-index: -1
        }

        body:before {
            width: 340px;
            height: 340px;
            top: -150px;
            right: -110px;
            background: rgba(96, 165, 250, .22)
        }

        body:after {
            width: 300px;
            height: 300px;
            bottom: -150px;
            left: -100px;
            background: rgba(129, 140, 248, .16)
        }

        .card {
            width: 100%;
            max-width: 520px;
            padding: 42px 42px 34px;
            border: 1px solid var(--line);
            border-radius: 24px;
            background: rgba(255, 255, 255, .94);
            box-shadow: 0 24px 70px rgba(15, 23, 42, .12);
            position: relative;
            overflow: hidden
        }

        .card:before {
            content: "";
            position: absolute;
            top: 0;
            left: 12%;
            right: 12%;
            height: 3px;
            background: linear-gradient(90deg, transparent, var(--accent), #38bdf8, transparent)
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
            margin-bottom: 30px;
            color: #0f172a;
            font-weight: 800;
            letter-spacing: .01em
        }

        .brand-mark {
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #fff;
            box-shadow: 0 8px 18px rgba(29, 78, 216, .22)
        }

        .brand-mark svg {
            width: 23px;
            height: 23px
        }

        .brand span {
            color: var(--accent)
        }

        h1 {
            font-family: 'Sora', sans-serif;
            font-size: 25px;
            line-height: 1.2;
            margin: 0 0 10px;
            color: #0f172a
        }

        p.sub {
            color: var(--muted);
            font-size: 13.5px;
            line-height: 1.65;
            margin: 0 0 24px
        }

        p.sub strong {
            color: #334155
        }

        .alert {
            display: flex;
            gap: 10px;
            padding: 13px;
            border-radius: 13px;
            margin-bottom: 18px;
            font-size: 13px;
            line-height: 1.5;
            background: #fff1f2;
            color: #be123c;
            border: 1px solid rgba(225, 29, 72, .22)
        }

        .alert svg {
            width: 19px;
            height: 19px;
            flex-shrink: 0;
            margin-top: 1px
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 800;
            color: #334155;
            margin-bottom: 8px
        }

        input[type=email],
        input[type=text] {
            width: 100%;
            height: 52px;
            padding: 0 15px;
            border-radius: 13px;
            border: 1px solid var(--line);
            background: var(--input);
            color: #0f172a;
            font-size: 14px;
            margin-bottom: 18px;
            outline: none
        }

        input[type=email]:focus,
        input[type=text]:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .1)
        }

        .otp-input {
            text-align: center;
            font-size: 24px;
            letter-spacing: 10px;
            font-weight: 800;
            height: 58px
        }

        .btn-submit {
            width: 100%;
            height: 52px;
            border: 0;
            border-radius: 13px;
            background: linear-gradient(100deg, var(--accent), #38bdf8);
            color: #fff;
            font-weight: 800;
            cursor: pointer;
            font-size: 14.5px;
            box-shadow: 0 12px 24px rgba(29, 78, 216, .2);
            transition: transform .2s ease, box-shadow .2s ease
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 16px 30px rgba(29, 78, 216, .28)
        }

        .link-row {
            margin-top: 18px;
            font-size: 12.5px;
            text-align: center;
            color: var(--muted)
        }

        .link-row a,
        .link-row button {
            background: none;
            border: 0;
            color: var(--accent);
            cursor: pointer;
            font-size: 12.5px;
            text-decoration: none
        }

        .link-row a:hover,
        .link-row button:hover {
            text-decoration: underline
        }

        .step-badge {
            display: inline-flex;
            font-size: 11px;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: var(--accent);
            font-weight: 800;
            background: #eff6ff;
            border: 1px solid #dbeafe;
            border-radius: 999px;
            padding: 6px 10px;
            margin-bottom: 14px
        }

        .codes-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 18px 0;
            padding: 18px;
            border-radius: 14px;
            background: #f8fafc;
            border: 1px solid var(--line)
        }

        .codes-grid div {
            font-family: monospace;
            font-size: 15px;
            letter-spacing: 1px;
            color: #1e3a8a;
            text-align: center;
            padding: 6px 0
        }

        .warn-box {
            background: #fffbeb;
            border: 1px solid #fcd34d;
            color: #92400e;
            padding: 12px 14px;
            border-radius: 12px;
            font-size: 12.5px;
            line-height: 1.6;
            margin-bottom: 8px
        }

        html[data-theme="dark"] {
            --bg: #060812;
            --text: #f5f7ff;
            --muted: #9ca8c7;
            --line: rgba(148, 163, 255, .16);
            --surface: #0f1630;
            --input: #080d20;
            --grid: rgba(148, 163, 255, .035)
        }

        html[data-theme="dark"] .card {
            background: rgba(15, 22, 48, .92);
            box-shadow: 0 30px 90px rgba(0, 0, 0, .35)
        }

        html[data-theme="dark"] h1 {
            color: #f5f7ff
        }

        html[data-theme="dark"] label {
            color: #dbe4ff
        }

        html[data-theme="dark"] input[type=email],
        html[data-theme="dark"] input[type=text],
        html[data-theme="dark"] .codes-grid {
            color: #fff;
            background: #080d20;
            border-color: var(--line)
        }

        html[data-theme="dark"] .codes-grid div {
            color: #dfe7ff
        }

        html[data-theme="dark"] .step-badge {
            color: #55d6ff;
            background: rgba(85, 214, 255, .1);
            border-color: rgba(85, 214, 255, .22)
        }

        html[data-theme="dark"] .link-row a,
        html[data-theme="dark"] .link-row button {
            color: #55d6ff
        }

        @media(max-width:560px) {
            body {
                padding: 14px
            }

            .card {
                padding: 32px 22px 26px;
                border-radius: 20px
            }

            .brand {
                margin-bottom: 24px
            }

            h1 {
                font-size: 22px
            }

            .codes-grid {
                gap: 7px;
                padding: 13px
            }

            .codes-grid div {
                font-size: 13px
            }
        }
    </style>
    <script>
        try {
            var savedTheme = localStorage.getItem('manage-grocery-theme');
            if (savedTheme === 'dark') document.documentElement.setAttribute('data-theme', 'dark');
        } catch (error) { }
    </script>
</head>

<body>
    <div class="card">
        <div class="brand">
            <div class="brand-mark"><svg viewBox="0 0 48 48" fill="none">
                    <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="currentColor"
                        opacity=".95" />
                    <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" stroke="#dbeafe" stroke-width="2.5" stroke-linecap="round" />
                </svg></div><span>Manage <span>Grocery</span></span>
        </div>

        <?php if ($step === 'email'): ?>
            <span class="step-badge">Step 1 of 3</span>
            <h1>Set up two-factor login</h1>
            <p class="sub">Because <strong><?= clean(ucfirst($pending['role'])) ?></strong> accounts handle sensitive data,
                this account now needs a verification code at every login. Enter an email address you have access to — we'll
                send codes there.</p>
            <?php if ($error): ?>
                <div class="alert"><svg viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                        <path d="M10 6.5v4M10 13.5v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg><span><?= clean($error) ?></span></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <label>Email address</label>
                <input type="email" name="email" placeholder="you@example.com" value="<?= clean($prevEmail) ?>" required
                    autofocus>
                <button type="submit" name="submit_email" value="1" class="btn-submit">Send verification code</button>
            </form>
            <div class="link-row">
                <a href="login.php" style="color:var(--muted);">&larr; Back to login</a>
            </div>

        <?php elseif ($step === 'confirm'): ?>
            <span class="step-badge">Step 2 of 3</span>
            <h1>Confirm your email</h1>
            <p class="sub">We sent a 6-digit code to <strong><?= clean($setupEmail) ?></strong>. Enter it below to confirm
                you own this inbox.</p>
            <?php if ($error): ?>
                <div class="alert"><svg viewBox="0 0 20 20" fill="none">
                        <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.6" />
                        <path d="M10 6.5v4M10 13.5v.01" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg><span><?= clean($error) ?></span></div><?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <input type="text" name="otp_code" class="otp-input" maxlength="6" inputmode="numeric" placeholder="000000"
                    required autofocus>
                <button type="submit" name="submit_code" value="1" class="btn-submit">Confirm &amp; enable 2FA</button>
            </form>
            <div class="link-row">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <button type="submit" name="resend" value="1">Resend code</button>
                </form>
                &nbsp;·&nbsp;
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <button type="submit" name="back_to_email" value="1">Change email</button>
                </form>
            </div>

        <?php elseif ($step === 'backup_codes' && $backupCodesToShow): ?>
            <span class="step-badge">Step 3 of 3</span>
            <h1>Save your backup codes</h1>
            <p class="sub">2FA is now enabled. Each code below can be used <strong>once</strong> to log in if you ever lose
                access to your email. Save them somewhere safe — this is the only time they'll be shown.</p>
            <div class="warn-box">⚠️ These codes will not be shown again after you leave this page. Screenshot, print, or
                write them down now.</div>
            <div class="codes-grid">
                <?php foreach ($backupCodesToShow as $c): ?>
                    <div><?= clean($c) ?></div>
                <?php endforeach; ?>
            </div>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                <button type="submit" name="finish" value="1" class="btn-submit">I've saved these — Continue to my
                    dashboard</button>
            </form>
        <?php endif; ?>

    </div>
</body>

</html>