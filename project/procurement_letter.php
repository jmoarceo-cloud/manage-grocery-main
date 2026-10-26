<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'inventory_staff', 'finance'], true)) {
    http_response_code(403);
    exit('You are not authorized to view procurement letters.');
}
$requestId = dec_id($_GET['request_id'] ?? '');
if ($requestId <= 0 && ctype_digit((string) ($_GET['request_id'] ?? ''))) {
    $requestId = (int) $_GET['request_id'];
}
$stmt = $pdo->prepare("SELECT ir.*, s.company_name AS supplier_name, p.product_name, u.full_name AS requester_account_name FROM inventory_requests ir LEFT JOIN suppliers s ON s.id = ir.supplier_id LEFT JOIN products p ON p.id = ir.product_id LEFT JOIN users u ON u.employee_id = ir.requested_by_employee_id WHERE ir.request_id = ? LIMIT 1");
$stmt->execute([$requestId]);
$request = $stmt->fetch();
if (!$request) {
    http_response_code(404);
    exit('Procurement request not found.');
}
$requestItems = [$request];
if (!empty($request['request_group_id'])) {
    $groupStmt = $pdo->prepare("SELECT ir.*, s.company_name AS supplier_name, p.product_name, u.full_name AS requester_account_name FROM inventory_requests ir LEFT JOIN suppliers s ON s.id = ir.supplier_id LEFT JOIN products p ON p.id = ir.product_id LEFT JOIN users u ON u.employee_id = ir.requested_by_employee_id WHERE ir.request_group_id = ? ORDER BY ir.request_id ASC");
    $groupStmt->execute([$request['request_group_id']]);
    $requestItems = $groupStmt->fetchAll();
    if (!$requestItems) {
        $requestItems = [$request];
    }
    $request = $requestItems[0];
}
$requester = $request['requester_full_name'] ?: ($request['requester_account_name'] ?: 'Inventory Staff');
$supplier = $request['supplier_name'] ?: 'To be assigned';
$totalAmount = 0.0;
$hasEstimatedCost = false;
foreach ($requestItems as $item) {
    if ($item['estimated_cost'] !== null) {
        $totalAmount += (float) $item['estimated_cost'];
        $hasEstimatedCost = true;
    }
}
$amount = $hasEstimatedCost ? peso($totalAmount) : 'To be confirmed';
$requestNumbers = implode(', ', array_map(
    static function ($item) {
        return '#' . (int) $item['request_id'];
    },
    $requestItems
));
$date = date('F j, Y', strtotime($request['requested_at']));
$signatureImage = static function ($value) {
    return is_string($value) && preg_match('#^data:image/png;base64,[A-Za-z0-9+/=]+$#', $value) ? $value : ''; };
