<?php
require_once __DIR__ . '/includes/public_bootstrap.php';

$pdo = public_pdo();
if (!pub_rate_limit($pdo, 'nutrition_compare', 120, 60)) {
    http_response_code(429);
    exit('Too many requests. Please try again shortly.');
}

$tokens = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['ids'] ?? '')))));
$ids = [];
foreach (array_slice($tokens, 0, 3) as $token) {
    $id = dec_id($token);
    if ($id > 0) {
        $ids[$id] = $id;
    }
}

$products = [];
$comparisonNotice = '';
if ($ids) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT p.id, p.product_name, p.category, p.image, d.brand, d.nutrition_basis,
                d.serving_size, d.calories, d.protein_g, d.carbs_g, d.fat_g,
                d.sugar_g, d.sodium_mg, d.allergens
         FROM products p
         INNER JOIN product_details d ON d.product_id = p.id AND d.review_status = 'verified'
         WHERE p.is_active = 1 AND p.id IN ({$placeholders})
         ORDER BY p.product_name"
    );
    $stmt->execute(array_values($ids));
    $products = $stmt->fetchAll();
    $bases = array_values(array_unique(array_filter(array_column($products, 'nutrition_basis'))));
    $categories = array_values(array_unique(array_column($products, 'category')));
    if (count($bases) > 1) {
        $comparisonNotice = 'Not comparable — these products use different measurement bases (per serving versus per 100g).';
    } elseif (count($categories) > 1) {
        $comparisonNotice = 'Choose products from the same category for a meaningful comparison.';
    }
}

