<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/privacy_response_links.php';
requireAdmin();

$activeNav = 'privacy_requests';
$pageTitle = 'Privacy Requests — HRMS';
$__navBase = '';
$successMessage = $_SESSION['privacy_admin_success'] ?? '';
$errorMessage = $_SESSION['privacy_admin_error'] ?? '';
unset($_SESSION['privacy_admin_success'], $_SESSION['privacy_admin_error']);

$allowedStatuses = [
    'pending' => 'Pending',
    'in_review' => 'In review',
    'fulfilled' => 'Fulfilled',
    'on_hold' => 'On hold',
    'denied' => 'Denied',
];
$allowedTypes = [
    'access' => 'Access',
    'correction' => 'Correction',
    'deletion' => 'Deletion',
    'portability' => 'Portability',
    'restriction' => 'Restriction',
    'objection' => 'Objection',
    'other' => 'Other',
];

function recordPrivacyAdminAudit(PDO $pdo, int $requestId, string $action, string $details): void
{
    $pdo->prepare("
        INSERT INTO hr_audit_log (module, record_id, action, details, user_id, user_name)
        VALUES ('privacy_request', ?, ?, ?, ?, ?)
    ")->execute([
        $requestId,
        $action,
        mb_substr($details, 0, 255),
        (int) $_SESSION['user_id'],
        mb_substr((string) ($_SESSION['full_name'] ?? 'Admin'), 0, 150),
    ]);
}

