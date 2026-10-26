<?php
if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

// ========================================================
// mailer.php — Email sending for the Job Applicants module
// ========================================================
// This uses PHPMailer (located in includes/PHPMailer/) to send
// real emails via SMTP (e.g. Gmail). The built-in PHP mail()
// function usually does NOT work on XAMPP without extra setup,
// which is why SMTP is used here instead.
//
// HOW TO SET UP (Gmail example):
// 1. Create or use a Gmail account for the system (e.g. hr.notificationsystem@example.com)
// 2. Enable 2-Step Verification in Google Account settings
// 3. Create an "App Password": https://myaccount.google.com/apppasswords
// 4. Put the email and 16-character app password below (SMTP_USERNAME / SMTP_PASSWORD)
//
// You can also use another SMTP provider (Outlook, Zoho, or the SMTP
// provided by your hosting provider) — just change SMTP_HOST/PORT.
// ========================================================

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';
require_once __DIR__ . '/../../includes/env.php';
loadEnv(__DIR__ . '/../../.env');

function mailerEnvValue($key, $default = '') {
    $value = env($key, $default);
    if (trim((string) $value) !== '') {
        return (string) $value;
    }

    $path = __DIR__ . '/../../.env';
    if (!is_readable($path)) {
        return (string) $default;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return (string) $default;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        list($envKey, $envValue) = explode('=', $line, 2);
        if (trim($envKey) !== $key) {
            continue;
        }
        $envValue = trim($envValue);
        if (strlen($envValue) >= 2 && (($envValue[0] === '"' && substr($envValue, -1) === '"') || ($envValue[0] === "'" && substr($envValue, -1) === "'"))) {
            $envValue = substr($envValue, 1, -1);
        }
        return $envValue;
    }
    return (string) $default;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =====================================================
// !!! REPLACE THESE WITH YOUR OWN SMTP DETAILS !!!
// =====================================================
define('SMTP_HOST',       mailerEnvValue('SMTP_HOST', 'smtp.gmail.com'));
define('SMTP_PORT',       (int) mailerEnvValue('SMTP_PORT', '587'));
define('SMTP_USERNAME',   mailerEnvValue('SMTP_USERNAME', ''));
define('SMTP_PASSWORD',   mailerEnvValue('SMTP_PASSWORD', ''));
define('SMTP_FROM_EMAIL', mailerEnvValue('SMTP_FROM_EMAIL', SMTP_USERNAME));
define('SMTP_FROM_NAME',  mailerEnvValue('SMTP_FROM_NAME', 'HR Department'));
define('MAIL_DEBUG_MODE', false); // set to true only while testing/debugging

/**
 * Core sender. Returns true/false. It does not throw an error back
 * to the caller — instead it just logs it, so the whole request
 * (e.g. saving the applicant) isn't interrupted just because the email failed.
 */
function sendSystemEmail($toEmail, $toName, $subject, $htmlBody, $plainTextBody = null, $fromName = null) {
    if (trim(SMTP_USERNAME) === '' || trim(SMTP_PASSWORD) === '') {
        error_log('System email not sent: HRMS SMTP credentials are missing after loading the project .env file.');
        return false;
    }
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        error_log('System email not sent: recipient address is invalid.');
        return false;
    }
    $smtpPassword = strtolower(SMTP_HOST) === 'smtp.gmail.com'
        ? preg_replace('/\s+/', '', SMTP_PASSWORD)
        : SMTP_PASSWORD;
    $mail = new PHPMailer(true);
    try {
        $mail->SMTPDebug  = MAIL_DEBUG_MODE ? 2 : 0;
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = $smtpPassword;
        $mail->SMTPSecure = SMTP_PORT === 465
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->Timeout   = 15;
        $mail->CharSet   = PHPMailer::CHARSET_UTF8;

        $mail->setFrom(SMTP_FROM_EMAIL, $fromName !== null ? $fromName : SMTP_FROM_NAME);
        $mail->addAddress($toEmail, $toName);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainTextBody !== null
            ? (string) $plainTextBody
            : strip_tags(str_replace('<br>', "\n", $htmlBody));

        $mail->send();
        return true;
    } catch (Throwable $e) {
        $detail = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        error_log("System email delivery failed: " . $detail);
        return false;
    }
}

