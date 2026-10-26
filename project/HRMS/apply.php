<?php
require_once 'includes/config.php';
require_once 'includes/mailer.php';
require_once 'includes/application_form.php';
require_once 'includes/ph_location_data.php';

$success_message = "";
$error_message   = "";

// When opened inside the "View public form" preview popup on
// manage_application_form.php (an iframe), this is just a preview for
// HR/employees — the Back link doesn't make sense nested inside a modal,
// so it's hidden. Real applicants (via index.php) never pass this flag,
// and still get the normal full-page Back navigation.
$embed = isset($_GET['embed']) && $_GET['embed'] == '1';

// Name: letters, spaces, hyphen, apostrophe, period only
function isValidApplicantName($value) {
    return preg_match('/^[\p{L}\s.\'-]+$/u', $value) === 1;
}

// Renders the Region -> Province -> City/Municipality [-> Barangay]
// cascading <select> group used by both "Place of Birth" and
// "Home Address" below. $prefix ("pob" or "addr") becomes the
// id/name prefix for every level, e.g. pob_region, addr_barangay.
// If the form previously failed validation, $old already carries the
// applicant's earlier picks (see $old = $_POST above), so the chain
// is re-rendered already filled in — no JS round trip needed on reload.
function render_ph_cascade($prefix, array $old, $includeBarangay, $barangayRequired) {
    $chain = ph_render_cascade_options($prefix, $old, $includeBarangay);
    ob_start();
    ?>
    <select name="<?= $prefix ?>_region" id="<?= $prefix ?>Region" class="ph-cascade-region" data-prefix="<?= $prefix ?>" required>
        <option value="">-- Region --</option>
        <?php foreach (ph_regions() as $r): ?>
            <option value="<?= clean($r['code']) ?>" <?= ($chain['region'] === $r['code']) ? 'selected' : '' ?>><?= clean($r['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="<?= $prefix ?>_province" id="<?= $prefix ?>Province" class="ph-cascade-province" data-prefix="<?= $prefix ?>" required <?= empty($chain['provinces']) ? 'disabled' : '' ?>>
        <option value="">-- Province --</option>
        <?php foreach ($chain['provinces'] as $p): ?>
            <option value="<?= clean($p['code']) ?>" <?= ($chain['province'] === $p['code']) ? 'selected' : '' ?>><?= clean($p['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="<?= $prefix ?>_city" id="<?= $prefix ?>City" class="ph-cascade-city" data-prefix="<?= $prefix ?>" required <?= empty($chain['cities']) ? 'disabled' : '' ?>>
        <option value="">-- City/Municipality --</option>
        <?php foreach ($chain['cities'] as $c): ?>
            <option value="<?= clean($c['code']) ?>" <?= ($chain['city'] === $c['code']) ? 'selected' : '' ?>><?= clean($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($includeBarangay): ?>
    <select name="<?= $prefix ?>_barangay" id="<?= $prefix ?>Barangay" class="ph-cascade-barangay" data-prefix="<?= $prefix ?>" <?= $barangayRequired ? 'required' : '' ?> <?= empty($chain['barangays']) ? 'disabled' : '' ?>>
        <option value="">-- Barangay --</option>
        <?php foreach ($chain['barangays'] as $b): ?>
            <option value="<?= clean($b['code']) ?>" <?= ((string)$chain['barangay'] === (string)$b['code']) ? 'selected' : '' ?>><?= clean($b['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php
    return ob_get_clean();
}

// Reference Contact Number validation now goes through the shared
// country-aware validatePhoneNumber() in includes/phone_countries.php.

define('MAX_RESUME_SIZE', 5 * 1024 * 1024); // 5MB
$ALLOWED_RESUME_EXT = ['pdf'];
define('APPLICANT_PRIVACY_NOTICE_VERSION', '2026-09-28-v1');
$applicantRetentionDays = filter_var(
    env('APPLICANT_REJECTED_RETENTION_DAYS', '365'),
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1, 'max_range' => 3650]]
);
if ($applicantRetentionDays === false) {
    error_log('Invalid APPLICANT_REJECTED_RETENTION_DAYS; the retention job will refuse to run.');
}

// Minimum/maximum reference rows the applicant can submit — kept in sync
// with the "+ Add another reference" cap in the JS below.
define('MIN_CHARACTER_REFERENCES', 2);
define('MAX_CHARACTER_REFERENCES', 3);

$custom_fields = getActiveFormFields($pdo);
$old = $_POST ?? [];

// The dynamic custom-field system (managed from manage_application_form.php)
// still powers "Position Applied For", "Contact Number", and "Cover Note" —
// plus anything else HR adds later. Split them out here so each one can be
// placed under the right section heading below, instead of dumping them
// all in one generic block like before.
$cf_by_key       = [];
foreach ($custom_fields as $cf) {
    $cf_by_key[$cf['field_key']] = $cf;
}
$cf_position     = $cf_by_key['position_applied'] ?? null;
$cf_contact      = $cf_by_key['contact_number'] ?? null;
// Cover Note is intentionally excluded from "leftover" too, since it's
// no longer shown anywhere on the form (see $fieldsToValidate below,
// which also excludes it from validation).
$cf_leftover     = array_filter($custom_fields, function ($cf) {
    return !in_array($cf['field_key'], ['position_applied', 'contact_number', 'cover_note'], true);
});

$stmt = $pdo->prepare("SELECT is_active FROM application_form_fields WHERE field_key = 'position_applied'");
$stmt->execute();
$position_field_active = (bool) $stmt->fetchColumn();
$hiring_closed = !$position_field_active;

if (!$hiring_closed && !$embed && $_SERVER['REQUEST_METHOD'] == 'POST') {

    requireCSRF();

    $first_name = trim($_POST['first_name'] ?? '');
    $last_name  = trim($_POST['last_name'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $privacyConsent = ($_POST['privacy_consent'] ?? '') === '1';
    $privacyNoticeVersion = trim((string) ($_POST['privacy_notice_version'] ?? ''));

    $date_of_birth   = trim($_POST['date_of_birth'] ?? '');
    $place_of_birth  = trim($_POST['place_of_birth'] ?? '');
    $sex             = trim($_POST['sex'] ?? '');
    $civil_status    = trim($_POST['civil_status'] ?? '');
    $home_address    = trim($_POST['home_address'] ?? '');

    $employment_type = trim($_POST['employment_type'] ?? '');
    $education       = trim($_POST['highest_educational_attainment'] ?? '');
    $year_graduated  = trim($_POST['year_graduated'] ?? '');

    $ref_names     = $_POST['reference_name'] ?? [];
    $ref_companies = $_POST['reference_company'] ?? [];
    $ref_positions = $_POST['reference_position'] ?? [];
    $ref_contacts  = $_POST['reference_contact'] ?? [];
    $ref_contact_countries = $_POST['reference_contact_country'] ?? [];

    // Cover Note is no longer rendered on the form (removed from Additional
    // Information), so it must not be validated/required either — otherwise
    // an admin-side "required" flag on a hidden field would silently block
    // every submission.
    $fieldsToValidate = array_filter($custom_fields, function ($cf) {
        return $cf['field_key'] !== 'cover_note';
    });
    $fieldCheck = validateFormFields($fieldsToValidate, $_POST);

    // Build/validate character references: keep only rows where at least
    // one field was filled in, so a blank optional 3rd row doesn't trip
    // "required" errors, then require the fields to all be present on
    // whichever rows remain.
    $references = [];
    $refCount = max(count($ref_names), count($ref_companies), count($ref_positions), count($ref_contacts));
    for ($i = 0; $i < $refCount; $i++) {
        $rName    = trim($ref_names[$i] ?? '');
        $rCompany = trim($ref_companies[$i] ?? '');
        $rPos     = trim($ref_positions[$i] ?? '');
        $rContact = trim($ref_contacts[$i] ?? '');
        $rCountry = trim($ref_contact_countries[$i] ?? 'PH');
        if ($rName === '' && $rCompany === '' && $rPos === '' && $rContact === '') {
            continue; // fully blank optional row — skip silently
        }
        $references[] = ['name' => $rName, 'company' => $rCompany, 'position' => $rPos, 'contact_number' => $rContact, 'contact_country' => $rCountry];
    }

    if (empty($first_name) || empty($last_name) || empty($email)) {
        $error_message = "Please complete all required fields.";
    } elseif (!isValidApplicantName($first_name) || !isValidApplicantName($last_name)) {
        $error_message = "First and Last Name must contain letters only.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please enter a valid email address. Your application updates will be sent here.";
    } elseif (!$privacyConsent || !hash_equals(APPLICANT_PRIVACY_NOTICE_VERSION, $privacyNoticeVersion)) {
        $error_message = "Please review and acknowledge the applicant privacy notice before submitting.";
    } elseif (empty($date_of_birth) || empty($place_of_birth) || empty($sex) || empty($civil_status) || empty($home_address)) {
        $error_message = "Please complete all required fields under Personal Information.";
    } elseif (calculateAgeFromDOB($date_of_birth) === null) {
        $error_message = "Please enter a valid Date of Birth.";
    } elseif (calculateAgeFromDOB($date_of_birth) < APPLICANT_MIN_AGE) {
        $error_message = "Applicants must be at least " . APPLICANT_MIN_AGE . " years old.";
    } elseif (!in_array($sex, APPLICANT_SEX_OPTIONS, true)) {
        $error_message = "Please select a valid Sex/Gender.";
    } elseif (!in_array($civil_status, APPLICANT_CIVIL_STATUS_OPTIONS, true)) {
        $error_message = "Please select a valid Civil Status.";
    } elseif (empty($employment_type) || !in_array($employment_type, APPLICANT_EMPLOYMENT_TYPE_OPTIONS, true)) {
        $error_message = "Please select a valid Employment Type under Position Details.";
    } elseif (empty($education) || !in_array($education, APPLICANT_EDUCATION_OPTIONS, true)) {
        $error_message = "Please select your Highest Educational Attainment.";
    } elseif (empty($year_graduated) || !preg_match('/^\d{4}$/', $year_graduated) || (int) $year_graduated < 1950 || (int) $year_graduated > (int) date('Y')) {
        $error_message = "Please enter a valid Year Graduated (e.g. " . date('Y') . ").";
    } elseif (count($references) < MIN_CHARACTER_REFERENCES) {
        $error_message = "Please provide at least " . MIN_CHARACTER_REFERENCES . " Character References, with all their fields filled in.";
    } elseif (!empty($fieldCheck['errors'])) {
        $error_message = $fieldCheck['errors'][0];
    } elseif (!isset($_FILES['resume']) || $_FILES['resume']['error'] == UPLOAD_ERR_NO_FILE) {
        $error_message = "A resume attachment is required.";
    } elseif ($_FILES['resume']['error'] !== UPLOAD_ERR_OK) {
        $error_message = "There was a problem uploading your resume. Please try again.";
    } elseif ($_FILES['resume']['size'] > MAX_RESUME_SIZE) {
        $error_message = "Your resume file must not exceed 5MB.";
    } else {

        // Every reference row must be fully filled in (no half-empty rows).
        $incompleteRef = false;
        foreach ($references as $ref) {
            if ($ref['name'] === '' || $ref['company'] === '' || $ref['position'] === '' || $ref['contact_number'] === '') {
                $incompleteRef = true;
                break;
            }
        }

        // Every reference row's Contact Number must be digits only and
        // exactly the right length for its selected country.
        $invalidRefContact = false;
        foreach ($references as $ref) {
            if (validatePhoneNumber($ref['contact_country'], $ref['contact_number']) !== null) {
                $invalidRefContact = true;
                break;
            }
        }

        if ($incompleteRef) {
            $error_message = "Please complete Name, Company, Position, and Contact Number for each Character Reference.";
        } elseif ($invalidRefContact) {
            $error_message = "Each Character Reference's Contact Number must contain numbers only and be exactly the right number of digits for the selected country.";
        } else {

        // Store references with the country code baked into the saved
        // number (e.g. "+63 9171234567") — same format used everywhere
        // else in the system.
        foreach ($references as &$__ref) {
            $__ref['contact_number'] = formatPhoneForStorage($__ref['contact_country'], $__ref['contact_number']);
            unset($__ref['contact_country']);
        }
        unset($__ref);

        $ext = strtolower(pathinfo($_FILES['resume']['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $ALLOWED_RESUME_EXT)) {
            $error_message = "Only PDF resume files are accepted.";
        } elseif (!isValidPdfSignature($_FILES['resume']['tmp_name'])) {
            // Item 13 — the extension says .pdf but the file's actual
            // content doesn't start with a PDF signature. Reject before
            // it ever touches the filesystem.
            $error_message = "That file doesn't look like a valid PDF. Please upload an actual PDF resume.";
        } else {

            try {
                $stored_filename = 'resume_' . bin2hex(random_bytes(12)) . '.' . $ext;
                $upload_dir      = __DIR__ . '/uploads/resumes/';
                $destination     = $upload_dir . $stored_filename;

                // The uploads/resumes folder isn't tracked by git and won't
                // exist on a fresh clone/deploy, which made move_uploaded_file()
                // fail silently (no such file or directory). Create it on the
                // fly if missing, before attempting the move.
                if (!is_dir($upload_dir)) {
                    @mkdir($upload_dir, 0755, true);
                }

                if (!is_dir($upload_dir) || !is_writable($upload_dir)) {
                    $error_message = "The server's resume upload folder is missing or not writable. Please contact the administrator.";
                } elseif (!move_uploaded_file($_FILES['resume']['tmp_name'], $destination)) {
                    $error_message = "The resume could not be saved. Please try again.";
                } else {

                    $mapped = $fieldCheck['mapped'];
                    $customResponsesJson = !empty($fieldCheck['custom']) ? json_encode($fieldCheck['custom'], JSON_UNESCAPED_UNICODE) : null;
                    $referencesJson = json_encode($references, JSON_UNESCAPED_UNICODE);

                    $stmt = $pdo->prepare("
                        INSERT INTO job_applicants
                        (first_name, last_name, email, contact_number, position_applied, resume_filename, resume_original_name, cover_note, custom_responses, status,
                         date_of_birth, place_of_birth, sex, civil_status, home_address,
                         employment_type, highest_educational_attainment, year_graduated, character_references,
                         privacy_consent, privacy_notice_version, privacy_consent_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'for_resume', ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
                    ");
                    $stmt->execute([
                        $first_name,
                        $last_name,
                        $email,
                        $mapped['contact_number'] ?? null,
                        $mapped['position_applied'] ?? null,
                        $stored_filename,
                        $_FILES['resume']['name'],
                        $mapped['cover_note'] ?? null,
                        $customResponsesJson,
                        $date_of_birth,
                        $place_of_birth,
                        $sex,
                        $civil_status,
                        $home_address,
                        $employment_type,
                        $education,
                        $year_graduated,
                        $referencesJson,
                        APPLICANT_PRIVACY_NOTICE_VERSION,
                        date('Y-m-d H:i:s'),
                    ]);

                
                    sendApplicationReceivedEmail($email, $first_name . ' ' . $last_name, $mapped['position_applied'] ?? '');

                    $success_message = "Thank you for your application, " . $first_name . "! We have received your resume. Please check your email (" . htmlspecialchars($email) . ") for updates.";
                    $old = [];
                    $references = [];
                }
            } catch (PDOException $e) {
                error_log("Apply submission error: " . $e->getMessage());
                $error_message = "An error occurred. Please try again later.";
            }
        }
        }
    }
}

// Redisplay whatever the applicant typed if the form bounced back with an
// error — always at least MIN_CHARACTER_REFERENCES rows so the layout
// doesn't collapse.
if (!isset($references)) {
    $references = [];
}
while (count($references) < MIN_CHARACTER_REFERENCES) {
    $references[] = ['name' => '', 'company' => '', 'position' => '', 'contact_number' => ''];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#1d4ed8" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#070914" media="(prefers-color-scheme: dark)">
<title>Job Application Form — HCI GroceryOne Careers</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:               #f8fafc;
    --surface:          #ffffff;
    --surface-soft:     #f1f5f9;
    --text-heading:     #0f172a;
    --text-body:        #334155;
    --text-muted:       #64748b;
    --border:           #e2e8f0;
    --border-strong:    #cbd5e1;
    --accent:           #1d4ed8;
    --accent-soft:      #eff6ff;
    --green:            #10b981;
    --green-soft:       #ecfdf5;
    --red:              #e11d48;
    --red-soft:         #fff1f2;
    --grid-line:        rgba(37, 99, 235, 0.05);
    --shadow-sm:        0 1px 3px rgba(15, 23, 42, .06), 0 1px 2px rgba(15, 23, 42, .04);
    --shadow-md:        0 8px 20px rgba(15, 23, 42, .06), 0 2px 6px rgba(15, 23, 42, .03);
    --shadow-lg:        0 16px 36px rgba(15, 23, 42, .08), 0 2px 6px rgba(15, 23, 42, .04);
    --ring:             0 0 0 4px rgba(29, 78, 216, .12);
    --ease:             cubic-bezier(.16, 1, .3, 1);
    --font-display:     'Fraunces', Georgia, serif;
    --font-body:        'Plus Jakarta Sans', sans-serif;

    /* SVG arrow data-URI for selects — light theme */
    --select-arrow: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 20 20' fill='none'><path d='M5 7.5l5 5 5-5' stroke='%2364748b' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/></svg>");
}

html[data-theme="dark"] {
    --bg:               #070914;
    --surface:          #111a3c;
    --surface-soft:     #0a1024;
    --text-heading:     #f4f7ff;
    --text-body:        #cbd5e1;
    --text-muted:       #9ca8c7;
    --border:           rgba(148, 163, 255, .16);
    --border-strong:    rgba(148, 163, 255, .28);
    --accent:           #4d8dff;
    --accent-soft:      rgba(77, 141, 255, .12);
    --green:            #34d399;
    --green-soft:       rgba(52, 211, 153, .12);
    --red:              #f43f5e;
    --red-soft:         rgba(244, 63, 94, .12);
    --grid-line:        rgba(128, 151, 255, .08);
    --shadow-sm:        0 1px 3px rgba(0, 0, 0, .3);
    --shadow-md:        0 8px 20px rgba(0, 0, 0, .35);
    --shadow-lg:        0 16px 36px rgba(0, 0, 0, .45);
    --ring:             0 0 0 4px rgba(77, 141, 255, .18);

    /* SVG arrow data-URI — dark theme */
    --select-arrow: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 20 20' fill='none'><path d='M5 7.5l5 5 5-5' stroke='%239ca8c7' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'/></svg>");
}

html {
    scroll-behavior: smooth;
    background: var(--bg);
    color-scheme: light;
}
html[data-theme="dark"] { color-scheme: dark; }

body {
    min-height: 100vh;
    min-height: 100dvh;
    font-family: var(--font-body);
    color: var(--text-body);
    line-height: 1.55;
    background-color: var(--bg);
    background-image:
        linear-gradient(var(--grid-line) 1px, transparent 1px),
        linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 32px 32px;
    background-attachment: fixed;
    transition: background-color .3s ease, color .3s ease;
    -webkit-font-smoothing: antialiased;
    -webkit-tap-highlight-color: transparent;
    overflow-x: hidden;
}
a { text-decoration: none; color: inherit; }

.wrap {
    max-width: 780px;
    margin: 0 auto;
    padding: 32px 20px 56px;
    padding-left: max(20px, env(safe-area-inset-left));
    padding-right: max(20px, env(safe-area-inset-right));
    padding-bottom: max(56px, env(safe-area-inset-bottom));
    padding-top: max(32px, env(safe-area-inset-top));
}

/* ============================================================
   TOP NAV — Back link + theme toggle
   ============================================================ */
.top-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 22px;
}

.back-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 40px;
    padding: 0 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    color: var(--text-body);
    font-size: .8125rem;
    font-weight: 600;
    transition: .2s var(--ease);
    white-space: nowrap;
}
.back-link:hover {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: var(--accent);
    transform: translateY(-1px);
}
.back-link svg { width: 15px; height: 15px; flex-shrink: 0; }

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
}
.theme-toggle:hover {
    color: var(--accent);
    border-color: var(--accent);
    transform: translateY(-1px);
}
.theme-toggle svg { width: 16px; height: 16px; color: var(--accent); flex-shrink: 0; }
.theme-toggle .sun-icon { display: none; }
html[data-theme="dark"] .theme-toggle .moon-icon { display: none; }
html[data-theme="dark"] .theme-toggle .sun-icon { display: block; }

/* ============================================================
   BRAND ROW
   ============================================================ */
.brand-row { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; }
.brand-mark {
    width: 40px;
    height: 40px;
    border-radius: 12px;
    flex-shrink: 0;
    background: linear-gradient(145deg, var(--accent), #0284c7);
    color: #fff;
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 6px 16px color-mix(in srgb, var(--accent) 26%, transparent);
}
.brand-name {
    font-family: var(--font-display);
    font-weight: 700;
    font-size: 15px;
    color: var(--text-heading);
    letter-spacing: .2px;
}
.brand-name span {
    display: block;
    font-family: var(--font-body);
    font-weight: 500;
    font-size: 11.5px;
    color: var(--text-muted);
    letter-spacing: .4px;
    text-transform: uppercase;
    margin-top: 1px;
}

/* ============================================================
   CARD
   ============================================================ */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 40px 40px 36px;
    position: relative;
    overflow: hidden;
    box-shadow: var(--shadow-lg);
}
.card::before {
    content: "";
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 6px;
    background: linear-gradient(90deg, var(--accent), #0284c7 60%, #4d8dff);
}

h1 {
    font-family: var(--font-display);
    color: var(--text-heading);
    font-size: 28px;
    font-weight: 800;
    margin-bottom: 6px;
    letter-spacing: -.02em;
    line-height: 1.15;
}
.sub {
    color: var(--text-muted);
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 28px;
    max-width: 52ch;
}

.alert {
    padding: 13px 16px;
    border-radius: 12px;
    font-size: 13.5px;
    font-weight: 500;
    margin-bottom: 22px;
    border-left: 4px solid transparent;
    line-height: 1.5;
}
.alert--success { background: var(--green-soft); color: var(--green); border-left-color: var(--green); }
.alert--error { background: var(--red-soft); color: var(--red); border-left-color: var(--red); }
.alert--info { background: var(--accent-soft); color: var(--accent); border-left-color: var(--accent); }

/* ============================================================
   SECTIONS
   ============================================================ */
.section { margin-bottom: 30px; padding-bottom: 26px; border-bottom: 1px solid var(--border); }
.section:last-of-type { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
.section.applicant-privacy-notice {
    margin-bottom: 0;
    padding: 18px;
    border: 1px solid color-mix(in srgb, var(--accent) 25%, var(--border));
    border-radius: 14px;
    background: var(--accent-soft);
}
.applicant-privacy-notice .field-group label {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
    line-height: 1.5;
}
.applicant-privacy-notice input[type="checkbox"] {
    width: 18px;
    height: 18px;
    flex: 0 0 18px;
    margin-top: 2px;
    accent-color: var(--accent);
}
.applicant-privacy-notice .hint { line-height: 1.65; }
.applicant-privacy-notice a { color: var(--accent); font-weight: 700; text-decoration: underline; text-underline-offset: 2px; }
.section-head { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; }
.section-num {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 9px;
    background: var(--accent);
    color: #fff;
    font-size: 12.5px;
    font-weight: 700;
    font-family: var(--font-display);
    flex-shrink: 0;
    box-shadow: 0 3px 8px color-mix(in srgb, var(--accent) 24%, transparent);
}
.section-head h2 {
    font-family: var(--font-display);
    font-size: 16px;
    font-weight: 700;
    color: var(--text-heading);
    letter-spacing: -.1px;
}
.section-head .section-note {
    font-size: 11px;
    color: var(--accent);
    font-weight: 700;
    letter-spacing: .3px;
    text-transform: uppercase;
    background: var(--accent-soft);
    padding: 3px 9px;
    border-radius: 999px;
    margin-left: auto;
    border: 1px solid color-mix(in srgb, var(--accent) 22%, var(--border));
}

/* ============================================================
   FORM FIELDS
   ============================================================ */
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-grid .full { grid-column: 1 / -1; }
.field-group { min-width: 0; }
.field-group label {
    display: block;
    font-size: 12.5px;
    font-weight: 600;
    margin-bottom: 6px;
    color: var(--text-heading);
}

/* --- Text inputs, textareas --- */
.field-group input:not([type="file"]):not([type="checkbox"]):not([type="radio"]),
.field-group textarea {
    width: 100%;
    height: 44px;
    padding: 0 13px;
    border: 1.5px solid var(--border);
    border-radius: 11px;
    font-family: var(--font-body);
    font-size: 16px;
    line-height: 1.4;
    background-color: var(--surface);
    color: var(--text-heading);
    transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
}
.field-group textarea {
    height: auto;
    min-height: 84px;
    padding: 11px 13px;
    resize: vertical;
}
.field-group input::placeholder,
.field-group textarea::placeholder { color: var(--text-muted); }
.field-group input:hover,
.field-group textarea:hover { border-color: var(--border-strong); }
.field-group input:focus,
.field-group textarea:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: var(--ring);
}
.field-group input:disabled {
    background-color: var(--surface-soft);
    color: var(--text-muted);
    -webkit-text-fill-color: var(--text-muted);
    cursor: not-allowed;
    opacity: 1;
}
.field-group .hint { font-size: 11.5px; color: var(--text-muted); margin-top: 5px; }

/* --- Selects (single source of truth for both normal + cascade) --- */
.field-group select,
.ph-cascade select {
    display: block;
    width: 100%;
    height: 44px;
    padding: 0 34px 0 13px;
    border: 1.5px solid var(--border);
    border-radius: 11px;
    font-family: var(--font-body);
    font-size: 16px;
    font-weight: 500;
    line-height: 1.4;
    color: var(--text-heading);
    background-color: var(--surface);
    background-image: var(--select-arrow);
    background-repeat: no-repeat;
    background-position: right 12px center;
    background-size: 14px 14px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
    cursor: pointer;
    text-overflow: ellipsis;
    transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
}
.field-group select:hover,
.ph-cascade select:hover { border-color: var(--border-strong); }
.field-group select:focus,
.ph-cascade select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: var(--ring);
}
.field-group select option,
.ph-cascade select option {
    background: var(--surface);
    color: var(--text-heading);
}

/* Disabled selects — force our own styling so the browser's native
   disabled look never takes over (that was the dark-mode bug where
   the placeholder text turned into tiny stacked letters). */
.field-group select:disabled,
.ph-cascade select:disabled {
    background-color: var(--surface-soft);
    color: var(--text-muted);
    -webkit-text-fill-color: var(--text-muted);
    border-color: var(--border);
    opacity: 1;
    cursor: not-allowed;
    box-shadow: none;
}

/* --- File input --- */
.field-group input[type="file"] {
    width: 100%;
    padding: 10px;
    font-size: 13px;
    font-family: var(--font-body);
    border: 1.5px dashed var(--border);
    border-radius: 11px;
    background-color: var(--surface-soft);
    color: var(--text-heading);
    cursor: pointer;
}
.field-group input[type="file"]::file-selector-button {
    padding: 7px 14px;
    margin-right: 12px;
    border: none;
    border-radius: 8px;
    background: var(--accent-soft);
    color: var(--accent);
    font-weight: 600;
    font-size: 12.5px;
    font-family: var(--font-body);
    cursor: pointer;
    transition: background .15s ease;
}
.field-group input[type="file"]::file-selector-button:hover {
    background: color-mix(in srgb, var(--accent) 18%, transparent);
}

/* --- Date input --- */
.field-group input[type="date"] {
    padding: 0 13px;
    color-scheme: light;
}
html[data-theme="dark"] .field-group input[type="date"] {
    color-scheme: dark;
}

/* --- PH cascade layout --- */
.ph-cascade {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 10px;
}
#addrCascade { margin-bottom: 10px; }

.checkbox-group { display: flex; flex-wrap: wrap; gap: 10px 16px; padding: 8px 0; }
.checkbox-choice { display: flex; align-items: center; gap: 6px; font-size: 13px; font-weight: 500; }

/* ============================================================
   CHARACTER REFERENCES
   ============================================================ */
.ref-row {
    border: 1.5px solid var(--border);
    border-radius: 14px;
    padding: 18px;
    margin-bottom: 12px;
    position: relative;
    background: var(--surface-soft);
}
.ref-row .ref-title {
    font-size: 12.5px;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 8px;
    flex-wrap: wrap;
}
.ref-remove {
    background: none;
    border: none;
    color: var(--red);
    font-size: 12px;
    font-weight: 600;
    font-family: var(--font-body);
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 6px;
    transition: background .15s ease;
}
.ref-remove:hover { background: var(--red-soft); }
.add-ref-btn {
    width: 100%;
    padding: 11px;
    border: 1.5px dashed color-mix(in srgb, var(--accent) 40%, var(--border));
    border-radius: 11px;
    background: var(--surface);
    color: var(--accent);
    font-weight: 600;
    font-size: 13px;
    font-family: var(--font-body);
    cursor: pointer;
    transition: background .15s ease, border-color .15s ease;
}
.add-ref-btn:hover {
    background: var(--accent-soft);
    border-color: var(--accent);
}
.add-ref-btn:disabled { opacity: .45; cursor: not-allowed; }

/* ============================================================
   SUBMIT BUTTON
   ============================================================ */
.btn {
    padding: 14px 20px;
    border: none;
    border-radius: 12px;
    font-family: var(--font-body);
    font-size: 14.5px;
    font-weight: 700;
    letter-spacing: .2px;
    cursor: pointer;
    background: var(--accent);
    color: #FFFFFF;
    width: 100%;
    margin-top: 24px;
    box-shadow: 0 8px 20px color-mix(in srgb, var(--accent) 32%, transparent);
    transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
}
.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 26px color-mix(in srgb, var(--accent) 40%, transparent);
}
.btn:active { transform: translateY(0); }
.btn:disabled { cursor: not-allowed; opacity: .6; transform: none; box-shadow: none; }

.login-link { text-align: center; margin-top: 20px; font-size: 13px; color: var(--text-muted); }
.login-link a { color: var(--accent); font-weight: 600; }
.login-link a:hover { text-decoration: underline; }

.section-divider {
    margin: 0 0 20px;
    padding-bottom: 18px;
    border-bottom: 1px dashed var(--border);
}

/* ============================================================
   OVERFLOW SAFETY — long emails, filenames, unbroken words
   ============================================================ */
.alert, .hint, .sub, .field-group label, .brand-name, .ref-title, .login-link {
    overflow-wrap: anywhere;
    word-break: break-word;
}
input, textarea, select { min-width: 0; }

/* Phone country + number row: fluid by default, stacked on tiny screens */
.phone-field-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}
.phone-field-row .phone-country-select {
    flex: 0 1 auto;
    min-width: 0;
    max-width: 100%;
}
.phone-field-row .phone-number-input {
    flex: 1 1 140px;
    min-width: 0;
}

/* ============================================================
   RESPONSIVE — collapse the 2-col grid a little earlier so small
   tablets and landscape phones read comfortably.
   ============================================================ */
@media (max-width: 640px) {
    .form-grid { grid-template-columns: 1fr; }
}

/* ============================================================
   SMALL PHONES
   ============================================================ */
@media (max-width: 560px) {
    .card { padding: 28px 22px 26px; border-radius: 18px; }
    .theme-toggle span { display: none; }
    .theme-toggle { width: 40px; padding: 0; justify-content: center; }
}

@media (max-width: 420px) {
    .wrap {
        padding: 22px 14px 40px;
        padding-left: max(14px, env(safe-area-inset-left));
        padding-right: max(14px, env(safe-area-inset-right));
        padding-bottom: max(40px, env(safe-area-inset-bottom));
        padding-top: max(22px, env(safe-area-inset-top));
    }
    .card { padding: 24px 16px 22px; border-radius: 16px; }
    h1 { font-size: 22px; }
    .sub { font-size: 13px; margin-bottom: 22px; }

    .top-nav { gap: 8px; margin-bottom: 18px; }
    .back-link { height: 38px; padding: 0 11px; font-size: 12px; }
    .theme-toggle { height: 38px; width: 38px; padding: 0; justify-content: center; }
    .theme-toggle span { display: none; }

    .section-head { flex-wrap: wrap; gap: 8px; }
    .section-head .section-note { margin-left: 0; }

    /* Stack country + phone number instead of letting them fight */
    .phone-field-row { flex-direction: column; }
    .phone-field-row .phone-country-select,
    .phone-field-row .phone-number-input {
        flex: 1 1 auto;
        width: 100%;
    }

    .ref-row { padding: 14px; }
    .btn { font-size: 14px; padding: 13px 18px; }
}

/* ============================================================
   ULTRA-NARROW (Galaxy Fold ~280px and older 320px phones)
   ============================================================ */
@media (max-width: 340px) {
    .wrap {
        padding: 16px 10px 32px;
        padding-left: max(10px, env(safe-area-inset-left));
        padding-right: max(10px, env(safe-area-inset-right));
        padding-bottom: max(32px, env(safe-area-inset-bottom));
        padding-top: max(16px, env(safe-area-inset-top));
    }
    .card { padding: 20px 12px; }
    .ph-cascade { grid-template-columns: 1fr; }
    .section-num { width: 24px; height: 24px; font-size: 11px; border-radius: 7px; }
    .section-head h2 { font-size: 14px; }
    .brand-mark { width: 34px; height: 34px; font-size: 13px; }
    .brand-name { font-size: 13.5px; }
    .brand-name span { font-size: 10.5px; }
    .btn { font-size: 13px; padding: 12px 14px; }
    .add-ref-btn { font-size: 12px; padding: 10px; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
    html { scroll-behavior: auto; }
}
</style>
<link rel="stylesheet" href="../theme-toggle.css?v=4">
<script src="../theme-sync.js?v=3" defer></script>
</head>
<body>
<div class="wrap">

    <!-- ========== TOP NAV: Back link + Theme toggle ========== -->
    <div class="top-nav">
        <?php if (!$embed): ?>
            <?php if (!empty($success_message)): ?>
            <a href="index.php" class="back-link">
                <svg viewBox="0 0 20 20" fill="none"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span>Back to Home</span>
            </a>
            <?php else: ?>
            <a href="index.php" class="back-link" id="smartBackLink" onclick="return smartGoBack(event);">
                <svg viewBox="0 0 20 20" fill="none"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <span id="smartBackLabel">Back to Home</span>
            </a>
            <?php endif; ?>
        <?php else: ?>
            <div></div>
        <?php endif; ?>

        <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
            <svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M20.4 15.4A8.5 8.5 0 0 1 8.6 3.6 9 9 0 1 0 20.4 15.4Z" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4" stroke-linecap="round"/>
            </svg>
            <span>Dark</span>
        </button>
    </div>

    <!-- ========== BRAND ========== -->
    <div class="brand-row">
        <div class="brand-mark">MG</div>
        <div class="brand-name">HCI GroceryOne<span>Careers · Applicant Portal</span></div>
    </div>

    <div class="card">
        <h1>Job Application Form</h1>
        <p class="sub">Fill out the form below and attach your resume. We'll send application updates to your email.</p>

        <?php if ($embed): ?>
        <div class="alert alert--info">
            👁️ Preview only — this is how applicants will see the form. Submitting is disabled here.
        </div>
        <?php endif; ?>

        <?php if ($hiring_closed): ?>
            <div class="alert alert--error">
                We currently don't have any open positions available. Please check back again later or follow our social media pages for future updates.
            </div>
            <div class="login-link"><a href="index.php" onclick="return smartGoBack(event);">Back Home</a></div>
        <?php else: ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert--success"><?= clean($success_message) ?></div>
        <?php endif; ?>
        <?php if (!empty($error_message)): ?>
            <div class="alert alert--error"><?= clean($error_message) ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="applyForm">
            <?php csrfField(); ?>

            <!-- ============= 1. PERSONAL INFORMATION ============= -->
            <div class="section">
                <div class="section-head"><span class="section-num">1</span><h2>Personal Information</h2></div>

                <div class="form-grid">
                    <div class="field-group"><label>First Name *</label><input type="text" name="first_name" value="<?= clean($old['first_name'] ?? '') ?>" pattern="[\p{L}\s.'-]+" title="Letters only" required></div>
                    <div class="field-group"><label>Last Name *</label><input type="text" name="last_name" value="<?= clean($old['last_name'] ?? '') ?>" pattern="[\p{L}\s.'-]+" title="Letters only" required></div>

                    <div class="field-group">
                        <label>Date of Birth *</label>
                        <input type="date" name="date_of_birth" id="dobInput" value="<?= clean($old['date_of_birth'] ?? '') ?>" max="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="field-group">
                        <label>Age</label>
                        <input type="text" id="ageDisplay" value="" disabled>
                    </div>

                    <div class="field-group full">
                        <label>Place of Birth *</label>
                        <div class="ph-cascade" id="pobCascade">
                            <?= render_ph_cascade('pob', $old, true, false) ?>
                        </div>
                        <input type="hidden" name="place_of_birth" id="pobComposed" value="<?= clean($old['place_of_birth'] ?? '') ?>">
                        <div class="hint">Region, then Province, then City/Municipality. Barangay is optional here.</div>
                    </div>

                    <div class="field-group">
                        <label>Sex/Gender *</label>
                        <select name="sex" required>
                            <option value="">-- Choose --</option>
                            <?php foreach (APPLICANT_SEX_OPTIONS as $opt): ?>
                                <option value="<?= clean($opt) ?>" <?= (($old['sex'] ?? '') === $opt) ? 'selected' : '' ?>><?= clean($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label>Civil Status *</label>
                        <select name="civil_status" required>
                            <option value="">-- Choose --</option>
                            <?php foreach (APPLICANT_CIVIL_STATUS_OPTIONS as $opt): ?>
                                <option value="<?= clean($opt) ?>" <?= (($old['civil_status'] ?? '') === $opt) ? 'selected' : '' ?>><?= clean($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="field-group full">
                        <label>Home Address *</label>
                        <div class="ph-cascade" id="addrCascade">
                            <?= render_ph_cascade('addr', $old, true, true) ?>
                        </div>
                        <input type="text" name="addr_street" id="addrStreet" value="<?= clean($old['addr_street'] ?? '') ?>" placeholder="House/Unit No., Street, Subdivision" required>
                        <input type="hidden" name="home_address" id="addrComposed" value="<?= clean($old['home_address'] ?? '') ?>">
                    </div>

                    <?php if ($cf_contact): $fname = 'cf_' . $cf_contact['field_key']; ?>
                    <div class="field-group"><label><?= clean($cf_contact['label']) ?> <?= $cf_contact['is_required'] ? '*' : '' ?></label><?php renderFormFieldInput($cf_contact, $old[$fname] ?? ''); ?></div>
                    <?php endif; ?>
                    <div class="field-group"><label>Email Address *</label><input type="email" name="email" value="<?= clean($old['email'] ?? '') ?>" required>
                        <div class="hint">Your application updates will be sent here.</div>
                    </div>
                </div>
            </div>

            <!-- ============= 2. POSITION DETAILS ============= -->
            <div class="section">
                <div class="section-head"><span class="section-num">2</span><h2>Position Details</h2></div>
                <div class="form-grid">
                    <?php if ($cf_position): $fname = 'cf_' . $cf_position['field_key']; ?>
                    <div class="field-group"><label><?= clean($cf_position['label']) ?> <?= $cf_position['is_required'] ? '*' : '' ?></label><?php renderFormFieldInput($cf_position, $old[$fname] ?? ''); ?></div>
                    <?php endif; ?>
                    <div class="field-group">
                        <label>Employment Type *</label>
                        <select name="employment_type" required>
                            <option value="">-- Choose --</option>
                            <?php foreach (APPLICANT_EMPLOYMENT_TYPE_OPTIONS as $opt): ?>
                                <option value="<?= clean($opt) ?>" <?= (($old['employment_type'] ?? '') === $opt) ? 'selected' : '' ?>><?= clean($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ============= 3. EDUCATION ============= -->
            <div class="section">
                <div class="section-head"><span class="section-num">3</span><h2>Education</h2></div>
                <div class="form-grid">
                    <div class="field-group">
                        <label>Highest Educational Attainment *</label>
                        <select name="highest_educational_attainment" required>
                            <option value="">-- Choose --</option>
                            <?php foreach (APPLICANT_EDUCATION_OPTIONS as $opt): ?>
                                <option value="<?= clean($opt) ?>" <?= (($old['highest_educational_attainment'] ?? '') === $opt) ? 'selected' : '' ?>><?= clean($opt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-group">
                        <label>Year Graduated *</label>
                        <input type="number" name="year_graduated" value="<?= clean($old['year_graduated'] ?? '') ?>" min="1950" max="<?= date('Y') ?>" placeholder="e.g. <?= date('Y') ?>" required>
                    </div>
                </div>
            </div>

            <!-- ============= 4. CHARACTER REFERENCES ============= -->
            <div class="section">
                <div class="section-head">
                    <span class="section-num">4</span><h2>Character References</h2>
                    <span class="section-note">at least <?= MIN_CHARACTER_REFERENCES ?> required</span>
                </div>
                <div id="refContainer">
                    <?php foreach ($references as $i => $ref):
                        $isOptional = $i >= MIN_CHARACTER_REFERENCES; ?>
                    <div class="ref-row" data-ref-row>
                        <div class="ref-title">
                            <span>Reference <?= $i + 1 ?><?= $isOptional ? ' (optional)' : ' *' ?></span>
                            <?php if ($isOptional): ?><button type="button" class="ref-remove" data-ref-remove>&times; Remove</button><?php endif; ?>
                        </div>
                        <div class="form-grid">
                            <div class="field-group"><label>Full Name<?= $isOptional ? '' : ' *' ?></label><input type="text" name="reference_name[]" value="<?= clean($ref['name']) ?>" <?= $isOptional ? '' : 'required' ?>></div>
                            <div class="field-group"><label>Company<?= $isOptional ? '' : ' *' ?></label><input type="text" name="reference_company[]" value="<?= clean($ref['company']) ?>" <?= $isOptional ? '' : 'required' ?>></div>
                            <div class="field-group"><label>Position<?= $isOptional ? '' : ' *' ?></label><input type="text" name="reference_position[]" value="<?= clean($ref['position']) ?>" <?= $isOptional ? '' : 'required' ?>></div>
                            <div class="field-group">
                                <label>Contact Number<?= $isOptional ? '' : ' *' ?></label>
                                <?php
                                [$refIso, $refNum] = parseStoredPhone($ref['contact_number']);
                                ?>
                                <div class="phone-field-row">
                                    <select name="reference_contact_country[]" class="phone-country-select"><?= phoneCountryOptionsHtml($refIso) ?></select>
                                    <input type="tel" name="reference_contact[]" class="phone-number-input" value="<?= htmlspecialchars($refNum) ?>" inputmode="numeric" <?= $isOptional ? '' : 'required' ?>>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="add-ref-btn" id="addRefBtn" <?= count($references) >= MAX_CHARACTER_REFERENCES ? 'disabled' : '' ?>>+ Add another reference</button>
            </div>

            <?php if (!empty($cf_leftover)): ?>
            <div class="section">
                <div class="section-head"><span class="section-num">5</span><h2>Additional Information</h2></div>
                <div class="form-grid">
                    <?php foreach ($cf_leftover as $cf): $fname = 'cf_' . $cf['field_key']; ?>
                    <div class="field-group <?= in_array($cf['field_type'], ['textarea']) ? 'full' : '' ?>">
                        <label><?= clean($cf['label']) ?> <?= $cf['is_required'] ? '*' : '' ?></label>
                        <?php renderFormFieldInput($cf, $old[$fname] ?? ''); ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ============= UPLOAD REQUIREMENTS ============= -->
            <div class="section">
                <div class="section-head"><span class="section-num"><?= !empty($cf_leftover) ? '6' : '5' ?></span><h2>Upload Requirements</h2></div>
                <div class="form-grid">
                    <div class="field-group full">
                        <label>Resume *</label>
                        <input type="file" name="resume" accept=".pdf,application/pdf" required>
                        <div class="hint">Accepted format: PDF · Maximum size: 5 MB.</div>
                    </div>
                </div>
            </div>

            <div class="section applicant-privacy-notice">
                <input type="hidden" name="privacy_notice_version" value="<?= htmlspecialchars(APPLICANT_PRIVACY_NOTICE_VERSION, ENT_QUOTES, 'UTF-8') ?>">
                <div class="field-group full">
                    <label>
                        <input type="checkbox" name="privacy_consent" value="1" required <?= (($old['privacy_consent'] ?? '') === '1') ? 'checked' : '' ?>>
                        I have read the applicant privacy notice and agree to the processing of my information for recruitment.
                    </label>
                    <p class="hint">
                        We use the information and documents you provide to assess your application, contact you about recruitment,
                        and complete required hiring steps. Access is limited to authorized HR personnel. If you are hired, necessary
                        information may be transferred to your employee record under applicable HR retention rules. If your
                        application is rejected, it is scheduled for deletion after
                        <?= $applicantRetentionDays === false ? 'the configured retention period' : 'up to ' . (int) $applicantRetentionDays . ' days' ?>,
                        unless a request,
                        legal hold, or other documented obligation requires us to retain it. Privacy notice version
                        <?= htmlspecialchars(APPLICANT_PRIVACY_NOTICE_VERSION, ENT_QUOTES, 'UTF-8') ?>.
                        For privacy requests, use <a href="privacy_request.php">the data subject request form</a>.
                    </p>
                </div>
            </div>

            <button type="<?= $embed ? 'button' : 'submit' ?>" class="btn" <?= $embed ? 'disabled title="Preview only — submitting is disabled"' : '' ?>>Submit Application</button>
        </form>

        <?php if (!$embed): ?>
        <div class="login-link">Already have an account? <a href="login.php">Login here.</a></div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script>
// --- Theme toggle ---
(function () {
    var toggle = document.getElementById('themeToggle');
    var label  = toggle ? toggle.querySelector('span') : null;
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
        try { localStorage.setItem('manage-grocery-theme', next); } catch (e) {}
        updateControl();
    });
}());

// --- Age auto-compute from Date of Birth ---
(function () {
    var dob = document.getElementById('dobInput');
    var age = document.getElementById('ageDisplay');
    if (!dob || !age) return;

    function computeAge() {
        if (!dob.value) { age.value = ''; return; }
        var birth = new Date(dob.value + 'T00:00:00');
        var today = new Date();
        if (isNaN(birth.getTime()) || birth > today) { age.value = ''; return; }
        var years = today.getFullYear() - birth.getFullYear();
        var m = today.getMonth() - birth.getMonth();
        if (m < 0 || (m === 0 && today.getDate() < birth.getDate())) years--;
        age.value = years >= 0 ? (years + ' years old') : '';
    }
    dob.addEventListener('change', computeAge);
    dob.addEventListener('input', computeAge);
    computeAge();
})();

// --- Region -> Province -> City/Municipality -> Barangay cascading
// dropdowns, shared by "Place of Birth" (prefix "pob") and
// "Home Address" (prefix "addr"). Each level's options come from
// ph_location_lookup.php as the applicant narrows down; the composed
// human-readable string is written into the matching hidden input
// (#pobComposed / #addrComposed) so the rest of the form's PHP
// (validation + DB insert) doesn't need to change at all.
(function () {
    function byId(id) { return document.getElementById(id); }

    function setOptions(select, rows, placeholder) {
        select.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        select.appendChild(opt0);
        rows.forEach(function (row) {
            var opt = document.createElement('option');
            opt.value = row.code;
            opt.textContent = row.name;
            select.appendChild(opt);
        });
    }

    function fetchLevel(level, param, value) {
        return fetch('ph_location_lookup.php?level=' + level + '&' + param + '=' + encodeURIComponent(value))
            .then(function (r) { return r.ok ? r.json() : []; })
            .catch(function () { return []; });
    }

    function selectedText(select) {
        return select.selectedIndex > 0 ? select.options[select.selectedIndex].text : '';
    }

    // Wires one cascade group. `prefix` matches the ids rendered by
    // render_ph_cascade() in apply.php (pobRegion/addrRegion, etc).
    function wireCascade(prefix, opts) {
        var region   = byId(prefix + 'Region');
        var province = byId(prefix + 'Province');
        var city     = byId(prefix + 'City');
        var barangay = byId(prefix + 'Barangay'); // may be null if not rendered
        var composed = byId(opts.composedId);
        var street   = opts.streetId ? byId(opts.streetId) : null;
        if (!region || !province || !city || !composed) return;

        function compose() {
            var parts = [];
            if (street && street.value.trim()) parts.push(street.value.trim());
            if (barangay) { var bt = selectedText(barangay); if (bt) parts.push(bt); }
            var ct = selectedText(city); if (ct) parts.push(ct);
            var pt = selectedText(province); if (pt) parts.push(pt);
            composed.value = parts.join(', ');
        }

        region.addEventListener('change', function () {
            setOptions(province, [], '-- Province --');
            setOptions(city, [], '-- City/Municipality --');
            if (barangay) setOptions(barangay, [], '-- Barangay --');
            province.disabled = true; city.disabled = true; if (barangay) barangay.disabled = true;
            compose();
            if (!region.value) return;
            fetchLevel('province', 'region', region.value).then(function (rows) {
                setOptions(province, rows, '-- Province --');
                province.disabled = false;
            });
        });

        province.addEventListener('change', function () {
            setOptions(city, [], '-- City/Municipality --');
            if (barangay) setOptions(barangay, [], '-- Barangay --');
            city.disabled = true; if (barangay) barangay.disabled = true;
            compose();
            if (!province.value) return;
            fetchLevel('city', 'province', province.value).then(function (rows) {
                setOptions(city, rows, '-- City/Municipality --');
                city.disabled = false;
            });
        });

        city.addEventListener('change', function () {
            if (barangay) {
                setOptions(barangay, [], '-- Barangay --');
                barangay.disabled = true;
            }
            compose();
            if (!barangay || !city.value) return;
            fetchLevel('barangay', 'city', city.value).then(function (rows) {
                setOptions(barangay, rows, '-- Barangay --');
                barangay.disabled = false;
            });
        });

        if (barangay) barangay.addEventListener('change', compose);
        if (street) street.addEventListener('input', compose);

        // Keep the hidden field correct even if the applicant submits
        // without touching a select again after autofill, etc.
        composed.form && composed.form.addEventListener('submit', compose);
        compose();
    }

    wireCascade('pob', { composedId: 'pobComposed' });
    wireCascade('addr', { composedId: 'addrComposed', streetId: 'addrStreet' });
})();

// --- Character References: add/remove optional rows (max <?= MAX_CHARACTER_REFERENCES ?>) ---
(function () {
    var container = document.getElementById('refContainer');
    var addBtn = document.getElementById('addRefBtn');
    var MAX_REFS = <?= (int) MAX_CHARACTER_REFERENCES ?>;
    var PHONE_OPTIONS_HTML = <?= json_encode(phoneCountryOptionsHtml('PH')) ?>;
    if (!container || !addBtn) return;

    function rowCount() { return container.querySelectorAll('[data-ref-row]').length; }
    function refreshAddBtn() { addBtn.disabled = rowCount() >= MAX_REFS; }

    function bindRemove(row) {
        var btn = row.querySelector('[data-ref-remove]');
        if (btn) btn.addEventListener('click', function () {
            row.remove();
            refreshAddBtn();
        });
    }
    container.querySelectorAll('[data-ref-row]').forEach(bindRemove);

    addBtn.addEventListener('click', function () {
        if (rowCount() >= MAX_REFS) return;
        var n = rowCount() + 1;
        var row = document.createElement('div');
        row.className = 'ref-row';
        row.setAttribute('data-ref-row', '');
        row.innerHTML =
            '<div class="ref-title"><span>Reference ' + n + ' (optional)</span>' +
            '<button type="button" class="ref-remove" data-ref-remove>&times; Remove</button></div>' +
            '<div class="form-grid">' +
            '<div class="field-group"><label>Full Name</label><input type="text" name="reference_name[]"></div>' +
            '<div class="field-group"><label>Company</label><input type="text" name="reference_company[]"></div>' +
            '<div class="field-group"><label>Position</label><input type="text" name="reference_position[]"></div>' +
            '<div class="field-group"><label>Contact Number</label>' +
            '<div class="phone-field-row">' +
            '<select name="reference_contact_country[]" class="phone-country-select">' + PHONE_OPTIONS_HTML + '</select>' +
            '<input type="tel" name="reference_contact[]" class="phone-number-input" inputmode="numeric">' +
            '</div></div>' +
            '</div>';
        container.appendChild(row);
        bindRemove(row);
        // Let the shared phone-field script (event delegation) apply the
        // right maxlength/placeholder for the freshly-inserted select.
        var newSelect = row.querySelector('.phone-country-select');
        if (newSelect) newSelect.dispatchEvent(new Event('change', { bubbles: true }));
        refreshAddBtn();
    });

    refreshAddBtn();
})();

// If the user came from another page on the same site (e.g. "View public
// form" from manage_application_form.php in the Admin panel, or from
// index.php), the Back link should return there directly — not always
// "Home" regardless of where they came from.
(function () {
    var ref = document.referrer;
    var sameSite = ref && ref.indexOf(window.location.origin) === 0;
    if (sameSite && ref.indexOf('apply.php') === -1) {
        var label = document.getElementById('smartBackLabel');
        if (label && ref.indexOf('manage_application_form.php') !== -1) {
            label.textContent = 'Back to Application Form Settings';
        } else if (label && ref.indexOf('index.php') === -1) {
            label.textContent = 'Back';
        }
    }
})();

function smartGoBack(e) {
    var ref = document.referrer;
    var sameSite = ref && ref.indexOf(window.location.origin) === 0 && ref.indexOf('apply.php') === -1;
    if (sameSite) {
        // Go directly to the referrer URL instead of using history.back() —
        // this is more reliable since it works even if apply.php was opened
        // in a NEW TAB (e.g. "View public form", which uses target="_blank"),
        // where the new tab has NO history stack entry to go back to.
        e.preventDefault();
        window.location.href = ref;
        return false;
    }
    if (window.history.length > 1) {
        e.preventDefault();
        window.history.back();
        return false;
    }
    // No history/referrer (user arrived here directly) — just go Home.
    return true;
}
</script>
<?php renderPhoneFieldScript(); ?>
</body>
</html>