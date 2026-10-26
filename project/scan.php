<?php
require_once __DIR__ . '/includes/public_bootstrap.php';
$pdo = public_pdo();
$returnKey = (string) ($_GET['return'] ?? '');
$returnTargets = [
    'admin' => 'dashboard.php',
    'inventory' => 'inventory_dashboard.php',
    'cashier' => 'pos.php',
];
if (!isset($returnTargets[$returnKey])) {
    $returnKey = '';
}
$returnQuery = $returnKey !== '' ? '&return=' . rawurlencode($returnKey) : '';
$returnHref = $returnTargets[$returnKey] ?? 'catalog.php';
$returnLabel = $returnKey !== '' ? 'Back to dashboard' : 'Back to catalog';
$browseHref = in_array($returnKey, ['admin', 'inventory'], true) ? 'products.php' : 'catalog.php';
$scanAgainHref = 'scanner.php?return=' . rawurlencode($returnKey !== '' ? $returnKey : 'catalog');
if (!pub_rate_limit($pdo, 'scan', 60, 60)) {
    http_response_code(429);
    exit('Too many scan requests. Please try again shortly.');
}

$token = trim((string) ($_GET['id'] ?? ''));
$barcode = trim((string) ($_GET['b'] ?? ''));
if ($token !== '') {
    $id = dec_id($token);
    if ($id > 0) {
        header('Location: product_detail.php?id=' . rawurlencode($token) . $returnQuery, true, 302);
        exit;
    }
}
if ($barcode !== '' && preg_match('/^[0-9A-Za-z._-]{3,64}$/', $barcode)) {
    $stmt = $pdo->prepare('SELECT id FROM products WHERE barcode = ? AND is_active = 1 LIMIT 1');
    $stmt->execute([$barcode]);
    $id = (int) $stmt->fetchColumn();
    if ($id === 0 && preg_match('/^0(\d{13})$/', $barcode, $gtinMatch)) {
        $stmt->execute([$gtinMatch[1]]);
        $id = (int) $stmt->fetchColumn();
    }
    if ($id > 0) {
        pub_log_scan($pdo, $id, 'phone');
        header('Location: product_detail.php?id=' . rawurlencode(enc_id($id)) . $returnQuery, true, 302);
        exit;
    }
    $message = 'No active product was found for that barcode.';
}
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Scan a product</title>
<?php include __DIR__ . '/includes/public_theme.php'; ?>
<style>
.scan-result-page { min-height:100vh; }
.scan-result-nav { width:min(100% - 32px, 1120px); height:72px; margin:0 auto; padding:0 22px; border:1px solid var(--public-line); border-radius:0 0 18px 18px; }
.scan-result-main { width:min(100% - 32px, 720px); margin:clamp(36px,9vh,88px) auto 0; padding-bottom:56px; }
.scan-result-heading { margin:0 0 8px; color:var(--public-heading); font-family:'Fraunces',Georgia,serif; font-size:clamp(2rem,5vw,3rem); line-height:1.1; letter-spacing:-.035em; }
.scan-result-lede { margin:0 0 24px; color:var(--public-muted); }
.scan-result-card { padding:clamp(22px,5vw,36px); }
.scan-result-card-title { margin:0 0 8px; color:var(--public-heading); font-size:1.2rem; }
.scan-result-error { display:flex; gap:12px; align-items:flex-start; margin-bottom:22px; padding:16px 18px; border:1px solid #fecaca; border-radius:14px; background:#fff1f2; color:#9f1239; }
.scan-result-error strong { display:block; margin-bottom:3px; color:#881337; }
.scan-result-error p { margin:0; font-size:.92rem; }
.scan-result-icon { flex:0 0 26px; width:26px; height:26px; display:grid; place-items:center; border-radius:50%; background:#ffe4e6; font-weight:800; }
.scan-result-code { margin:0 0 20px; color:var(--public-muted); font-size:.92rem; }
.scan-result-code strong { color:var(--public-heading); font-variant-numeric:tabular-nums; letter-spacing:.035em; }
.scan-result-form { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:10px; align-items:end; }
.scan-result-form label { grid-column:1/-1; color:var(--public-heading); font-size:.88rem; font-weight:700; }
.scan-result-form input { width:100%; min-height:48px; padding:0 14px; border:1px solid var(--public-line); border-radius:11px; background:#fff; color:var(--public-heading); font:inherit; }
.scan-result-form input:focus-visible { outline:3px solid color-mix(in srgb,var(--public-accent) 24%,transparent); border-color:var(--public-accent); }
.scan-result-form button { min-height:48px; padding:0 19px; border:0; border-radius:11px; background:var(--public-accent); color:#fff; font:inherit; font-weight:800; cursor:pointer; box-shadow:0 5px 14px rgba(29,78,216,.2); }
.scan-result-form button:hover { filter:brightness(.94); transform:translateY(-1px); }
.scan-result-help { margin:20px 0 0; color:var(--public-muted); font-size:.86rem; }
.scan-result-actions { display:flex; flex-wrap:wrap; gap:10px; margin-top:20px; }
.scan-result-actions a { display:inline-flex; min-height:42px; align-items:center; padding:0 14px; border:1px solid var(--public-line); border-radius:10px; color:var(--public-accent); font-weight:700; text-decoration:none; }
.scan-result-actions a:hover { border-color:var(--public-accent); background:var(--public-soft); }
@media (max-width:520px) {
    .scan-result-nav { width:100%; height:64px; padding:0 16px; border-width:0 0 1px; border-radius:0; }
    .scan-result-main { width:min(100% - 24px, 720px); margin-top:34px; }
    .scan-result-form { grid-template-columns:1fr; }
    .scan-result-form button { width:100%; }
}
</style>
</head>
<body class="public-page scan-result-page">
<nav class="public-nav scan-result-nav"><a href="<?= public_escape($returnHref) ?>">← <?= public_escape($returnLabel) ?></a><a href="<?= public_escape($browseHref) ?>">HCI GroceryOne</a></nav>
<main class="scan-result-main">
    <h1 class="scan-result-heading">Product lookup</h1>
    <p class="scan-result-lede">Search by scanning a product code or entering it below.</p>
    <section class="public-card scan-result-card" aria-labelledby="scanResultTitle">
        <?php if (!empty($message)): ?>
            <div class="scan-result-error" role="alert">
                <span class="scan-result-icon" aria-hidden="true">!</span>
                <div><strong id="scanResultTitle">No matching product found</strong><p><?= public_escape($message) ?> Check that the code is saved on an active product.</p></div>
            </div>
            <?php if ($barcode !== ''): ?><p class="scan-result-code">Scanned code: <strong><?= public_escape($barcode) ?></strong></p><?php endif; ?>
        <?php else: ?>
            <h2 class="scan-result-card-title" id="scanResultTitle">Find a product</h2>
            <p class="scan-result-lede">Scan a shelf QR/barcode, or enter the barcode manually.</p>
        <?php endif; ?>
        <form class="scan-result-form" method="get" action="scan.php">
            <?php if ($returnKey !== ''): ?><input type="hidden" name="return" value="<?= public_escape($returnKey) ?>"><?php endif; ?>
            <label for="b">Barcode or product code</label>
            <input id="b" name="b" inputmode="text" autocomplete="off" maxlength="64" value="<?= public_escape($barcode) ?>" placeholder="Enter barcode or store code" autofocus>
            <button type="submit">Find product</button>
        </form>
        <?php if (!empty($message)): ?><p class="scan-result-help">If this is a new barcode, add it to the product record first, then scan again.</p><?php endif; ?>
        <div class="scan-result-actions"><a href="<?= public_escape($scanAgainHref) ?>">Scan another code</a><a href="<?= public_escape($browseHref) ?>">Browse products</a></div>
    </section>
</main>
</body></html>
