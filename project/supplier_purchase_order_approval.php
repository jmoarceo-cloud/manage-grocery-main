<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/HRMS/includes/mailer.php';

$poIdToken = (string) ($_GET['po_id'] ?? $_POST['po_id'] ?? '');
$poId = $poIdToken !== '' ? dec_id($poIdToken) : 0;
if ($poId <= 0 && ctype_digit($poIdToken)) {
    $poId = (int) $poIdToken;
}
$token = (string) ($_GET['token'] ?? $_POST['token'] ?? '');
$stmt = $pdo->prepare("
    SELECT po.*, s.company_name AS supplier_name, s.email AS supplier_email,
           s.procurement_signature_reference
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    WHERE po.id = ? AND po.requested_by_hrms_user_id IS NOT NULL
    LIMIT 1
");
$stmt->execute([$poId]);
$po = $stmt->fetch();
$items = [];
if ($po) {
    $itemStmt = $pdo->prepare("SELECT item_name, quantity_ordered, unit_cost, subtotal FROM purchase_order_items WHERE po_id = ? ORDER BY id");
    $itemStmt->execute([$poId]);
    $items = $itemStmt->fetchAll();
}

$error = '';
$success = '';
$tokenIsValid = static function (array $order, string $plainToken): bool {
    return $plainToken !== ''
        && hash_equals((string) ($order['supplier_approval_token_hash'] ?? ''), hash('sha256', $plainToken))
        && !empty($order['supplier_approval_expires_at'])
        && strtotime((string) $order['supplier_approval_expires_at']) >= time();
};

if (!$po || !$tokenIsValid($po, $token) || !in_array($po['status'], ['pending_supplier', 'ordered'], true)) {
    http_response_code(404);
    $error = 'This supplier approval link is invalid or expired.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $signature = trim((string) ($_POST['supplier_signature'] ?? ''));
    if (($_POST['supplier_approval_confirmed'] ?? '') !== '1') {
        $error = 'Confirm that you reviewed the Formality Letter before signing.';
    } elseif (!hasDrawnPngSignature($signature)) {
        $error = 'Draw your supplier e-signature before approving.';
    } elseif ($po['status'] !== 'pending_supplier') {
        $error = 'This Purchase Order has already been processed.';
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare("
                SELECT status, supplier_approval_token_hash, supplier_approval_expires_at
                FROM purchase_orders WHERE id = ? FOR UPDATE
            ");
            $lock->execute([$poId]);
            $lockedPo = $lock->fetch();
            if (
                !$lockedPo
                || $lockedPo['status'] !== 'pending_supplier'
                || !$tokenIsValid($lockedPo, $token)
            ) {
                throw new RuntimeException('The Purchase Order changed or the approval link expired.');
            }
            $update = $pdo->prepare("
                UPDATE purchase_orders
                SET supplier_signature = ?, supplier_signature_at = NOW(),
                    status = 'ordered', ordered_at = NOW()
                WHERE id = ? AND status = 'pending_supplier'
            ");
            $update->execute([$signature, $poId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('Supplier approval was not recorded.');
            }
            $pdo->commit();
            $po['status'] = 'ordered';
            $success = 'Your e-signature has been recorded. The purchase order is now with Admin for delivery coordination and receipt.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Supplier PO e-signature failed for PO #' . $poId . ': ' . $e->getMessage());
            $error = 'Supplier approval could not be saved. Please refresh and try again or contact Finance.';
        }

        if ($success !== '') {
            try {
                $admins = $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
                $notify = $pdo->prepare("
                    INSERT INTO notifications (user_id, category, type, message, link_url, source_type, source_id)
                    VALUES (?, 'workflow', 'purchase_order', ?, ?, 'purchase_order', ?)
                ");
                foreach ($admins as $adminId) {
                    $notify->execute([
                        (int) $adminId,
                        'Supplier e-signed ' . $po['po_number'] . '; coordinate delivery and receive the order.',
                        'purchase_orders.php?po=' . enc_id($poId),
                        $poId,
                    ]);
                }
            } catch (Throwable $e) {
                error_log('Supplier PO Admin notification failed for PO #' . $poId . ': ' . $e->getMessage());
            }
            if (!empty($po['supplier_email']) && filter_var($po['supplier_email'], FILTER_VALIDATE_EMAIL)) {
                $body = '<p>Supplier e-signature for Purchase Order <strong>' . clean($po['po_number']) . '</strong> has been recorded. Admin will coordinate delivery and receiving.</p>';
                if (!sendSystemEmail(
                    (string) $po['supplier_email'],
                    (string) $po['supplier_name'],
                    'Supplier Approval Confirmed — ' . (string) $po['po_number'],
                    emailWrapper('Supplier Approval Confirmed', $body)
                )) {
                    error_log('Supplier PO approval confirmation email failed for PO #' . $poId);
                }
            }
        }
    }
}

$total = array_sum(array_map(static function ($item) {
    return (float) $item['subtotal'];
}, $items));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formality Letter — Supplier Approval</title>
    <style>
        *{box-sizing:border-box}body{margin:0;padding:28px 16px;background:#f3f6f9;color:#17222e;font:15px Arial,sans-serif;line-height:1.55}
        .letter{max-width:860px;margin:0 auto;padding:32px;background:#fff;border:1px solid #dce5ec;border-radius:14px;box-shadow:0 10px 28px rgba(15,23,42,.08)}
        h1,h2{color:#123c5c}h1{font-size:25px;margin:0 0 18px}h2{font-size:18px;margin:24px 0 10px}
        .alert{padding:12px 14px;border-radius:8px;margin:14px 0}.error{background:#fee2e2;color:#991b1b}.success{background:#dcfce7;color:#166534}
        .meta,.signature-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.meta div,.signature-card{padding:13px;border:1px solid #dce5ec;border-radius:9px;background:#f8fafc}
        .label{display:block;color:#526273;font-size:11px;text-transform:uppercase;font-weight:bold;margin-bottom:3px}
        table{width:100%;border-collapse:collapse;margin:12px 0}th,td{padding:10px;border-bottom:1px solid #dce5ec;text-align:left}th{background:#f3f6f9}td:last-child,th:last-child{text-align:right}
        .signature{display:block;width:100%;height:105px;object-fit:contain;background:#fff;border:1px solid #dce5ec;border-radius:6px;margin-top:8px}
        .signature-pad{display:block;width:100%;height:165px;touch-action:none;border:1px dashed #93c5fd;border-radius:8px;background:#fff;margin:8px 0}
        .actions{display:flex;gap:10px;flex-wrap:wrap}.button{border:0;border-radius:8px;padding:11px 16px;background:#123c5c;color:#fff;font-weight:bold;cursor:pointer}.button--secondary{background:#fff;color:#123c5c;border:1px solid #cbd5e1}
        @media(max-width:600px){.letter{padding:20px}.meta,.signature-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="letter">
    <h1>Formality Letter — Supplier Approval</h1>
    <?php if ($error !== ''): ?><div class="alert error"><?= clean($error) ?></div><?php endif; ?>
    <?php if ($success !== ''): ?><div class="alert success"><?= clean($success) ?></div><?php endif; ?>
    <?php if ($po && $tokenIsValid($po, $token)): ?>
        <div class="meta">
            <div><span class="label">Purchase Order</span><?= clean($po['po_number']) ?></div>
            <div><span class="label">Supplier</span><?= clean($po['supplier_name']) ?></div>
            <div><span class="label">Requesting Department</span><?= clean($po['department']) ?></div>
            <div><span class="label">Requester</span><?= clean($po['created_by_name'] ?? 'HR / Supervisor') ?></div>
            <div><span class="label">Submitted</span><?= clean(date('M j, Y g:i A', strtotime($po['submitted_at'] ?? $po['created_at']))) ?></div>
            <div><span class="label">Finance approved</span><?= clean(!empty($po['approved_at']) ? date('M j, Y g:i A', strtotime($po['approved_at'])) : '—') ?></div>
        </div>
        <h2>Requested items and estimated costs</h2>
        <table>
            <thead><tr><th>Item</th><th>Quantity</th><th>Estimated cost</th></tr></thead>
            <tbody>
            <?php foreach ($items as $item): ?>
                <tr><td><?= clean($item['item_name']) ?></td><td><?= (int) $item['quantity_ordered'] ?></td><td><?= peso($item['subtotal']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="text-align:right;font-size:17px"><strong>Total: <?= peso($total) ?></strong></p>
        <?php if (!empty($po['notes'])): ?><p><strong>Request notes:</strong> <?= nl2br(clean($po['notes'])) ?></p><?php endif; ?>
        <p>Please review the Formality Letter and all item details. Your e-signature confirms acceptance of the listed items and estimated order terms. Once signed, the PO moves to Admin for delivery coordination and receipt.</p>
        <div class="signature-grid">
            <div class="signature-card"><strong>Requester e-signature</strong>
                <?php if (hasDrawnPngSignature($po['requester_signature'] ?? '')): ?><img class="signature" src="<?= clean($po['requester_signature']) ?>" alt="Requester e-signature"><small>Signed <?= clean(!empty($po['requester_signature_at']) ? date('M j, Y g:i A', strtotime($po['requester_signature_at'])) : '') ?></small><?php else: ?><p>Requester signature unavailable.</p><?php endif; ?>
            </div>
            <div class="signature-card"><strong>Finance approval e-signature</strong>
                <?php if (hasDrawnPngSignature($po['finance_signature'] ?? '')): ?><img class="signature" src="<?= clean($po['finance_signature']) ?>" alt="Finance e-signature"><small>Approved <?= clean(!empty($po['finance_signature_at']) ? date('M j, Y g:i A', strtotime($po['finance_signature_at'])) : '') ?></small><?php else: ?><p>Finance signature unavailable.</p><?php endif; ?>
            </div>
        </div>
        <?php if ($po['status'] === 'pending_supplier'): ?>
            <h2>Supplier e-signature</h2>
            <?php if (hasDrawnPngSignature($po['procurement_signature_reference'] ?? '')): ?><div class="signature-card"><strong>Registered signature reference</strong><img class="signature" src="<?= clean($po['procurement_signature_reference']) ?>" alt="Registered supplier signature reference"></div><?php endif; ?>
            <canvas class="signature-pad" id="supplierSignaturePad" width="1040" height="320" aria-label="Draw supplier e-signature"></canvas>
            <form method="post" id="supplierApprovalForm">
                <?php csrfField(); ?>
                <input type="hidden" name="po_id" value="<?= clean($poIdToken) ?>">
                <input type="hidden" name="token" value="<?= clean($token) ?>">
                <input type="hidden" name="supplier_signature" id="supplierSignature">
                <label style="display:flex;gap:8px;align-items:flex-start;margin:12px 0;">
                    <input type="checkbox" name="supplier_approval_confirmed" value="1" required>
                    <span>I reviewed the Formality Letter, item quantities and costs, and approve this Purchase Order as the supplier.</span>
                </label>
                <div class="actions">
                    <button type="button" class="button button--secondary" id="clearSupplierSignature">Clear Signature</button>
                    <button type="submit" class="button">Confirm and E-sign Approval</button>
                </div>
                <div class="alert error" id="signatureError" hidden>Draw your e-signature before confirming.</div>
            </form>
        <?php else: ?>
            <div class="alert success">Supplier e-signature recorded <?= clean(!empty($po['supplier_signature_at']) ? date('M j, Y g:i A', strtotime($po['supplier_signature_at'])) : '') ?>. Admin will coordinate receipt.</div>
            <?php if (hasDrawnPngSignature($po['supplier_signature'] ?? '')): ?><div class="signature-card"><strong>Supplier e-signature</strong><img class="signature" src="<?= clean($po['supplier_signature']) ?>" alt="Supplier e-signature"></div><?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</main>
<script>
(() => {
    const canvas = document.getElementById('supplierSignaturePad');
    const form = document.getElementById('supplierApprovalForm');
    if (!canvas || !form) return;
    const context = canvas.getContext('2d');
    const signature = document.getElementById('supplierSignature');
    const error = document.getElementById('signatureError');
    let drawing = false;
    let signed = false;
    context.strokeStyle = '#123c5c';
    context.lineWidth = 4;
    context.lineCap = 'round';
    const point = event => {
        const rect = canvas.getBoundingClientRect();
        return { x: (event.clientX - rect.left) * canvas.width / rect.width, y: (event.clientY - rect.top) * canvas.height / rect.height };
    };
    canvas.addEventListener('pointerdown', event => {
        event.preventDefault();
        drawing = true;
        signed = true;
        canvas.setPointerCapture(event.pointerId);
        const position = point(event);
        context.beginPath();
        context.moveTo(position.x, position.y);
    });
    canvas.addEventListener('pointermove', event => {
        if (!drawing) return;
        event.preventDefault();
        const position = point(event);
        context.lineTo(position.x, position.y);
        context.stroke();
        signature.value = canvas.toDataURL('image/png');
        error.hidden = true;
    });
    const stop = () => { drawing = false; };
    canvas.addEventListener('pointerup', stop);
    canvas.addEventListener('pointercancel', stop);
    document.getElementById('clearSupplierSignature').addEventListener('click', () => {
        context.clearRect(0, 0, canvas.width, canvas.height);
        signature.value = '';
        signed = false;
    });
    form.addEventListener('submit', event => {
        if (!signed || !signature.value) {
            event.preventDefault();
            error.hidden = false;
        }
    });
})();
</script>
</body>
</html>