$fullName = trim((string) ($_SESSION['full_name'] ?? ''));
$initial = strtoupper(substr($fullName !== '' ? $fullName : 'A', 0, 1));
$avatar = $_SESSION['profile_picture'] ?? '';
$iconDocument = '<svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M5 2.5h7l3 3v12H5a1.5 1.5 0 0 1-1.5-1.5V4A1.5 1.5 0 0 1 5 2.5z" stroke="currentColor" stroke-width="1.5"/><path d="M12 2.5v3h3M7 10h6M7 13h6M7 16h3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>';
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Formality Letter #<?= (int) $requestId ?></title>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <style>
        body {
            overflow-x: hidden
        }

        .letter-layout {
            display: grid;
            grid-template-columns: minmax(0, 1.35fr) minmax(280px, .65fr);
            gap: 20px;
            align-items: start
        }

        .panel-body {
            padding: 26px
        }

        .letter-paper {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            padding: 42px 48px;
            box-shadow: 0 8px 24px rgba(18, 60, 92, .08)
        }

        .brand {
            color: var(--text-accent);
            font-size: 12px;
            font-weight: 800;
            letter-spacing: .13em;
            text-transform: uppercase
        }

        .letter-paper h2 {
            text-align: center;
            color: var(--ink);
            font-size: 25px;
            margin: 32px 0 6px
        }

        .letter-ref {
            text-align: center;
            color: var(--ink-soft);
            font-size: 12px
        }

        .letter-date {
            margin: 32px 0 20px;
            color: var(--ink-soft);
            font-size: 13px
        }

        .letter-paper p {
            font-size: 14px;
            line-height: 1.75;
            color: var(--ink)
        }

        .details {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 15px 18px;
            margin: 22px 0
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            gap: 18px;
            padding: 9px 0;
            border-bottom: 1px solid #edf1f4;
            font-size: 13px
        }

        .detail-row:last-child {
            border-bottom: 0
        }

        .detail-row strong {
            color: var(--text-accent);
            text-align: right
        }

        .status-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 999px;
            background: #e0f2fe;
            color: #075985;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase
        }

        .side-title {
            margin: 0;
            color: var(--ink);
            font-size: 17px
        }

        .side-copy {
            color: var(--ink-soft);
            font-size: 13px;
            line-height: 1.55
        }

        .sig-list {
            display: grid;
            gap: 14px;
            margin-top: 18px
        }

        .sig-card {
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 12px;
            background: #f8fafc
        }

        .sig-card strong {
            display: block;
            color: var(--ink);
            font-size: 13px
        }

        .sig-card img {
            display: block;
            width: 100%;
            height: 74px;
            object-fit: contain;
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 6px;
            margin: 8px 0
        }

        .sig-card span {
            color: var(--ink-soft);
            font-size: 12px
        }

        .toolbar {
            display: flex;
            justify-content: flex-end;
            gap: 9px;
            margin-bottom: 16px
        }

        .toolbar a,
        .toolbar button {
            font: 700 13px inherit;
            text-decoration: none;
            cursor: pointer
        }

        .toolbar a {
            background: var(--white);
            color: var(--text-accent);
            border: 1px solid var(--line)
        }

        .toolbar button {
            border: 0
        }

        .toolbar a,
        .toolbar button {
            padding: 10px 14px;
            border-radius: 9px
        }

        .print-only {
            display: none
        }

        @media(max-width:900px) {
            .letter-layout {
                grid-template-columns: 1fr
            }
        }

        @media(max-width:650px) {
            .letter-paper {
                padding: 28px 20px
            }

            .detail-row {
                display: block
            }

            .detail-row strong {
                display: block;
                text-align: left;
                margin-top: 4px
            }

            .toolbar {
                justify-content: stretch
            }

            .toolbar>* {
                flex: 1;
                text-align: center
            }
        }

        @media print {
            body {
                background: #fff
            }

            .main-area {
                margin: 0
            }

            .sidebar,
            .topbar,
            .page-header,
            .toolbar,
            .side-panel {
                display: none !important
            }

            .content {
                padding: 0
            }

            .letter-layout {
                display: block
            }

            .letter-paper {
                border: 0;
                box-shadow: none;
                padding: 20px;
                max-width: none
            }

            .print-only {
                display: block
            }
        }
    </style>
</head>

