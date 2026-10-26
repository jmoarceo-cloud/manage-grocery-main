<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();

function productBarcodeSvg(string $barcode): string
{
    if (!preg_match('/^\d{13}$/', $barcode)) {
        return '';
    }

    $leftOdd = ['0001101', '0011001', '0010011', '0111101', '0100011', '0110001', '0101111', '0111011', '0110111', '0001011'];
    $leftEven = ['0100111', '0110011', '0011011', '0100001', '0011101', '0111001', '0000101', '0010001', '0001001', '0010111'];
    $right = ['1110010', '1100110', '1101100', '1000010', '1011100', '1001110', '1010000', '1000100', '1001000', '1110100'];
    $parity = ['LLLLLL', 'LLGLGG', 'LLGGLG', 'LLGGGL', 'LGLLGG', 'LGGLLG', 'LGGGLL', 'LGLGLG', 'LGLGGL', 'LGGLGL'];

    $bits = '101';
    $pattern = $parity[(int) $barcode[0]];
    for ($position = 1; $position <= 6; $position++) {
        $digit = (int) $barcode[$position];
        $bits .= $pattern[$position - 1] === 'L' ? $leftOdd[$digit] : $leftEven[$digit];
    }
    $bits .= '01010';
    for ($position = 7; $position <= 12; $position++) {
        $bits .= $right[(int) $barcode[$position]];
    }
    $bits .= '101';

    $bars = '';
    $length = strlen($bits);
    for ($position = 0; $position < $length; $position++) {
        if ($bits[$position] !== '1') {
            continue;
        }
        $isGuard = $position < 3 || ($position >= 45 && $position < 50) || $position >= 92;
        $height = $isGuard ? 39 : 34;
        $bars .= '<rect x="' . $position . '" y="2" width="1" height="' . $height . '"/>';
    }

    return '<svg class="ean13" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 95 48" role="img" aria-label="EAN-13 barcode ' . clean($barcode) . '"><rect width="95" height="48" fill="#fff"/><g fill="#000">' . $bars . '</g><text x="47.5" y="47" text-anchor="middle" font-family="Arial,sans-serif" font-size="6">' . clean($barcode) . '</text></svg>';
}

$products = $pdo->query(
    "SELECT id, product_name, barcode, price
     FROM products
     WHERE is_active = 1 AND barcode IS NOT NULL AND barcode <> ''
     ORDER BY product_name ASC"
)->fetchAll();
if (!$products) {
    http_response_code(404);
    exit('No active products have barcodes to print.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Print POS Barcodes</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 32px; color: #0f172a; background: #f8fafc; font: 14px/1.5 "Plus Jakarta Sans", "Trebuchet MS", Verdana, sans-serif; }
        .toolbar { display: flex; justify-content: space-between; align-items: center; gap: 20px; margin: 0 auto 24px; padding: 20px 24px; max-width: 1100px; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .055); }
        .toolbar strong { color: #1d4ed8; font-size: 18px; }
        .toolbar p { margin: 5px 0 0; color: #475569; }
        .toolbar a, .toolbar button { display: inline-block; padding: 11px 16px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; color: #1d4ed8; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
        .toolbar button { border-color: #2563eb; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; box-shadow: 0 2px 10px rgba(37, 99, 235, .22); }
        .labels { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); justify-content: center; gap: 16px; max-width: 1100px; margin: 0 auto; }
        .label { min-height: 152px; padding: 14px; border: 1px solid #e2e8f0; border-top: 3px solid #3b82f6; border-radius: 16px; display: flex; flex-direction: column; align-items: center; justify-content: space-between; overflow: hidden; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); text-align: center; }
        .label-name { width: 100%; overflow: hidden; color: #0f172a; font-weight: 800; font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
        .ean13 { display: block; width: min(100%, 280px); height: 72px; }
        .label-note { color: #1d4ed8; font-size: 12px; font-weight: 700; }
        .label-unavailable { font-size: 12px; font-weight: 700; color: #9f1239; }
        .labels--few { grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 480px)); justify-content: center; }
        .labels--few .label { min-height: 220px; padding: 20px; }
        .labels--few .label-name { font-size: 19px; }
        .labels--few .ean13 { width: min(100%, 360px); height: 100px; }
        .labels--few .label-note { font-size: 15px; }
        @media (max-width: 760px) { body { padding: 16px; } .labels { grid-template-columns: repeat(auto-fit, minmax(min(100%, 260px), 1fr)); gap: 12px; } .label { min-height: 140px; } }
        @media (max-width: 520px) { .labels { grid-template-columns: 1fr; } .toolbar { align-items: flex-start; flex-direction: column; padding: 16px; } .toolbar > div:last-child { display: flex; width: 100%; gap: 8px; } }
        @media print {
            @page { size: A4; margin: 8mm; }
            body { padding: 0; background: #fff; font: 9pt Arial, sans-serif; }
            .toolbar { display: none; }
            .labels { max-width: none; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 3mm; }
            .label { height: 34mm; min-height: 0; padding: 2mm; break-inside: avoid; border: 1px solid #cbd5e1; border-top: 1mm solid #3b82f6; border-radius: 1mm; box-shadow: none; }
            .label-name { font-size: 9pt; }
            .ean13 { width: 58mm; height: 22mm; }
            .label-note { font-size: 7pt; }
            .labels--few { grid-template-columns: repeat(auto-fit, minmax(55mm, 65mm)); justify-content: center; }
            .labels--few .label { height: 52mm; min-height: 52mm; padding: 3mm; }
            .labels--few .label-name { font-size: 11pt; }
            .labels--few .ean13 { width: 58mm; height: 30mm; }
            .labels--few .label-note { font-size: 9pt; }
        }
    </style>
</head>
<body>
    <header class="toolbar">
        <div>
            <strong>POS barcode labels</strong>
            <p><?= count($products) ?> active products. Internal barcodes are store-only identifiers, not manufacturer UPC/EAN assignments.</p>
        </div>
        <div><a href="products.php">Back to products</a> <button type="button" onclick="window.print()">Print labels</button></div>
    </header>
    <main class="labels<?= count($products) <= 3 ? ' labels--few' : '' ?>">
        <?php foreach ($products as $product): ?>
            <article class="label">
                <div class="label-name"><?= clean($product['product_name']) ?></div>
                <?php $barcodeSvg = productBarcodeSvg((string) $product['barcode']); ?>
                <?php if ($barcodeSvg !== ''): ?>
                    <?= $barcodeSvg ?>
                <?php else: ?>
                    <div class="label-unavailable">Cannot print as EAN-13: <?= clean($product['barcode']) ?></div>
                <?php endif; ?>
                <div class="label-note">Internal POS code · <?= peso($product['price']) ?></div>
            </article>
        <?php endforeach; ?>
    </main>
</body>
</html>
