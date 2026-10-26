<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/public_bootstrap.php';

$isEmbedded = ($_GET['embedded'] ?? '') === '1';
$isExit = ($_GET['exit'] ?? '') === '1';
$error = '';
$passwordHash = null;

try {
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('The POS database is unavailable.');
    }
    ensureStoreSettingsTable($pdo);
    $stmt = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute(['catalog_private_password_hash']);
    $storedHash = $stmt->fetchColumn();
    $passwordHash = is_string($storedHash) && $storedHash !== '' ? $storedHash : null;
    if ($passwordHash === null) {
        $error = 'A Supervisor must set the private catalog password before this kiosk can be unlocked.';
    }
} catch (Throwable $e) {
    error_log('Private catalog password lookup failed: ' . $e->getMessage());
    http_response_code(503);
    exit('Private catalog access is temporarily unavailable.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $isExit = ($_POST['exit'] ?? '') === '1';
    $password = (string) ($_POST['store_password'] ?? '');

    try {
        if (!pub_rate_limit(public_pdo(), 'private_catalog_password', 5, 300)) {
            http_response_code(429);
            $error = 'Too many attempts. Please wait a few minutes and try again.';
        } elseif ($passwordHash === null) {
            $error = 'A Supervisor must set the private catalog password first.';
        } elseif (!password_verify($password, $passwordHash)) {
            $error = 'That password is incorrect. Please try again.';
        } else {
            session_regenerate_id(true);
            if ($isExit) {
                unset(
                    $_SESSION['catalog_private_authorized'],
                    $_SESSION['catalog_private_granted_at'],
                    $_SESSION['catalog_private_kiosk']
                );
                redirect('catalog.php');
            }

            $_SESSION['catalog_private_kiosk'] = true;
            $_SESSION['catalog_private_authorized'] = true;
            $_SESSION['catalog_private_granted_at'] = time();
            redirect('catalog.php?private=1');
        }
    } catch (Throwable $e) {
        error_log('Private catalog password check failed: ' . $e->getMessage());
        http_response_code(503);
        exit('Private catalog access is temporarily unavailable.');
    }
}

$csrfToken = generateCSRFToken();
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $isExit ? 'Supervisor access required' : 'Private catalog access' ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        *,*::before,*::after{box-sizing:border-box}
        :root{--bg:#f4f7fc;--card:#fff;--ink:#111a30;--muted:#60708d;--line:#dbe4f2;--blue:#2450df;--error:#b42318;--error-bg:#fff1f2;--grid:rgba(37,99,235,.055)}
        html[data-theme=dark]{--bg:#111827;--card:#1c2432;--ink:#eef3ff;--muted:#aab6ca;--line:#344155;--blue:#4d8dff;--error:#ffc4d4;--error-bg:#3a1524;--grid:rgba(128,151,255,.07);color-scheme:dark}
        html,body{min-height:100%;margin:0}
        body{min-height:100vh;display:grid;place-items:center;padding:24px;background-color:var(--bg);background-image:linear-gradient(var(--grid) 1px,transparent 1px),linear-gradient(90deg,var(--grid) 1px,transparent 1px),radial-gradient(ellipse at 50% 35%,rgba(37,99,235,.12),transparent 60%);background-size:54px 54px,54px 54px,auto;color:var(--ink);font-family:'Plus Jakarta Sans',Arial,sans-serif}
        .card{width:min(430px,100%);padding:38px 32px 34px;border:1px solid color-mix(in srgb,var(--line) 75%,transparent);border-radius:24px;background:var(--card);box-shadow:0 24px 64px rgba(15,23,42,.13);text-align:center}
        h1{margin:0 0 8px;font-family:Fraunces,Georgia,serif;font-size:clamp(25px,6vw,32px);font-weight:700;letter-spacing:-.035em;line-height:1.15}
        .subtitle{margin:0 0 30px;color:var(--muted);font-size:13px;line-height:1.55}
        .error{margin:0 0 18px;padding:11px 13px;border-radius:11px;background:var(--error-bg);color:var(--error);font-size:13px;text-align:left}
        .field{text-align:left}
        label{display:block;margin:0 0 8px;font-size:12px;font-weight:700}
        .password-wrap{position:relative}
        input{width:100%;height:50px;padding:0 48px 0 14px;border:1px solid var(--line);border-radius:12px;background:var(--card);color:var(--ink);font:500 14px 'Plus Jakarta Sans',Arial,sans-serif;outline:none}
        input::placeholder{color:#92a0b8}
        input:focus{border-color:var(--blue);box-shadow:0 0 0 3px color-mix(in srgb,var(--blue) 16%,transparent)}
        .reveal{position:absolute;top:50%;right:8px;display:grid;width:36px;height:36px;place-items:center;transform:translateY(-50%);border:0;border-radius:9px;background:transparent;color:var(--muted);cursor:pointer}
        .reveal svg{width:19px;height:19px}
        .submit{width:100%;min-height:52px;margin-top:22px;border:0;border-radius:12px;background:var(--blue);box-shadow:0 12px 28px color-mix(in srgb,var(--blue) 24%,transparent);color:#fff;font:800 14px 'Plus Jakarta Sans',Arial,sans-serif;cursor:pointer}
        .submit:hover{filter:brightness(1.06)}
        .submit:disabled{opacity:.55;box-shadow:none;cursor:not-allowed}
        .note{margin:22px 0 0;color:var(--muted);font-size:11px}
        @media(max-width:520px){body{padding:16px}.card{padding:32px 22px 28px;border-radius:20px}}
    </style>
    <script>
        (function(){try{document.documentElement.dataset.theme=localStorage.getItem('manage-grocery-theme')||'light'}catch(error){}}());
    </script>
</head>
<body>
    <main class="card">
        <h1><?= $isExit ? 'Exit private catalog' : 'Private catalog access' ?></h1>
        <p class="subtitle"><?= $isExit ? 'Supervisor password is required to leave private mode.' : 'Enter the Supervisor password to open the private catalog.' ?></p>
        <?php if ($error !== ''): ?><p class="error" role="alert"><?= clean($error) ?></p><?php endif; ?>
        <form method="post" action="catalog_supervisor_login.php<?= $isEmbedded ? '?embedded=1' : '' ?><?= $isExit ? ($isEmbedded ? '&' : '?') . 'exit=1' : '' ?>">
            <input type="hidden" name="csrf_token" value="<?= clean($csrfToken) ?>">
            <input type="hidden" name="exit" value="<?= $isExit ? '1' : '0' ?>">
            <div class="field">
                <label for="store_password">Supervisor password</label>
                <div class="password-wrap">
                    <input id="store_password" name="store_password" type="password" placeholder="Enter password" autocomplete="current-password" required autofocus <?= $passwordHash === null ? 'disabled' : '' ?>>
                    <button class="reveal" id="revealPassword" type="button" aria-label="Show password" aria-pressed="false">
                        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M1.5 10S4.5 4 10 4s8.5 6 8.5 6-3 6-8.5 6-8.5-6-8.5-6Z" stroke="currentColor" stroke-width="1.5"/><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.5"/></svg>
                    </button>
                </div>
            </div>
            <button class="submit" type="submit" <?= $passwordHash === null ? 'disabled' : '' ?>><?= $isExit ? 'Unlock public catalog' : 'Open private catalog' ?></button>
        </form>
        <p class="note">Grocery Store &amp; Point of Sale</p>
    </main>
    <script>
        (function(){var input=document.getElementById('store_password');var button=document.getElementById('revealPassword');button.addEventListener('click',function(){var visible=input.type==='password';input.type=visible?'text':'password';button.setAttribute('aria-label',visible?'Hide password':'Show password');button.setAttribute('aria-pressed',String(visible));input.focus()})}());
    </script>
</body>
</html>