// =====================================================
// EMAIL TEMPLATES — Job Applicants
// =====================================================

function emailWrapper($title, $bodyHtml, $brandName = 'HR Department') {
    return '
    <div style="font-family: Arial, sans-serif; background:#F3F6F9; padding:32px;">
        <div style="max-width:520px; margin:0 auto; background:#ffffff; border-radius:16px; overflow:hidden; border:1px solid #DCE5EC;">
            <div style="background:#123C5C; padding:20px 28px;">
                <h2 style="color:#ffffff; margin:0; font-size:18px;">' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '</h2>
            </div>
            <div style="padding:28px; color:#17222E; font-size:14px; line-height:1.6;">
                <h3 style="color:#123C5C; margin-top:0;">' . $title . '</h3>
                ' . $bodyHtml . '
            </div>
            <div style="padding:16px 28px; background:#F3F6F9; color:#3D4C5C; font-size:12px;">
                This is an automated email from ' . htmlspecialchars($brandName, ENT_QUOTES, 'UTF-8') . '. Please do not reply to this email.
            </div>
        </div>
    </div>';
}

// Sent when the applicant passes a stage and there is already a set
// date for the next interview.
//   $stage = 'initial'  -> passed Resume screening
//   $stage = 'final'    -> passed Initial Interview
function sendInterviewScheduleEmail($toEmail, $applicantName, $position, $stage, $interviewDate) {
    $isFinal = ($stage === 'final');
    $label   = $isFinal ? 'Final Interview' : 'Initial Interview';
    $passed  = $isFinal ? 'Initial Interview' : 'resume screening';

    $formattedDate = date('F j, Y (l)', strtotime($interviewDate));

    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>Good news! <strong>You passed ' . htmlspecialchars($passed) . '</strong> "for the position of
           <strong>' . htmlspecialchars($position) . '</strong>.</p>
        <p>Here is the schedule for your <strong>' . htmlspecialchars($label) . '</strong>:</p>
        <div style="margin:16px 0; padding:14px 16px; background:#F3F6F9; border-left:4px solid #123C5C; border-radius:6px;">
            <p style="margin:0 0 4px 0;"><strong>' . htmlspecialchars($label) . ':</strong> ' . htmlspecialchars($formattedDate) . '</p>
            <p style="margin:0;">Go to the store on the said date and introduce yourself to our HR team.</p>
        </div>
        <p>Please arrive early. If anything unexpected comes up, please contact us right away.</p>
        <p>Good luck!</p>';

    return sendSystemEmail(
        $toEmail,
        $applicantName,
        $label . ' Schedule — HR Department',
        emailWrapper('Schedule of Your ' . $label, $body)
    );
}

function sendApplicationReceivedEmail($toEmail, $applicantName, $position) {
    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>We have already received your application for the position of <strong>' . htmlspecialchars($position) . '</strong>. Thank you for applying!</p>
        <p>Our HR team will first review your resume. We will notify you of the next step once <strong>you pass the resume screening.</strong>.</p>
        <p>Thank you so much, and just wait for our update!</p>';
    return sendSystemEmail($toEmail, $applicantName, 'Application Received — HR Department', emailWrapper('Your Application Has Been Received', $body));
}

function sendHiredEmail($toEmail, $applicantName, $position) {
    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>Good news! You passed the interview and are now confirmed for the position of <strong>' . htmlspecialchars($position) . '</strong>.</p>
        <p>Our HR team will notify you regarding the next steps, including the date of your orientation.</p>
        <p>Welcome to HCI GroceryOne!</p>';
    return sendSystemEmail($toEmail, $applicantName, 'Congratulations! You are Now Confirmed', emailWrapper('Your Application Is Confirmed', $body));
}

