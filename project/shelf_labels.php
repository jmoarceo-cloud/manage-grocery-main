<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();
require_once __DIR__ . '/includes/phpqrcode/phpqrcode.php';

function shelfLabelQrSvg(string $url, string $label): string
{
    $matrix = QRcode::text($url, false, QR_ECLEVEL_H);
    $size = count($matrix);
    if ($size === 0) {
        throw new RuntimeException('The QR code could not be generated.');
    }

    $quietZone = 4;
    $viewSize = $size + ($quietZone * 2);
    $path = '';
    foreach ($matrix as $y => $line) {
        for ($x = 0, $length = strlen($line); $x < $length; $x++) {
            if ($line[$x] === '1') {
                $path .= 'M' . ($x + $quietZone) . ' ' . ($y + $quietZone) . 'h1v1h-1z';
            }
        }
    }

    return '<svg class="shelf-qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $viewSize . ' ' . $viewSize . '" role="img" aria-label="' . clean($label) . '"><rect width="' . $viewSize . '" height="' . $viewSize . '" fill="#fff"/><path d="' . $path . '" fill="#111827"/></svg>';
}

$productsStmt = $pdo->query(
    "SELECT id, product_name, category, price
     FROM products
     WHERE is_active = 1
     ORDER BY category ASC, product_name ASC"
);
$products = $productsStmt->fetchAll();
$errors = [];
$printProducts = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $tokens = isset($_POST['product_ids']) && is_array($_POST['product_ids']) ? $_POST['product_ids'] : [];
    $ids = [];
    foreach ($tokens as $token) {
        $id = dec_id($token);
        if ($id > 0) {
            $ids[$id] = true;
        }
    }
    $ids = array_keys($ids);
    if (!$ids || count($ids) > 96) {
        $errors[] = 'Select between 1 and 96 active products.';
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, product_name, category, price
             FROM products
             WHERE is_active = 1 AND id IN ($placeholders)
             ORDER BY product_name ASC"
        );
        $stmt->execute($ids);
        $selected = $stmt->fetchAll();
        if (count($selected) !== count($ids)) {
            $errors[] = 'One or more selected products are unavailable.';
        } else {
            $baseUrl = rtrim((string) env('APP_URL', ''), '/');
            if ($baseUrl === '') {
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['SCRIPT_NAME']);
            }
            $printProducts = $selected;
        }
    }
}
if ($printProducts !== null) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Print Shelf Labels</title>
        <style>
            * { box-sizing: border-box; }
            body { margin: 0; padding: 32px; color: #0f172a; background: #f8fafc; font: 14px/1.5 "Plus Jakarta Sans", "Trebuchet MS", Verdana, sans-serif; }
            .print-toolbar { max-width: 1100px; margin: 0 auto 24px; padding: 20px 24px; display: flex; justify-content: space-between; align-items: center; gap: 20px; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .055); }
            .print-toolbar strong { color: #1d4ed8; font-size: 18px; }
            .print-toolbar p { margin: 5px 0 0; color: #475569; }
            .print-toolbar a, .print-toolbar button { display: inline-block; padding: 11px 16px; border: 1px solid #e2e8f0; border-radius: 12px; background: #fff; color: #1d4ed8; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer; }
            .print-toolbar button { border-color: #2563eb; background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; box-shadow: 0 2px 10px rgba(37, 99, 235, .22); }
            .print-toolbar a:hover { background: #eff6ff; }
            .print-toolbar button:hover { filter: brightness(1.08); }
            .shelf-labels { max-width: 1100px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr)); justify-content: center; gap: 16px; }
            .shelf-label { min-height: 152px; display: flex; align-items: center; gap: 16px; padding: 16px; overflow: hidden; border: 1px solid #e2e8f0; border-top: 3px solid #3b82f6; border-radius: 16px; background: #fff; box-shadow: 0 10px 28px rgba(15, 23, 42, .06); }
            .shelf-qr { width: 112px; height: 112px; flex: 0 0 112px; padding: 4px; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; shape-rendering: crispEdges; }
            .shelf-label__details { min-width: 0; }
            .shelf-label__name { display: -webkit-box; overflow: hidden; color: #0f172a; font-size: 15px; font-weight: 800; line-height: 1.35; overflow-wrap: anywhere; -webkit-box-orient: vertical; -webkit-line-clamp: 3; }
            .shelf-label__category { display: inline-block; margin-top: 8px; padding: 3px 8px; border-radius: 999px; background: #eff6ff; color: #1d4ed8; font-size: 11px; font-weight: 700; }
            .shelf-label__price { margin-top: 8px; color: #1d4ed8; font-size: 16px; font-weight: 800; }
            .shelf-labels--few { grid-template-columns: repeat(auto-fit, minmax(min(100%, 340px), 480px)); justify-content: center; }
            .shelf-labels--few .shelf-label { min-height: 220px; gap: 20px; padding: 22px; }
            .shelf-labels--few .shelf-qr { width: 150px; height: 150px; flex-basis: 150px; }
            .shelf-labels--few .shelf-label__name { font-size: 19px; }
            .shelf-labels--few .shelf-label__category { margin-top: 12px; font-size: 13px; }
            .shelf-labels--few .shelf-label__price { margin-top: 12px; font-size: 20px; }
            @media (max-width: 760px) { body { padding: 16px; } .shelf-labels { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; } .shelf-label { min-height: 136px; gap: 12px; padding: 12px; } .shelf-qr { width: 92px; height: 92px; flex-basis: 92px; } }
            @media (max-width: 520px) { .shelf-labels { grid-template-columns: 1fr; } .print-toolbar { align-items: flex-start; flex-direction: column; padding: 16px; } .print-toolbar > div:last-child { display: flex; width: 100%; gap: 8px; } }
            @media print {
                @page { size: A4 portrait; margin: 8mm; }
                body { padding: 0; background: #fff; font: 9pt Arial, sans-serif; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
                .print-toolbar { display: none; }
                .shelf-labels { max-width: none; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 3mm; }
                .shelf-label { min-height: 34mm; gap: 3mm; padding: 2mm; break-inside: avoid; border: 1px solid #cbd5e1; border-top: 1mm solid #3b82f6; border-radius: 1mm; box-shadow: none; }
                .shelf-qr { width: 25mm; height: 25mm; flex: 0 0 25mm; padding: 0; border: 0; border-radius: 0; }
                .shelf-label__name { font-size: 9pt; }
                .shelf-label__category { margin-top: 2mm; padding: 0; background: transparent; color: #475569; font-size: 7.5pt; }
                .shelf-label__price { margin-top: 2mm; color: #1d4ed8; font-size: 9pt; }
                .shelf-labels--few { grid-template-columns: repeat(auto-fit, minmax(55mm, 65mm)); justify-content: center; }
                .shelf-labels--few .shelf-label { min-height: 52mm; gap: 3mm; padding: 3mm; }
                .shelf-labels--few .shelf-qr { width: 40mm; height: 40mm; flex-basis: 40mm; }
                .shelf-labels--few .shelf-label__name { font-size: 11pt; }
                .shelf-labels--few .shelf-label__category { font-size: 9pt; }
                .shelf-labels--few .shelf-label__price { font-size: 11pt; }
            }
        </style>
    </head>
    <body>
        <header class="print-toolbar">
            <div><strong><?= count($printProducts) ?> shelf labels ready</strong><p>Print on A4 paper. QR codes open each product’s details page.</p></div>
            <div><a href="shelf_labels.php">Back to selection</a> <button type="button" onclick="window.print()">Print labels</button></div>
        </header>
        <main class="shelf-labels<?= count($printProducts) <= 3 ? ' shelf-labels--few' : '' ?>">
            <?php foreach ($printProducts as $product): ?>
                <?php
                $token = enc_id((int) $product['id']);
                $url = $baseUrl . '/scan.php?id=' . rawurlencode($token);
                ?>
                <article class="shelf-label">
                    <?= shelfLabelQrSvg($url, 'QR code for ' . $product['product_name']) ?>
                    <div class="shelf-label__details">
                        <div class="shelf-label__name"><?= clean($product['product_name']) ?></div>
                        <div class="shelf-label__category"><?= clean($product['category']) ?></div>
                        <div class="shelf-label__price"><?= peso($product['price']) ?></div>
                    </div>
                </article>
            <?php endforeach; ?>
        </main>
    </body>
    </html>
    <?php
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Shelf QR labels</title>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <style>
        .label-intro { display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap; }
        .label-kicker { color:var(--green-mid); font-size:12px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; margin:0 0 6px; }
        .label-count { color:var(--ink-soft); font-size:13px; }
        .label-toolbar { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .label-selection { color:var(--ink-soft); font-size:13px; }
        .label-selection strong { color:var(--text-accent); }
        .label-table-wrap {
            max-height: min(56vh, 520px);
            overflow: auto;
            border: 1px solid var(--line);
            border-radius: 12px;
            scrollbar-gutter: stable;
        }
        .label-table thead th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: var(--white);
            box-shadow: 0 1px 0 var(--line);
        }
        .label-table td:first-child, .label-table th:first-child { width:48px; text-align:center; }
        .label-table td:last-child, .label-table th:last-child { text-align:right; }
        .label-actions { display:flex; justify-content:flex-end; gap:12px; margin-top:18px; flex-wrap:wrap; }
        .label-empty { color:var(--ink-soft); padding:24px; text-align:center; }
    </style>
</head>
<body>
<div class="app-shell">
    <?php $active = "shelf_labels"; include 'includes/sidebar.php'; ?>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_products ?></div>
            <h1>Shelf Labels</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($__avatarFile)): ?><img src="uploads/avatars/<?= clean($__avatarFile) ?>" alt=""><?php else: ?><?= clean($__initial) ?><?php endif; ?></span>
                <span class="user-name"><?= clean($__full_name !== '' ? $__full_name : 'Administrator') ?></span>
            </div>
        </header>
        <main class="content">
            <div class="label-intro">
                <div>
                    <p class="label-kicker">In-store signage</p>
                    <div class="page-header"><h1>Shelf QR labels</h1><p class="page-subtitle">Create printable A4 labels that connect every shelf product to its verified details.</p></div>
                </div>
                <span class="label-count"><?= count($products) ?> active products</span>
            </div>

            <?php foreach ($errors as $error): ?><div class="alert alert--error"><?= clean($error) ?></div><?php endforeach; ?>

            <section class="panel">
                <div class="panel-header">
                    <div><h2>Choose products</h2><span class="field-hint">Select between 1 and 96 products for printable A4 labels.</span></div>
                    <span class="badge badge--neutral" id="selectionSummary">0 selected</span>
                </div>
                <div class="panel-body">
                    <form method="post" id="labelForm">
                        <?php csrfField(); ?>
                        <div class="label-toolbar">
                            <label class="field-label"><input type="checkbox" id="all" aria-label="Select all products"> Select all active products</label>
                            <span class="label-selection">Labels include a QR code, product name, category, and price.</span>
                        </div>
                        <div class="label-table-wrap">
                            <table class="data-table label-table">
                                <thead><tr><th><span class="sr-only">Select</span></th><th>Product</th><th>Category</th><th>Price</th></tr></thead>
                                <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td><input type="checkbox" name="product_ids[]" value="<?= clean(enc_id((int) $product['id'])) ?>" aria-label="Select <?= clean($product['product_name']) ?>"></td>
                                        <td><strong><?= clean($product['product_name']) ?></strong></td>
                                        <td><?= clean($product['category']) ?></td>
                                        <td><?= peso($product['price']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$products): ?><tr><td class="label-empty" colspan="4">No active products are available for labeling.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="label-actions">
                            <button class="action-btn" type="submit">Create printable labels</button>
                        </div>
                    </form>
                </div>
            </section>
        </main>
    </div>
</div>
<script>
(function () {
    var all = document.getElementById('all');
    var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="product_ids[]"]'));
    var summary = document.getElementById('selectionSummary');
    function update() {
        var selected = boxes.filter(function (box) { return box.checked; }).length;
        summary.textContent = selected + ' selected';
        all.checked = boxes.length > 0 && selected === boxes.length;
        all.indeterminate = selected > 0 && selected < boxes.length;
    }
    all.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = all.checked; });
        update();
    });
    boxes.forEach(function (box) { box.addEventListener('change', update); });
    update();
})();
</script>
</body>
</html>
