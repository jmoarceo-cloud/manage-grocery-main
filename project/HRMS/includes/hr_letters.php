<?php
if (
    PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__
) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

foreach (['id', 'applicant_id'] as $__hrLetterIdParameter) {
    if (isset($_GET[$__hrLetterIdParameter])) {
        $__hrLetterIdToken = (string) $_GET[$__hrLetterIdParameter];
        $__hrLetterId = dec_id($__hrLetterIdToken);
        $_GET[$__hrLetterIdParameter] = $__hrLetterId > 0
            ? $__hrLetterId
            : (ctype_digit($__hrLetterIdToken) ? (int) $__hrLetterIdToken : 0);
    }
}
unset($__hrLetterIdParameter, $__hrLetterIdToken, $__hrLetterId);

function hrLetterEsc($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
function hrLetterBody($value)
{
    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h1><h2><h3><h4><blockquote><hr><table><thead><tbody><tr><th><td>';
    $body = strip_tags((string) $value, $allowed);
    return preg_replace_callback('/<(\/?)([a-z0-9]+)\b[^>]*>/i', static function ($match) {
        return '<' . $match[1] . strtolower($match[2]) . '>';
    }, $body);
}
function hrLetterContractTemplate($recipientName, $position = '', $employmentType = '')
{
    $recipientName = trim((string) $recipientName) ?: '[Applicant name]';
    $position = trim((string) $position) ?: '[Position title]';
    $employmentType = trim((string) $employmentType) ?: '[Employment type]';
    return '<p><strong>CONTRACTUAL EMPLOYMENT AGREEMENT</strong></p>'
        . '<p>Date: ' . date('F j, Y') . '</p>'
        . '<p>Dear ' . hrLetterEsc($recipientName) . ',</p>'
        . '<p>We are pleased to offer you employment with HCI GroceryOne, subject to the terms below and the company policies explained to you by Human Resources.</p>'
        . '<p><strong>Position:</strong> ' . hrLetterEsc($position) . '<br>'
        . '<strong>Employment type:</strong> ' . hrLetterEsc($employmentType) . '<br>'
        . '<strong>Contract period:</strong> See the contract details shown with this agreement.</p>'
        . '<p><strong>Compensation and pay schedule:</strong> [Enter the approved salary or rate and pay frequency before sending.]</p>'
        . '<p><strong>Work assignment and schedule:</strong> [Enter the work location, expected schedule, and reporting details.]</p>'
        . '<p>Your employment is subject to applicable Philippine labor laws, company policies, and the completion of any remaining pre-employment requirements. Human Resources will confirm your reporting instructions and discuss any questions about these terms before your start date.</p>'
        . '<p>Please review this agreement carefully. To accept, use the secure link in the email containing this contract and draw your electronic signature. Your signed response will be recorded with this contract. If you do not agree with a term or need clarification, contact Human Resources before signing.</p>'
        . '<p>Sincerely,<br>Human Resources<br>HCI GroceryOne</p>';
}
function hrLetterContractDetails($letter)
{
    if (($letter['letter_type'] ?? '') !== 'contract')
        return '';
    $formatDate = static function ($value) {
        $timestamp = $value ? strtotime((string) $value) : false;
        return $timestamp ? date('F j, Y', $timestamp) : 'To be confirmed';
    };
    return '<table class="contract-details" role="presentation" style="width:100%;border-collapse:collapse;margin:18px 0;background:#F3F6F9;border:1px solid #DCE5EC;">'
        . '<tr><td style="padding:10px 12px;color:#526273;width:35%;"><strong>Contract duration</strong></td><td style="padding:10px 12px;">' . hrLetterEsc($letter['contract_duration_label'] ?? 'To be confirmed') . '</td></tr>'
        . '<tr><td style="padding:10px 12px;color:#526273;"><strong>Start date</strong></td><td style="padding:10px 12px;">' . hrLetterEsc($formatDate($letter['contract_start_date'] ?? null)) . '</td></tr>'
        . '<tr><td style="padding:10px 12px;color:#526273;"><strong>End date</strong></td><td style="padding:10px 12px;">' . hrLetterEsc($formatDate($letter['contract_end_date'] ?? null)) . '</td></tr>'
        . '</table>';
}
function hrLetterLog($pdo, $id, $type, $details = '')
{
    $pdo->prepare("INSERT INTO hr_letter_events (letter_id,event_type,actor_id,details) VALUES (?,?,?,?)")
        ->execute([$id, $type, $_SESSION['user_id'] ?? null, $details]);
}
function hrLetterNotify($posPdo, $message)
{
    if (!$posPdo)
        return;
    try {
        $posPdo->prepare("INSERT INTO notifications (product_id,category,message) VALUES (NULL,'hr_letter',?)")->execute([$message]);
    } catch (Throwable $e) {
        error_log('HR letter notification skipped: ' . $e->getMessage());
    }
}
function hrLetterUrl($token)
{
    return rtrim(appFullBaseUrl(), '/') . '/letter_recipient.php?token=' . rawurlencode($token);
}
function hrLetterEmailHtml($letter, $link)
{
    $isApplicantContract = ($letter['letter_type'] ?? '') === 'contract' && !empty($letter['applicant_id']);
    $buttonLabel = $isApplicantContract ? 'Review and e-sign contract' : 'Review and respond to this letter';
    $intro = $isApplicantContract
        ? '<p style="margin:0 0 18px;">Please review the employment agreement below, including the contract dates and terms. To accept, use the secure button to open the signature page and submit your electronic signature.</p>'
        : '<p style="margin:0 0 18px;">Please review the letter below. Use the secure button to open the page where you can respond.</p>';
    $details = hrLetterContractDetails($letter);
    $body = hrLetterBody($letter['body_html'] ?? '');
    $reference = !empty($letter['reference_no'])
        ? '<p style="margin:22px 0 0;color:#64748b;font-size:12px;">Reference: ' . hrLetterEsc($letter['reference_no']) . '</p>'
        : '';

    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'
        . hrLetterEsc($letter['subject'] ?? 'Human Resources correspondence')
        . '</title></head><body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">'
        . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;">Please review your employment agreement and submit your response securely.</div>'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:#f1f5f9;"><tr><td align="center" style="padding:32px 14px;">'
        . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:640px;border:1px solid #dbe3ec;background-color:#ffffff;">'
        . '<tr><td style="padding:22px 30px;background-color:#123c5c;color:#ffffff;">'
        . '<div style="font-size:11px;font-weight:bold;letter-spacing:1.4px;text-transform:uppercase;color:#cbddea;">HCI GroceryOne</div>'
        . '<div style="padding-top:5px;font-size:20px;font-weight:bold;line-height:1.35;">Human Resources</div>'
        . '</td></tr><tr><td style="padding:30px;font-size:15px;line-height:1.65;">'
        . '<p style="margin:0 0 8px;color:#64748b;font-size:11px;font-weight:bold;letter-spacing:1px;text-transform:uppercase;">Official correspondence</p>'
        . '<h1 style="margin:0 0 22px;color:#123c5c;font-size:23px;line-height:1.3;">' . hrLetterEsc($letter['subject'] ?? '') . '</h1>'
        . '<p style="margin:0 0 18px;">Dear ' . hrLetterEsc($letter['recipient_name'] ?? 'Applicant') . ',</p>'
        . $intro
        . ($details !== '' ? '<div style="margin:22px 0;">' . $details . '</div>' : '')
        . '<div style="font-size:14px;line-height:1.7;">' . $body . '</div>'
        . '<table role="presentation" align="center" cellpadding="0" cellspacing="0" border="0" style="margin:28px auto 22px;"><tr><td align="center" bgcolor="#123c5c" style="border-radius:5px;">'
        . '<a href="' . hrLetterEsc($link) . '" style="display:inline-block;padding:13px 23px;border:1px solid #123c5c;border-radius:5px;color:#ffffff;font-size:14px;font-weight:bold;text-decoration:none;">' . $buttonLabel . '</a>'
        . '</td></tr></table>'
        . '<p style="margin:0;color:#526273;font-size:13px;">If the button does not open, copy and paste this address into your browser:</p>'
        . '<p style="margin:6px 0 0;font-size:12px;line-height:1.5;word-break:break-all;"><a href="' . hrLetterEsc($link) . '" style="color:#1769aa;">' . hrLetterEsc($link) . '</a></p>'
        . $reference
        . '</td></tr><tr><td style="padding:18px 30px;border-top:1px solid #e2e8f0;background-color:#f8fafc;color:#64748b;font-size:12px;line-height:1.6;">'
        . 'This is an official automated message from the Human Resources Department of HCI GroceryOne. Please do not reply directly to this email. For assistance, contact Human Resources through the usual company channels.'
        . '</td></tr></table></td></tr></table></body></html>';
}
function hrLetterEmailText($letter, $link)
{
    $isApplicantContract = ($letter['letter_type'] ?? '') === 'contract' && !empty($letter['applicant_id']);
    $body = hrLetterBody($letter['body_html'] ?? '');
    $body = preg_replace('/<\s*br\s*>/i', "\n", $body);
    $body = preg_replace('/<\/\s*(p|h[1-6]|li|tr|blockquote)\s*>/i', "\n", $body);
    $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $formatDate = static function ($value) {
        $timestamp = $value ? strtotime((string) $value) : false;
        return $timestamp ? date('F j, Y', $timestamp) : 'To be confirmed';
    };
    $details = $isApplicantContract
        ? [
            'Contract duration: ' . (string) ($letter['contract_duration_label'] ?? 'To be confirmed'),
            'Start date: ' . $formatDate($letter['contract_start_date'] ?? null),
            'End date: ' . $formatDate($letter['contract_end_date'] ?? null),
        ]
        : [];
    $lines = [
        'MANAGE GROCERY',
        'HUMAN RESOURCES',
        '',
        (string) ($letter['subject'] ?? 'Official correspondence'),
        '',
        'Dear ' . (string) ($letter['recipient_name'] ?? 'Applicant') . ',',
        '',
        $isApplicantContract
        ? 'Please review the employment agreement below, including the contract dates and terms. To accept, open the secure link and submit your electronic signature.'
        : 'Please review the letter below. Use the secure link to open the page where you can respond.',
        '',
        implode("\n", $details),
        '',
        trim(preg_replace('/[ \t]+/', ' ', $body)),
        '',
        $isApplicantContract ? 'Review and e-sign contract:' : 'Review and respond to this letter:',
        $link,
        '',
        'This is an official automated message from the Human Resources Department of HCI GroceryOne. Please do not reply directly to this email. For assistance, contact Human Resources through the usual company channels.',
    ];
    return implode("\n", $lines);
}
function hrLetterEmail($pdo, $letter, $action = 'send')
{
    if (!filter_var($letter['recipient_email'], FILTER_VALIDATE_EMAIL))
        return [false, 'Recipient email is invalid.'];
    $link = hrLetterUrl($letter['access_token']);
    $body = hrLetterEmailHtml($letter, $link);
    $plainBody = hrLetterEmailText($letter, $link);
    $ok = sendSystemEmail(
        $letter['recipient_email'],
        $letter['recipient_name'],
        $letter['subject'],
        $body,
        $plainBody,
        'HCI GroceryOne | Human Resources'
    );
    $pdo->prepare("INSERT INTO hr_letter_email_log (letter_id,recipient_email,subject,status,error_message) VALUES (?,?,?,?,?)")
        ->execute([$letter['id'], $letter['recipient_email'], $letter['subject'], $ok ? 'sent' : 'failed', $ok ? null : 'SMTP delivery failed']);
    return [$ok, $ok ? 'Email sent.' : 'Email could not be sent. Check SMTP settings.'];
}
function hrLetterHeader($title, $active = 'hr_letters')
{
    global $pdo, $posPdo;
    $activeNav = $active;
    $pageTitle = $title . ' — HRMS';
    $themeCssPath = __DIR__ . '/../assets/hrms-theme.css';
    $themeCssUrl = rtrim((string) appBaseUrl(), '/')
        . '/assets/hrms-theme.css?v='
        . (filemtime($themeCssPath) ?: time());
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . hrLetterEsc($pageTitle) . '</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="' . hrLetterEsc($themeCssUrl) . '">';
    include __DIR__ . '/supervisor_style.php';
    echo '<style>
    .main-content{margin-left:0;min-height:100vh;padding:0 32px 56px;background:var(--cream);}
    .page-header{max-width:1440px;margin:0 auto;padding:24px 0 18px;}
    .page-header h1{display:none;}
    .page-header p{color:var(--ink-soft);font-size:13px;margin-top:7px;}
    .letter-page-shell{max-width:1440px;margin:0 auto;}
    .letter-card{background:var(--white);border:1px solid var(--line);border-radius:18px;padding:24px;box-shadow:0 10px 28px rgba(15,23,42,.055);margin-bottom:18px;}
    .letter-card h2{margin:0 0 16px;color:var(--ink);font-family:var(--font-display);font-size:17px;}
    .letter-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 18px;}
    .letter-form-grid .full{grid-column:1/-1;}
    .letter-card label{display:block;color:var(--ink);font-size:12px;font-weight:700;margin-bottom:5px;}
    .letter-card input,.letter-card select,.letter-card textarea{width:100%;box-sizing:border-box;border:1px solid var(--line);border-radius:10px;background:var(--white);color:var(--ink);padding:11px 12px;margin:0 0 14px;font:inherit;font-size:13px;}
    .letter-card textarea{min-height:220px;resize:vertical;line-height:1.55;}
    .letter-card input:focus,.letter-card select:focus,.letter-card textarea:focus{outline:0;border-color:var(--green-mid);box-shadow:0 0 0 3px rgba(37,99,235,.12);}
    .letter-table{width:100%;border-collapse:collapse;}
    .letter-table th{color:var(--ink-soft);background:#f8fafc;font-size:10px;letter-spacing:.06em;text-transform:uppercase;}
    .letter-table th,.letter-table td{padding:13px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle;}
    .letter-table tr:last-child td{border-bottom:0;}
    .letter-muted{color:var(--ink-soft);font-size:13px;}
    .letter-primary-btn{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:0 15px;border:0;border-radius:10px;background:var(--green-deep);color:#fff;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;}
    .letter-primary-btn:hover{color:#fff;background:var(--green-mid);}
    @media (max-width:920px){.main-content{padding:0 20px 44px;}.letter-form-grid{grid-template-columns:1fr;}.letter-form-grid .full{grid-column:auto;}}
    @media (max-width:640px){.main-content{padding:0 16px 36px;}.page-header{padding:18px 0 14px;}.letter-card{padding:18px;}}
    html[data-theme="dark"] .letter-table th{background:#111827;}
    </style></head><body><div class="app-shell">';
    include __DIR__ . '/hr_sidebar.php';
    $fullName = trim((string) ($_SESSION['full_name'] ?? ''));
    $initial = function_exists('mb_strtoupper')
        ? mb_strtoupper(mb_substr($fullName, 0, 1))
        : strtoupper(substr($fullName, 0, 1));
    $initial = $initial ?: '?';
    $role = function_exists('roleLabel') ? roleLabel($_SESSION['role'] ?? '') : ($_SESSION['role'] ?? '');
    $profileHref = ($_SESSION['role'] ?? '') === 'admin' ? 'admin_profile.php' : 'my_profile.php';
    $avatar = '';
    if (!empty($_SESSION['profile_picture'])) {
        $avatar = '<img src="' . hrLetterEsc(appBaseUrl() . '/uploads/avatars/' . rawurlencode(basename((string) $_SESSION['profile_picture']))) . '" alt="">';
    }
    echo '<div class="main-area"><header class="topbar">'
        . '<nav class="topbar-breadcrumbs" aria-label="Breadcrumb">'
        . '<span>HRMS &amp; Payroll</span><span aria-hidden="true">/</span><span>' . hrLetterEsc($role) . '</span><span aria-hidden="true">/</span>'
        . '<h1 aria-current="page">' . hrLetterEsc($title) . '</h1></nav>'
        . '<div class="topbar-actions">'
        . '<a class="topbar-icon-action" href="hr_notifications.php" aria-label="Open notifications" title="Notifications">'
        . '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M6 8a4 4 0 0 1 8 0c0 3.2 1 4.5 1.5 5H4.5C5 12.5 6 11.2 6 8Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M8.2 15.5a1.8 1.8 0 0 0 3.6 0" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg></a>'
        . '<details class="topbar-profile"><summary aria-label="Open account menu"><span class="user-avatar">' . $avatar . ($avatar ? '' : hrLetterEsc($initial)) . '</span>'
        . '<span class="topbar-profile-copy"><strong>' . hrLetterEsc($fullName ?: $role) . '</strong><span>' . hrLetterEsc($role) . '</span></span>'
        . '<svg class="topbar-profile-chevron" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg></summary>'
        . '<div class="topbar-profile-menu"><a href="' . hrLetterEsc($profileHref) . '">Account profile</a><a href="' . hrLetterEsc(logoutUrl()) . '">Log out</a></div></details>'
        . '</div></header><main class="main-content"><div class="page-header"><div><p>Contracts and official letters</p></div></div>';
}
function hrLetterFooter()
{
    echo '</main></div></div></body></html>';
}
