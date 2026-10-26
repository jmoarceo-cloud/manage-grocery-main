<?php

require_once 'includes/config.php';
requireLogin();

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'inventory_staff', 'finance'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$params = [(int) ($_GET['id'] ?? 0)];
$sql = "
    SELECT por.*, po.po_number, po.department, po.status AS po_status,
           s.company_name AS supplier_name
    FROM purchase_order_receipts por
    JOIN purchase_orders po ON po.id = por.po_id
    JOIN suppliers s ON s.id = po.supplier_id
    WHERE por.id = ?
";
if ($role === 'inventory_staff') {
    $department = 'Unassigned';
    if (!empty($hrmsPdo) && !empty($_SESSION['employee_id'])) {
        try {
            $departmentStmt = $hrmsPdo->prepare("SELECT department FROM employees WHERE employee_id = ? LIMIT 1");
            $departmentStmt->execute([(int) $_SESSION['employee_id']]);
            $department = trim((string) $departmentStmt->fetchColumn()) ?: 'Unassigned';
        } catch (PDOException $e) {
            error_log("Receipt download department lookup skipped: " . $e->getMessage());
        }
    }
    $sql .= " AND po.department = ?";
    $params[] = $department;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$receipt = $stmt->fetch();
if (!$receipt) {
    http_response_code(404);
    exit('Receipt not found');
}

$isGeneratedReceipt = (string) $receipt['receipt_file_path'] === 'AUTO_GENERATED';
if ($isGeneratedReceipt) {
    $itemsStmt = $pdo->prepare("
        SELECT item_name, quantity_received, unit_cost, line_total
        FROM purchase_order_receipt_items
        WHERE receipt_id = ?
        ORDER BY id
    ");
    $itemsStmt->execute([(int) $receipt['id']]);
    $receiptItems = $itemsStmt->fetchAll();
    if (!$receiptItems) {
        http_response_code(404);
        exit('Receipt details not found');
    }
    $receiptTotal = array_sum(array_map(static function ($item): float {
        return (float) $item['line_total'];
    }, $receiptItems));
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Receiving Receipt — <?= clean($receipt['po_number']) ?></title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; padding: 28px 16px; background: #f3f6f9; color: #17222e; font: 14px Arial, sans-serif; line-height: 1.55; }
            main { max-width: 820px; margin: 0 auto; padding: 34px; border: 1px solid #dce5ec; border-radius: 12px; background: #fff; }
            h1, h2 { color: #123c5c; }
            h1 { margin: 0; font-size: 25px; }
            .sub { margin: 3px 0 22px; color: #526273; }
            .meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
            .meta div { padding: 11px; border: 1px solid #dce5ec; border-radius: 8px; background: #f8fafc; }
            .label { display: block; color: #526273; font-size: 10px; font-weight: 700; text-transform: uppercase; }
            table { width: 100%; margin-top: 18px; border-collapse: collapse; }
            th, td { padding: 9px; border-bottom: 1px solid #dce5ec; text-align: left; }
            th { background: #f3f6f9; }
            .num { text-align: right; }
            .total { text-align: right; font-size: 17px; }
            .note { margin-top: 18px; padding: 12px; border-radius: 8px; background: #f8fafc; }
            .toolbar { display: flex; justify-content: flex-end; max-width: 820px; margin: 0 auto 12px; }
            button { padding: 10px 14px; border: 0; border-radius: 7px; background: #123c5c; color: #fff; font-weight: 700; cursor: pointer; }
            @media(max-width:600px) { main { padding: 20px; } .meta { grid-template-columns: 1fr; } }
            @media print { body { padding: 0; background: #fff; } main { max-width: none; padding: 0; border: 0; border-radius: 0; } .toolbar { display: none; } }
        </style>
    </head>
    <body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
    <main>
        <h1>Goods Receiving Receipt</h1>
        <p class="sub">System-generated record of goods received against a Purchase Order.</p>
        <div class="meta">
            <div><span class="label">Receipt number</span>GR-<?= (int) $receipt['id'] ?></div>
            <div><span class="label">Purchase Order</span><?= clean($receipt['po_number']) ?></div>
            <div><span class="label">Supplier</span><?= clean($receipt['supplier_name']) ?></div>
            <div><span class="label">Department</span><?= clean($receipt['department'] ?: 'Unassigned') ?></div>
            <div><span class="label">Received at</span><?= clean(date('M d, Y g:i A', strtotime($receipt['received_at']))) ?></div>
            <div><span class="label">Received by</span><?= clean($receipt['received_by_name'] ?: ('User #' . $receipt['received_by'])) ?></div>
        </div>
        <h2>Received items</h2>
        <table>
            <thead><tr><th>Item</th><th class="num">Qty received</th><th class="num">Unit cost</th><th class="num">Line total</th></tr></thead>
            <tbody>
            <?php foreach ($receiptItems as $item): ?>
                <tr>
                    <td><?= clean($item['item_name']) ?></td>
                    <td class="num"><?= (int) $item['quantity_received'] ?></td>
                    <td class="num"><?= peso($item['unit_cost']) ?></td>
                    <td class="num"><?= peso($item['line_total']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="total"><strong>Total value received: <?= peso($receiptTotal) ?></strong></p>
        <?php if (!empty($receipt['note'])): ?><div class="note"><strong>Note:</strong><br><?= nl2br(clean($receipt['note'])) ?></div><?php endif; ?>
        <p style="margin-top:24px;color:#526273;font-size:12px;">This receipt is generated by the HCI GroceryOne when an authorized user records received quantities. It is a system receiving record and not a supplier-issued invoice.</p>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$expectedPrefix = 'uploads/receipts/purchase-orders/';
$relativePath = str_replace('\\', '/', (string) $receipt['receipt_file_path']);
if (strpos($relativePath, $expectedPrefix) !== 0 || basename($relativePath) !== substr($relativePath, strlen($expectedPrefix))) {
    http_response_code(404);
    exit('Receipt not found');
}
$uploadRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'receipts' . DIRECTORY_SEPARATOR . 'purchase-orders');
$filePath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
if (!$uploadRoot || !$filePath || strpos($filePath, $uploadRoot . DIRECTORY_SEPARATOR) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    exit('Receipt not found');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $filePath) : false;
if ($finfo) {
    finfo_close($finfo);
}
$allowedMime = ['image/jpeg', 'image/png', 'application/pdf'];
if (!in_array($mime, $allowedMime, true)) {
    http_response_code(415);
    exit('Unsupported receipt format');
}

$downloadName = basename((string) ($receipt['original_file_name'] ?: basename($filePath)));
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="receipt"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