<body>
    <div class="app-shell"><?php $active = 'inventory_requests';
    include __DIR__ . '/includes/sidebar.php'; ?>
        <div class="main-area">
            <header class="topbar">
                <div class="topbar-icon"><?= $iconDocument ?></div>
                <h1>Formality Letter</h1>
                <div class="topbar-user"><span class="user-avatar"><?php if ($avatar): ?><img
                                src="uploads/avatars/<?= clean($avatar) ?>"
                                alt=""><?php else: ?><?= clean($initial) ?><?php endif; ?></span><span
                        class="user-name"><?= clean($fullName !== '' ? $fullName : 'Administrator') ?></span></div>
            </header>
            <main class="content">
                <div class="page-header">
                    <h1>Formality Letter</h1>
                    <p class="page-subtitle">Review the combined request details and approval signatures for request line<?= count($requestItems) === 1 ? '' : 's' ?>
                        <?= clean($requestNumbers) ?>.</p>
                </div>
                <div class="toolbar"><a href="inventory_requests.php">← Back to Inventory Requests</a><button
                        class="action-btn" type="button" onclick="window.print()">Print / Save PDF</button></div>
                <div class="letter-layout">
                    <article class="panel">
                        <div class="panel-body">
                            <div class="letter-paper">
                                <div class="brand">HCI GroceryOne · Procurement</div>
                                <h2>Formality Letter</h2>
                                <div class="letter-ref">Request lines <?= clean($requestNumbers) ?> · <span
                                        class="status-badge"><?= clean(strtoupper(str_replace('_', ' ', $request['status']))) ?></span>
                                </div>
                                <div class="letter-date"><?= clean($date) ?></div>
                                <p>To whom it may concern,</p>
                                <p>This letter formally documents the procurement request submitted through the Manage
                                    Grocery System. The request is subject to the required requester, Finance, supplier,
                                    and Admin review before the goods are received and added to inventory.</p>
                                <div class="details">
                                    <div class="detail-row">
                                        <span>Requested items</span>
                                        <strong>
                                            <?php foreach ($requestItems as $item): ?>
                                                <?= clean($item['item_name']) ?> — <?= (int) $item['quantity'] ?> pcs<?= $item['estimated_cost'] !== null ? ' — ' . clean(peso($item['estimated_cost'])) : '' ?><br>
                                            <?php endforeach; ?>
                                        </strong>
                                    </div>
                                    <div class="detail-row"><span>Estimated total
                                            cost</span><strong><?= clean($amount) ?></strong></div>
                                    <div class="detail-row">
                                        <span>Supplier</span><strong><?= clean($supplier) ?></strong></div>
                                    <div class="detail-row">
                                        <span>Requester</span><strong><?= clean($requester) ?></strong></div>
                                    <?php
                                    $groupNotes = array_values(array_filter(array_map(
                                        static function ($item) {
                                            return trim((string) ($item['notes'] ?? ''));
                                        },
                                        $requestItems
                                    )));
                                    ?>
                                    <?php if ($groupNotes): ?>
                                        <div class="detail-row">
                                            <span>Notes</span><strong><?= clean(implode(' · ', array_unique($groupNotes))) ?></strong></div>
                                    <?php endif; ?>
                                </div>
                                <p>Kindly review the details above and provide the applicable electronic approval.
                                    Inventory quantity will be updated only after Admin completes the receive and
                                    restock process.</p>
                                <p>Respectfully,<br><strong><?= clean($requester) ?></strong><br>Inventory Staff /
                                    Requester</p>
                            </div>
                        </div>
                    </article>
                    <aside class="panel side-panel">
                        <div class="panel-body">
                            <h2 class="side-title">Approval signatures</h2>
                            <p class="side-copy">Use these signatures for the manual Before and After verification
                                during the approval workflow.</p>
                            <div class="sig-list">
                                <?php foreach ([
                                    ['requester_signature', 'Inventory Staff / Requester', 'requester_signature_at'],
                                    ['finance_signature', 'Finance Department', 'finance_signature_at'],
                                    ['supplier_signature', $supplier . ' / Supplier', 'supplier_signature_at'],
                                ] as $sig): ?>
                                    <div class="sig-card">
                                <strong><?= clean($sig[1]) ?></strong><?php if ($signatureImage($request[$sig[0]])): ?><img
                                        src="signature_image.php?request_id=<?= rawurlencode(enc_id($requestId)) ?>&amp;field=<?= rawurlencode($sig[0]) ?>"
                                                alt="<?= clean($sig[1]) ?> signature"><?php else: ?><span>Signature not
                                                available
                                                yet.</span><?php endif; ?>
                                        <?php if (!empty($request[$sig[2]])): ?>
                                            <span>Signed <?= clean(date('M d, Y h:i A', strtotime($request[$sig[2]]))) ?></span>
                                        <?php endif; ?>
                                        <?php if ($sig[0] === 'requester_signature'): ?><span><?= !empty($request['requester_signature_checked_at']) ? 'Checked by Finance.' : 'Awaiting Finance signature check.' ?></span><?php endif; ?>
                                    </div><?php endforeach; ?>
                            </div>
                        </div>
                    </aside>
                </div>
            </main>
        </div>
    </div>
</body>

</html>