// Sent when the applicant passes the FINAL Interview: list of requirements
// and the deadline. This must be completed before they can be hired.
// $documents = ['key' => 'Label', ...]
// $portalUrl = the applicant's own magic link to requirements_submit.php
// (see applicantRequirementsPortalUrl() in includes/config.php) — they
// submit their documents there directly instead of physically dropping
// them off at the HR office. Optional/blank for backward-compatibility,
// in which case the email falls back to the old "bring to HR" wording.
function sendRequirementsEmail($toEmail, $applicantName, $position, $deadline, $documents, $portalUrl = '') {
    $formattedDeadline = date('F j, Y (l)', strtotime($deadline));

    $items = '';
    foreach ($documents as $label) {
        $items .= '<li style="margin-bottom:4px;">' . htmlspecialchars($label) . '</li>';
    }

    $portalBtnHtml = '';
    $howToSubmit   = 'Bring the documents to our HR office.';
    if (trim((string) $portalUrl) !== '') {
        $safeUrl = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');
        $portalBtnHtml = '
        <div style="margin:22px 0; text-align:center;">
            <a href="' . $safeUrl . '"
               style="display:inline-block; background:#123C5C; color:#ffffff; text-decoration:none;
                      font-weight:600; font-size:14px; padding:12px 26px; border-radius:10px;">
                Submit Your Requirements
            </a>
        </div>
        <p style="font-size:12px; color:#3D4C5C; word-break:break-all;">
            If the button doesn\'t work, copy and paste this link into your browser:<br>
            <a href="' . $safeUrl . '" style="color:#123C5C;">' . $safeUrl . '</a>
        </p>';
        $howToSubmit = 'Click the button below to submit each requirement online — no need to visit the HR office in person just for this.';
    }

    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>Congratulations — <strong>you passed the Final Interview</strong> for the position of
           <strong>' . htmlspecialchars($position) . '</strong>!</p>
        <p>Before you can be officially hired, you need to submit the following <strong>requirements</strong>:</p>
        <div style="margin:16px 0; padding:14px 18px; background:#F3F6F9; border-left:4px solid #123C5C; border-radius:6px;">
            <ul style="margin:0; padding-left:18px;">' . $items . '</ul>
        </div>
        <div style="margin:16px 0; padding:14px 16px; background:#FBEFD8; border-left:4px solid #E0A23B; border-radius:6px;">
            <p style="margin:0;"><strong>Deadline:</strong> ' . htmlspecialchars($formattedDeadline) . '</p>
        </div>
        <p><strong>Important:</strong> If the requirements are not completed before the deadline,
           you will no longer be qualified for this position.</p>
        <p>' . $howToSubmit . '</p>' . $portalBtnHtml . '
        <p>Thank you very much!</p>';

    return sendSystemEmail($toEmail, $applicantName, 'Requirements for Your Hiring — HR Department', emailWrapper('Complete Your Requirements', $body));
}

// Sent when the deadline has passed and the requirements are not complete.
function sendDisqualifiedEmail($toEmail, $applicantName, $position, $deadline) {
    $formattedDeadline = date('F j, Y', strtotime($deadline));

    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>Thank you for your interest in the position of <strong>' . htmlspecialchars($position) . '</strong> with our organization.</p>
        <div style="margin:16px 0; padding:14px 16px; background:#F3F6F9; border-left:4px solid #C8472D; border-radius:6px;">
            <p style="margin:0;">We did not receive your complete requirements before the deadline on
            <strong>' . htmlspecialchars($formattedDeadline) . '</strong>.</p>
        </div>
        <p>Because of this, <strong>you are no longer qualified</strong> for this position at this time.</p>
        <p>You are welcome to apply with us again in the future. Thank you very much.</p>';

    return sendSystemEmail($toEmail, $applicantName, 'Update on Your Application — HR Department', emailWrapper('Requirements Not Completed', $body));
}

function sendOrientationEmail($toEmail, $applicantName, $position, $orientationDate) {
    $formattedDate = date('F j, Y (l)', strtotime($orientationDate));
    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>Here is the date of your orientation for the position of <strong>' . htmlspecialchars($position) . '</strong>:</p>
        <div style="margin:16px 0; padding:14px 16px; background:#F3F6F9; border-left:4px solid #123C5C; border-radius:6px;">
            <p style="margin:0 0 4px 0;"><strong>Orientation Date:</strong> ' . htmlspecialchars($formattedDate) . '</p>
            <p style="margin:0;">Just go to the store on the said date and speak with our HR Manager for your orientation.</p>
        </div>
        <p>If you have any questions, feel free to visit or contact us.</p>';
    return sendSystemEmail($toEmail, $applicantName, 'Orientation Date — HR Department', emailWrapper('Your Orientation Date', $body));
}

