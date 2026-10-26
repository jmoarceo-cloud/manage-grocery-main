<?php
require_once 'includes/config.php';
requireAdmin();

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// =====================================================
// ADMIN'S OWN PROFILE: change own password
// =====================================================
// Ito ang "forgot/change password" na self-service para sa admin mismo
// (hiwalay sa "reset_user_password" na para sa ibang users, na nasa
// admin_dashboard.php > User Accounts) — kailangan muna ibigay ang
// current password bago tanggapin ang bago.
if ($_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();
    $action = $_POST['action'] ?? '';

    if ($action == 'change_own_password') {

        $current_password = trim($_POST['current_password'] ?? '');
        $new_password      = trim($_POST['new_password'] ?? '');
        $confirm_password  = trim($_POST['confirm_password'] ?? '');

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $me = $stmt->fetch();

        if (!$me || !password_verify($current_password, $me['password'])) {
            $error_message = "The current password you entered is incorrect.";
        } elseif (strlen($new_password) < 6) {
            $error_message = "The new password must be at least 6 characters long.";
        } elseif ($new_password !== $confirm_password) {
            $error_message = "Passwords do not match.";
        } else {
            try {
                $hashed = password_hash($new_password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                    ->execute([$hashed, $_SESSION['user_id']]);
                $_SESSION['flash_success'] = "Your password has been successfully updated.";
                redirect('admin_profile.php');
            } catch (PDOException $e) {
                error_log("Change own password error: " . $e->getMessage());
                $error_message = "Could not change your password. Please try again.";
            }
        }

    } elseif ($action == 'update_profile_picture') {

        // See processProfilePictureUpload() in includes/config.php —
        // saves the picture, remembers it (survives logout/login), and
        // mirrors it to the POS side too (matched via pos_user_id,
        // since Admin doesn't have an employee_id).
        $__ap = processProfilePictureUpload($pdo, $posPdo);
        if ($__ap['error']) {
            $_SESSION['flash_error'] = $__ap['error'];
        } else {
            $_SESSION['flash_success'] = $__ap['message'];
        }
        redirect('admin_profile.php');

    } elseif ($action == 'remove_profile_picture') {

        $__ap = removeProfilePicture($pdo, $posPdo);
        if ($__ap['error']) {
            $_SESSION['flash_error'] = $__ap['error'];
        } else {
            $_SESSION['flash_success'] = $__ap['message'];
        }
        redirect('admin_profile.php');

    } elseif ($action == 'update_2fa_email') {
        require_once 'includes/twofactor.php';
        $current_password = trim($_POST['current_password_2fa'] ?? '');
        $new_email = trim($_POST['two_factor_email'] ?? '');

        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $me = $stmt->fetch();

        if (!$me || !password_verify($current_password, $me['password'])) {
            $_SESSION['flash_error'] = "The current password you entered is incorrect.";
        } elseif (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = "Please enter a valid email address.";
        } else {
            $pdo->prepare("UPDATE users SET two_factor_email = ? WHERE id = ?")->execute([$new_email, $_SESSION['user_id']]);
            $_SESSION['flash_success'] = "Your 2FA recovery email has been updated. You'll receive login codes at this address from now on.";
        }
        redirect('admin_profile.php');

    } elseif ($action == 'regenerate_backup_codes') {
        require_once 'includes/twofactor.php';
        $current_password = trim($_POST['current_password_backup'] ?? '');
        $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $me = $stmt->fetch();

        if (!$me || !password_verify($current_password, $me['password'])) {
            $_SESSION['flash_error'] = "The current password you entered is incorrect.";
        } else {
            $newCodes = twofaGenerateBackupCodes();
            $hashes = array_map(fn($c) => password_hash($c, PASSWORD_DEFAULT), $newCodes);
            $pdo->prepare("UPDATE users SET two_factor_backup_codes = ? WHERE id = ?")->execute([json_encode($hashes), $_SESSION['user_id']]);
            $_SESSION['flash_new_backup_codes'] = $newCodes; // shown once below, then discarded
        }
        redirect('admin_profile.php');
    }
}

// =====================================================
// SARILING ACCOUNT — ito lang ang dapat makita dito: ang sariling
// account info ng naka-login na admin (username, full name, role,
// status) at ang change-password form. Wala nang KPIs, User Accounts
// list, leave requests, atbp. — nasa admin_dashboard.php na lang yun.
// =====================================================
$stmt = $pdo->prepare("SELECT id, username, full_name, is_active, profile_picture, two_factor_enabled, two_factor_email FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$my_admin_account = $stmt->fetch();

$new_backup_codes_to_show = $_SESSION['flash_new_backup_codes'] ?? null;
unset($_SESSION['flash_new_backup_codes']);

// Initials for the avatar badge
$initials = "";
if (!empty($my_admin_account['full_name'])) {
    $parts = preg_split('/\s+/', trim($my_admin_account['full_name']));
    $initials = strtoupper(substr($parts[0], 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : (substr($parts[0], 1, 1) ?: '')));
}
?>
<!DOCTYPE html>
<html lang="tl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>My Profile — HRMS &amp; Payroll</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --green-deep:    #123C5C;
    --text-accent:   var(--green-deep);
    --green-mid:     #1978A8;
    --green-leaf:    #4FC3D1;
    --green-pale:    #E3F2F6;
    --mustard:       #E0A23B;
    --mustard-pale:  #FBEFD8;
    --red-alert:     #C8472D;
    --red-pale:      #FBE7E2;
    --cream:         #F3F6F9;
    --white:         #FFFFFF;
    --ink:           #17222E;
    --ink-soft:      #3D4C5C;
    --line:          #DCE5EC;
    --font-display: 'Sora', sans-serif;
    --font-body: 'Inter', sans-serif;
}

html, body {
    min-height: 100vh;
    font-family: var(--font-body);
    color: var(--ink);
    background: var(--cream);
}

a { text-decoration: none; color: inherit; }

/* ── Sidebar (same look/size as the POS "My Profile" sidebar) ── */
.app-shell { display: flex; min-height: 100vh; }
.sidebar {
    width: 288px; flex-shrink: 0;
    background: radial-gradient(420px 260px at 100% 0%, rgba(79,195,209,.16), transparent 60%), var(--green-deep);
    color: #FFFFFF;
    display: flex; flex-direction: column; padding: 30px 24px;
    position: fixed; top: 0; left: 0; height: 100vh; overflow-y: auto;
    z-index: 100;
    box-shadow: 2px 0 12px rgba(0,0,0,0.18);
}
.sidebar-brand { display: flex; align-items: center; gap: 14px; padding: 0 4px 26px; margin-bottom: 22px; border-bottom: 1px solid rgba(255,255,255,0.14); }
.brand-mark { width: 46px; height: 46px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: var(--white); border-radius: 13px; box-shadow: 0 4px 10px rgba(0,0,0,.14); }
.brand-mark .brand-icon, .brand-icon { width: 28px; height: 28px; }
.brand-text { display: flex; flex-direction: column; line-height: 1.35; }
.brand-name { font-family: var(--font-display); font-weight: 700; font-size: 17px; }
.brand-tagline { font-size: 11px; color: var(--green-leaf); font-weight: 600; letter-spacing: .02em; }
.nav-section-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: rgba(255,255,255,.42); padding: 0 14px; margin: 4px 0 10px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 5px; }
.nav-link { display: flex; align-items: center; gap: 13px; padding: 13px 15px; border-radius: 12px; color: rgba(255,255,255,0.82); font-size: 14.5px; font-weight: 600; transition: background 0.15s ease, color 0.15s ease; }
.nav-icon-box { width: 30px; height: 30px; border-radius: 9px; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: rgba(255,255,255,.08); }
.nav-link.is-active .nav-icon-box { background: rgba(255,255,255,.35); }
.nav-icon { width: 17px; height: 17px; flex-shrink: 0; }
.nav-link:hover { background: rgba(255,255,255,0.1); color: #FFFFFF; }
.nav-link.is-active { background: var(--green-leaf); color: var(--green-deep); box-shadow: 0 4px 12px rgba(0,0,0,.16); }
.nav-link--logout { margin-top: 16px; border-top: 1px solid rgba(255,255,255,0.14); padding-top: 18px; color: rgba(255,255,255,0.65); }
.nav-link--logout:hover { background: rgba(200,71,45,0.18); color: var(--salmon-bg, #ffb3a3); }
.sidebar-foot { margin-top: auto; padding: 14px; border-radius: 14px; background: rgba(255,255,255,.06); display: flex; align-items: center; gap: 12px; }
.sidebar-foot .foot-avatar { width: 40px; height: 40px; border-radius: 50%; flex-shrink: 0; background: linear-gradient(150deg, var(--green-leaf), #2E9BAE); color: var(--green-deep); display: flex; align-items: center; justify-content: center; font-family: var(--font-display); font-weight: 700; font-size: 15px; overflow: hidden; }
.sidebar-foot .foot-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }
.sidebar-foot .foot-text { display: flex; flex-direction: column; line-height: 1.3; min-width: 0; }
.sidebar-foot .foot-name { font-size: 13.5px; font-weight: 700; color: #FFFFFF; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sidebar-foot .foot-role { font-size: 11.5px; color: rgba(255,255,255,0.6); font-weight: 600; }
.main-area { margin-left: 288px; flex: 1; min-width: 0; display: flex; flex-direction: column; position: relative; z-index: 1; }
@media (max-width: 900px) {
    .sidebar { width: 76px; padding: 24px 12px; }
    .brand-text, .nav-link span, .sidebar-foot .foot-text { display: none; }
    .sidebar-brand { justify-content: center; padding-bottom: 18px; }
    .nav-link { justify-content: center; padding: 12px; gap: 0; }
    .sidebar-foot { justify-content: center; padding: 10px; }
    .main-area { margin-left: 76px; }
}

.topbar {
    background: var(--white);
    border-bottom: 1px solid var(--line);
    padding: 16px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.topbar h1 {
    font-family: var(--font-display);
    font-size: 20px;
    color: var(--text-accent);
}
.topbar .user-info {
    font-size: 13.5px;
    color: var(--ink-soft);
    display: flex;
    align-items: center;
    gap: 16px;
}
.topbar a.dashboard-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    color: var(--text-accent);
}
.topbar a.dashboard-link svg { width: 15px; height: 15px; }
.topbar a.logout { color: var(--red-alert); font-weight: 600; font-size: 13.5px; }

.container { width: 100%; max-width: 1120px; margin: 0 auto; padding: 32px; }

.alert {
    padding: 12px 16px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 20px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.alert--success { background: var(--green-succ-bg, #e6f4ea); color: var(--green-succ-text, #1a7a3b); }
.alert--error   { background: var(--red-pale); color: var(--red-alert); }

/* ---------- Profile hero card ---------- */
.profile-hero {
    background: linear-gradient(135deg, var(--green-deep), var(--green-mid));
    border-radius: 20px;
    padding: 32px;
    display: flex;
    align-items: center;
    gap: 22px;
    color: #FFFFFF;
    margin-bottom: 22px;
    box-shadow: 0 10px 30px -12px rgba(18, 60, 92, 0.45);
}
.avatar-badge-wrap {
    width: 76px;
    height: 76px;
    flex-shrink: 0;
    position: relative;
}
.avatar-badge {
    width: 76px;
    height: 76px;
    border-radius: 50%;
    background: rgba(255,255,255,0.16);
    border: 2px solid rgba(255,255,255,0.35);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font-display);
    font-size: 28px;
    font-weight: 700;
    letter-spacing: 0.5px;
    overflow: hidden;
}
.avatar-badge img {
    width: 100%; height: 100%; object-fit: cover; border-radius: 50%;
}
.avatar-edit-btn {
    position: absolute; right: -3px; bottom: -3px; z-index: 3;
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--green-mid); border: 3px solid var(--white);
    display: flex; align-items: center; justify-content: center;
    color: #FFFFFF; cursor: pointer; box-shadow: 0 2px 8px rgba(18,60,92,.3);
    transition: background .15s ease; padding: 0;
}
.avatar-edit-btn:hover { background: var(--green-deep); }
.avatar-edit-btn svg { width: 13px; height: 13px; }
.avatar-upload-form input[type="file"] { display: none; }
.avatar-edit-menu {
    display: none; position: absolute; left: 0; top: 84px; z-index: 4;
    background: var(--white); border: 1px solid rgba(18,60,92,.12); border-radius: 10px;
    box-shadow: 0 8px 22px rgba(18,60,92,.25); overflow: hidden; min-width: 150px;
}
.avatar-edit-menu.open { display: block; }
.avatar-edit-menu-item {
    display: block; width: 100%; text-align: left; padding: 10px 14px;
    background: none; border: none; font-size: 13.5px; font-weight: 600;
    color: var(--ink); cursor: pointer; font-family: inherit;
}
.avatar-edit-menu-item:hover,
.avatar-edit-menu-item:focus-visible { background: var(--cream); }
.avatar-edit-menu-item:focus-visible { outline: 2px solid var(--green-mid); outline-offset: -2px; }
.avatar-edit-menu-item-danger { color: var(--red-alert, #C8472D); }
.hero-info h2 {
    font-family: var(--font-display);
    font-size: 24px;
    margin-bottom: 4px;
}
.hero-info .hero-username {
    font-size: 13.5px;
    opacity: 0.85;
    margin-bottom: 10px;
}
.hero-tags { display: flex; gap: 8px; flex-wrap: wrap; }
.hero-tag {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    background: rgba(255,255,255,0.16);
    border: 1px solid rgba(255,255,255,0.25);
}
.hero-tag.status-active-tag { background: rgba(255,255,255,0.18); }
.hero-tag.status-inactive-tag { background: rgba(200,71,45,0.35); border-color: rgba(255,255,255,0.3); }
.hero-tag .dot { width: 7px; height: 7px; border-radius: 50%; background: #7EE8A6; }
.hero-tag.status-inactive-tag .dot { background: var(--salmon-bg, #FFC4B4); }

/* ---------- Section cards ---------- */
.section-header { margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
.section-header h2 { font-family: var(--font-display); color: var(--text-accent); font-size: 18px; }
.section-header svg { width: 18px; height: 18px; color: var(--green-mid); }

.card {
    background: var(--white);
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 22px 24px;
    margin-bottom: 20px;
}

.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 18px; }
.info-item label {
    display: block;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--ink-soft);
    margin-bottom: 6px;
}
.info-item .info-value {
    font-size: 14.5px;
    font-weight: 600;
    color: var(--ink);
}

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
}
.status-badge .dot { width: 7px; height: 7px; border-radius: 50%; }
.status-active   { background: var(--green-pale); color: var(--text-accent); }
.status-active .dot { background: var(--green-mid); }
.status-inactive { background: var(--red-pale); color: var(--red-alert); }
.status-inactive .dot { background: var(--red-alert); }

/* ---------- Password section ---------- */
.pw-toggle-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    cursor: pointer;
    list-style: none;
}
.pw-toggle-row::-webkit-details-marker { display: none; }
.pw-toggle-row .pw-toggle-left { display: flex; align-items: center; gap: 10px; }
.pw-toggle-row .pw-icon-circle {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: var(--mustard-pale);
    display: flex; align-items: center; justify-content: center;
    color: var(--mustard);
    flex-shrink: 0;
}
.pw-toggle-row .pw-icon-circle svg { width: 17px; height: 17px; }
.pw-toggle-row strong { font-size: 14.5px; color: var(--ink); }
.pw-toggle-row span.sub { display: block; font-size: 12px; color: var(--ink-soft); margin-top: 1px; }
.pw-toggle-row .chevron { width: 18px; height: 18px; color: var(--ink-soft); transition: transform .2s ease; }
details[open] .chevron { transform: rotate(180deg); }

.pw-form { margin-top: 18px; padding-top: 18px; border-top: 1px dashed var(--line); max-width: 440px; }

.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.form-grid .full { grid-column: 1 / -1; }
.field-group { margin-bottom: 14px; }
.field-group label { display: block; font-size: 12.5px; font-weight: 600; margin-bottom: 6px; color: var(--ink); }
.field-group input {
    width: 100%;
    padding: 10px 12px;
    border: 1.5px solid var(--line);
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 13.5px;
    background: var(--cream);
    transition: border-color .15s ease, background .15s ease;
}
.field-group input:focus {
    outline: none;
    border-color: var(--green-mid);
    background: var(--white);
}
.field-group .hint { font-size: 11.5px; color: var(--ink-soft); margin-top: 4px; }

.password-wrap { position: relative; }
.password-wrap input { width: 100%; padding-right: 40px !important; }
.password-toggle-btn {
    position: absolute;
    right: 6px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    padding: 6px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--ink-soft);
    border-radius: 8px;
}
.password-toggle-btn:hover { color: var(--ink); background: var(--white); }
.password-toggle-btn svg { width: 18px; height: 18px; }

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    font-family: var(--font-body);
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s ease;
    display: inline-flex;
    align-items: center;
    gap: 7px;
}
.btn-primary { background: linear-gradient(135deg, var(--green-mid), var(--green-deep)); color: #FFFFFF; box-shadow: 0 2px 8px rgba(18,60,92,0.22); }
.btn-primary:hover { filter: brightness(1.08); box-shadow: 0 5px 16px rgba(18,60,92,0.32); transform: translateY(-2px); }
.btn-primary:active { transform: translateY(0); filter: brightness(0.97); }
.btn svg { width: 15px; height: 15px; }

@media (max-width: 640px) {
    .form-grid, .info-grid { grid-template-columns: 1fr; }
    .container { padding: 20px 16px; }
    .topbar { padding: 14px 16px; flex-wrap: wrap; gap: 8px; }
    .profile-hero { flex-direction: column; text-align: center; padding: 26px 20px; }
    .hero-tags { justify-content: center; }
}
</style>
</head>
<body>

<div class="app-shell">
<?php $activeNav = 'my_profile'; include 'includes/hr_sidebar.php'; ?>
<div class="main-area">

<div class="topbar">
    <h1>HRMS &amp; Payroll — My Profile</h1>
    <div class="user-info">
        <span><?= clean($_SESSION['full_name']) ?> (<?= clean(roleLabel($_SESSION['role'])) ?>)</span>
    </div>
</div>

<div class="container">

    <?php // Success/error ngayon ay pinapakita bilang SweetAlert toast
    // (auto-disappear) sa dulo ng page — tingnan ang script bago
    // mag-</body>, hindi na ito namamalagi na static banner dito. ?>

    <!-- Profile hero -->
    <div class="profile-hero">
        <div class="avatar-badge-wrap">
            <div class="avatar-badge">
                <?php if (!empty($my_admin_account['profile_picture'])): ?>
                    <img src="uploads/avatars/<?= clean($my_admin_account['profile_picture']) ?>" alt="Profile picture">
                <?php else: ?>
                    <?= clean($initials ?: 'A') ?>
                <?php endif; ?>
            </div>
            <button type="button" class="avatar-edit-btn" id="avatarEditBtn" title="Edit profile picture">
                <svg viewBox="0 0 20 20" fill="none"><path d="M13.8 3.7l2.5 2.5-8.4 8.4-3.1.6.6-3.1 8.4-8.4z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round" stroke-linecap="round"/><path d="M12 5.5l2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
            </button>
            <div class="avatar-edit-menu" id="avatarEditMenu">
                <button type="button" class="avatar-edit-menu-item" id="avatarChangeBtn">Change Photo</button>
                <?php if (!empty($my_admin_account['profile_picture'])): ?>
                <button type="button" class="avatar-edit-menu-item avatar-edit-menu-item-danger" id="avatarRemoveBtn">Remove Photo</button>
                <?php endif; ?>
            </div>
            <form method="POST" enctype="multipart/form-data" id="avatarUploadForm" class="avatar-upload-form">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="update_profile_picture">
                <input type="file" id="avatarFileInput" name="profile_picture" accept=".jpg,.jpeg,.png,.webp" onchange="document.getElementById('avatarUploadForm').submit()">
            </form>
            <?php if (!empty($my_admin_account['profile_picture'])): ?>
            <form method="POST" id="avatarRemoveForm">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="remove_profile_picture">
            </form>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            var editBtn    = document.getElementById('avatarEditBtn');
            var menu       = document.getElementById('avatarEditMenu');
            var changeBtn  = document.getElementById('avatarChangeBtn');
            var removeBtn  = document.getElementById('avatarRemoveBtn');
            var fileInput  = document.getElementById('avatarFileInput');
            var removeForm = document.getElementById('avatarRemoveForm');
            if (!editBtn || !menu) return;

            editBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                menu.classList.toggle('open');
            });
            document.addEventListener('click', function (e) {
                if (menu.classList.contains('open') && !menu.contains(e.target) && e.target !== editBtn) {
                    menu.classList.remove('open');
                }
            });
            if (changeBtn) {
                changeBtn.addEventListener('click', function () {
                    menu.classList.remove('open');
                    fileInput.click();
                });
            }
            if (removeBtn && removeForm) {
                removeBtn.addEventListener('click', function () {
                    menu.classList.remove('open');
                    Swal.fire({
                        title: 'Remove profile picture?',
                        text: 'Your current profile picture will be removed.',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, remove',
                        cancelButtonText: 'Cancel',
                        confirmButtonColor: '#C8472D'
                    }).then(function (result) {
                        if (result.isConfirmed) removeForm.submit();
                    });
                });
            }
        })();
        </script>
        <div class="hero-info">
            <h2><?= clean($my_admin_account['full_name'] ?? '') ?></h2>
            <div class="hero-username">@<?= clean($my_admin_account['username'] ?? '') ?></div>
            <div class="hero-tags">
                <span class="hero-tag"><span class="dot" style="background:var(--white);"></span> Administrator</span>
                <span class="hero-tag <?= !empty($my_admin_account['is_active']) ? 'status-active-tag' : 'status-inactive-tag' ?>">
                    <span class="dot"></span>
                    <?= !empty($my_admin_account['is_active']) ? 'Active' : 'Inactive' ?>
                </span>
            </div>
        </div>
    </div>

    <!-- Account details -->
    <div class="section-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
        <h2>Account Details</h2>
    </div>
    <div class="card">
        <div class="info-grid">
            <div class="info-item">
                <label>Username</label>
                <div class="info-value"><?= clean($my_admin_account['username'] ?? '') ?></div>
            </div>
            <div class="info-item">
                <label>Full Name</label>
                <div class="info-value"><?= clean($my_admin_account['full_name'] ?? '') ?></div>
            </div>
            <div class="info-item">
                <label>Role</label>
                <div class="info-value">Administrator</div>
            </div>
            <div class="info-item">
                <label>Status</label>
                <div>
                    <span class="status-badge <?= !empty($my_admin_account['is_active']) ? 'status-active' : 'status-inactive' ?>">
                        <span class="dot"></span>
                        <?= !empty($my_admin_account['is_active']) ? 'Active' : 'Inactive' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Security -->
    <div class="section-header">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
        <h2>Security</h2>
    </div>
    <div class="card">
        <details>
            <summary class="pw-toggle-row">
                <div class="pw-toggle-left">
                    <span class="pw-icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </span>
                    <span>
                        <strong>Change / Forgot Password</strong>
                        <span class="sub">Update the password used to sign in to this admin account</span>
                    </span>
                </div>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </summary>
            <form method="POST" class="pw-form">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="change_own_password">
                <div class="field-group">
                    <label>Current Password *</label>
                    <div class="password-wrap">
                        <input type="password" name="current_password" id="myCurrentPassword" required>
                        <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('myCurrentPassword', this)" aria-label="Show/Hide password">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>
                <div class="field-group">
                    <label>New Password *</label>
                    <div class="password-wrap">
                        <input type="password" name="new_password" id="myNewPassword" minlength="6" required>
                        <button type="button" class="password-toggle-btn" onclick="togglePasswordVisibility('myNewPassword', this)" aria-label="Show/Hide password">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                    <div class="hint">At least 6 characters.</div>
                </div>
                <div class="field-group">
                    <label>Confirm New Password *</label>
                    <input type="password" name="confirm_password" minlength="6" required>
                </div>
                <button type="submit" class="btn btn-primary">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>
                    Update Password
                </button>
            </form>
        </details>
    </div>

    <!-- Two-Factor Authentication -->
    <div class="card">
        <details <?= $new_backup_codes_to_show ? 'open' : '' ?>>
            <summary class="pw-toggle-row">
                <div class="pw-toggle-left">
                    <span class="pw-icon-circle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="9" width="16" height="10" rx="1.5"></rect><path d="M8 9V6a4 4 0 018 0v3"></path></svg>
                    </span>
                    <span>
                        <strong>Two-Factor Authentication</strong>
                        <span class="sub">
                            <?php if (!empty($my_admin_account['two_factor_enabled'])): ?>
                                Enabled — codes are sent to <?= clean($my_admin_account['two_factor_email'] ?? '—') ?>
                            <?php else: ?>
                                Not yet set up — you'll be asked to set it up on your next login
                            <?php endif; ?>
                        </span>
                    </span>
                </div>
                <svg class="chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
            </summary>

            <?php if ($new_backup_codes_to_show): ?>
                <div style="background:#2a2410; border:1px solid #d7b23e55; color:#7A5A1E; padding:14px 16px; border-radius:12px; font-size:13px; line-height:1.6; margin:16px 0;">
                    ⚠️ New backup codes generated — your old ones no longer work. Save these now, they won't be shown again:
                    <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:12px; font-family:monospace; font-size:14px;">
                        <?php foreach ($new_backup_codes_to_show as $c): ?>
                            <div style="background:#fff; border-radius:8px; padding:6px 0; text-align:center;"><?= clean($c) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form method="POST" class="pw-form">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="update_2fa_email">
                <div class="field-group">
                    <label>Recovery Email (where login codes are sent) *</label>
                    <input type="email" name="two_factor_email" value="<?= clean($my_admin_account['two_factor_email'] ?? '') ?>" required>
                </div>
                <div class="field-group">
                    <label>Confirm with Current Password *</label>
                    <input type="password" name="current_password_2fa" required>
                </div>
                <button type="submit" class="btn btn-primary">Update Recovery Email</button>
            </form>

            <hr style="border:none; border-top:1px solid var(--line, #e5e9f0); margin:20px 0;">

            <form method="POST" class="pw-form" data-confirm="This will invalidate all of your current backup codes. Continue?" data-confirm-title="Regenerate Backup Codes" data-confirm-danger="1" data-confirm-ok="Yes, regenerate">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="regenerate_backup_codes">
                <div class="field-group">
                    <label>Confirm with Current Password *</label>
                    <input type="password" name="current_password_backup" required>
                </div>
                <button type="submit" class="btn btn-secondary">Regenerate Backup Codes</button>
                <div class="hint" style="margin-top:8px;">Use this if you've lost your backup codes, or used them all up. This immediately invalidates the old set.</div>
            </form>
        </details>
    </div>

</div>

</div>
</div>

<script>
const EYE_OPEN_ICON  = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
const EYE_CLOSED_ICON = '<path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.5 18.5 0 0 1 5.06-5.94M9.9 4.24A10.4 10.4 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    if (!input) return;
    const isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    btn.querySelector('svg').innerHTML = isHidden ? EYE_CLOSED_ICON : EYE_OPEN_ICON;
}
</script>

<?php include 'includes/sweetalert.php'; ?>

<?php if (!empty($success_message)): ?>
<script>
Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
</script>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
<script>
Swal.fire({ icon: 'error', title: <?= json_encode(strip_tags($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
</script>
<?php endif; ?>

</body>
</html>