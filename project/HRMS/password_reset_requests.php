<?php
require_once __DIR__ . '/includes/config.php';
requireHR();
require_once dirname(__DIR__) . '/includes/password_reset.php';

if (!$posPdo) {
    http_response_code(503);
    exit('POS account database is unavailable.');
}

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $requestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT);
    $action = (string) ($_POST['action'] ?? '');
    if (!$requestId || !in_array($action, ['approve', 'handled', 'dismissed'], true)) {
        $error = 'Invalid password request action.';
    } else {
        if ($action === 'approve') {
            $lookup = $posPdo->prepare("SELECT request_id, user_id, account_system FROM password_reset_requests WHERE request_id = ? AND status = 'pending' LIMIT 1");
            $lookup->execute([$requestId]);
            $request = $lookup->fetch();
            $accountSystem = $request['account_system'] ?? 'pos';
            $accountPdo = $accountSystem === 'hrms' ? $pdo : $posPdo;
            $user = null;
            if ($request && !empty($request['user_id']) && in_array($accountSystem, ['pos', 'hrms'], true)) {
                $userLookup = $accountPdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
                $userLookup->execute([(int) $request['user_id']]);
                $user = $userLookup->fetch();
            }
            $sent = $user ? issuePasswordResetToken($posPdo, $user, $requestId, $accountSystem) : false;
            $message = $sent ? 'Approved. A one-time reset link was sent to the verified account email.' : 'The request could not be emailed. Verify the account has a configured email and try again.';
        } else {
            $stmt = $posPdo->prepare("
            UPDATE password_reset_requests
            SET status = ?, handled_by = ?, handled_at = NOW()
            WHERE request_id = ? AND status = 'pending'
        ");
            $stmt->execute([$action, (int) ($_SESSION['user_id'] ?? 0), $requestId]);
            $message = $stmt->rowCount() ? 'Password request updated.' : 'That request was already handled.';
        }
    }
}

