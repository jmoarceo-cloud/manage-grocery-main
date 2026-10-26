<?php
require_once __DIR__ . '/includes/config.php';
requireLogin();

$role = (string) ($_SESSION['role'] ?? '');
if (!in_array($role, ['admin', 'finance'], true)) {
    http_response_code(403);
    exit('You are not authorized to view this purchase-order letter.');
}

$poToken = (string) ($_GET['po_id'] ?? '');
$poId = $poToken !== '' ? dec_id($poToken) : 0;
if ($poId <= 0 && ctype_digit($poToken)) {
    $poId = (int) $poToken;
}

$stmt = $pdo->prepare("
    SELECT po.*, s.company_name AS supplier_name, s.email AS supplier_email,
           finance_user.full_name AS finance_approver_name
    FROM purchase_orders po
    JOIN suppliers s ON s.id = po.supplier_id
    LEFT JOIN users finance_user ON finance_user.id = po.approved_by
    WHERE po.id = ? AND po.requested_by_hrms_user_id IS NOT NULL
    LIMIT 1
");
$stmt->execute([$poId]);
$po = $stmt->fetch();
if (!$po) {
    http_response_code(404);
    exit('HR/Supervisor purchase-order letter not found.');
}

$itemStmt = $pdo->prepare("
    SELECT item_name, quantity_ordered, quantity_received, unit_cost, subtotal
    FROM purchase_order_items
    WHERE po_id = ?
    ORDER BY id
");
$itemStmt->execute([$poId]);
$items = $itemStmt->fetchAll();
$total = array_sum(array_map(static function ($item): float {
    return (float) $item['subtotal'];
}, $items));
$statusLabel = ucwords(str_replace('_', ' ', (string) $po['status']));
$signatureIsValid = static function ($signature): bool {
    return is_string($signature) && hasDrawnPngSignature($signature);
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Formality Letter — <?= clean($po['po_number']) ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 28px 16px; background: #f3f6f9; color: #17222e; font: 14px Arial, sans-serif; line-height: 1.6; }
        .letter { max-width: 900px; margin: 0 auto; padding: 38px; background: #fff; border: 1px solid #dce5ec; border-radius: 12px; box-shadow: 0 8px 24px rgba(18,60,92,.08); }
        .brand { color: #123c5c; font-size: 12px; font-weight: 800; letter-spacing: .13em; text-transform: uppercase; }
        h1 { margin: 28px 0 4px; color: #123c5c; font-size: 25px; text-align: center; }
        .reference { margin: 0 0 25px; color: #526273; text-align: center; }
        .meta, .signatures { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        .meta > div, .signature-card { padding: 13px; border: 1px solid #dce5ec; border-radius: 9px; background: #f8fafc; overflow-wrap: anywhere; }
        .label { display: block; color: #526273; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
        h2 { margin: 25px 0 8px; color: #123c5c; font-size: 17px; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { padding: 9px; border-bottom: 1px solid #dce5ec; text-align: left; }
        th { background: #f3f6f9; }
        td.numeric, th.numeric { text-align: right; }
        .total { text-align: right; font-size: 16px; }
        .signature-card strong { display: block; color: #123c5c; }
        .signature-card img { display: block; width: 100%; height: 84px; margin: 8px 0; border: 1px solid #dce5ec; border-radius: 6px; background: #fff; object-fit: contain; }
        .signature-card small { color: #526273; }
        .missing { padding: 18px 0; color: #8b1e1e; }
        .toolbar { display: flex; justify-content: flex-end; gap: 8px; max-width: 900px; margin: 0 auto 12px; }
        .toolbar a, .toolbar button { padding: 9px 13px; border: 1px solid #cbd5e1; border-radius: 7px; background: #fff; color: #123c5c; font-weight: 700; text-decoration: none; cursor: pointer; }
        @media (max-width: 600px) { .letter { padding: 22px; } .meta, .signatures { grid-template-columns: 1fr; } }
        @media print { body { padding: 0; background: #fff; } .letter { max-width: none; padding: 0; border: 0; border-radius: 0; box-shadow: none; } .toolbar { display: none; } }
    </style>
</head>
<body>
<div class="toolbar">
    <button type="button" onclick="window.print()">Print Formality Letter</button>
    <a href="purchase_orders.php?po=<?= rawurlencode(enc_id($poId)) ?>">Back to Purchase Order</a>
</div>
<main class="letter">
    <div class="brand">HCI GroceryOne</div>
    <h1>Formality Letter</h1>
    <p class="reference">Purchase Order <?= clean($po['po_number']) ?></p>

    <div class="meta">
        <div><span class="label">Supplier</span><?= clean($po['supplier_name']) ?></div>
        <div><span class="label">Supplier email</span><?= clean($po['supplier_email'] ?: '—') ?></div>
        <div><span class="label">Requesting department</span><?= clean($po['department'] ?: 'Unassigned') ?></div>
        <div><span class="label">Requested by</span><?= clean($po['created_by_name'] ?: 'HR / Supervisor') ?></div>
        <div><span class="label">Request date</span><?= clean(date('F j, Y', strtotime($po['submitted_at'] ?: $po['created_at']))) ?></div>
        <div><span class="label">Current status</span><?= clean($statusLabel) ?></div>
    </div>

    <p style="margin-top:24px;">Please supply the items listed below for the requesting department. This request is subject to Finance approval and supplier acceptance. Delivery and receipt are coordinated by Admin after the supplier's e-signature is recorded.</p>

    <h2>Requested items</h2>
    <table>
        <thead><tr><th>Item</th><th class="numeric">Quantity</th><th class="numeric">Unit cost</th><th class="numeric">Estimated total</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
            <tr>
                <td><?= clean($item['item_name']) ?></td>
                <td class="numeric"><?= (int) $item['quantity_ordered'] ?></td>
                <td class="numeric"><?= peso($item['unit_cost']) ?></td>
                <td class="numeric"><?= peso($item['subtotal']) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$items): ?><tr><td colspan="4">No line items found.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <p class="total"><strong>Total estimated amount: <?= peso($total) ?></strong></p>
    <?php if (!empty($po['notes'])): ?><p><strong>Request notes:</strong><br><?= nl2br(clean($po['notes'])) ?></p><?php endif; ?>

    <h2>Approval and acceptance e-signatures</h2>
    <div class="signatures">
        <div class="signature-card">
            <strong>Requester e-signature</strong>
            <?php if ($signatureIsValid($po['requester_signature'] ?? null)): ?>
                <img src="<?= clean($po['requester_signature']) ?>" alt="Requester e-signature">
                <small><?= clean($po['created_by_name'] ?: 'Requester') ?> · <?= clean(!empty($po['requester_signature_at']) ? date('M j, Y g:i A', strtotime($po['requester_signature_at'])) : 'Signed') ?></small>
            <?php else: ?><div class="missing">Requester signature not recorded.</div><?php endif; ?>
        </div>
        <div class="signature-card">
            <strong>Finance approval e-signature</strong>
            <?php if ($signatureIsValid($po['finance_signature'] ?? null)): ?>
                <img src="<?= clean($po['finance_signature']) ?>" alt="Finance approval e-signature">
                <small><?= clean($po['finance_approver_name'] ?: 'Finance') ?> · <?= clean(!empty($po['finance_signature_at']) ? date('M j, Y g:i A', strtotime($po['finance_signature_at'])) : 'Approved') ?></small>
            <?php else: ?><div class="missing">Finance signature not recorded.</div><?php endif; ?>
        </div>
        <div class="signature-card">
            <strong>Supplier acceptance e-signature</strong>
            <?php if ($signatureIsValid($po['supplier_signature'] ?? null)): ?>
                <img src="<?= clean($po['supplier_signature']) ?>" alt="Supplier acceptance e-signature">
                <small><?= clean($po['supplier_name']) ?> · <?= clean(!empty($po['supplier_signature_at']) ? date('M j, Y g:i A', strtotime($po['supplier_signature_at'])) : 'Accepted') ?></small>
            <?php else: ?><div class="missing">Supplier signature is pending.</div><?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
