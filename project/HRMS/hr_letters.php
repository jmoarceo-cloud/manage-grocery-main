<?php
require_once 'includes/hr_letters.php';
requireHR();

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $id = (int) ($_POST['id'] ?? 0);
    $action = $_POST['action'] ?? '';

    try {
        $q = $pdo->prepare('SELECT * FROM hr_letters WHERE id = ?');
        $q->execute([$id]);
        $letter = $q->fetch();

        if (!$letter) {
            throw new RuntimeException('Letter not found.');
        }
        if (
            !in_array($_SESSION['role'] ?? '', ['admin', 'hr_manager'], true)
            && (int) $letter['created_by'] !== (int) ($_SESSION['user_id'] ?? 0)
        ) {
            throw new RuntimeException('You are not allowed to change this letter.');
        }

        if ($action === 'submit' && in_array($letter['status'], ['draft', 'rejected'], true)) {
            $pdo->prepare("UPDATE hr_letters SET status = 'pending_approval' WHERE id = ?")->execute([$id]);
            hrLetterLog($pdo, $id, 'submitted');
            $msg = ($letter['letter_type'] === 'contract' && !empty($letter['applicant_id']))
                ? 'Contract submitted. An Admin or HR Manager must approve it here; after approval, it will be emailed to the applicant for e-signature.'
                : 'Letter submitted. An Admin or HR Manager can approve it from the Pending approval row below.';
        } elseif (
            $action === 'approve'
            && in_array($_SESSION['role'], ['admin', 'hr_manager'], true)
            && $letter['status'] === 'pending_approval'
        ) {
            $pdo->prepare("UPDATE hr_letters SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$_SESSION['user_id'], $id]);
            hrLetterLog($pdo, $id, 'approved');
            hrLetterNotify($posPdo, 'Letter ' . $letter['reference_no'] . ' was approved.');
            if ($letter['letter_type'] === 'contract' && !empty($letter['applicant_id'])) {
                [$emailSent, $mailMessage] = hrLetterEmail($pdo, $letter);
                if ($emailSent) {
                    $pdo->prepare("UPDATE hr_letters SET status = 'sent', sent_at = NOW() WHERE id = ? AND status = 'approved'")->execute([$id]);
                    hrLetterLog($pdo, $id, 'sent', 'Applicant contract emailed for electronic signature.');
                    $msg = 'Contract approved and emailed to the applicant for review and electronic signature.';
                } else {
                    $error = 'Contract approved, but the applicant email was not sent. ' . $mailMessage . ' Use Send email to retry.';
                }
            } else {
                $msg = 'Letter approved.';
            }
        } elseif (
            $action === 'reject'
            && in_array($_SESSION['role'], ['admin', 'hr_manager'], true)
            && $letter['status'] === 'pending_approval'
        ) {
            $reason = trim($_POST['reason'] ?? '');
            $pdo->prepare("UPDATE hr_letters SET status = 'rejected', rejection_reason = ? WHERE id = ?")
                ->execute([$reason, $id]);
            hrLetterLog($pdo, $id, 'rejected', $reason);
            $msg = 'Letter rejected.';
        } elseif ($action === 'send' && in_array($letter['status'], ['approved', 'sent'], true)) {
            [$ok, $mailMessage] = hrLetterEmail($pdo, $letter);
            if ($ok) {
                $pdo->prepare("UPDATE hr_letters SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$id]);
                hrLetterLog($pdo, $id, 'sent');
            }
            $msg = $mailMessage;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$where = ($_SESSION['role'] ?? '') === 'hr' ? 'WHERE l.created_by = ?' : '';
$stmt = $pdo->prepare("
    SELECT l.*, CONCAT(e.first_name, ' ', e.last_name) AS employee_name
    FROM hr_letters l
    LEFT JOIN employees e ON e.employee_id = l.employee_id
    $where
    ORDER BY l.created_at DESC
");
$stmt->execute($where ? [$_SESSION['user_id']] : []);
$letters = $stmt->fetchAll();

$statusCounts = [];
foreach ($letters as $letter) {
    $statusCounts[$letter['status']] = ($statusCounts[$letter['status']] ?? 0) + 1;
}

$statusLabels = [
    'draft' => 'Draft',
    'pending_approval' => 'Pending approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'sent' => 'Sent',
    'accepted' => 'Accepted',
    'declined' => 'Declined',
];

hrLetterHeader('Contracts & Official Letters');
?>
<style>
    .app-shell > .main-area {
        width: calc(100% - 248px);
        margin-left: 248px;
    }

    .main-content {
        width: 100%;
        min-height: 100vh;
        margin: 0;
        padding: 0 clamp(18px, 2.2vw, 34px) 48px;
        background: transparent;
    }

    .page-header {
        max-width: none;
        margin: 0;
        padding: clamp(20px, 3vw, 30px) 0 18px;
    }

    .page-header h1 {
        color: var(--text-accent);
        font-family: var(--font-headline);
        font-size: clamp(1.4rem, 2.2vw, 2rem);
        line-height: 1.2;
    }

    .page-header p {
        margin-top: 7px;
        color: var(--ink-soft);
        font-size: .875rem;
    }

    .letters-page {
        width: 100%;
        max-width: none;
        min-width: 0;
        margin: 0;
    }

    .letters-intro {
        margin: 4px 0 0;
        color: var(--ink-soft);
        font-size: .875rem;
        line-height: 1.55;
    }

    .letters-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        margin: 4px 0 22px;
    }

    .letters-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .letter-btn {
        display: inline-flex;
        min-height: 42px;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 9px 15px;
        border: 1px solid transparent;
        border-radius: var(--radius-sm);
        background: var(--accent);
        color: var(--accent-foreground);
        font: 700 .8125rem/1.25 var(--font-body);
        text-align: center;
        text-decoration: none;
        cursor: pointer;
        transition: transform .16s ease, box-shadow .16s ease, background-color .16s ease, border-color .16s ease, color .16s ease;
    }

    .letter-btn:hover {
        background: var(--accent-hover);
        color: #fff;
        transform: translateY(-1px);
        box-shadow: 0 7px 16px color-mix(in srgb, var(--accent) 20%, transparent);
    }

    .letter-btn:active {
        transform: translateY(0);
        box-shadow: none;
    }

    .letter-btn:focus-visible {
        outline: 3px solid var(--focus-ring);
        outline-offset: 2px;
    }

    .letter-btn--light {
        border-color: var(--line);
        background: var(--white);
        color: var(--text-accent);
        box-shadow: none;
    }

    .letter-btn--light:hover {
        border-color: color-mix(in srgb, var(--accent) 35%, var(--line));
        background: var(--green-pale);
        color: var(--text-accent);
        box-shadow: none;
    }

    .letter-btn--danger {
        border-color: #dc2626;
        background: #dc2626;
        color: #fff;
        box-shadow: 0 2px 8px rgb(220 38 38 / 14%);
    }

    .letter-btn--danger:hover {
        border-color: #b91c1c;
        background: #b91c1c;
        color: #fff;
    }

    .letter-btn--success {
        border-color: #15803d;
        background: #15803d;
        color: #fff;
    }

    .letter-btn--success:hover {
        border-color: #166534;
        background: #166534;
        color: #fff;
    }

    .letter-summary {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 22px;
    }

    .letter-stat {
        min-width: 0;
        padding: 16px 18px;
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        background: var(--white);
        box-shadow: var(--shadow-card);
    }

    .letter-stat__label {
        color: var(--ink-soft);
        font-size: .7rem;
        font-weight: 700;
        letter-spacing: .06em;
        line-height: 1.45;
        text-transform: uppercase;
    }

    .letter-stat__value {
        display: block;
        margin-top: 4px;
        color: var(--text-accent);
        font-size: clamp(1.3rem, 2vw, 1.65rem);
        font-weight: 700;
        line-height: 1.2;
    }

    .letters-panel {
        overflow: hidden;
        border: 1px solid var(--line);
        border-radius: var(--radius-lg);
        background: var(--white);
        box-shadow: var(--shadow-card);
    }

    .letters-panel__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 18px 20px;
        border-bottom: 1px solid var(--line);
    }

    .letters-panel__head h2 {
        margin: 0;
        color: var(--ink);
        font-family: var(--font-headline);
        font-size: clamp(1.05rem, 1.5vw, 1.25rem);
        font-weight: 500;
        line-height: 1.3;
    }

    .letters-panel__head p {
        margin: 5px 0 0;
        color: var(--ink-soft);
        font-size: .8rem;
        line-height: 1.55;
    }

    .letters-table-wrap {
        max-width: 100%;
        overflow-x: auto;
        overscroll-behavior-x: contain;
        -webkit-overflow-scrolling: touch;
    }

    .letters-table {
        width: 100%;
        min-width: 980px;
        border-collapse: collapse;
        color: var(--ink);
        font-size: .8125rem;
    }

    .letters-table th {
        background: var(--surface-muted);
        color: var(--ink-soft);
        font-size: .6875rem;
        font-weight: 750;
        letter-spacing: .06em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .letters-table th,
    .letters-table td {
        padding: 14px 16px;
        border-bottom: 1px solid var(--line);
        text-align: left;
        vertical-align: middle;
    }

    .letters-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .letters-table tbody tr {
        transition: background-color .16s ease;
    }

    .letters-table tbody tr:hover {
        background: color-mix(in srgb, var(--accent) 4%, var(--white));
    }

    .letter-reference {
        color: var(--text-accent);
        font-size: .75rem;
        font-weight: 750;
        white-space: nowrap;
    }

    .letter-recipient {
        color: var(--ink);
        font-weight: 700;
        overflow-wrap: anywhere;
    }

    .letter-subject {
        display: inline-block;
        max-width: 230px;
        color: var(--ink-soft);
        overflow-wrap: anywhere;
    }

    .letter-date {
        color: var(--ink-soft);
        font-size: .75rem;
        white-space: nowrap;
    }

    .status-badge {
        display: inline-flex;
        min-height: 26px;
        align-items: center;
        padding: 4px 9px;
        border-radius: var(--radius-pill);
        font-size: .6875rem;
        font-weight: 750;
        line-height: 1.2;
        white-space: nowrap;
    }

    .status-badge--draft {
        background: #f1f5f9;
        color: #475569;
    }

    .status-badge--pending_approval {
        background: #fef3c7;
        color: #92400e;
    }

    .status-badge--approved,
    .status-badge--accepted {
        background: #dcfce7;
        color: #166534;
    }

    .status-badge--sent {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .status-badge--rejected,
    .status-badge--declined {
        background: #fee2e2;
        color: #b91c1c;
    }

    .letter-row-actions {
        display: flex;
        min-width: 0;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .letter-row-actions .letter-btn {
        min-height: 34px;
        padding: 7px 10px;
        border-radius: 7px;
        font-size: .72rem;
    }

    .letter-row-actions form {
        display: inline-flex;
        gap: 6px;
        flex-wrap: wrap;
        margin: 0;
    }

    .empty-letters {
        padding: clamp(32px, 5vw, 48px) 20px;
        color: var(--ink-soft);
        text-align: center;
    }

    .empty-letters strong {
        display: block;
        margin-bottom: 6px;
        color: var(--ink);
        font-size: .95rem;
    }

    .alert-card {
        margin-bottom: 16px;
        padding: 13px 16px;
        border-radius: var(--radius-md);
        font-size: .8125rem;
        font-weight: 600;
        line-height: 1.55;
        overflow-wrap: anywhere;
    }

    .alert-card--success {
        border: 1px solid #bbf7d0;
        background: #dcfce7;
        color: #166534;
    }

    .alert-card--error {
        border: 1px solid #fecaca;
        background: #fee2e2;
        color: #b91c1c;
    }

    .workflow-note {
        margin: 0 0 18px;
        padding: 14px 16px;
        border: 1px solid #bfdbfe;
        border-radius: var(--radius-md);
        background: #eff6ff;
        color: #1e3a5f;
        font-size: .8125rem;
        line-height: 1.65;
        overflow-wrap: anywhere;
    }

    .workflow-note strong {
        color: #123c5c;
    }

    .workflow-note code {
        overflow-wrap: anywhere;
    }

    html[data-theme="dark"] .letters-table th {
        background: var(--surface-muted);
    }

    html[data-theme="dark"] .letters-table tbody tr:hover {
        background: color-mix(in srgb, var(--accent) 10%, var(--surface));
    }

    html[data-theme="dark"] .status-badge--draft {
        background: #334155;
        color: #e2e8f0;
    }

    html[data-theme="dark"] .status-badge--pending_approval {
        background: #422006;
        color: #fde68a;
    }

    html[data-theme="dark"] .status-badge--approved,
    html[data-theme="dark"] .status-badge--accepted {
        background: #052e16;
        color: #86efac;
    }

    html[data-theme="dark"] .status-badge--sent {
        background: #172554;
        color: #bfdbfe;
    }

    html[data-theme="dark"] .status-badge--rejected,
    html[data-theme="dark"] .status-badge--declined {
        background: #450a0a;
        color: #fca5a5;
    }

    html[data-theme="dark"] .alert-card--success {
        border-color: #166534;
        background: #052e16;
        color: #86efac;
    }

    html[data-theme="dark"] .alert-card--error {
        border-color: #7f1d1d;
        background: #450a0a;
        color: #fca5a5;
    }

    html[data-theme="dark"] .workflow-note {
        border-color: #1e3a5f;
        background: #172554;
        color: #dbeafe;
    }

    html[data-theme="dark"] .workflow-note strong {
        color: #bfdbfe;
    }

    @media (max-width: 920px) {
        .letter-summary {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }

    @media (max-width: 640px) {
        .main-content {
            padding-bottom: 36px;
        }

        .page-header {
            padding: 18px 0 14px;
        }

        .letter-summary {
            gap: 10px;
        }

        .letter-stat {
            padding: 13px;
        }

        .letters-panel__head {
            align-items: flex-start;
        }

        .letters-toolbar {
            align-items: stretch;
        }

        .letters-actions {
            width: 100%;
        }

        .letters-actions .letter-btn {
            flex: 1 1 30%;
            padding-right: 9px;
            padding-left: 9px;
        }
    }

    @media (max-width: 400px) {
        .letters-actions {
            display: grid;
            grid-template-columns: 1fr;
        }

        .letters-actions .letter-btn {
            width: 100%;
        }
    }

    @media (prefers-reduced-motion: reduce) {

        .letter-btn,
        .letters-table tbody tr {
            transition-duration: .01ms;
        }
    }
</style>

<div class="letters-page">
    <?php if ($msg): ?>
        <div class="alert-card alert-card--success"><?= hrLetterEsc($msg) ?></div><?php endif; ?>
    <?php if ($error): ?>
        <div class="alert-card alert-card--error"><?= hrLetterEsc($error) ?></div><?php endif; ?>

    <div class="workflow-note">
        <strong>Next steps:</strong> submitted letters appear below as <strong>Pending approval</strong>.
        An Admin or HR Manager opens this page and selects <strong>Approve</strong> on that row.
        For an applicant contract, approval automatically emails the applicant a secure link to review and e-sign.
        If the email cannot be delivered, the letter stays <strong>Approved</strong> and the row shows <strong>Send
            email</strong> so HR can retry.
        For links opened on another device, configure <code>APP_PUBLIC_BASE_URL</code> in <code>.env</code> to your
        public HTTPS domain or active tunnel; localhost is only reachable from this computer. Resend already-sent
        contract links after changing it.
    </div>

    <div class="letters-toolbar">
        <p class="letters-intro">Prepare, approve, send, and track employee and applicant letters in one place.</p>
        <div class="letters-actions">
            <a class="letter-btn" href="hr_letter_edit.php"><i class="fa-solid fa-plus"></i> Create letter</a>
            <a class="letter-btn letter-btn--light" href="hr_letter_templates.php"><i class="fa-regular fa-copy"></i>
                Templates</a>
            <a class="letter-btn letter-btn--light" href="hr_letter_reminders.php"><i class="fa-regular fa-clock"></i>
                Reminders</a>
        </div>
    </div>

    <section class="letter-summary" aria-label="Letter summary">
        <div class="letter-stat"><span class="letter-stat__label">Total letters</span><strong
                class="letter-stat__value"><?= count($letters) ?></strong></div>
        <div class="letter-stat"><span class="letter-stat__label">Needs approval</span><strong
                class="letter-stat__value"><?= (int) ($statusCounts['pending_approval'] ?? 0) ?></strong></div>
        <div class="letter-stat"><span class="letter-stat__label">Awaiting response</span><strong
                class="letter-stat__value"><?= (int) ($statusCounts['sent'] ?? 0) ?></strong></div>
        <div class="letter-stat"><span class="letter-stat__label">Accepted</span><strong
                class="letter-stat__value"><?= (int) ($statusCounts['accepted'] ?? 0) ?></strong></div>
    </section>

    <section class="letters-panel">
        <div class="letters-panel__head">
            <div>
                <h2>Letter records</h2>
                <p>Review the latest contracts, offers, notices, and official HR documents. Pending letters have
                    approval actions in the table below.</p>
            </div>
        </div>
        <?php if (!$letters): ?>
            <div class="empty-letters"><strong>No letters yet</strong>Create a draft to start your first contract or
                official HR letter.</div>
        <?php else: ?>
            <div class="letters-table-wrap">
                <table class="letters-table">
                    <thead>
                        <tr>
                            <th>Reference</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($letters as $letter): ?>
                            <?php $status = $letter['status']; ?>
                            <tr>
                                <td><span class="letter-reference"><?= hrLetterEsc($letter['reference_no']) ?></span></td>
                                <td><span
                                        class="letter-recipient"><?= hrLetterEsc($letter['recipient_name']) ?></span><?php if (!empty($letter['employee_name'])): ?><br><small
                                            class="muted"><?= hrLetterEsc($letter['employee_name']) ?></small><?php endif; ?></td>
                                <td><span class="letter-subject"><?= hrLetterEsc($letter['subject']) ?></span></td>
                                <td><span
                                        class="status-badge status-badge--<?= hrLetterEsc($status) ?>"><?= hrLetterEsc($statusLabels[$status] ?? ucwords(str_replace('_', ' ', $status))) ?></span>
                                </td>
                                <td><span
                                        class="letter-date"><?= hrLetterEsc(date('M d, Y', strtotime($letter['created_at']))) ?></span>
                                </td>
                                <td>
                                    <div class="letter-row-actions">
                                        <a class="letter-btn letter-btn--light"
                                            href="hr_letter_edit.php?id=<?= enc_id($letter['id']) ?>">View / Edit</a>
                                        <a class="letter-btn letter-btn--light"
                                            href="hr_letter_print.php?id=<?= enc_id($letter['id']) ?>">Print</a>
                                        <?php if (!empty($letter['signed_file'])): ?><a class="letter-btn letter-btn--light"
                                                href="hr_letter_download.php?id=<?= enc_id($letter['id']) ?>">Signed
                                                PDF</a><?php endif; ?>
                                        <?php if (in_array($status, ['sent', 'accepted', 'declined'], true) && empty($letter['signed_file'])): ?><a
                                                class="letter-btn letter-btn--light"
                                                href="hr_letter_signed.php?id=<?= enc_id($letter['id']) ?>">Upload
                                                signed</a><?php endif; ?>
                                        <?php if (in_array($status, ['draft', 'rejected'], true)): ?>
                                            <form method="post"><?php csrfField(); ?><input type="hidden" name="id"
                                                    value="<?= (int) $letter['id'] ?>"><button class="letter-btn" name="action"
                                                    value="submit">Submit</button></form>
                                        <?php endif; ?>
                                        <?php if ($status === 'pending_approval' && in_array($_SESSION['role'], ['admin', 'hr_manager'], true)): ?>
                                            <form method="post"><?php csrfField(); ?><input type="hidden" name="id"
                                                    value="<?= (int) $letter['id'] ?>"><button class="letter-btn" name="action"
                                                    value="approve">Approve</button><button class="letter-btn letter-btn--danger"
                                                    name="action" value="reject">Reject</button></form>
                                        <?php elseif ($status === 'approved'): ?>
                                            <form method="post"><?php csrfField(); ?><input type="hidden" name="id"
                                                    value="<?= (int) $letter['id'] ?>"><button class="letter-btn" name="action"
                                                    value="send">Send email</button></form>
                                        <?php elseif ($status === 'sent' && $letter['letter_type'] === 'contract' && !empty($letter['applicant_id'])): ?>
                                            <form method="post"><?php csrfField(); ?><input type="hidden" name="id"
                                                    value="<?= (int) $letter['id'] ?>"><button class="letter-btn letter-btn--light"
                                                    name="action" value="send">Resend e-sign email</button></form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>
<?php hrLetterFooter(); ?>