$requests = $posPdo->query("
    SELECT r.request_id, r.username, r.account_system, r.user_id, r.status, r.requested_at, r.handled_at, r.email_sent_at
    FROM password_reset_requests r
    ORDER BY (r.status = 'pending') DESC, r.requested_at DESC
    LIMIT 200
")->fetchAll();
foreach ($requests as &$request) {
    $accountSystem = in_array($request['account_system'] ?? '', ['pos', 'hrms'], true)
        ? $request['account_system']
        : 'pos';
    $accountPdo = $accountSystem === 'hrms' ? $pdo : $posPdo;
    $user = null;
    if (!empty($request['user_id'])) {
        $userLookup = $accountPdo->prepare('SELECT id, is_active, two_factor_email FROM users WHERE id = ? LIMIT 1');
        $userLookup->execute([(int) $request['user_id']]);
        $user = $userLookup->fetch() ?: null;
    }

    if (!$user && $request['status'] === 'pending' && empty($request['user_id'])) {
        $posLookup = $posPdo->prepare('SELECT id, is_active, two_factor_email FROM users WHERE BINARY username = ? LIMIT 1');
        $posLookup->execute([$request['username']]);
        $posUser = $posLookup->fetch() ?: null;
        $hrmsLookup = $pdo->prepare('SELECT id, is_active, two_factor_email FROM users WHERE BINARY username = ? LIMIT 1');
        $hrmsLookup->execute([$request['username']]);
        $hrmsUser = $hrmsLookup->fetch() ?: null;

        if (($posUser && !$hrmsUser) || (!$posUser && $hrmsUser)) {
            $accountSystem = $hrmsUser ? 'hrms' : 'pos';
            $user = $hrmsUser ?: $posUser;
            $repair = $posPdo->prepare('UPDATE password_reset_requests SET account_system = ?, user_id = ? WHERE request_id = ? AND status = \'pending\' AND user_id IS NULL');
            $repair->execute([$accountSystem, (int) $user['id'], (int) $request['request_id']]);
        }
    }

    $request['account_system'] = $accountSystem;
    $request['user_id'] = $user['id'] ?? $request['user_id'];
    $request['account_active'] = $user['is_active'] ?? null;
    $request['two_factor_email'] = $user['two_factor_email'] ?? '';
}
unset($request);
$pendingCount = count(array_filter($requests, static fn($row) => $row['status'] === 'pending'));
$eligiblePendingCount = count(array_filter($requests, static function ($row) {
    return $row['status'] === 'pending'
        && !empty($row['user_id'])
        && (int) ($row['account_active'] ?? 0) === 1
        && filter_var(trim((string) ($row['two_factor_email'] ?? '')), FILTER_VALIDATE_EMAIL);
}));
$blockedPendingCount = max(0, $pendingCount - $eligiblePendingCount);
$canResetPasswords = ($_SESSION['role'] ?? '') === 'admin';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Password Requests — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <?php include __DIR__ . '/includes/supervisor_style.php'; ?>
    <style>
        .request-header {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 20px;
        }

        .request-header h2 {
            margin: 0;
            font-family: var(--font-display);
            color: var(--text-accent);
            font-size: 22px;
        }

        .request-header p {
            margin: 7px 0 0;
            color: var(--ink-soft);
        }

        .request-count {
            padding: 8px 13px;
            border-radius: 999px;
            background: var(--green-pale);
            color: var(--text-accent);
            font-weight: 800;
            white-space: nowrap;
        }

        .request-card {
            overflow: hidden;
            background: var(--white);
            border: 1px solid var(--line);
            border-radius: 18px;
            box-shadow: 0 10px 28px rgba(15, 23, 42, .055);
        }

        .request-table {
            width: 100%;
            border-collapse: collapse;
        }

        .request-table th,
        .request-table td {
            padding: 15px 18px;
            text-align: left;
            border-bottom: 1px solid var(--line);
        }

        .request-table th {
            color: var(--ink-soft);
            font-size: 11px;
            letter-spacing: .08em;
            text-transform: uppercase;
            background: var(--cream);
        }

        .request-table td {
            color: var(--ink);
            font-size: 14px;
        }

        .request-table tr:last-child td {
            border-bottom: 0;
        }

        .status {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 800;
            text-transform: capitalize;
        }

        .status.pending {
            color: #92400e;
            background: #fef3c7;
        }

        .status.handled {
            color: #166534;
            background: #dcfce7;
        }

        .status.dismissed {
            color: #475569;
            background: #e2e8f0;
        }

        .status.blocked {
            color: #9f1239;
            background: #ffe4e6;
        }

        .request-blocker {
            display: block;
            max-width: 300px;
            margin-top: 6px;
            color: var(--ink-soft);
            font-size: 11px;
            line-height: 1.5;
        }

        .request-actions button:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .request-actions {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .request-actions form {
            display: flex;
            gap: 7px;
            flex-wrap: wrap;
        }

        .request-actions button {
            min-height: 38px;
            border: 0;
            border-radius: 10px;
            padding: 8px 12px;
            color: #fff;
            background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
            font: 600 12.5px var(--font-body);
            cursor: pointer;
        }

        .request-actions button.dismiss {
            color: var(--ink);
            background: var(--line);
        }

        .empty {
            padding: 42px 18px;
            color: var(--ink-soft);
            text-align: center;
        }

        .notice {
            margin-bottom: 18px;
            padding: 12px 16px;
            border-radius: 12px;
            background: var(--green-pale);
            color: var(--text-accent);
            font-size: 14px;
        }

        .next-steps {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 18px;
            margin-bottom: 20px;
            padding: 18px 20px;
            border: 1px solid #bfdbfe;
            border-radius: 16px;
            background: linear-gradient(135deg, #eff6ff, #f8fbff);
        }

        .next-steps strong {
            display: block;
            color: var(--text-accent);
            font-family: var(--font-display);
            font-size: 14px;
            margin-bottom: 5px;
        }

        .next-steps p {
            margin: 0;
            color: var(--ink-soft);
            font-size: 13px;
            line-height: 1.5;
        }

        .next-steps a {
            flex-shrink: 0;
            padding: 10px 14px;
            border-radius: 10px;
            color: #fff;
            background: linear-gradient(135deg, var(--green-mid), var(--green-deep));
            font-size: 13px;
            font-weight: 700;
        }

        @media(max-width:700px) {
            .request-header {
                align-items: flex-start;
                flex-direction: column
            }

            .request-card {
                overflow-x: auto
            }

            .request-table {
                min-width: 680px
            }
        }

        html[data-theme="dark"] .request-card {
            background: #0D1013;
            border-color: #26313A;
        }

        html[data-theme="dark"] .request-table th {
            background: #111518;
        }

        html[data-theme="dark"] .request-table td {
            color: var(--ink);
            border-color: #26313A;
        }

        html[data-theme="dark"] .next-steps {
            border-color: #1e40af;
            background: #172554;
        }

        @media(max-width:700px) {
            .next-steps {
                align-items: flex-start;
                flex-direction: column
            }

            .next-steps a {
                width: 100%;
                text-align: center
            }
        }
    </style>
</head>

<body>
    <div class="app-shell">
        <?php $activeNav = 'password_reset_requests';
        include __DIR__ . '/includes/hr_sidebar.php'; ?>
        <main class="main-area">
            <header class="topbar">
                <h1>Password Requests <?php if ($pendingCount > 0): ?><span class="badge"
                            style="background:var(--mustard); color:#fff;"><?= $pendingCount ?>
                            pending</span><?php endif; ?></h1>
                <div class="user-info"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: '?') ?><?php endif; ?></span><span><?= clean($_SESSION['full_name'] ?? 'User') ?>
                        (<?= clean(roleLabel($_SESSION['role'] ?? '')) ?>)</span></div>
            </header>
            <div class="container">
                <div class="request-header">
                    <div>
                        <h2>Password reset requests</h2>
                        <p>Verify the user’s identity, then approve a one-time link to the verified account email.</p>
                    </div><span class="request-count"><?= $pendingCount ?> pending · <?= $eligiblePendingCount ?>
                        ready</span>
                </div>
                <div class="next-steps">
                    <div><strong>What to do after opening a request</strong>
                        <p>Verify the user first. A request must match an active account with a valid verified email
                            before an approval link can be sent.
                            <?= $blockedPendingCount > 0 ? (int) $blockedPendingCount . ' pending request(s) need account or email repair.' : 'All pending requests are ready for review.' ?>
                        </p>
                    </div>
                    <?php if ($canResetPasswords): ?><a href="admin_dashboard.php#user-accounts">Open User
                            Accounts</a><?php else: ?><span
                            style="color:var(--ink-soft);font-size:12px;font-weight:700;">Ask an Admin to reset the
                            password</span><?php endif; ?>
                </div>
                <?php if ($message !== ''): ?>
                    <div class="notice"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <?php if ($error !== ''): ?>
                    <div class="notice"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
                <div class="request-card">
                    <table class="request-table">
                        <thead>
                            <tr>
                                <th>Username</th>
                                <th>Requested</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($requests as $request): ?>
                                <?php
                                $requestReady = !empty($request['user_id'])
                                    && (int) ($request['account_active'] ?? 0) === 1
                                    && filter_var(trim((string) ($request['two_factor_email'] ?? '')), FILTER_VALIDATE_EMAIL);
                                $blocker = empty($request['user_id']) || $request['account_active'] === null
                                    ? 'No account is linked to this username. Verify the account before taking action.'
                                    : ((int) $request['account_active'] !== 1
                                        ? 'The account is inactive. Reactivate it before issuing a reset link.'
                                        : (!$requestReady ? 'A valid verified account email is missing. Add it to the account before approval.' : ''));
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($request['username'], ENT_QUOTES, 'UTF-8') ?></strong>
                                        <?php if ($request['status'] === 'pending' && !$requestReady): ?><span
                                                class="request-blocker"><?= htmlspecialchars($blocker, ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars($request['requested_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <span
                                            class="status <?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($request['status'] === 'pending' && !$requestReady): ?><span
                                                class="status blocked">Action needed</span><?php endif; ?>
                                    </td>
                                    <td><?php if ($request['status'] === 'pending'): ?>
                                            <div class="request-actions">
                                                <form method="post"><?php csrfField(); ?><input type="hidden" name="request_id"
                                                        value="<?= (int) $request['request_id'] ?>"><button name="action"
                                                        value="approve" <?= $requestReady ? '' : 'disabled title="' . htmlspecialchars($blocker, ENT_QUOTES, 'UTF-8') . '"' ?>>Approve &amp;
                                                        email link</button><button name="action" value="dismissed"
                                                        class="dismiss">Dismiss</button></form>
                                            </div>
                                        <?php elseif ($request['status'] === 'approved'): ?>Link
                                            sent<?php else: ?><?= htmlspecialchars($request['status'], ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$requests): ?>
                                <tr>
                                    <td colspan="4" class="empty">No password reset requests have been submitted.</td>
                                </tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>

</html>