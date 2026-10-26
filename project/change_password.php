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
    if (strlen($new) < 8 || !preg_match('/[A-Za-z]/', $new) || !preg_match('/\d/', $new)) {
        $error = 'Use at least 8 characters with at least one letter and one number.';
    } elseif ($new !== $confirm) {
        $error = 'The new passwords do not match.';
    } elseif ($row && password_verify($new, $row['password'])) {
        $error = 'Choose a password different from your current password.';
    } else {
        $pdo->beginTransaction();
        try {
            $credentialValid = $useBackup
                ? ($backup !== '' && twofaCheckAndConsumeBackupCode($pdo, (int) $_SESSION['user_id'], $backup))
                : ($row && password_verify($current, $row['password']));
            if (!$credentialValid) {
                $pdo->rollBack();
                $error = $useBackup ? 'That backup code is invalid, expired, or already used.' : 'The current password is incorrect.';
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$newHash, (int) $_SESSION['user_id']]);

                global $hrmsPdo;
                if (!empty($hrmsPdo) && !empty($_SESSION['username'])) {
                    try {
                        $hrmsPdo->prepare('UPDATE users SET password = ? WHERE username = ?')->execute([$newHash, $_SESSION['username']]);
                    } catch (Throwable $e) {
                        error_log('HRMS password sync failed: ' . $e->getMessage());
                    }
                }

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
    <title>Change Password — HCI GroceryOne</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .password-page {
            width: min(860px, 100%);
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
            box-shadow: 0 10px 22px rgba(29, 78, 216, .2)
        }

        .password-hero-icon svg {
            width: 23px;
            height: 23px
        }

        .password-hero h1 {
            margin: 0;
            color: var(--text-accent);
            font-size: 28px
        }

        .password-hero p {
            margin: 5px 0 0;
            color: var(--ink-soft);
            font-size: 14px
        }

        .password-card {
            width: min(680px, 100%);
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--shadow-card)
        }

        .password-card h2 {
            margin: 0;
            color: var(--text-accent);
            font-size: 20px
        }

        .password-card .intro {
            margin: 7px 0 24px;
            color: var(--ink-soft);
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
            color: var(--ink)
        }

        .password-input {
            position: relative
        }

        .password-input input {
            width: 100%;
            height: 48px;
            padding: 0 48px 0 14px;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: var(--cream);
            color: var(--ink);
            font: 14px var(--font-body);
            outline: none
        }

        .password-input input:focus {
            border-color: var(--green-mid);
            box-shadow: 0 0 0 4px var(--green-pale)
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
            color: var(--ink-soft);
            cursor: pointer;
            display: grid;
            place-items: center
        }

        .password-toggle:hover {
            background: var(--green-pale);
            color: var(--green-mid)
        }

        .password-toggle svg {
            width: 19px;
            height: 19px;
            display: block
        }

        input[type="password"]::-ms-reveal,
        input[type="password"]::-ms-clear {
            display: none
        }

        .password-help {
            margin: 20px 0 22px;
            padding: 13px 15px;
            border-radius: 12px;
            background: var(--cream);
            border: 1px solid var(--line);
            color: var(--ink-soft);
            font-size: 12.5px;
            line-height: 1.55
        }

        .password-actions {
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap
        }

        .password-actions .btn {
            min-height: 44px;
            padding: 0 20px;
            border: 1px solid transparent;
            border-radius: 11px;
            font: 700 13px var(--font-body);
            cursor: pointer
        }

        .password-actions .btn-primary {
            color: #fff;
            background: var(--green-mid);
            border-color: var(--green-mid);
            box-shadow: 0 6px 16px rgba(37, 99, 235, .2)
        }

        .password-actions .btn-primary:hover {
            background: var(--green-deep);
            border-color: var(--green-deep);
            transform: translateY(-1px)
        }

        .password-cancel {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 42px;
            color: var(--green-deep);
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            padding: 0 14px;
            border: 1px solid var(--green-mid);
            border-radius: 9px;
            background: var(--white)
        }

        .password-cancel:hover {
            background: var(--green-mid);
            color: #fff
        }

        .credential-field[hidden] {
            display: none !important
        }

        .password-input-plain {
            width: 100%;
            height: 48px;
            padding: 0 14px;
            border: 1px solid var(--line);
            border-radius: 11px;
            background: var(--cream);
            color: var(--ink);
            font: 14px var(--font-body);
            outline: none
        }

        .password-input-plain:focus {
            border-color: var(--green-mid);
            box-shadow: 0 0 0 4px var(--green-pale)
        }

        .password-actions .password-cancel[type="button"] {
            border-color: var(--green-mid);
            background: var(--white);
            padding: 0 14px;
            border-radius: 9px;
            cursor: pointer
        }

        .password-actions .password-cancel[type="button"]:hover {
            background: var(--green-mid);
            color: #fff
        }

        .password-alert {
            padding: 12px 14px;
            border-radius: 11px;
            margin-bottom: 18px;
            font-size: 13px
        }

        .password-alert.success {
            background: var(--green-pale);
            color: var(--green-leaf)
        }

        .password-alert.error {
            background: var(--red-pale);
            color: var(--red-alert)
        }

        html[data-theme="dark"] .password-card {
            background: var(--surface)
        }

        html[data-theme="dark"] .password-input input,
        html[data-theme="dark"] .password-help {
            background: var(--surface-soft)
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
    </style>
</head>

<body>
    <div class="app-shell">
        <?php $active = 'my_profile';
        include __DIR__ . '/includes/sidebar.php'; ?>
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
                        <div class="password-alert success"><?= clean($message) ?></div><?php endif; ?>
                    <?php if ($error): ?>
                        <div class="password-alert error"><?= clean($error) ?></div><?php endif; ?>
                    <form method="post"><?php csrfField(); ?><input type="hidden" name="action" value="change_password">
                        <div class="password-fields">
                            <div class="password-field credential-field" id="passwordCredential"><label
                                    for="current_password">Current password</label>
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
                            <div class="password-field credential-field" id="backupCredential" hidden><label
                                    for="backup_code">One-time backup code</label><input id="backup_code"
                                    class="password-input-plain" type="text" name="backup_code" placeholder="XXXX-XXXX"
                                    autocomplete="off" autocapitalize="characters"></div>
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
                            current password.</div>
                        <input type="hidden" name="credential_type" id="credentialType" value="password">
                        <div class="password-actions"><button class="btn btn-primary" type="submit">Change
                                password</button><button class="password-cancel" type="button" id="backupToggle">Use
                                backup code instead</button><a class="password-cancel" href="my_profile.php">Back</a>
                        </div>
                    </form>
                </section>
            </div>
        </main>
    </div>
    <script>document.querySelectorAll('.password-toggle').forEach(function (button) { button.addEventListener('click', function () { var input = document.getElementById(button.dataset.target); var visible = input.type === 'text'; input.type = visible ? 'password' : 'text'; button.setAttribute('aria-label', (visible ? 'Show ' : 'Hide ') + input.name.replaceAll('_', ' ')); }); }); var backupToggle = document.getElementById('backupToggle'); backupToggle.addEventListener('click', function () { var backup = document.getElementById('backupCredential'), password = document.getElementById('passwordCredential'), type = document.getElementById('credentialType'), current = document.getElementById('current_password'); var use = type.value === 'password'; type.value = use ? 'backup' : 'password'; backup.hidden = !use; password.hidden = use; current.required = !use; document.getElementById('backup_code').required = use; backupToggle.textContent = use ? 'Use current password instead' : 'Use backup code instead'; });</script>
</body>

</html>