// Sent when HR/Admin rejects ONE already-submitted requirement (e.g. an
// ID that's too blurry to read, or the wrong document entirely) — NOT
// the whole application. Tells the applicant exactly which requirement
// needs to be resent and why, so they don't have to guess or resend
// everything.
// $portalUrl = the applicant's own magic link to requirements_submit.php
// (see applicantRequirementsPortalUrl()) — the SAME link from the
// original requirements email still works here, since it stays valid
// for re-submissions. Optional/blank for backward-compatibility.
function sendDocumentRejectedEmail($toEmail, $applicantName, $position, $documentLabel, $reason, $portalUrl = '') {
    $portalBtnHtml = '';
    $howToResend   = 'Please prepare a clear, correct copy of this requirement and bring/send it to our HR office again.';
    if (trim((string) $portalUrl) !== '') {
        $safeUrl = htmlspecialchars($portalUrl, ENT_QUOTES, 'UTF-8');
        $portalBtnHtml = '
        <div style="margin:22px 0; text-align:center;">
            <a href="' . $safeUrl . '"
               style="display:inline-block; background:#123C5C; color:#ffffff; text-decoration:none;
                      font-weight:600; font-size:14px; padding:12px 26px; border-radius:10px;">
                Resubmit This Requirement
            </a>
        </div>
        <p style="font-size:12px; color:#3D4C5C; word-break:break-all;">
            If the button doesn\'t work, copy and paste this link into your browser:<br>
            <a href="' . $safeUrl . '" style="color:#123C5C;">' . $safeUrl . '</a>
        </p>';
        $howToResend = 'Please click the button below to prepare and resend a clear, correct copy of just this requirement.';
    }

    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>We reviewed the requirements you submitted for the position of
           <strong>' . htmlspecialchars($position) . '</strong>, and one of them needs to be resent:</p>
        <div style="margin:16px 0; padding:14px 16px; background:#FBE7E2; border-left:4px solid #C8472D; border-radius:6px;">
            <p style="margin:0 0 4px 0;"><strong>Requirement:</strong> ' . htmlspecialchars($documentLabel) . '</p>
            <p style="margin:0;"><strong>Reason:</strong> ' . nl2br(htmlspecialchars(trim($reason))) . '</p>
        </div>
        <p>' . $howToResend . '
           The rest of your submitted requirements are unaffected — you only need to resend this one.</p>' . $portalBtnHtml . '
        <p>Thank you for your patience!</p>';

    return sendSystemEmail(
        $toEmail,
        $applicantName,
        'Action Needed: Resend "' . $documentLabel . '" — HR Department',
        emailWrapper('One Requirement Needs to Be Resent', $body)
    );
}

function sendRejectionEmail($toEmail, $applicantName, $position, $reason = '') {
    // If HR/Admin provided a reason, it is included in the email so
    // the applicant clearly understands why it didn't move forward.
    $reasonHtml = '';
    if (trim((string) $reason) !== '') {
        $reasonHtml = '
        <div style="margin:16px 0; padding:14px 16px; background:#F3F6F9; border-left:4px solid #C8472D; border-radius:6px;">
            <p style="margin:0 0 4px 0;"><strong>Reason:</strong></p>
            <p style="margin:0;">' . nl2br(htmlspecialchars(trim($reason))) . '</p>
        </div>';
    }

    $body = '
        <p>Hi ' . htmlspecialchars($applicantName) . ',</p>
        <p>Thank you for your time and interest in the position of <strong>' . htmlspecialchars($position) . '</strong> Here in HCI GroceryOne</p>
        <p>After careful review, we have decided to move forward with other candidates at this time.</p>
        ' . $reasonHtml . '
        <p>This doesnt mean you lack ability — we hope you will try again with us in the future.</p>
        <p>Thank you so much and good luck with your job search!</p>';
    return sendSystemEmail($toEmail, $applicantName, 'Update on Your Application', emailWrapper('Update on Your Application', $body));
}

