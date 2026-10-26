<?php
require_once 'includes/config.php';
require_once __DIR__ . '/includes/mailer.php';

$requestIdToken = (string) ($_GET['request_id'] ?? '');
$requestId = $requestIdToken !== '' ? dec_id($requestIdToken) : (int) ($_POST['request_id'] ?? 0);
if ($requestId <= 0 && ctype_digit($requestIdToken)) {
    $requestId = (int) $requestIdToken;
}
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$stmt = $pdo->prepare("SELECT ir.*, s.company_name, s.email, s.procurement_signature_reference FROM inventory_requests ir LEFT JOIN suppliers s ON s.id = ir.supplier_id WHERE ir.request_id = ? LIMIT 1");
$stmt->execute([$requestId]);
$request = $stmt->fetch();
$requestItems = $request ? [$request] : [];
if ($request && !empty($request['request_group_id'])) {
    $groupStmt = $pdo->prepare("SELECT ir.*, s.company_name, s.email, s.procurement_signature_reference FROM inventory_requests ir LEFT JOIN suppliers s ON s.id = ir.supplier_id WHERE ir.request_group_id = ? ORDER BY ir.request_id ASC");
    $groupStmt->execute([$request['request_group_id']]);
    $requestItems = $groupStmt->fetchAll();
}
$signatureImageUrl = static function (string $field) use ($requestId, $token): string {
    return 'signature_image.php?request_id=' . rawurlencode(enc_id($requestId))
        . '&field=' . rawurlencode($field)
        . '&token=' . rawurlencode($token);
};
$error = '';
$success = '';