$choices = $pdo->query(
    "SELECT p.id, p.product_name
     FROM products p
     INNER JOIN product_details d ON d.product_id = p.id AND d.review_status = 'verified'
     WHERE p.is_active = 1
     ORDER BY p.product_name
     LIMIT 100"
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nutrition comparison - Grocery Store</title>
    <style>
        :root{--bg:#f8fafc;--card:#fff;--ink:#334155;--heading:#0f172a;--muted:#64748b;--line:#e2e8f0;--accent:#1d4ed8;--soft:#eff6ff}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.6 'Plus Jakarta Sans',Arial,sans-serif;background-image:linear-gradient(rgba(37,99,235,.045) 1px,transparent 1px),linear-gradient(90deg,rgba(37,99,235,.045) 1px,transparent 1px);background-size:32px 32px}.wrap{max-width:1180px;margin:auto;padding:32px clamp(18px,5vw,72px) 72px}.navbar{height:74px;margin:-32px calc(-1 * clamp(18px,5vw,72px)) 32px;padding:0 clamp(18px,5vw,72px);display:flex;align-items:center;justify-content:space-between;gap:16px;background:rgba(255,255,255,.92);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:5;backdrop-filter:blur(18px)}.nav-left{display:flex;align-items:center;gap:16px;flex-shrink:0}.back-btn,.theme-toggle{display:inline-flex;align-items:center;gap:7px;height:40px;padding:0 14px;border:1px solid var(--line);border-radius:12px;background:#fff;color:var(--ink);font:600 .8125rem 'Plus Jakarta Sans',Arial,sans-serif;text-decoration:none;cursor:pointer}.back-btn svg,.theme-toggle svg{width:16px;height:16px}.nav-divider{width:1px;height:24px;background:var(--line)}.nav-brand{display:flex;align-items:center;gap:10px;color:var(--heading);text-decoration:none}.nav-logo{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:linear-gradient(135deg,var(--accent),#0284c7)}.nav-logo svg{width:22px;height:22px}.nav-title{font:700 1.0625rem 'Fraunces',Georgia,serif}.nav-title span{color:var(--accent)}.nav-search{flex:1;max-width:440px;min-width:0}.nav-search form{display:flex;gap:8px}.search-input{flex:1;height:40px;padding:0 14px;border:1px solid var(--line);border-radius:12px;background:#f1f5f9;color:var(--heading);font:.875rem 'Plus Jakarta Sans',Arial,sans-serif}.btn-search{height:40px;padding:0 20px;border:1px solid var(--accent);border-radius:12px;background:var(--accent);color:#fff;font:700 .875rem 'Plus Jakarta Sans',Arial,sans-serif;cursor:pointer}.theme-toggle svg{color:var(--accent)}.panel{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:24px;margin-top:20px;box-shadow:0 8px 20px rgba(15,23,42,.06)}h1{font:800 clamp(2rem,4vw,3rem)/1.1 'Fraunces',Georgia,serif;color:var(--heading);margin:0}.picker{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.picker label{display:grid;gap:6px;font-weight:800;color:var(--heading)}.picker select,button{padding:11px 12px;border:1px solid #cbd5e1;border-radius:10px;background:#fff;color:var(--ink);font:inherit}.picker select:focus{outline:3px solid rgba(29,78,216,.14);border-color:var(--accent)}button{background:var(--accent);color:#fff;font-weight:800;cursor:pointer}button:hover{filter:brightness(1.08)}.table-wrap{overflow:auto}.compare{width:100%;border-collapse:separate;border-spacing:0;min-width:620px}.compare th,.compare td{padding:13px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}.compare th{background:var(--soft);color:var(--heading);font-weight:800}.compare tr:first-child th:first-child{border-top-left-radius:12px}.compare tr:first-child th:last-child{border-top-right-radius:12px}.compare td.best{background:#ecfdf5;color:#166534;font-weight:800}.muted{color:var(--muted)}.notice{padding:14px;background:#fff7ed;border-left:4px solid #f97316;border-radius:10px}@media(max-width:700px){.navbar{height:auto;min-height:74px;flex-wrap:wrap;padding-block:12px;margin-top:-14px}.nav-left{flex:1;min-width:0}.nav-divider{display:none}.nav-search{order:3;flex-basis:100%;max-width:none}.theme-toggle span{display:none}.theme-toggle{width:40px;padding:0;justify-content:center}.picker{grid-template-columns:1fr}}
        html[data-theme="dark"] { --bg:#070914; --card:#111a3c; --ink:#cbd5e1; --heading:#f4f7ff; --muted:#9ca8c7; --line:rgba(148,163,255,.16); --accent:#4d8dff; --soft:#0a1024; }
        html[data-theme="dark"] .navbar, html[data-theme="dark"] .back-btn, html[data-theme="dark"] .theme-toggle { background:#111a3c; }
        html[data-theme="dark"] .search-input, html[data-theme="dark"] select { background:#0a1024; color:var(--ink); }
    </style>
<link rel="stylesheet" href="theme-toggle.css?v=4">
<script src="theme-sync.js?v=3" defer></script>
</head>
<body>
<main class="wrap">
    <nav class="navbar">
        <div class="nav-left">
            <a href="index.php" class="back-btn"><svg viewBox="0 0 20 20" fill="none"><path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Back to Home</a>
            <div class="nav-divider"></div>
            <a class="nav-brand" href="catalog.php"><div class="nav-logo"><svg viewBox="0 0 48 48" aria-hidden="true"><path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#fff"/><path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/><path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg></div><span class="nav-title">Manage <span>Grocery</span></span></a>
        </div>
        <div class="nav-search"><form method="get" action="catalog.php"><input class="search-input" name="search" placeholder="Search products..." aria-label="Search products"><button class="btn-search" type="submit">Search</button></form></div>
        <button class="theme-toggle" type="button" aria-label="Switch to dark mode" aria-pressed="false"><svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M20.4 15.4A8.5 8.5 0 0 1 8.6 3.6 9 9 0 1 0 20.4 15.4Z" stroke-linecap="round" stroke-linejoin="round"/></svg><svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4" stroke-linecap="round"/></svg><span>Dark</span></button>
    </nav>
    <h1>Nutrition comparison</h1>
    <p class="muted">Only active products with verified label information can be compared.</p>
    <section class="panel">
        <form method="get" class="picker">
            <?php for ($i = 0; $i < 3; $i++): ?>
                <label>Product <?= $i + 1 ?>
                    <select name="pick<?= $i ?>">
                        <option value="">Choose a product</option>
                        <?php foreach ($choices as $choice): ?>
                            <option value="<?= public_escape(enc_id((int) $choice['id'])) ?>"><?= public_escape($choice['product_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
            <?php endfor; ?>
            <button type="submit">Compare products</button>
        </form>
    </section>
    <?php if (isset($_GET['pick0'])):
        $selected = [];
        for ($i = 0; $i < 3; $i++) {
            $pick = trim((string) ($_GET['pick' . $i] ?? ''));
            if ($pick !== '') {
                $selected[] = $pick;
            }
        }
        $target = 'nutrition_compare.php?ids=' . rawurlencode(implode(',', $selected));
    ?>
        <script>window.location.replace(<?= json_encode($target, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);</script>
    <?php elseif ($comparisonNotice !== ''): ?>
        <section class="panel notice"><?= public_escape($comparisonNotice) ?></section>
    <?php elseif ($products): ?>
        <section class="panel table-wrap">
            <table class="compare">
                <thead><tr><th>Nutrient</th><?php foreach ($products as $product): ?><th><?= public_escape($product['product_name']) ?><?php if ($product['brand']): ?><br><span class="muted"><?= public_escape($product['brand']) ?></span><?php endif; ?></th><?php endforeach; ?></tr></thead>
                <tbody>
                    <?php foreach (['serving_size'=>'Serving size','calories'=>'Calories (kcal)','protein_g'=>'Protein (g)','carbs_g'=>'Carbohydrates (g)','fat_g'=>'Fat (g)','sugar_g'=>'Sugar (g)','sodium_mg'=>'Sodium (mg)','allergens'=>'Allergens'] as $field => $label):
                        $numeric = in_array($field, ['calories', 'protein_g', 'carbs_g', 'fat_g', 'sugar_g', 'sodium_mg'], true);
                        $values = $numeric ? array_values(array_filter(array_map(static function ($product) use ($field) {
                            return $product[$field] === null || $product[$field] === '' ? null : (float) $product[$field];
                        }, $products), static fn($value) => $value !== null)) : [];
                        $best = $numeric && count($values) > 1 ? min($values) : null;
                    ?>
                        <tr><th><?= $label ?></th><?php foreach ($products as $product):
                            $value = $product[$field];
                            $isBest = $best !== null && $value !== null && $value !== '' && abs((float) $value - $best) < 0.0001;
                        ?><td class="<?= $isBest ? 'best' : '' ?>"><?= public_escape($value === null || $value === '' ? 'Not listed' : $value) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p class="muted">Values are shown as verified from the product label. “Not listed” is not a zero.</p>
        </section>
    <?php elseif ($tokens): ?>
        <section class="panel notice">Those products do not have verified nutrition details yet.</section>
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