function privateResponsePath(string $filename): ?string
{
    if ($filename === '' || basename($filename) !== $filename
        || !preg_match('/^dsr_[a-f0-9]{32}\.pdf$/D', $filename)) {
        return null;
    }
    $directory = realpath(privacyResponseDirectory());
    $file = $directory === false ? false : realpath($directory . DIRECTORY_SEPARATOR . $filename);
    return $file !== false && strpos($file, $directory . DIRECTORY_SEPARATOR) === 0 && is_file($file)
        ? $file
        : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = $_POST['action'] ?? null;
    $adminNotesInput = $_POST['admin_notes'] ?? null;

    if ($requestId === false) {
        $errorMessage = 'Invalid request identifier.';
    } elseif (!is_string($action) || !in_array($action, ['review', 'verify_identity', 'upload_response'], true)) {
        $errorMessage = 'Select a valid review action.';
    } elseif ($action !== 'upload_response' && !is_string($adminNotesInput)) {
        $errorMessage = 'Review notes must be plain text.';
    } else {
        $adminNotes = is_string($adminNotesInput) ? trim($adminNotesInput) : '';
        $newStatus = $_POST['status'] ?? null;
        if (mb_strlen($adminNotes) > 5000) {
            $errorMessage = 'Review notes must be 5,000 characters or fewer.';
        } elseif ($action === 'review' && (!is_string($newStatus) || !isset($allowedStatuses[$newStatus]))) {
            $errorMessage = 'Select a valid request status.';
        } elseif ($action === 'verify_identity' && $adminNotes === '') {
            $errorMessage = 'Record the identity verification method in the internal notes.';
        } elseif ($action === 'upload_response' && (
            !isset($_FILES['response_file']) || !is_array($_FILES['response_file'])
            || ($_FILES['response_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_string($_FILES['response_file']['tmp_name'] ?? null)
            || !is_uploaded_file($_FILES['response_file']['tmp_name'])
        )) {
            $errorMessage = 'Select a valid PDF response file to upload.';
        }

        $newResponsePath = null;
        $newResponseName = null;
        $oldResponseName = null;
        if ($errorMessage === '' && $action === 'upload_response') {
            $uploadedFile = $_FILES['response_file'];
            if ((int) ($uploadedFile['size'] ?? 0) < 1 || (int) $uploadedFile['size'] > 10 * 1024 * 1024) {
                $errorMessage = 'The response PDF must be between 1 byte and 10 MB.';
            } else {
                $fileInfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fileInfo->file($uploadedFile['tmp_name']);
                $signature = file_get_contents($uploadedFile['tmp_name'], false, null, 0, 5);
                if ($mime !== 'application/pdf' || $signature !== '%PDF-') {
                    $errorMessage = 'The response file must be a valid PDF.';
                } else {
                    $directory = privacyResponseDirectory();
                    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                        $errorMessage = 'Could not create private response storage.';
                    } elseif (!is_writable($directory)) {
                        $errorMessage = 'Private response storage is not writable.';
                    } else {
                        $newResponseName = 'dsr_' . bin2hex(random_bytes(16)) . '.pdf';
                        $newResponsePath = $directory . DIRECTORY_SEPARATOR . $newResponseName;
                        if (!move_uploaded_file($uploadedFile['tmp_name'], $newResponsePath)) {
                            $errorMessage = 'Could not save the response PDF.';
                            $newResponsePath = null;
                            $newResponseName = null;
                        }
                    }
                    $originalName = basename((string) ($uploadedFile['name'] ?? 'privacy-response.pdf'));
                    $responseOriginalName = preg_replace('/[\x00-\x1F\x7F]/', '', $originalName);
                    $responseOriginalName = mb_substr($responseOriginalName ?: 'privacy-response.pdf', 0, 255);
                }
            }
        }

        if ($errorMessage !== '') {
            if ($newResponsePath !== null && is_file($newResponsePath)) {
                unlink($newResponsePath);
            }
        } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('
                SELECT status, identity_verified_at, response_filename, response_expires_at
                FROM data_subject_requests WHERE request_id = ? FOR UPDATE
            ');
            $stmt->execute([$requestId]);
            $existing = $stmt->fetch();
            if (!$existing) {
                $pdo->rollBack();
                $errorMessage = 'Privacy request not found.';
            } elseif ($action === 'review' && in_array($newStatus, ['in_review', 'fulfilled'], true)
                && empty($existing['identity_verified_at'])) {
                $pdo->rollBack();
                $errorMessage = 'Record identity verification before reviewing or completing this request.';
            } elseif ($action === 'review' && $newStatus === 'fulfilled'
                && (empty($existing['response_filename'])
                    || privateResponsePath((string) $existing['response_filename']) === null
                    || empty($existing['response_expires_at'])
                    || strtotime((string) $existing['response_expires_at']) <= time())) {
                $pdo->rollBack();
                $errorMessage = 'Attach the response PDF before marking the request fulfilled.';
            } elseif ($action === 'review' && $newStatus === 'denied' && $adminNotes === '') {
                $pdo->rollBack();
                $errorMessage = 'Add a reason to the internal notes before denying a request.';
            } elseif ($action === 'verify_identity' && $adminNotes === '') {
                $pdo->rollBack();
                $errorMessage = 'Record the identity verification method in the internal notes.';
            } elseif ($action === 'upload_response' && empty($existing['identity_verified_at'])) {
                $pdo->rollBack();
                $errorMessage = "Verify the requester's identity before attaching a response.";
            } else {
                if ($action === 'review') {
                    $update = $pdo->prepare("
                        UPDATE data_subject_requests
                        SET status = ?, admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
                        WHERE request_id = ?
                    ");
                    $update->execute([$newStatus, $adminNotes !== '' ? $adminNotes : null, (int) $_SESSION['user_id'], $requestId]);
                    recordPrivacyAdminAudit($pdo, (int) $requestId, 'status_changed', 'Status changed to ' . $newStatus);
                } elseif ($action === 'verify_identity') {
                    $update = $pdo->prepare("
                        UPDATE data_subject_requests
                        SET identity_verified_at = COALESCE(identity_verified_at, NOW()),
                            admin_notes = ?, reviewed_by = ?, reviewed_at = NOW()
                        WHERE request_id = ?
                    ");
                    $update->execute([$adminNotes, (int) $_SESSION['user_id'], $requestId]);
                    recordPrivacyAdminAudit($pdo, (int) $requestId, 'identity_verified', 'Identity verification recorded.');
                } else {
                    $oldResponseName = (string) ($existing['response_filename'] ?? '');
                    $expiry = date('Y-m-d H:i:s', time() + 7 * 24 * 60 * 60);
                    $update = $pdo->prepare("
                        UPDATE data_subject_requests
                        SET response_filename = ?, response_original_name = ?, response_expires_at = ?,
                            reviewed_by = ?, reviewed_at = NOW()
                        WHERE request_id = ?
                    ");
                    $update->execute([$newResponseName, $responseOriginalName, $expiry, (int) $_SESSION['user_id'], $requestId]);
                    recordPrivacyAdminAudit($pdo, (int) $requestId, 'response_attached', 'Response PDF attached; signed link expires ' . $expiry . '.');
                }
                $pdo->commit();
                if ($oldResponseName !== '' && $oldResponseName !== $newResponseName) {
                    $oldPath = privateResponsePath($oldResponseName);
                    if ($oldPath !== null && !unlink($oldPath)) {
                        error_log('Could not remove replaced privacy response file for request ' . $requestId);
                    }
                }
                if ($action === 'review' && in_array($existing['status'], ['completed'], true) && $newStatus === 'fulfilled') {
                    $_SESSION['privacy_admin_success'] = 'Legacy completed request marked fulfilled.';
                } else {
                    $_SESSION['privacy_admin_success'] = $action === 'review'
                        ? 'Privacy request updated.'
                        : ($action === 'verify_identity' ? 'Identity verification recorded.' : 'Response PDF attached; signed link expires in 7 days.');
                }
                header('Location: privacy_requests.php');
                exit;
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($newResponsePath !== null && is_file($newResponsePath)) {
                unlink($newResponsePath);
            }
            error_log('Privacy request review failed: ' . $e->getMessage());
            $errorMessage = 'The request could not be updated. Please try again.';
        }
        }
    }
}

$requests = $pdo->query("
    SELECT request_id, requester_name, requester_email, request_type, request_details,
           status, admin_notes, submitted_at, reviewed_at, identity_verified_at,
           response_filename, response_original_name, response_expires_at
    FROM data_subject_requests
    ORDER BY FIELD(status, 'pending', 'identity_verification', 'identity_verified', 'in_review', 'on_hold', 'completed', 'fulfilled', 'denied'),
             submitted_at ASC
    LIMIT 200
")->fetchAll();
$pendingCount = count(array_filter($requests, static function ($request) {
    return in_array($request['status'], ['pending', 'identity_verification', 'identity_verified', 'in_review', 'on_hold'], true);
}));
$signedResponseLinks = [];
foreach ($requests as $request) {
    if ($request['status'] !== 'fulfilled' || empty($request['identity_verified_at'])) {
        continue;
    }
    if (privateResponsePath((string) ($request['response_filename'] ?? '')) === null) {
        $errorMessage = 'A fulfilled request has no stored response PDF. Re-attach the response before sharing the link.';
        continue;
    }
    try {
        $signedResponseLinks[(int) $request['request_id']] = privacyResponseDownloadUrl($request);
    } catch (Throwable $e) {
        error_log('Privacy response link generation failed: ' . $e->getMessage());
        $errorMessage = 'Signed response links are unavailable. Check private response storage permissions.';
    }
}
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Privacy Requests | HCI GroceryOne HRMS</title>
    <link rel="stylesheet" href="../assets/css/theme.css?v=<?= (int) filemtime(__DIR__ . '/../assets/css/theme.css') ?>">
    <link rel="stylesheet" href="payroll/assets/style.css">
    <style>
        .privacy-requests {
            background-color: var(--background);
            background-image:
                linear-gradient(to right, color-mix(in srgb, var(--border) 35%, transparent) 1px, transparent 1px),
                linear-gradient(to bottom, color-mix(in srgb, var(--border) 35%, transparent) 1px, transparent 1px);
            background-size: 42px 42px;
            color: var(--text);
            font-family: "Plus Jakarta Sans", "Segoe UI", Arial, sans-serif;
        }

        .privacy-requests .topbar {
            position: sticky;
            top: 0;
            z-index: 5;
            min-height: 64px;
            border-bottom: 1px solid var(--border);
            background: color-mix(in srgb, var(--surface) 94%, transparent);
            backdrop-filter: blur(18px);
        }

        .privacy-requests .topbar,
        .privacy-requests .panel {
            background-color: var(--surface);
        }

        .privacy-requests .topbar h1 {
            color: var(--text);
            font-family: Georgia, "Times New Roman", serif;
            font-size: 24px;
            font-weight: 500;
            letter-spacing: -.045em;
        }

        .privacy-requests .topbar .user-info { color: var(--text-muted); }

        .privacy-requests .container {
            width: 100%;
            max-width: none;
            margin-inline: 0;
            padding: clamp(24px, 2.8vw, 38px) clamp(18px, 2.4vw, 38px) 48px;
        }

        .privacy-requests .page-heading {
            display: grid;
            grid-template-columns: minmax(250px, 1fr) minmax(0, 1.35fr);
            align-items: center;
            gap: 24px;
            margin-bottom: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border);
        }

        .privacy-requests .page-heading h2 {
            margin: 0;
            color: var(--text);
            font-family: Georgia, "Times New Roman", serif;
            font-size: clamp(30px, 3.2vw, 40px);
            font-weight: 500;
            letter-spacing: -.05em;
            line-height: 1.08;
        }

        .privacy-requests .safety-note {
            max-width: none;
            margin: 0;
            padding: 13px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface);
            color: var(--text-muted);
            font-size: 13px;
            line-height: 1.65;
        }

        .privacy-requests .request-list {
            display: grid;
            gap: 18px;
        }

        .privacy-requests .panel {
            display: block;
            margin: 0;
            padding: 24px;
            border: 1px solid var(--border);
            border-radius: 14px;
            background: var(--surface);
            box-shadow: var(--shadow-surface);
            color: var(--text-muted);
        }

        .privacy-requests article.panel { color: var(--text); }

        .privacy-requests .request-meta {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border);
            color: var(--text-muted);
            font-size: 12px;
        }

        .privacy-requests .request-meta > * {
            padding: 5px 9px;
            border: 1px solid var(--border);
            border-radius: 999px;
            background: var(--surface-muted);
        }

        .privacy-requests .request-meta > strong {
            border-color: color-mix(in srgb, var(--accent) 22%, var(--border));
            background: color-mix(in srgb, var(--accent) 7%, var(--surface));
            color: var(--accent);
        }

        .privacy-requests .request-meta .status {
            border-color: color-mix(in srgb, var(--accent) 22%, var(--border));
            color: var(--accent);
            font-weight: 700;
        }

        .privacy-requests article.panel > p { color: var(--text-muted); }
        .privacy-requests article.panel > p strong { color: var(--text); }
        .privacy-requests article.panel a { color: var(--accent); }

        .privacy-requests .request-details {
            margin: 16px 0;
            padding: 14px 16px;
            border: 1px solid var(--border);
            border-radius: 10px;
            background: var(--surface-muted);
            color: var(--text);
            line-height: 1.65;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .privacy-requests .request-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: end;
            gap: 14px;
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid var(--border);
        }

        .privacy-requests .request-form--review {
            grid-template-columns: minmax(180px, .75fr) minmax(0, 2fr);
        }

        .privacy-requests .request-form label {
            display: grid;
            gap: 7px;
            color: var(--text);
            font-size: 12px;
            font-weight: 700;
        }

        .privacy-requests .request-form input,
        .privacy-requests .request-form select,
        .privacy-requests .request-form textarea {
            width: 100%;
            min-width: 0;
            padding: 10px 12px;
            border: 1px solid var(--border);
            border-radius: 9px;
            background: var(--surface);
            color: var(--text);
            font: inherit;
            font-size: 13px;
        }

        .privacy-requests .request-form textarea {
            min-height: 76px;
            resize: vertical;
        }

        .privacy-requests .request-form input[type="file"] { padding: 8px; }

        .privacy-requests .request-form button {
            min-height: 40px;
            padding: 9px 14px;
            border: 1px solid var(--accent);
            border-radius: 9px;
            background: var(--accent);
            color: var(--accent-foreground);
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
        }

        .privacy-requests .request-form button:hover:not(:disabled) {
            border-color: var(--accent-hover);
            background: var(--accent-hover);
        }

        .privacy-requests .request-form button:disabled {
            border-color: var(--border);
            background: var(--surface-muted);
            color: var(--text-muted);
            cursor: not-allowed;
        }

        .privacy-requests .notice {
            padding: 12px 15px;
            border: 1px solid color-mix(in srgb, var(--accent) 24%, var(--border));
            border-radius: 10px;
            background: var(--surface);
            color: var(--accent);
        }

        .privacy-requests .notice--error {
            border-color: #fda4af;
            background: color-mix(in srgb, #e11d48 8%, var(--surface));
            color: #be123c;
        }

        .privacy-requests .signed-link { font-weight: 700; }

        html[data-theme="dark"] .privacy-requests {
            background-color: var(--background) !important;
            background-image:
                linear-gradient(to right, color-mix(in srgb, var(--border) 35%, transparent) 1px, transparent 1px),
                linear-gradient(to bottom, color-mix(in srgb, var(--border) 35%, transparent) 1px, transparent 1px) !important;
            color: var(--text);
        }

        html[data-theme="dark"] .privacy-requests .topbar,
        html[data-theme="dark"] .privacy-requests .panel {
            border-color: var(--border);
            background: var(--surface);
            color: var(--text);
        }

        html[data-theme="dark"] .privacy-requests .safety-note,
        html[data-theme="dark"] .privacy-requests .request-details,
        html[data-theme="dark"] .privacy-requests .notice {
            border-color: var(--border);
            background: var(--surface-muted);
            color: var(--text-muted);
        }

        html[data-theme="dark"] .privacy-requests .notice--error {
            border-color: color-mix(in srgb, #fda4af 35%, var(--border));
            background: color-mix(in srgb, #e11d48 15%, var(--surface));
            color: #fecdd3;
        }

        @media (max-width: 900px) {
            .privacy-requests .page-heading {
                grid-template-columns: minmax(0, 1fr);
                gap: 14px;
            }
        }

        @media (max-width: 760px) {
            .privacy-requests .topbar { padding-inline: 18px; }
            .privacy-requests .topbar h1 { font-size: 21px; }
            .privacy-requests .container { padding-inline: 16px; }
            .privacy-requests .panel { padding: 18px; }
            .privacy-requests .request-form,
            .privacy-requests .request-form--review {
                grid-template-columns: minmax(0, 1fr);
            }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php include __DIR__ . '/includes/hr_sidebar.php'; ?>
    <main class="main-area privacy-requests">
        <div class="topbar">
            <h1>Privacy Requests</h1>
            <div class="user-info">
                <span class="user-avatar">
                    <?php if (!empty($_SESSION['profile_picture'])): ?>
                        <img src="<?= htmlspecialchars(
                            rtrim((string) appBaseUrl(), '/') . '/uploads/avatars/'
                            . rawurlencode(basename((string) $_SESSION['profile_picture'])),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>" alt="">
                    <?php else: ?>
                        <?= htmlspecialchars(
                            mb_strtoupper(mb_substr(trim((string) ($_SESSION['full_name'] ?? 'Admin')), 0, 1)) ?: '?',
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    <?php endif; ?>
                </span>
                <span><?= htmlspecialchars((string) ($_SESSION['full_name'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?> (Admin)</span>
            </div>
        </div>
        <div class="container">
            <div class="page-heading">
                <div>
                    <h2>Data subject requests</h2>
                    <p class="safety-note">Open requests: <?= (int) $pendingCount ?>. Verify identity through a trusted channel before disclosing or changing personal information. Keep review notes factual and do not put unnecessary sensitive data in them.</p>
                </div>
            </div>
            <?php if ($successMessage !== ''): ?><p class="notice" role="status"><?= htmlspecialchars($successMessage, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($errorMessage !== ''): ?><p class="notice notice--error" role="alert"><?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?></p><?php endif; ?>
            <div class="request-list">
                <?php foreach ($requests as $request): ?>
                    <article class="panel">
                        <?php
                        $legacyStatusLabels = [
                            'identity_verification' => 'Identity verification needed (legacy)',
                            'identity_verified' => 'Identity verified (legacy status)',
                            'completed' => 'Completed (legacy)',
                        ];
                        ?>
                        <div class="request-meta">
                            <strong>Request #<?= (int) $request['request_id'] ?></strong>
                            <span><?= htmlspecialchars($allowedTypes[$request['request_type']] ?? 'Other', ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="status"><?= htmlspecialchars($allowedStatuses[$request['status']] ?? $legacyStatusLabels[$request['status']] ?? $request['status'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span>Submitted <?= htmlspecialchars((string) $request['submitted_at'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if (!empty($request['identity_verified_at'])): ?><span>Identity verified <?= htmlspecialchars((string) $request['identity_verified_at'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                        </div>
                        <p><strong><?= htmlspecialchars($request['requester_name'], ENT_QUOTES, 'UTF-8') ?></strong> · <a href="mailto:<?= htmlspecialchars($request['requester_email'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($request['requester_email'], ENT_QUOTES, 'UTF-8') ?></a></p>
                        <div class="request-details"><?= htmlspecialchars($request['request_details'], ENT_QUOTES, 'UTF-8') ?></div>
                        <form method="post" class="request-form request-form--review">
                            <?php csrfField(); ?>
                            <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                            <input type="hidden" name="action" value="review">
                            <label>Status
                                <select name="status" required>
                                    <?php foreach ($allowedStatuses as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>" <?= $request['status'] === $value ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>Internal review notes
                                <textarea name="admin_notes" maxlength="5000" placeholder="Document identity verification steps, decision rationale, and fulfillment notes."><?= htmlspecialchars((string) ($request['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </label>
                            <button type="submit">Save status</button>
                        </form>
                        <form method="post" class="request-form request-form--verify">
                            <?php csrfField(); ?>
                            <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                            <input type="hidden" name="action" value="verify_identity">
                            <label>Identity verification notes
                                <textarea name="admin_notes" maxlength="5000" required placeholder="Record the trusted-channel verification method."><?= htmlspecialchars((string) ($request['admin_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                            </label>
                            <button type="submit" <?= !empty($request['identity_verified_at']) ? 'disabled' : '' ?>><?= !empty($request['identity_verified_at']) ? 'Identity verified' : 'Mark identity verified' ?></button>
                        </form>
                        <form method="post" enctype="multipart/form-data" class="request-form request-form--upload">
                            <?php csrfField(); ?>
                            <input type="hidden" name="request_id" value="<?= (int) $request['request_id'] ?>">
                            <input type="hidden" name="action" value="upload_response">
                            <label>Response PDF
                                <input type="file" name="response_file" accept="application/pdf,.pdf" required>
                            </label>
                            <button type="submit" <?= empty($request['identity_verified_at']) ? 'disabled' : '' ?>>Attach response PDF</button>
                        </form>
                        <?php if (!empty($request['response_filename'])): ?>
                            <p class="safety-note">Response file: <?= htmlspecialchars((string) ($request['response_original_name'] ?? 'PDF'), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($request['response_expires_at'])): ?> · link expiry <?= htmlspecialchars((string) $request['response_expires_at'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?></p>
                            <?php if (!empty($signedResponseLinks[(int) $request['request_id']])): ?>
                                <p><a class="signed-link" href="<?= htmlspecialchars($signedResponseLinks[(int) $request['request_id']], ENT_QUOTES, 'UTF-8') ?>">Copy signed requester download link</a></p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
                <?php if (!$requests): ?><div class="panel">No privacy requests have been submitted.</div><?php endif; ?>
            </div>
        </div>
    </main>
</div>
</body>
</html>
