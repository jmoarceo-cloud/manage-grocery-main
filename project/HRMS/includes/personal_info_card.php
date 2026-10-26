<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ============================================================
// includes/personal_info_card.php — shared "Personal Information"
// edit card. Renders as a single "Edit Profile" button that reveals
// the form (Email, Contact Number, plus the application-form fields:
// Date of Birth, Place of Birth, Sex/Gender, Civil Status, Home
// Address, Highest Educational Attainment, Year Graduated) — kept
// collapsed by default so it doesn't take up space on the page.
//
// Included by BOTH my_profile.php (HR Staff/HR Manager/Supervisor)
// and employee_dashboard.php (Cashier/other rank-and-file employees)
// so every employee gets this, not just HRMS staff. The including
// page must set $__myEmployee (the employees row, associative array)
// and, after calling processPersonalInfoUpdate(), $profile_message /
// $profile_error before including this file.
// ============================================================

if (!isset($__myEmployee) || !$__myEmployee) { return; }

$profile_message = $profile_message ?? '';
$profile_error   = $profile_error   ?? '';

$__piDob = !empty($__myEmployee['birthdate']) ? date('Y-m-d', strtotime($__myEmployee['birthdate'])) : '';
$__piPosted = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_personal_info';
$__piValue = static function ($key, $fallback) use ($__piPosted) {
    return $__piPosted && isset($_POST[$key]) && is_scalar($_POST[$key]) ? (string) $_POST[$key] : (string) $fallback;
};
$__piCountry = strtoupper($__piValue('contact_number_country', 'PH'));
$__piPhone = $__piValue('contact_number', '');
$__piPhoneForForm = $__piPosted
    ? ($__piPhone !== '' ? formatPhoneForStorage($__piCountry, $__piPhone) : null)
    : ($__myEmployee['contact_number'] ?? null);
?>
<div style="background:var(--white,#fff); border:1px solid var(--line,#DCE5EC); border-radius:16px; padding:20px 24px; margin-bottom:20px;">

    <?php if (!empty($profile_message)): ?>
        <script>
        Swal.fire({ icon: 'success', title: <?= json_encode(clean($profile_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
        </script>
    <?php endif; ?>
    <?php if (!empty($profile_error)): ?>
        <script>
        Swal.fire({ icon: 'error', title: <?= json_encode(clean($profile_error)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
        </script>
    <?php endif; ?>

    <div id="piHeader" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
        <div>
            <h3 style="font-family:var(--font-display,inherit); color:var(--text-accent,#123C5C); font-size:16px; margin:0 0 2px 0;">Personal Information</h3>
            <div style="color:var(--ink-soft,#3D4C5C); font-size:12.5px;">Email, contact number, and the details from your job application form.</div>
        </div>
        <button type="button" id="piEditBtn" class="btn" style="background:var(--green-mid,#1978A8); color:var(--white,#fff); white-space:nowrap; display:inline-flex; align-items:center; gap:6px;">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M13.5 3.5l3 3L6 17H3v-3l10.5-10.5z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
            Edit Profile
        </button>
    </div>

    <div id="piFormWrap" style="display:none; margin-top:18px; padding-top:18px; border-top:1px solid var(--line,#DCE5EC);">
        <form method="POST" class="leave-form" id="personalInfoForm">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="update_personal_info">

            <div class="field-group">
                <label>Email *</label>
                <input type="email" name="email" required value="<?= clean($__piValue('email', $__myEmployee['email'] ?? '')) ?>">
            </div>
            <div class="field-group">
                <label>Contact Number</label>
                <?php renderPhoneFieldInputs('contact_number', $__piPhoneForForm); ?>
            </div>
            <div class="field-group">
                <label>Date of Birth</label>
                <input type="date" name="date_of_birth" value="<?= clean($__piValue('date_of_birth', $__piDob)) ?>" max="<?= date('Y-m-d') ?>">
            </div>
            <div class="field-group">
                <label>Place of Birth</label>
                <input type="text" name="place_of_birth" value="<?= clean($__piValue('place_of_birth', $__myEmployee['place_of_birth'] ?? '')) ?>">
            </div>

            <div class="field-group">
                <label>Sex/Gender</label>
                <select name="sex">
                    <option value="">-- Select --</option>
                    <?php foreach (APPLICANT_SEX_OPTIONS as $__opt): ?>
                    <option value="<?= clean($__opt) ?>" <?= $__piValue('sex', $__myEmployee['sex'] ?? '') === $__opt ? 'selected' : '' ?>><?= clean($__opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-group">
                <label>Civil Status</label>
                <select name="civil_status">
                    <option value="">-- Select --</option>
                    <?php foreach (APPLICANT_CIVIL_STATUS_OPTIONS as $__opt): ?>
                    <option value="<?= clean($__opt) ?>" <?= $__piValue('civil_status', $__myEmployee['civil_status'] ?? '') === $__opt ? 'selected' : '' ?>><?= clean($__opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-group full">
                <label>Home Address</label>
                <input type="text" name="home_address" value="<?= clean($__piValue('home_address', $__myEmployee['address'] ?? '')) ?>">
            </div>

            <div class="field-group">
                <label>Highest Educational Attainment</label>
                <select name="highest_educational_attainment">
                    <option value="">-- Select --</option>
                    <?php foreach (APPLICANT_EDUCATION_OPTIONS as $__opt): ?>
                    <option value="<?= clean($__opt) ?>" <?= $__piValue('highest_educational_attainment', $__myEmployee['highest_educational_attainment'] ?? '') === $__opt ? 'selected' : '' ?>><?= clean($__opt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field-group">
                <label>Year Graduated</label>
                <input type="text" name="year_graduated" inputmode="numeric" maxlength="4" placeholder="e.g. <?= date('Y') ?>" value="<?= clean($__piValue('year_graduated', $__myEmployee['year_graduated'] ?? '')) ?>">
            </div>

            <div class="field-group full" style="display:flex; gap:10px;">
                <button type="submit" class="btn">Save Personal Information</button>
                <button type="button" id="piCancelBtn" class="btn" style="background:var(--white,#fff); color:var(--ink-soft,#3D4C5C); border:1px solid var(--line,#DCE5EC);">Cancel</button>
            </div>
        </form>
    </div>
</div>
<script>
(function () {
    var editBtn   = document.getElementById('piEditBtn');
    var cancelBtn = document.getElementById('piCancelBtn');
    var formWrap  = document.getElementById('piFormWrap');
    if (!editBtn || !formWrap) return;

    // Server-rendered with an error/success flash — open it already
    // expanded so the person sees the field(s) that need fixing / just saved.
    var __piHadFlash = <?= (!empty($profile_message) || !empty($profile_error)) ? 'true' : 'false' ?>;

    function showForm() { formWrap.style.display = 'block'; editBtn.style.display = 'none'; }
    function hideForm() { formWrap.style.display = 'none'; editBtn.style.display = 'inline-flex'; }

    editBtn.addEventListener('click', showForm);
    if (cancelBtn) cancelBtn.addEventListener('click', hideForm);
    if (__piHadFlash) showForm();
})();
</script>
<?php renderPhoneFieldScript(); ?>
