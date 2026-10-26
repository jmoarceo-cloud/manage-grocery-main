<?php
require_once 'includes/config.php';
requireSupervisorRole();

$activeNav = 'catalog_security';
$message = '';
$error = '';
$passwordConfigured = false;
$settingKey = 'catalog_private_password_hash';

if (!($posPdo instanceof PDO)) {
    $error = 'The POS database is unavailable. The private password could not be loaded.';
} else {
    try {
        $posPdo->exec("CREATE TABLE IF NOT EXISTS store_settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(100) NOT NULL UNIQUE,
            setting_value TEXT DEFAULT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        $stmt = $posPdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([$settingKey]);
        $currentHash = $stmt->fetchColumn();
        $passwordConfigured = is_string($currentHash) && $currentHash !== '';
    } catch (PDOException $e) {
        error_log('Private catalog password settings lookup failed: ' . $e->getMessage());
        $error = 'Private catalog password settings could not be loaded.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $posPdo instanceof PDO) {
    requireCSRF();
    $password = (string) ($_POST['store_password'] ?? '');
    $confirm = (string) ($_POST['store_password_confirm'] ?? '');
    if (strlen($password) < 8) {
        $error = 'Use at least 8 characters for the Supervisor password.';
    } elseif (!hash_equals($password, $confirm)) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $posPdo->prepare('INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $stmt->execute([$settingKey, $hash]);
            $passwordConfigured = true;
            $message = 'Private catalog Supervisor password saved.';
        } catch (PDOException $e) {
            error_log('Private catalog password save failed: ' . $e->getMessage());
            $error = 'The Supervisor password could not be saved.';
        }
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Private Catalog Access — Supervisor</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<?php include 'includes/supervisor_style.php'; ?>
<style>
.security-page-container{
    display:flex;
    flex:1 0 auto;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    width:100%;
    max-width:1320px;
    min-height:calc(100vh - 76px);
    margin-inline:auto;
    padding:clamp(24px,4vw,56px) clamp(18px,4vw,64px);
}
.security-page-container>.alert,
.security-panel{width:min(100%,1040px)}
.security-panel{padding:clamp(28px,4vw,48px);border-radius:20px}
.security-panel .panel-heading{margin-bottom:24px}
.security-panel .panel-heading h2{font-family:var(--font-display);font-size:clamp(21px,2vw,26px);color:var(--text-accent);margin-bottom:8px;letter-spacing:-.02em}
.security-panel .panel-heading p,.security-panel>p{color:var(--ink-soft);font-size:14px;line-height:1.7}
.security-panel>p{margin-bottom:24px}
.security-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:20px;max-width:none}
.security-fields label{display:block;margin-bottom:8px;font-size:14px;font-weight:650;color:var(--ink-soft)}
.security-fields input{width:100%;min-height:50px;padding:12px 14px;border:1px solid var(--line);border-radius:11px;font:inherit;font-size:15px;background:var(--white);color:var(--ink)}
.security-fields input:focus{outline:none;border-color:var(--green-mid);box-shadow:0 0 0 3px rgba(37,99,235,.14)}
.security-fields .field-hint{margin-top:8px;color:var(--ink-soft);font-size:13px;line-height:1.5}
.security-panel .save-button{min-height:48px;margin-top:24px;padding-inline:22px;font-size:14px}
.security-panel .alert{margin-bottom:18px}
@media(min-width:1500px){
    .security-panel{width:min(100%,1120px)}
}
@media(max-width:900px){
    .security-page-container{min-height:calc(100vh - 68px);padding:28px 24px}
    .security-panel{padding:30px}
}
@media(max-width:700px){
    .app-shell>.main-area{margin-left:64px!important;width:calc(100% - 64px)!important}
    .app-shell>.sidebar .sidebar-toggle{left:51px!important}
    .security-page-container{min-height:calc(100vh - 58px);padding:20px 14px}
    .security-panel{width:100%;padding:22px 18px;border-radius:16px}
    .security-panel .panel-heading{margin-bottom:18px}
    .security-panel .panel-heading h2{font-size:20px}
    .security-panel .panel-heading p,.security-panel>p{font-size:13px}
    .security-panel>p{margin-bottom:20px}
    .security-fields{grid-template-columns:minmax(0,1fr);gap:16px}
    .security-fields label{font-size:13px}
    .security-fields input{min-height:48px;font-size:16px}
    .security-panel .save-button{width:100%;margin-top:20px}
}
</style>
</head>
<body>
<div class="app-shell">
    <?php include 'includes/supervisor_sidebar.php'; ?>
    <div class="main-area">
        <div class="topbar">
            <h1>Private Catalog Access</h1>
            <div class="user-info">
                <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span>
                <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
            </div>
        </div>
        <div class="container security-page-container">
            <?php if ($message !== ''): ?><div class="alert alert--success"><?= clean($message) ?></div><?php endif; ?>
            <?php if ($error !== ''): ?><div class="alert alert--error"><?= clean($error) ?></div><?php endif; ?>
            <section class="panel security-panel">
                <div class="panel-heading">
                    <h2>Supervisor kiosk password</h2>
                    <p>Set or change the password that opens the private catalog and unlocks public browsing. This is separate from staff accounts and email verification.</p>
                </div>
                <p><?= $passwordConfigured ? 'A private catalog password is configured. Saving a new one replaces it.' : 'No private catalog password is configured yet. Set one before using private mode.' ?> Use at least 8 characters and keep it for Supervisors only.</p>
                <?php if ($posPdo instanceof PDO): ?>
                    <form method="post">
                        <?php csrfField(); ?>
                        <div class="security-fields">
                            <div>
                                <label for="store_password">New Supervisor password</label>
                                <input id="store_password" type="password" name="store_password" minlength="8" maxlength="128" autocomplete="new-password" required>
                                <p class="field-hint">At least 8 characters. It will not be displayed again.</p>
                            </div>
                            <div>
                                <label for="store_password_confirm">Confirm password</label>
                                <input id="store_password_confirm" type="password" name="store_password_confirm" minlength="8" maxlength="128" autocomplete="new-password" required>
                            </div>
                        </div>
                        <button class="btn btn-primary save-button" type="submit"><?= $passwordConfigured ? 'Change Supervisor password' : 'Set Supervisor password' ?></button>
                    </form>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>
</body>
</html>
