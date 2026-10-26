<?php
require_once __DIR__ . '/includes/public_bootstrap.php';

$id = get_enc_id($_GET, 'id');
if ($id < 1) {
    http_response_code(404);
    exit('Product not found.');
}

$pdo = public_pdo();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$catalogPrivate = !empty($_SESSION['catalog_private']);
$catalogHref = $catalogPrivate ? 'catalog.php?private=1' : 'catalog.php';
$returnKey = (string) ($_GET['return'] ?? '');
$returnTargets = [
    'admin' => ['href' => 'dashboard.php', 'label' => 'Back to dashboard'],
    'inventory' => ['href' => 'inventory_dashboard.php', 'label' => 'Back to dashboard'],
    'cashier' => ['href' => 'pos.php', 'label' => 'Back to dashboard'],
];
$returnContext = $returnTargets[$returnKey] ?? null;
$detailBackHref = $returnContext['href'] ?? $catalogHref;
$detailBackLabel = $returnContext['label'] ?? 'Back to catalog';
$productSearchAction = in_array($returnKey, ['admin', 'inventory'], true) ? 'products.php' : 'catalog.php';
$detailReturnQuery = $returnContext !== null ? '&return=' . rawurlencode($returnKey) : '';
if (!pub_rate_limit($pdo, 'product_detail', 120, 60)) {
    http_response_code(429);
    exit('Too many requests. Please try again shortly.');
}
$stmt = $pdo->prepare(
    "SELECT p.id, p.product_name, p.category, p.price, p.discount_type,
            p.discount_value,
            CASE WHEN COALESCE(pb.batch_count, 0) > 0 THEN COALESCE(pb.sellable_stock, 0) ELSE p.stock END AS stock,
            p.image,
            CASE WHEN d.review_status = 'verified' THEN d.description ELSE NULL END AS description,
            CASE WHEN d.review_status = 'verified' THEN d.brand ELSE NULL END AS brand,
            CASE WHEN d.review_status = 'verified' THEN d.unit_name ELSE NULL END AS unit_name,
            CASE WHEN d.review_status = 'verified' THEN d.net_weight ELSE NULL END AS net_weight,
            CASE WHEN d.review_status = 'verified' THEN d.ingredients ELSE NULL END AS ingredients,
            CASE WHEN d.review_status = 'verified' THEN d.allergens ELSE NULL END AS allergens,
            CASE WHEN d.review_status = 'verified' THEN d.storage_notes ELSE NULL END AS storage_notes,
            CASE WHEN d.review_status = 'verified' THEN d.nutrition_basis ELSE NULL END AS nutrition_basis,
            CASE WHEN d.review_status = 'verified' THEN d.serving_size ELSE NULL END AS serving_size,
            CASE WHEN d.review_status = 'verified' THEN d.servings_per_pack ELSE NULL END AS servings_per_pack,
            CASE WHEN d.review_status = 'verified' THEN d.calories ELSE NULL END AS calories,
            CASE WHEN d.review_status = 'verified' THEN d.protein_g ELSE NULL END AS protein_g,
            CASE WHEN d.review_status = 'verified' THEN d.carbs_g ELSE NULL END AS carbs_g,
            CASE WHEN d.review_status = 'verified' THEN d.fat_g ELSE NULL END AS fat_g,
            CASE WHEN d.review_status = 'verified' THEN d.sugar_g ELSE NULL END AS sugar_g,
            CASE WHEN d.review_status = 'verified' THEN d.sodium_mg ELSE NULL END AS sodium_mg,
            d.review_status,
            d.nutrition_source, d.nutrition_source_url
     FROM products p
     LEFT JOIN product_details d ON d.product_id = p.id
     LEFT JOIN (
         SELECT product_id,
                COUNT(*) AS batch_count,
                SUM(CASE WHEN quantity_remaining > 0
                               AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                         THEN quantity_remaining ELSE 0 END) AS sellable_stock
         FROM product_batches
         GROUP BY product_id
     ) pb ON pb.product_id = p.id
     WHERE p.id = ? AND p.is_active = 1
     LIMIT 1"
);
$stmt->execute([$id]);
$product = $stmt->fetch();
if (!$product) {
    http_response_code(404);
    exit('Product not found.');
}