// =====================================================
// EMAIL TEMPLATE — Payroll (salary released)
// =====================================================
// Sent by payroll/save_record.php when an employee's payroll is
// NEWLY processed (not on a re-save/update). Notifies the employee
// that their salary has been released, including the pay period, net
// pay, payout date, and the bank/card it was deposited to (account
// number masked for privacy).
function sendPayrollReleasedEmail($toEmail, $employeeName, $periodLabel, $netPay, $payoutDate, $bankName = '', $bankAccountNumber = '', $payslipUrl = '', $paymentMethod = 'bank') {
    $formattedNet    = '₱ ' . number_format((float) $netPay, 2);
    $formattedPayout = $payoutDate ? date('F j, Y', strtotime($payoutDate)) : '—';

    // Mask the account number — only show the last 4 characters.
    // For 'cash' (Walk-in) employees, there's no bank/card to show —
    // the salary is released to them in cash/personally instead, so a
    // different line is shown telling them to claim it from HR.
    $depositHtml = '';
    if ($paymentMethod === 'cash') {
        $depositHtml = '<p style="margin:0;"><strong>Payout Method:</strong> Cash (Walk-in) — please claim your salary from HR.</p>';
    } elseif (trim($bankName) !== '' || trim($bankAccountNumber) !== '') {
        $acct = trim($bankAccountNumber);
        if ($acct !== '') {
            $digits = preg_replace('/\s+/', '', $acct);
            $last4  = substr($digits, -4);
            $acct   = (strlen($digits) > 4 ? str_repeat('•', max(0, strlen($digits) - 4)) : '') . $last4;
        }
        $depositHtml =
            '<p style="margin:0 0 4px 0;"><strong>Bank/Card:</strong> ' . htmlspecialchars($bankName ?: '—') . '</p>' .
            '<p style="margin:0;"><strong>Account:</strong> ' . htmlspecialchars($acct ?: '—') . '</p>';
    }

    // Button that goes directly to View/Print the payslip (payslip.php?id=...).
    // The employee will need to log in first if there's no existing session
    // in the browser/device where they opened the email — this is already
    // handled by requireLogin() in payslip.php itself (it will redirect to
    // login.php and return here after logging in, since auth is session-based).
    $payslipBtnHtml = '';
    if (trim((string) $payslipUrl) !== '') {
        $safeUrl = htmlspecialchars($payslipUrl, ENT_QUOTES, 'UTF-8');
        $payslipBtnHtml = '
        <div style="margin:22px 0; text-align:center;">
            <a href="' . $safeUrl . '"
               style="display:inline-block; background:#123C5C; color:#ffffff; text-decoration:none;
                      font-weight:600; font-size:14px; padding:12px 26px; border-radius:10px;">
                View / Print Payslip
            </a>
        </div>
        <p style="font-size:12px; color:#3D4C5C; word-break:break-all;">
            If the button doesn\'t work, copy and paste this link into your browser:<br>
            <a href="' . $safeUrl . '" style="color:#123C5C;">' . $safeUrl . '</a>
        </p>';
    }

    $body = '
        <p>Hi ' . htmlspecialchars($employeeName) . ',</p>
        <p>We would like to inform you that <strong>your salary has been released</strong>' . ($paymentMethod === 'cash'
            ? ' and is ready for you to claim in cash from HR'
            : ' and sent to your bank/card') . ' for the following pay period:</p>
        <div style="margin:16px 0; padding:14px 16px; background:#F3F6F9; border-left:4px solid #123C5C; border-radius:6px;">
            <p style="margin:0 0 4px 0;"><strong>Pay Period:</strong> ' . htmlspecialchars($periodLabel) . '</p>
            <p style="margin:0 0 4px 0;"><strong>Net Pay:</strong> ' . htmlspecialchars($formattedNet) . '</p>
            <p style="margin:0 0 4px 0;"><strong>Payout Date:</strong> ' . htmlspecialchars($formattedPayout) . '</p>
            ' . $depositHtml . '
        </div>' . $payslipBtnHtml . '
        <p>' . ($payslipBtnHtml !== ''
            ? 'Click the button above to view or print your payslip receipt.'
            : 'You can view the full details of your payslip on your Employee Dashboard (My Payslips).') . '</p>
        <p>Thank you!</p>';
    return sendSystemEmail($toEmail, $employeeName, 'Salary Released — HR Department Payroll', emailWrapper('Your Salary Has Been Released', $body));
}