if (!$request || !$token || !hash_equals((string) ($request['supplier_approval_token_hash'] ?? ''), hash('sha256', $token)) || empty($request['supplier_approval_expires_at']) || strtotime($request['supplier_approval_expires_at']) < time()) {
    http_response_code(404);
    $error = 'This supplier approval link is invalid or expired.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $signature = trim($_POST['supplier_signature'] ?? '');
    if (($_POST['supplier_approval_confirmed'] ?? '') !== '1') {
        $error = 'Confirm that you reviewed and approve the Formality Letter before signing.';
    } elseif (!hasDrawnPngSignature($signature)) {
        $error = 'Draw your supplier e-signature before approving.';
    } elseif ($request['status'] !== 'pending_supplier') {
        $error = 'This request has already been processed.';
    } else {
        $expectedRows = count($requestItems);
        try {
            $pdo->beginTransaction();
            if (!empty($request['request_group_id'])) {
                $lockRequests = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_group_id = ? AND supplier_id = ? AND status = 'pending_supplier' ORDER BY request_id ASC FOR UPDATE");
                $lockRequests->execute([$request['request_group_id'], $request['supplier_id']]);
            } else {
                $lockRequests = $pdo->prepare("SELECT * FROM inventory_requests WHERE request_id = ? AND status = 'pending_supplier' FOR UPDATE");
                $lockRequests->execute([$requestId]);
            }
            $lockedRequests = $lockRequests->fetchAll();
            $lockedRequest = null;
            foreach ($lockedRequests as $lockedLine) {
                if ((int) $lockedLine['request_id'] === $requestId) {
                    $lockedRequest = $lockedLine;
                    break;
                }
            }
            if (
                count($lockedRequests) !== $expectedRows
                || !$lockedRequest
                || !hash_equals((string) $lockedRequest['supplier_approval_token_hash'], hash('sha256', $token))
                || empty($lockedRequest['supplier_approval_expires_at'])
                || strtotime($lockedRequest['supplier_approval_expires_at']) < time()
            ) {
                throw new RuntimeException('The request changed before supplier approval.');
            }

            $purchaseOrderIds = array_values(array_unique(array_filter(array_map(static function ($line) {
                return (int) ($line['purchase_order_id'] ?? 0);
            }, $lockedRequests))));
            if (!$purchaseOrderIds) {
                $legacyPo = createFinanceApprovedRestockPurchaseOrder(
                    $pdo,
                    $lockedRequests,
                    (int) ($lockedRequest['decided_by'] ?? 0),
                    'Finance Department'
                );
                $purchaseOrderIds[] = $legacyPo['id'];
            } elseif (count($purchaseOrderIds) !== 1 || count(array_filter($lockedRequests, static function ($line) {
                return empty($line['purchase_order_id']);
            })) > 0) {
                throw new RuntimeException('The request lines are not linked to one complete Purchase Order.');
            }

            $poPlaceholders = implode(',', array_fill(0, count($purchaseOrderIds), '?'));
            $poLock = $pdo->prepare("SELECT id, status FROM purchase_orders WHERE id IN ($poPlaceholders) ORDER BY id FOR UPDATE");
            $poLock->execute($purchaseOrderIds);
            $lockedOrders = $poLock->fetchAll();
            if (count($lockedOrders) !== count($purchaseOrderIds)) {
                throw new RuntimeException('The linked Purchase Order could not be found.');
            }
            foreach ($lockedOrders as $lockedOrder) {
                if ($lockedOrder['status'] !== 'approved') {
                    throw new RuntimeException('The linked Purchase Order is not Finance-approved.');
                }
            }

            if (!empty($request['request_group_id'])) {
                $updated = $pdo->prepare("UPDATE inventory_requests SET status = 'approved', supplier_signature = ?, supplier_signature_at = NOW() WHERE request_group_id = ? AND supplier_id = ? AND status = 'pending_supplier'");
                $updated->execute([$signature, $request['request_group_id'], $request['supplier_id']]);
            } else {
                $updated = $pdo->prepare("UPDATE inventory_requests SET status = 'approved', supplier_signature = ?, supplier_signature_at = NOW() WHERE request_id = ? AND status = 'pending_supplier'");
                $updated->execute([$signature, $requestId]);
            }
            if ($updated->rowCount() !== $expectedRows) {
                throw new RuntimeException('The request changed while supplier approval was being recorded.');
            }

            $orderedPo = $pdo->prepare("UPDATE purchase_orders SET status = 'ordered', ordered_at = NOW() WHERE id = ? AND status = 'approved'");
            foreach ($purchaseOrderIds as $purchaseOrderId) {
                $orderedPo->execute([$purchaseOrderId]);
                if ($orderedPo->rowCount() !== 1) {
                    throw new RuntimeException('The approved Purchase Order could not be moved to Orders.');
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Supplier approval failed for request #' . $requestId . ': ' . $e->getMessage());
            $error = 'The supplier approval could not be saved. Please refresh and try again, or contact Finance.';
        }
        if (!$error) {
            // Always send confirmation to the email registered on the
            // supplier record. The approval page never accepts a posted
            // recipient address.
            $supplierEmail = trim((string) ($request['email'] ?? ''));
            if (filter_var($supplierEmail, FILTER_VALIDATE_EMAIL)) {
                $confirmationMessage = '<p>Your supplier approval and e-signature for procurement request <strong>#' . $requestId . '</strong> have been recorded.</p>'
                    . '<p>The Finance-approved Purchase Order is now in the Orders workflow. The store will receive the items when the delivery arrives.</p>';
                $confirmationBody = emailWrapper(
                    'Supplier Approval Confirmed',
                    $confirmationMessage
                );
                if (!sendSystemEmail($supplierEmail, (string) ($request['company_name'] ?? 'Supplier'), 'Supplier Approval Confirmed — Procurement Request #' . $requestId, $confirmationBody)) {
                    error_log('Supplier approval confirmation email could not be sent for inventory request #' . $requestId . '.');
                }
            } else {
                error_log('Supplier approval recorded without a valid registered supplier email for inventory request #' . $requestId . '.');
            }
            $userStmt = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND (employee_id = ? OR role IN ('inventory_staff', 'admin'))");
            $userStmt->execute([(int) $request['requested_by_employee_id']]);
            $notificationMessage = 'Supplier e-signed restock request #' . $requestId . '. Its Finance-approved Purchase Order is now in Orders and ready for Admin to receive when delivered.';
            $notificationLink = 'purchase_orders.php?status=ordered';
            $notify = $pdo->prepare("INSERT INTO notifications (user_id, category, type, message, link_url, source_type, source_id) VALUES (?, 'workflow', 'inventory_request', ?, ?, 'inventory_request', ?)");
            foreach ($userStmt->fetchAll(PDO::FETCH_COLUMN) as $userId) {
                $notify->execute([(int) $userId, $notificationMessage, $notificationLink, $requestId]);
            }
            $success = 'Supplier approval recorded. The linked Purchase Order is now in Orders for Admin to receive when the delivery arrives.';
        }
    }
}
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Supplier Approval</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: #f3f6f9;
            color: #17222e;
            padding: 30px
        }

        .card {
            max-width: 900px;
            margin: auto;
            background: #fff;
            border: 1px solid #dce5ec;
            border-radius: 16px;
            padding: 28px
        }

        h1 {
            color: #123c5c
        }

        .alert {
            padding: 12px;
            border-radius: 8px;
            margin: 14px 0
        }

        .error {
            background: #fee2e2;
            color: #991b1b
        }

        .success {
            background: #dcfce7;
            color: #166534
        }

        canvas {
            width: 100%;
            height: 150px;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            touch-action: none
        }

        .btn {
            background: #123c5c;
            color: white;
            border: 0;
            border-radius: 8px;
            padding: 12px 18px;
            cursor: pointer
        }

        .items {
            width: 100%;
            border-collapse: collapse;
            margin: 16px 0;
        }

        .items th,
        .items td {
            padding: 10px;
            border-bottom: 1px solid #dce5ec;
            text-align: left;
        }

        .items th {
            background: #f3f6f9;
        }

        .signatures {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
            margin: 18px 0;
        }

        .signature-card {
            padding: 14px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: #f8fafc;
        }

        .signature-card img {
            display: block;
            width: 100%;
            height: 100px;
            object-fit: contain;
            background: #fff;
            border: 1px solid #dce5ec;
            border-radius: 6px;
            margin-top: 8px;
        }

        .confirm-check {
            display: flex;
            gap: 10px;
            align-items: flex-start;
            margin: 16px 0;
            line-height: 1.5;
        }

        @media (max-width: 620px) {
            body { padding: 12px; }
            .card { padding: 18px; }
            .signatures { grid-template-columns: 1fr; }
            .items th, .items td { padding: 7px 5px; font-size: 13px; }
        }
    </style>
</head>

<body>
    <main class="card">
        <h1>Formality Letter — Supplier Approval</h1>
        <?php if ($error): ?><div class="alert error"><?= clean($error) ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert success"><?= clean($success) ?></div><?php endif; ?>
        <?php if ($request && !$success): ?>
            <?php
            $groupedRequest = !empty($request['request_group_id']);
            $totalEstimate = array_sum(array_map(static function ($item) {
                return (float) ($item['estimated_cost'] ?? 0);
            }, $requestItems));
            ?>
            <p><strong>Request<?= count($requestItems) > 1 ? 's' : '' ?>:
                <?= clean(implode(', ', array_map(static function ($item) {
                    return '#' . (int) $item['request_id'];
                }, $requestItems))) ?></strong></p>
            <p><strong>Supplier:</strong> <?= clean($request['company_name'] ?? 'Supplier') ?>
                <?php if ($groupedRequest): ?><br><strong>Department:</strong> <?= clean($request['request_department'] ?? '') ?><?php endif; ?>
                <br><strong>Requester:</strong> <?= clean($request['requester_full_name'] ?? '') ?></p>

            <h2>Formality Letter details</h2>
            <table class="items">
                <thead><tr><th>Item</th><th>Quantity</th><th style="text-align:right;">Estimated cost</th></tr></thead>
                <tbody>
                    <?php foreach ($requestItems as $item): ?>
                        <tr>
                            <td><?= clean($item['item_name']) ?></td>
                            <td><?= (int) $item['quantity'] ?></td>
                            <td style="text-align:right;"><?= $item['estimated_cost'] !== null ? clean(peso($item['estimated_cost'])) : 'To be confirmed' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php if ($groupedRequest): ?><p style="text-align:right;"><strong>Total estimated cost: <?= clean(peso($totalEstimate)) ?></strong></p><?php endif; ?>
            <?php foreach (array_unique(array_filter(array_map(static function ($item) {
                return trim((string) ($item['notes'] ?? ''));
            }, $requestItems))) as $note): ?>
                <p><strong>Request note:</strong> <?= clean($note) ?></p>
            <?php endforeach; ?>

            <p>Finance has approved this request and its linked Purchase Order. Review the complete Formality Letter and the Inventory Staff and Finance signatures below, then add your supplier e-signature and confirm your approval. Once submitted, the Purchase Order will move to Orders so the store can coordinate delivery and receiving.</p>
            <div class="signatures">
                <div class="signature-card">
                    <strong>Inventory Staff signature</strong>
                    <?php if (hasDrawnPngSignature($request['requester_signature'] ?? '')): ?>
                        <img src="<?= clean($signatureImageUrl('requester_signature')) ?>" alt="Inventory Staff signature">
                        <p>Signed <?= clean(!empty($request['requester_signature_at']) ? date('M j, Y h:i A', strtotime($request['requester_signature_at'])) : 'with request') ?></p>
                    <?php else: ?>
                        <p>Signature unavailable</p>
                    <?php endif; ?>
                </div>
                <div class="signature-card">
                    <strong>Finance approval signature</strong>
                    <?php if (hasDrawnPngSignature($request['finance_signature'] ?? '')): ?>
                        <img src="<?= clean($signatureImageUrl('finance_signature')) ?>" alt="Finance approval signature">
                        <p>Approved <?= clean(!empty($request['finance_signature_at']) ? date('M j, Y h:i A', strtotime($request['finance_signature_at'])) : 'with approval') ?></p>
                    <?php else: ?>
                        <p>Signature unavailable</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="signature-card" style="margin:18px 0;">
                <strong>Supplier e-signature — draw to confirm approval</strong>
                <?php if (hasDrawnPngSignature($request['procurement_signature_reference'] ?? '')): ?>
                    <p style="margin-bottom:6px;">Enrolled supplier signature reference:</p>
                    <img src="<?= clean($signatureImageUrl('procurement_signature_reference')) ?>" alt="Enrolled supplier signature reference">
                <?php endif; ?>
                <canvas id="pad" width="800" height="220" aria-label="Draw supplier e-signature"></canvas>
            </div>
            <form method="post" id="supplierApprovalForm">
                <?php csrfField(); ?>
                <input type="hidden" name="request_id" value="<?= (int) $requestId ?>">
                <input type="hidden" name="token" value="<?= clean($token) ?>">
                <input type="hidden" name="supplier_signature" id="signature">
                <label class="confirm-check"><input type="checkbox" name="supplier_approval_confirmed" value="1" required>
                    <span>I reviewed the full Formality Letter, confirm the item quantities and estimated costs, and approve this request as the supplier.</span>
                </label>
                <p><button type="button" class="btn" id="clear">Clear Signature</button>
                    <button class="btn" type="submit">Confirm and E-sign Approval</button></p>
            </form>
        <?php endif; ?>
    </main>
    <script>
        const c = document.getElementById('pad');
        if (c) {
            const x = c.getContext('2d');
            let drawn = false;
            let drawing = false;
            const point = e => {
                const r = c.getBoundingClientRect();
                return { x: (e.clientX - r.left) * c.width / r.width, y: (e.clientY - r.top) * c.height / r.height };
            };
            c.addEventListener('pointerdown', e => {
                e.preventDefault();
                drawing = true;
                drawn = true;
                c.setPointerCapture(e.pointerId);
                const q = point(e);
                x.beginPath();
                x.moveTo(q.x, q.y);
            });
            c.addEventListener('pointermove', e => {
                if (!drawing) return;
                e.preventDefault();
                const q = point(e);
                x.lineTo(q.x, q.y);
                x.strokeStyle = '#123c5c';
                x.lineWidth = 3;
                x.lineCap = 'round';
                x.stroke();
            });
            const stopDrawing = () => { drawing = false; };
            c.addEventListener('pointerup', stopDrawing);
            c.addEventListener('pointercancel', stopDrawing);
            document.getElementById('clear').addEventListener('click', () => {
                drawn = false;
                drawing = false;
                x.clearRect(0, 0, c.width, c.height);
            });
            document.getElementById('supplierApprovalForm').addEventListener('submit', e => {
                if (!drawn) {
                    e.preventDefault();
                    alert('Draw your e-signature before approving.');
                    return;
                }
                document.getElementById('signature').value = c.toDataURL('image/png');
            });
        }
    </script>

</html>