pub_log_scan($pdo, $id, 'catalog_click');
$price = (float) $product['price'];
if ($product['discount_type'] === 'percent') {
    $price -= $price * (float) $product['discount_value'] / 100;
} elseif ($product['discount_type'] === 'fixed') {
    $price = max(0, $price - (float) $product['discount_value']);
}
$hasDiscount = $price < (float) $product['price'] - 0.001;
$relatedStmt = $pdo->prepare(
    "SELECT p.id, p.product_name, p.price,
            CASE WHEN COALESCE(pb.batch_count, 0) > 0 THEN COALESCE(pb.sellable_stock, 0) ELSE p.stock END AS stock,
            p.image
     FROM products p
     LEFT JOIN (
         SELECT product_id,
                COUNT(*) AS batch_count,
                SUM(CASE WHEN quantity_remaining > 0
                               AND (expiry_date IS NULL OR expiry_date >= CURDATE())
                         THEN quantity_remaining ELSE 0 END) AS sellable_stock
         FROM product_batches
         GROUP BY product_id
     ) pb ON pb.product_id = p.id
     WHERE p.is_active = 1 AND p.category = ? AND p.id <> ?
       AND (CASE WHEN COALESCE(pb.batch_count, 0) > 0 THEN COALESCE(pb.sellable_stock, 0) ELSE p.stock END) > 0
     ORDER BY product_name ASC
     LIMIT 4"
);
$relatedStmt->execute([$product['category'], $id]);
$related = $relatedStmt->fetchAll();
$stockLabel = (int) $product['stock'] > 0 ? ((int) $product['stock'] . ' in stock') : 'Out of stock';
$detailsAreVerified = (string) ($product['review_status'] ?? '') === 'verified';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= public_escape($product['product_name']) ?> - Product details</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <?php include __DIR__ . '/includes/public_theme.php'; ?>
    <style>
        :root { color-scheme:light; --ink:#172033; --heading:#0f172a; --muted:#64748b; --line:#e2e8f0; --card:#fff; --soft:#f1f5f9; --accent:#1d4ed8; --accent-soft:#eff6ff; --bg:#f8fafc; --green:#15803d; --orange:#f97316; }
        * { box-sizing:border-box; } body { margin:0; background:var(--bg); color:var(--ink); font:15px/1.6 'Plus Jakarta Sans',Arial,sans-serif; background-image:linear-gradient(rgba(37,99,235,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,.045) 1px,transparent 1px); background-size:32px 32px; }
        .nav { height:74px; padding:0 clamp(18px,5vw,72px); display:flex; align-items:center; justify-content:space-between; gap:16px; background:rgba(255,255,255,.9); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:5; backdrop-filter:blur(18px); }
        .brand { display:flex; align-items:center; gap:10px; color:var(--heading); font-weight:800; } .brand-mark { width:38px; height:38px; display:grid; place-items:center; border-radius:11px; color:#fff; background:linear-gradient(135deg,var(--accent),#0284c7); font-size:1.15rem; } .brand span { color:var(--accent); }
        .nav a { text-decoration:none; color:var(--ink); font-weight:700; } .nav a:hover { color:var(--accent); }
        .wrap { max-width:1180px; margin:0 auto; padding:32px clamp(18px,5vw,72px) 72px; } a { color:inherit; }
        .back { display:inline-flex; align-items:center; gap:7px; height:40px; margin:18px 0 18px; padding:0 14px; color:var(--ink); background:var(--card); border:1px solid var(--line); border-radius:12px; text-decoration:none; font:600 .8125rem 'Plus Jakarta Sans',Arial,sans-serif; box-shadow:0 2px 8px rgba(15,23,42,.04); transition:.2s ease; }
        .back:hover { color:var(--accent); border-color:var(--accent); background:var(--accent-soft); transform:translateY(-1px); }
        .back svg { width:16px; height:16px; flex-shrink:0; }
        .back::first-letter { font-size:1.1rem; }
        .detail { display:grid; grid-template-columns:minmax(300px,.92fr) minmax(360px,1.08fr); gap:36px; align-items:start; background:var(--card); padding:28px; border:1px solid var(--line); border-radius:22px; box-shadow:0 8px 20px rgba(15,23,42,.06); }
        .photo { min-height:360px; display:grid; place-items:center; background:linear-gradient(135deg,var(--soft),var(--accent-soft)); border-radius:16px; overflow:hidden; border:1px solid var(--line); }
        .photo img { width:100%; height:100%; max-height:400px; object-fit:contain; }
        .placeholder { color:var(--muted); font-size:4rem; } h1,h2 { font-family:'Fraunces',Georgia,serif; color:var(--heading); } h1 { margin:8px 0 4px; font-size:clamp(2rem,4vw,3.2rem); line-height:1.1; } h2 { margin:26px 0 8px; font-size:1.05rem; border-bottom:1px solid var(--line); padding-bottom:6px; }
        .category { color:var(--accent); font-weight:800; text-transform:uppercase; letter-spacing:.08em; font-size:.72rem; } .brand,.muted { color:var(--muted); } .price { margin:22px 0 4px; color:var(--accent); font-family:'Fraunces',Georgia,serif; font-size:2rem; font-weight:800; }
        .stock { font-weight:800; color:<?= (int) $product['stock'] > 0 ? '#15803d' : '#b91c1c' ?>; }
        .notice { padding:12px 14px; background:#fff7ed; border-left:4px solid var(--orange); border-radius:10px; }
        .detail-info{margin-top:18px;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.detail-summary{min-width:0}.nutrition-hero{margin-top:20px;padding:16px;border:1px solid var(--line);border-radius:16px;background:var(--soft)}.nutrition-hero h2{margin:0 0 6px;font-size:1rem}.nutrition-hero p{margin:0;font-size:.8rem;color:var(--muted)}.detail-info .info-card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;box-shadow:0 4px 14px rgba(15,23,42,.04)}.info-card h2{margin:0 0 8px}.info-card p{margin:0}.info-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.info-item{padding:10px;border-radius:11px;background:var(--soft)}.info-item strong{display:block;color:var(--heading);font-size:.7rem;text-transform:uppercase;letter-spacing:.05em}.info-item span{display:block;margin-top:2px;font-size:.88rem}.nutrition-table{width:100%;border-collapse:collapse;display:grid;grid-template-columns:repeat(2,minmax(0,1fr));column-gap:24px}.nutrition-table tbody{display:contents}.nutrition-table tr{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line)}.nutrition-table td{padding:6px 0}.nutrition-table td:last-child{text-align:right;font-weight:700;color:var(--heading)}.source-note{font-size:.75rem!important;color:var(--muted);margin-top:8px!important}.source-note a{color:var(--accent);font-weight:700}
        .related { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; margin-top:18px; } .related a { text-decoration:none; background:var(--card); border:1px solid var(--line); padding:14px; border-radius:14px; box-shadow:0 2px 8px rgba(15,23,42,.04); }
        .related img { width:100%; height:130px; object-fit:contain; background:var(--soft); border-radius:8px; } .related strong { display:block; margin-top:8px; color:var(--heading); } .related span { color:var(--accent); font-weight:800; } .related-stock { display:block; margin-top:3px; color:var(--muted); font-size:.78rem; font-weight:700; }
        .navbar { height:74px; padding:0 clamp(18px,5vw,72px); display:flex; align-items:center; justify-content:space-between; gap:16px; background:rgba(255,255,255,.92); border-bottom:1px solid var(--line); position:sticky; top:0; z-index:5; backdrop-filter:blur(18px); }
        .nav-left { display:flex; align-items:center; gap:16px; flex-shrink:0; }
        .back-btn, .theme-toggle { display:inline-flex; align-items:center; gap:7px; height:40px; padding:0 14px; border:1px solid var(--line); border-radius:12px; background:#fff; color:var(--ink); font:600 .8125rem 'Plus Jakarta Sans',Arial,sans-serif; text-decoration:none; cursor:pointer; }
        .back-btn:hover, .theme-toggle:hover { color:var(--accent); border-color:var(--accent); }
        .back-btn svg, .theme-toggle svg { width:16px; height:16px; flex-shrink:0; }
        .nav-divider { width:1px; height:24px; background:var(--line); }
        .nav-brand { display:flex; align-items:center; gap:10px; }
        .nav-logo { width:38px; height:38px; display:grid; place-items:center; border-radius:11px; background:linear-gradient(135deg,var(--accent),#0284c7); box-shadow:0 6px 16px rgba(29,78,216,.22); }
        .nav-logo svg { width:22px; height:22px; }
        .nav-title { font:700 1.0625rem 'Fraunces',Georgia,serif; color:var(--heading); }
        .nav-title span { color:var(--accent); }
        .nav-search { flex:1; max-width:440px; min-width:0; }
        .nav-search form { display:flex; gap:8px; }
        .search-input { flex:1; height:40px; padding:0 14px; border:1px solid var(--line); border-radius:12px; background:var(--soft); color:var(--heading); font: .875rem 'Plus Jakarta Sans',Arial,sans-serif; }
        .btn-search { height:40px; padding:0 20px; border:1px solid var(--accent); border-radius:12px; background:var(--accent); color:#fff; font:700 .875rem 'Plus Jakarta Sans',Arial,sans-serif; cursor:pointer; }
        .theme-toggle svg { color:var(--accent); }
        .back { margin-top:18px; }
        html[data-theme="dark"] { --ink:#cbd5e1; --heading:#f4f7ff; --muted:#9ca8c7; --line:rgba(148,163,255,.16); --card:#111a3c; --soft:#0a1024; --accent:#4d8dff; --accent-soft:rgba(77,141,255,.12); --bg:#070914; }
        html[data-theme="dark"] .navbar, html[data-theme="dark"] .back-btn, html[data-theme="dark"] .theme-toggle { background:#111a3c; }
        html[data-theme="dark"] .search-input { background:#0a1024; }
        @media (max-width:700px) { .navbar{height:auto;min-height:74px;flex-wrap:wrap;padding-block:12px;} .nav-left{flex:1;min-width:0;} .nav-divider{display:none;} .nav-search{order:3;flex-basis:100%;max-width:none;} .theme-toggle span{display:none;} .theme-toggle{width:40px;padding:0;justify-content:center;} .wrap{padding:14px;} .detail{grid-template-columns:1fr;padding:18px;gap:20px;} .photo{min-height:280px;} .detail-info{grid-template-columns:1fr;}.info-grid{grid-template-columns:1fr 1fr;}.nutrition-table{grid-template-columns:1fr;} }
    </style>
<link rel="stylesheet" href="theme-toggle.css?v=4">
<script src="theme-sync.js?v=3" defer></script>
</head>
<body>
<nav class="navbar">
    <div class="nav-left">
        <a href="<?= public_escape($detailBackHref) ?>" class="back-btn"><svg viewBox="0 0 20 20" fill="none"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><?= public_escape($detailBackLabel) ?></a>
        <div class="nav-divider"></div>
        <a class="nav-brand" href="<?= public_escape($detailBackHref) ?>">
            <div class="nav-logo"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#fff"/><path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/><path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg></div>
            <span class="nav-title">Manage <span>Grocery</span></span>
        </a>
    </div>
    <div class="nav-search"><form method="get" action="<?= public_escape($productSearchAction) ?>"><?php if ($productSearchAction === 'catalog.php'): ?><input type="hidden" name="private" value="<?= $catalogPrivate ? '1' : '0' ?>"><?php endif; ?><input class="search-input" name="search" placeholder="Search products..." aria-label="Search products"><button class="btn-search" type="submit">Search</button></form></div>
    <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false"><svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.4 15.4A8.5 8.5 0 0 1 8.6 3.6 9 9 0 1 0 20.4 15.4Z" stroke-linecap="round" stroke-linejoin="round"/></svg><svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4" stroke-linecap="round"/></svg><span>Dark</span></button>
</nav>
<main class="wrap">
    <section class="detail">
        <div class="photo">
            <?php $image = public_image_url($product['image']); ?>
            <?php if ($image): ?><img src="<?= public_escape($image) ?>" alt="<?= public_escape($product['product_name']) ?>"><?php else: ?><div class="placeholder" aria-hidden="true">🛒</div><?php endif; ?>
        </div>
        <div class="detail-summary">
            <h1><?= public_escape($product['product_name']) ?></h1>
            <div class="price"><?= public_peso($price) ?><?php if ($hasDiscount): ?><del class="muted"><?= public_peso($product['price']) ?></del><?php endif; ?></div>
            <div class="stock"><?= public_escape($stockLabel) ?></div>
            <?php if (!$detailsAreVerified): ?>
                <div class="notice">This product’s details are still awaiting human verification and are not yet published for public viewing.</div>
            <?php endif; ?>
            <?php if ($detailsAreVerified && ($product['calories'] !== null || $product['protein_g'] !== null || $product['carbs_g'] !== null || $product['fat_g'] !== null || $product['sugar_g'] !== null || $product['sodium_mg'] !== null)): ?>
                <div class="nutrition-hero">
                    <h2>Nutrition facts</h2>
                    <?php if ((string) ($product['serving_size'] ?? '') !== ''): ?><p>Serving size: <?= public_escape($product['serving_size']) ?><?php if ($product['servings_per_pack'] !== null): ?> · <?= public_escape($product['servings_per_pack']) ?> servings per pack<?php endif; ?></p><?php endif; ?>
                    <table class="nutrition-table"><tbody>
                        <?php foreach (['calories' => 'Calories', 'protein_g' => 'Protein (g)', 'carbs_g' => 'Carbohydrates (g)', 'fat_g' => 'Fat (g)', 'sugar_g' => 'Sugar (g)', 'sodium_mg' => 'Sodium (mg)'] as $key => $label): ?>
                            <?php if ($product[$key] !== null): ?><tr><td><?= $label ?></td><td><?= public_escape(rtrim(rtrim(number_format((float) $product[$key], 2, '.', ''), '0'), '.')) ?></td></tr><?php endif; ?>
                        <?php endforeach; ?>
                    </tbody></table>
                    <?php if ((string) ($product['nutrition_basis'] ?? '') !== ''): ?><p class="source-note">Values are <?= public_escape(str_replace('_', ' ', $product['nutrition_basis'])) ?><?php if ((string) ($product['nutrition_source_url'] ?? '') !== ''): ?> · <a href="<?= public_escape($product['nutrition_source_url']) ?>" target="_blank" rel="noopener">View source</a><?php endif; ?></p><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php $hasDetails = $detailsAreVerified && ((string) ($product['description'] ?? '') !== '' || (string) ($product['ingredients'] ?? '') !== '' || (string) ($product['allergens'] ?? '') !== '' || (string) ($product['storage_notes'] ?? '') !== ''); ?>
        </div>
    </section>
    <?php if ($hasDetails): ?>
        <section class="detail-info">
            <?php if ((string) ($product['description'] ?? '') !== '' || (string) ($product['brand'] ?? '') !== '' || (string) ($product['unit_name'] ?? '') !== '' || (string) ($product['net_weight'] ?? '') !== ''): ?>
                <div class="info-card">
                    <h2>Product information</h2>
                    <?php if ((string) ($product['description'] ?? '') !== ''): ?><p><?= nl2br(public_escape($product['description'])) ?></p><?php endif; ?>
                    <div class="info-grid">
                        <?php foreach (['brand' => 'Brand', 'unit_name' => 'Unit', 'net_weight' => 'Net weight'] as $key => $label): ?>
                            <?php if ((string) ($product[$key] ?? '') !== ''): ?><div class="info-item"><strong><?= $label ?></strong><span><?= public_escape($product[$key]) ?></span></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ((string) ($product['ingredients'] ?? '') !== '' || (string) ($product['allergens'] ?? '') !== '' || (string) ($product['storage_notes'] ?? '') !== ''): ?>
                <div class="info-card">
                    <h2>Ingredients, allergens &amp; storage</h2>
                    <div class="info-grid">
                        <?php foreach (['ingredients' => 'Ingredients', 'allergens' => 'Allergens', 'storage_notes' => 'Storage notes'] as $key => $label): ?>
                            <?php if ((string) ($product[$key] ?? '') !== ''): ?><div class="info-item"><strong><?= $label ?></strong><span><?= nl2br(public_escape($product[$key])) ?></span></div><?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
    <?php if ($related): ?>
        <h2>Related products</h2>
        <div class="related">
            <?php foreach ($related as $item): ?>
                <a href="product_detail.php?id=<?= public_escape(enc_id((int) $item['id'])) ?><?= $catalogPrivate ? '&private=1' : '' ?><?= public_escape($detailReturnQuery) ?>">
                    <?php $relatedImage = public_image_url($item['image']); ?>
                    <?php if ($relatedImage): ?><img src="<?= public_escape($relatedImage) ?>" alt=""><?php endif; ?>
                    <strong><?= public_escape($item['product_name']) ?></strong>
                    <span><?= public_peso($item['price']) ?></span>
                    <small class="related-stock"><?= (int) $item['stock'] ?> left</small>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<script>
(function () {
    var root = document.documentElement;
    var toggle = document.querySelector('.theme-toggle');
    var saved = localStorage.getItem('manage-grocery-theme');
    if (saved === 'dark') root.dataset.theme = 'dark';
    if (!toggle) return;
    function updateToggleState() {
        var dark = root.dataset.theme === 'dark';
        toggle.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
        toggle.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
    updateToggleState();
    toggle.addEventListener('click', function () {
        var dark = root.dataset.theme !== 'dark';
        root.dataset.theme = dark ? 'dark' : 'light';
        localStorage.setItem('manage-grocery-theme', dark ? 'dark' : 'light');
        toggle.querySelector('span').textContent = dark ? 'Light' : 'Dark';
        updateToggleState();
    });
}());
</script>
</body>
</html>
