<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/twofactor.php';
requireLogin();

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $current = (string) ($_POST['current_password'] ?? '');
    $backup = (string) ($_POST['backup_code'] ?? '');
    $useBackup = ($_POST['credential_type'] ?? 'password') === 'backup';
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');
    $stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $stmt->execute([(int) $_SESSION['user_id']]);
    $row = $stmt->fetch();
    if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new))
        $error = 'Use at least 8 characters with at least one letter and one number.';
    elseif ($new !== $confirm)
        $error = 'The new passwords do not match.';
    elseif ($row && password_verify($new, $row['password']))
        $error = 'Choose a password different from your current password.';
    else {
        $pdo->beginTransaction();
        try {
            $credentialValid = $useBackup
                ? ($backup !== '' && twofaCheckAndConsumeBackupCode($pdo, (int) $_SESSION['user_id'], $backup))
                : ($row && password_verify($current, $row['password']));
            if (!$credentialValid) {
                $pdo->rollBack();
                $error = $useBackup ? 'That backup code is invalid, expired, or already used.' : 'The current password is incorrect.';
            } else {
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int) $_SESSION['user_id']]);
                $pdo->commit();
                session_regenerate_id(true);
                $message = 'Your password was changed successfully.';
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Change Password — HRMS &amp; Payroll</title>
    <?php include __DIR__ . '/includes/supervisor_style.php'; ?>
    <style>
        .password-page {
            max-width: 860px;
            margin: 0 auto;
            padding: 30px 28px 60px
        }

        .password-hero {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            margin-bottom: 24px
        }

        .password-hero-icon {
            width: 48px;
            height: 48px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #1d4ed8, #38bdf8);
            color: #fff;
            box-shadow: 0 10px 22px rgba(29, 78, 216, .2);
        }

        .password-hero-icon svg {
            width: 23px;
            height: 23px
        }

        .password-hero h1 {
            margin: 0;
            color: var(--text-accent, #0f172a);
            font-size: 28px
        }

        .password-hero p {
            margin: 5px 0 0;
            color: var(--ink-soft, #64748b);
            font-size: 14px
        }

        .password-card {
            max-width: 680px;
            background: var(--white, #fff);
            border: 1px solid var(--line, #e2e8f0);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow-card, 0 10px 30px rgba(15, 23, 42, .08))
        }

        .password-card h2 {
            margin: 0;
            color: var(--text-accent, #0f172a);
            font-size: 20px
        }

        .password-card .intro {
            margin: 7px 0 24px;
            color: var(--ink-soft, #64748b);
            font-size: 14px
        }

        .password-fields {
            display: grid;
            gap: 17px
        }

        .password-field {
            display: grid;
            gap: 7px
        }

        .password-field label {
            font-size: 13px;
            font-weight: 700;
            color: var(--ink, #334155)
        }

        .password-input {
            position: relative
        }

        .password-input input {
            width: 100%;
            height: 48px;
            padding: 0 48px 0 14px;
            border: 1px solid var(--line, #e2e8f0);
            border-radius: 11px;
            background: var(--surface-soft, #f8fafc);
            color: var(--ink, #0f172a);
            font: 14px var(--font-body, Inter, sans-serif);
            outline: none
        }

        .password-input input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .1)
        }

        .password-toggle {
            position: absolute;
            right: 8px;
            top: 7px;
            width: 34px;
            height: 34px;
            border: 0;
            border-radius: 8px;
            background: transparent;
            color: var(--ink-soft, #64748b);
            cursor: pointer;
            display: grid;
            place-items: center
        }

        .password-toggle:hover {
            background: #eff6ff;
            color: #1d4ed8
        }

        .password-toggle svg {
            width: 19px;
            height: 19px;
            display: block
        }

        .password-help {
            margin: 20px 0 22px;
            padding: 13px 15px;
            border-radius: 12px;
            background: var(--surface-soft, #f8fafc);
            border: 1px solid var(--line, #e2e8f0);
            color: var(--ink-soft, #64748b);
            font-size: 12.5px;
            line-height: 1.55
        }

        .password-input-plain {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid var(--line, #e2e8f0);
            border-radius: 11px;
            background: var(--surface-soft, #f8fafc);
            color: var(--ink, #0f172a);
            font: 14px var(--font-body, Inter, sans-serif);
            outline: none
        }

        .password-input-plain:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .1)
        }

        .credential-field[hidden] {
            display: none !important
        }

        .password-actions {
            display: flex;
            gap: 10px;
            align-items: center
        }

        .password-actions .btn {
            min-height: 44px;
            padding: 0 20px;
            border: 0;
            border-radius: 11px;
            font: 700 13px var(--font-body, Inter, sans-serif);
            cursor: pointer
        }

        .password-actions .btn-primary {
            color: #fff;
            background: linear-gradient(100deg, #1d4ed8, #38bdf8);
            box-shadow: 0 9px 20px rgba(29, 78, 216, .2)
        }

        .password-actions .btn-primary:hover {
            filter: brightness(1.06);
            transform: translateY(-1px)
        }

        .password-cancel {
            color: var(--ink-soft, #64748b);
            font-size: 13px;
            text-decoration: none
        }

        .password-cancel:hover {
            color: #1d4ed8
        }

        .password-actions .password-cancel[type="button"] {
            border: 1px solid var(--line, #e2e8f0);
            background: var(--surface-soft, #f8fafc);
            padding: 10px 12px;
            border-radius: 9px;
            cursor: pointer
        }

        .password-actions .password-cancel[type="button"]:hover {
            background: #eff6ff
        }

        @media(max-width:700px) {
            .password-page {
                padding: 22px 16px 40px
            }

            .password-hero h1 {
                font-size: 24px
            }

            .password-card {
                padding: 22px
            }

            .password-actions {
                flex-direction: column;
                align-items: stretch
            }

            .password-actions .btn {
                text-align: center
            }

            .password-cancel {
                text-align: center
            }
        }

        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none
        }
    </style>
</head>

<body>
    <div class="app-shell"><?php $activeNav = 'my_profile';
    include __DIR__ . '/includes/hr_sidebar.php'; ?>
        <main class="main-area">
            <div class="password-page">
                <div class="password-hero">
                    <div class="password-hero-icon"><svg viewBox="0 0 24 24" fill="none">
                            <rect x="5" y="10" width="14" height="10" rx="2" fill="currentColor" />
                            <path d="M8 10V7a4 4 0 118 0v3" stroke="currentColor" stroke-width="2"
                                stroke-linecap="round" />
                            <circle cx="12" cy="15" r="1.3" fill="#1d4ed8" />
                        </svg></div>
                    <div>
                        <h1>Change Password</h1>
                        <p>Keep your account secure with a strong, private password.</p>
                    </div>
                </div>
                <section class="password-card">
                    <h2>Update your password</h2>
                    <p class="intro">Enter your current password, then choose a new password you will remember.</p>
                    <?php if ($message): ?>
                        <div class="alert alert--success"><?= clean($message) ?></div><?php endif; ?><?php if ($error): ?>
                        <div class="alert alert--error"><?= clean($error) ?></div><?php endif; ?>
                    <form method="post"><?php csrfField(); ?>
                        <div class="password-fields">
                            <div class="password-field" id="passwordCredential"><label for="current_password">Current
                                    password</label>
                                <div class="password-input"><input id="current_password" type="password"
                                        name="current_password" required autocomplete="current-password"><button
                                        class="password-toggle" type="button" data-target="current_password"
                                        aria-label="Show current password"><svg viewBox="0 0 24 24" fill="none"
                                            aria-hidden="true">
                                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"
                                                stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                                            <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8" />
                                        </svg></button></div>
                            </div>
                            <div class="password-field"><label for="new_password">New password</label>
                                <div class="password-input"><input id="new_password" type="password" name="new_password"
                                        required minlength="8" autocomplete="new-password"><button
                                        class="password-toggle" type="button" data-target="new_password"
                                        aria-label="Show new password"><svg viewBox="0 0 24 24" fill="none"
                                            aria-hidden="true">
                                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"
                                                stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                                            <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8" />
                                        </svg></button></div>
                            </div>
                            <div class="password-field"><label for="confirm_password">Confirm new password</label>
                                <div class="password-input"><input id="confirm_password" type="password"
                                        name="confirm_password" required minlength="8"
                                        autocomplete="new-password"><button class="password-toggle" type="button"
                                        data-target="confirm_password" aria-label="Show password confirmation"><svg
                                            viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                            <path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"
                                                stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" />
                                            <circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8" />
                                        </svg></button></div>
                            </div>
                        </div>
                        <div class="password-help">Use at least 8 characters with at least one letter and one number. Do
                            not reuse a previous password. A backup code can be used once if you cannot verify your
                            current password.</div><input type="hidden" name="credential_type" id="credentialType"
                            value="password">
                        <div class="password-actions"><button class="btn btn-primary" type="submit">Change
                                password</button><button class="password-cancel" type="button" id="backupToggle">Use
                                backup code instead</button><a class="password-cancel" href="my_profile.php">Back</a>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
    <script>document.querySelectorAll('.password-toggle').forEach(function (button) { button.addEventListener('click', function () { var input = document.getElementById(button.dataset.target); var visible = input.type === 'text'; input.type = visible ? 'password' : 'text'; button.setAttribute('aria-label', (visible ? 'Show ' : 'Hide ') + input.name.replaceAll('_', ' ')); button.innerHTML = visible ? '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><circle cx="12" cy="12" r="2.5" stroke="currentColor" stroke-width="1.8"/></svg>' : '<svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m3 3 18 18M10.6 6.2A10.8 10.8 0 0 1 12 6c6 0 9.5 6 9.5 6a16.5 16.5 0 0 1-3.2 3.8M6.2 6.3C3.9 8 2.5 12 2.5 12s3.5 6 9.5 6c1.2 0 2.3-.2 3.3-.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>'; }); });</script>
    <script>var backupToggle = document.getElementById("backupToggle"); backupToggle.addEventListener("click", function () { var backup = document.getElementById("backupCredential"), password = document.getElementById("passwordCredential"), type = document.getElementById("credentialType"), current = document.getElementById("current_password"); var use = type.value === "password"; type.value = use ? "backup" : "password"; backup.hidden = !use; password.hidden = use; current.required = !use; document.getElementById("backup_code").required = use; backupToggle.textContent = use ? "Use current password instead" : "Use backup code instead"; });</script>
</body>

</html>