<?php
require_once __DIR__ . '/includes/public_bootstrap.php';
$catalogSessionActive = false;
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$catalogPrivateRequested = ($_GET['private'] ?? '') === '1';
$catalogKioskLocked = !empty($_SESSION['catalog_private_kiosk']);
$catalogAuthorizationFresh = !empty($_SESSION['catalog_private_authorized'])
    && (int) ($_SESSION['catalog_private_granted_at'] ?? 0) > time() - 1800;
$privateCatalogUrl = 'catalog.php?' . http_build_query(array_merge($_GET, ['private' => '1']));

if ($catalogKioskLocked && !$catalogAuthorizationFresh) {
    unset($_SESSION['catalog_private_authorized'], $_SESSION['catalog_private_granted_at']);
    header('Location: catalog_supervisor_login.php?embedded=1');
    exit;
}
if ($catalogKioskLocked && !$catalogPrivateRequested) {
    header('Location: ' . $privateCatalogUrl);
    exit;
}
if ($catalogPrivateRequested && !$catalogAuthorizationFresh) {
    header('Location: catalog_supervisor_login.php');
    exit;
}
if ($catalogAuthorizationFresh) {
    $_SESSION['catalog_private_granted_at'] = time();
}
$catalogSessionActive = $catalogPrivateRequested && $catalogAuthorizationFresh;
if ($catalogKioskLocked) {
    header('Cache-Control: no-store, private, max-age=0, must-revalidate');
    header('Pragma: no-cache');
}
$pdo = public_pdo();
if (!pub_rate_limit($pdo, 'catalog', 120, 60)) {
    http_response_code(429);
    exit('Too many requests. Please try again shortly.');
}

$search   = trim($_GET['search'] ?? '');
$category = trim($_GET['category'] ?? '');
$excludeAllergen = trim($_GET['exclude_allergen'] ?? '');

$where  = ["is_active = 1"];
$params = [];

if (!empty($search)) {
    $where[]  = "product_name LIKE ?";
    $params[] = '%' . $search . '%';
}
if (!empty($category)) {
    $where[]  = "category = ?";
    $params[] = $category;
}
$allowedAllergens = ['milk', 'peanut', 'tree nuts', 'soy', 'wheat', 'egg', 'fish', 'shellfish'];
if (in_array(strtolower($excludeAllergen), $allowedAllergens, true)) {
    $where[] = "(d.review_status = 'verified' AND (d.allergens IS NULL OR LOWER(d.allergens) NOT LIKE ?))";
    $params[] = '%' . strtolower($excludeAllergen) . '%';
} else {
    $excludeAllergen = '';
}

$sql = "SELECT p.id, p.product_name, p.category, p.price,
               CASE WHEN COALESCE(pb.batch_count, 0) > 0 THEN COALESCE(pb.sellable_stock, 0) ELSE p.stock END AS stock,
               p.image
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
        ) pb ON pb.product_id = p.id";
if (!empty($where)) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY (stock > 0) DESC, product_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$categories      = $pdo->query("SELECT id, category_name FROM categories ORDER BY category_name ASC")->fetchAll();
$total_products  = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();

$cat_counts = [];
$stmt2 = $pdo->query("SELECT category, COUNT(*) as cnt FROM products WHERE is_active = 1 GROUP BY category");
foreach ($stmt2->fetchAll() as $row) {
    $cat_counts[$row['category']] = $row['cnt'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Catalog — Grocery Store</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php include __DIR__ . '/includes/public_theme.php'; ?>
<script>
    (function () {
        try {
            var savedTheme = localStorage.getItem('manage-grocery-theme');
            document.documentElement.dataset.theme = savedTheme ||
                (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        } catch (error) {
            document.documentElement.dataset.theme = 'light';
        }
    }());
</script>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:               #f8fafc;
    --surface:          #ffffff;
    --surface-soft:     #f1f5f9;
    --text-heading:     #0f172a;
    --text-body:        #334155;
    --text-muted:       #64748b;
    --border:           #e2e8f0;
    --accent:           #2563eb;
    --accent-soft:      #eff6ff;
    --green:            #10b981;
    --green-soft:       #ecfdf5;
    --red:              #e11d48;
    --red-soft:         #fff1f2;
    --grid-line:        rgba(37, 99, 235, 0.05);
    --shadow-sm:        0 1px 3px rgba(15, 23, 42, .06), 0 1px 2px rgba(15, 23, 42, .04);
    --shadow-md:        0 8px 20px rgba(15, 23, 42, .06), 0 2px 6px rgba(15, 23, 42, .03);
    --ease:             cubic-bezier(.16, 1, .3, 1);
    --font-display:     'Fraunces', Georgia, serif;
    --font-body:        'Plus Jakarta Sans', sans-serif;
}

html[data-theme="dark"] {
    --bg:               #0b1120;
    --surface:          #111827;
    --surface-soft:     #1e293b;
    --text-heading:     #f1f5f9;
    --text-body:        #e2e8f0;
    --text-muted:       #aab8ca;
    --border:           #334155;
    --accent:           #2563eb;
    --accent-soft:      rgba(37, 99, 235, .12);
    --green:            #34d399;
    --green-soft:       rgba(52, 211, 153, .12);
    --red:              #f43f5e;
    --red-soft:         rgba(244, 63, 94, .12);
    --grid-line:        rgba(128, 151, 255, .08);
    --shadow-sm:        0 1px 3px rgba(0, 0, 0, .3);
    --shadow-md:        0 8px 20px rgba(0, 0, 0, .35);
}

html { scroll-behavior: smooth; background: var(--bg); }
body {
    min-height: 100vh;
    color: var(--text-body);
    font-family: var(--font-body);
    line-height: 1.55;
    background-color: var(--bg);
    background-image:
        linear-gradient(var(--grid-line) 1px, transparent 1px),
        linear-gradient(90deg, var(--grid-line) 1px, transparent 1px);
    background-size: 32px 32px;
    background-attachment: fixed;
    transition: background-color .3s ease, color .3s ease;
    -webkit-font-smoothing: antialiased;
}
a { text-decoration: none; color: inherit; }

/* NAVBAR */
.navbar {
    position: sticky;
    top: 0;
    z-index: 100;
    height: 74px;
    padding: 0 clamp(18px, 5vw, 72px);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    background: color-mix(in srgb, var(--surface) 88%, transparent);
    backdrop-filter: blur(18px);
    -webkit-backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border);
    box-shadow: 0 2px 10px rgba(15, 23, 42, .03);
}
.navbar { position: sticky; }
.nav-left { display: flex; align-items: center; gap: 16px; flex-shrink: 0; min-width: 0; }
.back-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 40px;
    padding: 0 15px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    color: var(--text-body);
    font-size: .8125rem;
    font-weight: 600;
    transition: .2s var(--ease);
    white-space: nowrap;
}
.back-btn:hover {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: var(--accent);
    transform: translateY(-1px);
}
.back-btn svg { width: 15px; height: 15px; flex-shrink: 0; }
.private-access {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 40px;
    padding: 0 14px;
    border: 1px solid var(--accent);
    border-radius: 12px;
    color: var(--accent);
    background: var(--accent-soft);
    font-size: .78rem;
    font-weight: 800;
    white-space: nowrap;
}
.nav-left .private-access {
    position: absolute;
    left: 50%;
    transform: translateX(-50%);
}
.private-access:hover { background: var(--accent); color: #fff; }
.access-modal { position: fixed; inset: 0; z-index: 1000; display: none; place-items: center; padding: 20px; background: rgba(15,23,42,.55); backdrop-filter: blur(8px); }
.access-modal.is-open { display: grid; }
.access-dialog { position: relative; width: min(470px, 100%); height: min(720px, 92vh); overflow: hidden; border-radius: 22px; background: var(--surface); box-shadow: 0 24px 80px rgba(15,23,42,.3); }
.access-dialog iframe { width: 100%; height: 100%; border: 0; background: var(--bg); }
.access-close { position: absolute; right: 12px; top: 12px; z-index: 2; width: 34px; height: 34px; border: 0; border-radius: 50%; background: rgba(255,255,255,.92); color: #0f172a; font-size: 1.3rem; cursor: pointer; box-shadow: 0 3px 12px rgba(15,23,42,.16); }
.nav-divider { width: 1px; height: 24px; background: var(--border); }
.nav-brand { display: flex; align-items: center; gap: 10px; }
.nav-logo {
    width: 38px; height: 38px;
    display: grid; place-items: center;
    border-radius: 11px;
    background: linear-gradient(135deg, var(--accent), #0284c7);
    box-shadow: 0 6px 16px color-mix(in srgb, var(--accent) 22%, transparent);
}
.nav-logo svg { width: 22px; height: 22px; }
.nav-title {
    font-family: var(--font-display);
    font-size: 1.0625rem;
    font-weight: 700;
    color: var(--text-heading);
    letter-spacing: -.01em;
}
.nav-title span { color: var(--accent); }

.nav-search { flex: 1; max-width: 440px; min-width: 0; }
.nav-search form { display: flex; gap: 8px; }
.search-input {
    flex: 1;
    height: 40px;
    padding: 0 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface-soft);
    color: var(--text-heading);
    font-family: var(--font-body);
    font-size: .875rem;
    transition: .2s var(--ease);
}
.search-input::placeholder { color: var(--text-muted); }
.search-input:focus {
    outline: none;
    background: var(--surface);
    border-color: var(--accent);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent) 12%, transparent);
}
.btn-search {
    height: 40px;
    padding: 0 20px;
    border: 1px solid var(--accent);
    border-radius: 12px;
    background: var(--accent);
    color: #fff;
    font-family: var(--font-body);
    font-size: .875rem;
    font-weight: 700;
    cursor: pointer;
    transition: .2s var(--ease);
    box-shadow: 0 6px 16px color-mix(in srgb, var(--accent) 22%, transparent);
    white-space: nowrap;
}
.btn-search:hover { transform: translateY(-1px); box-shadow: 0 10px 22px color-mix(in srgb, var(--accent) 28%, transparent); }

.theme-toggle {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    height: 40px;
    padding: 0 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--surface);
    color: var(--text-body);
    font-family: var(--font-body);
    font-size: .8125rem;
    font-weight: 600;
    cursor: pointer;
    transition: .2s var(--ease);
    flex-shrink: 0;
}
.theme-toggle:hover { color: var(--accent); border-color: var(--accent); transform: translateY(-1px); }
.theme-toggle svg { width: 16px; height: 16px; color: var(--accent); flex-shrink: 0; }
.theme-toggle .moon-icon { display: none; }
.theme-toggle .sun-icon { display: block; }
html[data-theme="dark"] .theme-toggle .sun-icon { display: none; }
html[data-theme="dark"] .theme-toggle .moon-icon { display: block; }

/* HERO */
.hero {
    position: relative;
    padding: 72px 24px 40px;
    text-align: center;
    background: transparent;
    border-bottom: 1px solid var(--border);
}
.hero-content {
    position: relative;
    z-index: 1;
    max-width: 720px;
    margin: 0 auto;
    animation: intro .6s var(--ease) both;
}
@keyframes intro {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: none; }
}
.hero-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 6px 14px;
    border: 1px solid color-mix(in srgb, var(--accent) 22%, var(--border));
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
    font-size: .6875rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    margin-bottom: 18px;
}
.hero-tag::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--accent);
}
.hero h1 {
    font-family: var(--font-display);
    font-size: clamp(2.25rem, 4.5vw, 3.25rem);
    font-weight: 800;
    line-height: 1.1;
    letter-spacing: -.03em;
    color: var(--text-heading);
    margin-bottom: 14px;
}
.hero h1 .accent { color: var(--accent); }
.hero p {
    font-size: .9375rem;
    line-height: 1.65;
    color: var(--text-muted);
    max-width: 520px;
    margin: 0 auto;
}

/* STORE INFO */
.store-info-bar {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
    gap: 12px;
    padding: 20px 24px 8px;
    background: transparent;
    border: 0;
}
.store-info-item {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 9px 14px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: color-mix(in srgb, var(--surface) 88%, transparent);
    box-shadow: var(--shadow-sm);
    color: var(--text-body);
    font-size: .75rem;
    font-weight: 500;
}
.store-info-item strong { color: var(--text-heading); font-weight: 700; }

/* PAGE LAYOUT */
.page-body {
    max-width: 1280px;
    margin: 0 auto;
    padding: 32px clamp(18px, 5vw, 72px) 72px;
    display: grid;
    grid-template-columns: 240px minmax(0, 1fr);
    gap: 28px;
    align-items: start;
}

/* SIDEBAR */
.filter-sidebar {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 20px;
    box-shadow: var(--shadow-md);
    position: sticky;
    top: 92px;
}
.filter-sidebar-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 14px;
    margin-bottom: 14px;
    border-bottom: 1px solid var(--border);
}
.filter-sidebar-head h3 {
    font-family: var(--font-body);
    font-size: .6875rem;
    font-weight: 700;
    color: var(--text-muted);
    text-transform: uppercase;
    letter-spacing: .12em;
}
.total-badge {
    display: inline-flex;
    align-items: center;
    padding: 3px 9px;
    border-radius: 999px;
    background: var(--accent-soft);
    border: 1px solid color-mix(in srgb, var(--accent) 20%, var(--border));
    color: var(--accent);
    font-size: .6875rem;
    font-weight: 700;
    font-family: var(--font-body);
    letter-spacing: .02em;
}
.filter-list { display: flex; flex-direction: column; gap: 4px; }
.filter-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    min-height: 42px;
    padding: 9px 13px;
    border: 1px solid transparent;
    border-radius: 8px;
    color: var(--text-body);
    font-size: .8125rem;
    font-weight: 600;
    transition: .2s var(--ease);
}
.filter-item:hover {
    background: var(--accent-soft);
    color: var(--accent);
    transform: translateX(2px);
}
.filter-item.active {
    background: var(--accent);
    color: #fff;
    border-color: var(--accent);
    box-shadow: 0 7px 16px color-mix(in srgb, var(--accent) 22%, transparent);
}
.filter-item .count {
    font-size: .6875rem;
    font-weight: 700;
    padding: 2px 8px;
    border-radius: 999px;
    background: var(--accent-soft);
    color: var(--accent);
}
.filter-item.active .count {
    background: rgba(255, 255, 255, .22);
    color: #fff;
}

/* CATALOG */
.catalog-area { min-width: 0; }
.catalog-header {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 18px;
    flex-wrap: wrap;
}
.catalog-search-form {
    display: flex;
    align-items: center;
    gap: 8px;
    flex: 1 1 360px;
    max-width: 520px;
}
.catalog-search-form .search-input { min-width: 0; }
.catalog-search-form .btn-search { flex-shrink: 0; }
 .catalog-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding-bottom: 16px;
    margin-bottom: 22px;
    border-bottom: 1px solid var(--border);
}
.catalog-header h2 {
    font-family: var(--font-display);
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--text-heading);
    letter-spacing: -.02em;
}
.catalog-header p {
    font-size: .75rem;
    color: var(--text-muted);
    margin-top: 3px;
}
.clear-link {
    padding: 8px 15px;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text-body);
    font-size: .75rem;
    font-weight: 600;
    transition: .2s var(--ease);
}
.clear-link:hover {
    background: var(--accent-soft);
    color: var(--accent);
    border-color: var(--accent);
}

/* PRODUCT GRID */
.product-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 18px;
}
.product-card {
    display: flex;
    flex-direction: column;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 20px;
    overflow: hidden;
    box-shadow: var(--shadow-sm);
    transition: .3s var(--ease);
}
.product-card:hover {
    transform: translateY(-5px);
    border-color: color-mix(in srgb, var(--accent) 40%, var(--border));
    box-shadow: 0 18px 34px color-mix(in srgb, var(--accent) 10%, transparent), var(--shadow-md);
}
.product-img {
    position: relative;
    aspect-ratio: 1.12;
    display: grid;
    place-items: center;
    overflow: hidden;
    background: linear-gradient(135deg, var(--surface-soft), var(--accent-soft));
    border-bottom: 1px solid var(--border);
}
.product-img img {
    width: 100%; height: 100%;
    object-fit: cover;
    transition: transform .4s var(--ease);
}
.product-card:hover .product-img img { transform: scale(1.05); }
.view-details { display:block; margin-top:8px; color:var(--accent); font-size:.8rem; font-weight:700; opacity:.78; }
.product-img-placeholder { font-size: 2.5rem; opacity: .45; }
.product-category-tag {
    position: absolute;
    top: 10px; left: 10px;
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: 999px;
    background: color-mix(in srgb, var(--surface) 94%, transparent);
    color: var(--accent);
    font-size: .625rem;
    font-weight: 700;
    letter-spacing: .05em;
    text-transform: uppercase;
    backdrop-filter: blur(4px);
}
.out-of-stock-overlay {
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    background: rgba(15, 23, 42, .14);
}
.out-of-stock-overlay span {
    padding: 7px 16px;
    border-radius: 999px;
    background: var(--red);
    color: #fff;
    font-size: .6875rem;
    font-weight: 800;
    letter-spacing: .05em;
    text-transform: uppercase;
    transform: rotate(-6deg);
    box-shadow: 0 8px 20px color-mix(in srgb, var(--red) 32%, transparent);
}
.product-body {
    display: flex;
    flex-direction: column;
    flex: 1;
    padding: 15px;
}
.product-name {
    font-size: .8125rem;
    font-weight: 700;
    line-height: 1.35;
    color: var(--text-heading);
    margin-bottom: 8px;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.2em;
}
.product-price {
    font-family: var(--font-display);
    font-size: 1.25rem;
    font-weight: 800;
    color: var(--accent);
    letter-spacing: -.01em;
    margin-bottom: 10px;
}
.product-card.out-of-stock { opacity: .78; }
.product-card.out-of-stock:hover { transform: translateY(-2px); }
.product-card.out-of-stock .product-price { color: var(--text-muted); }

.product-available,
.product-outofstock-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    align-self: flex-start;
    margin-top: auto;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: .6875rem;
    font-weight: 600;
}
.product-available {
    color: var(--green);
    background: var(--green-soft);
    border: 1px solid color-mix(in srgb, var(--green) 28%, var(--border));
}
.product-available::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--green);
}
.product-outofstock-badge {
    color: var(--red);
    background: var(--red-soft);
    border: 1px solid color-mix(in srgb, var(--red) 28%, var(--border));
}
.product-outofstock-badge::before {
    content: '';
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--red);
}

.empty-state {
    grid-column: 1 / -1;
    text-align: center;
    padding: 70px 20px;
    border: 1px dashed var(--border);
    border-radius: 22px;
    color: var(--text-muted);
}
.empty-state svg { width: 52px; height: 52px; opacity: .35; margin-bottom: 14px; }
.empty-state h3 {
    font-family: var(--font-display);
    font-size: 1.25rem;
    color: var(--text-heading);
    margin-bottom: 6px;
}
.empty-state p { font-size: .8125rem; }
.empty-state a {
    display: inline-block;
    margin-top: 18px;
    padding: 10px 22px;
    border-radius: 11px;
    background: var(--accent);
    color: #fff;
    font-size: .8125rem;
    font-weight: 700;
    transition: .2s var(--ease);
}
.empty-state a:hover { transform: translateY(-2px); }

/* FOOTER */
.footer {
    padding: 26px clamp(18px, 5vw, 72px);
    border-top: 1px solid var(--border);
    background: color-mix(in srgb, var(--surface) 90%, transparent);
    color: var(--text-muted);
    text-align: center;
    font-size: .8125rem;
}
.footer strong { color: var(--text-heading); font-weight: 700; }

/* RESPONSIVE */
@media (max-width: 1199px) {
    .product-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}
@media (max-width: 900px) {
    .page-body { grid-template-columns: 1fr; }
    .filter-sidebar { position: static; }
    .theme-toggle span { display: none; }
    .theme-toggle { width: 40px; padding: 0; justify-content: center; }
}
@media (max-width: 1100px) {
    .navbar { flex-wrap: wrap; height: auto; min-height: 74px; padding-block: 12px; }
    .nav-left { flex: 1 1 auto; min-width: 0; }
    .theme-toggle { order: 2; flex-shrink: 0; }
    .nav-search { order: 3; flex: 1 1 100%; max-width: none; }
    .nav-search form { min-width: 0; }
    .nav-search .search-input { min-width: 0; }
}
@media (max-width: 760px) {
    .nav-divider { display: none; }
    .product-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
}
@media (max-width: 560px) {
    .navbar { height: auto; min-height: 116px; flex-wrap: wrap; align-content: flex-start; gap: 8px; padding-block: 12px 58px; }
    .nav-left { flex: 1 1 auto; min-width: 0; gap: 5px; }
    .nav-search { order: 3; flex-basis: 100%; max-width: none; }
    .theme-toggle { order: 2; flex: 0 0 38px; }
    .catalog-search-form { flex-basis: 100%; max-width: none; }
    .back-btn { width: 34px; flex: 0 0 34px; justify-content: center; font-size: 0; padding: 0; }
    .back-btn svg { width: 18px; height: 18px; }
    .nav-divider { display: none; }
    .nav-brand { min-width: 0; flex: 0 1 auto; gap: 5px; }
    .nav-logo { width: 30px; height: 30px; flex: 0 0 30px; border-radius: 9px; }
    .nav-title { overflow: hidden; font-size: .76rem; text-overflow: ellipsis; white-space: nowrap; }
    .nav-left .private-access { position: absolute; top: 62px; left: 50%; min-width: 0; height: 36px; transform: translateX(-50%); padding-inline: 12px; border-radius: 10px; font-size: .7rem; }
    .navbar > .theme-toggle.theme-toggle { width: auto; min-width: 54px; height: 38px; flex: 0 0 auto; margin-left: auto; }
    .hero { padding: 48px 20px 28px; }
    .store-info-item { width: 100%; justify-content: center; }
    .product-body { padding: 12px; }
}

@media (max-width: 360px) {
    .navbar { min-height: 116px; gap: 6px; }
    .nav-left { gap: 3px; }
    .back-btn { width: 30px; flex-basis: 30px; }
    .nav-brand { gap: 3px; }
    .nav-logo { width: 28px; height: 28px; flex-basis: 28px; }
    .nav-title { font-size: .62rem; }
    .nav-left .private-access { top: 62px; padding-inline: 10px; font-size: .66rem; }
}

@media (min-width: 561px) and (max-width: 1100px) {
    .navbar {
        height: auto;
        min-height: 116px;
        align-content: flex-start;
        gap: 8px;
        padding-block: 12px 58px;
    }

    .nav-left {
        min-width: 0;
        flex: 1 1 auto;
        gap: 10px;
    }

    .nav-left .private-access {
        position: absolute;
        top: 62px;
        left: 50%;
        height: 36px;
        transform: translateX(-50%);
        padding-inline: 12px;
        border-radius: 10px;
        font-size: .7rem;
    }

    .navbar > .theme-toggle.theme-toggle {
        width: auto;
        min-width: 54px;
        height: 38px;
        flex: 0 0 auto;
        margin-left: auto;
    }
}

@media (max-width: 760px) {
    .filter-sidebar > div.filter-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 4px;
    }

    .filter-sidebar > div.filter-list .filter-item:first-child {
        grid-column: 1 / -1;
    }
}

@media (min-width: 761px) and (max-width: 900px) {
    .filter-sidebar > div.filter-list {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 4px;
    }

    .filter-sidebar > div.filter-list .filter-item:first-child {
        grid-column: 1 / -1;
    }
}

@media (min-width: 1101px) and (max-width: 1199px) {
    .product-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}

@media (max-width: 360px) {
    .product-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
}
</style>
<link rel="stylesheet" href="theme-toggle.css?v=4">
<link rel="stylesheet" href="assets/css/page-controls.css?v=4">
<script src="theme-sync.js?v=4" defer></script>
</head>
<body class="catalog-page">

<!-- ========== NAVBAR ========== -->
<nav class="navbar">
    <div class="nav-left">
        <a href="<?= $catalogSessionActive ? 'catalog_supervisor_login.php?exit=1' : 'index.php' ?>" class="back-btn" <?= $catalogSessionActive ? 'data-private-exit' : '' ?>>
            <svg viewBox="0 0 20 20" fill="none">
                <path d="M12 4l-6 6 6 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?= $catalogSessionActive ? 'Exit Private' : 'Back to Home' ?>
        </a>
        <div class="nav-divider"></div>
        <div class="nav-brand">
            <div class="nav-logo">
                <svg viewBox="0 0 48 48" aria-hidden="true">
                    <path d="M10 18h28l-2.5 18.5a3 3 0 0 1-3 2.5h-17a3 3 0 0 1-3-2.5L10 18z" fill="#ffffff"/>
                    <path d="M10 18h28v4a2 2 0 0 1-2 2H12a2 2 0 0 1-2-2v-4z" fill="#dbeafe"/>
                    <path d="M16 18c0-5 3.5-9 8-9s8 4 8 9" fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <span class="nav-title">Manage <span>Grocery</span></span>
        </div>
        <?php if ($catalogSessionActive): ?>
            <a class="private-access" href="scanner.php?return=catalog" aria-label="Open QR or barcode scanner">Scan QR / Barcode</a>
        <?php else: ?>
            <a class="private-access" href="catalog_supervisor_login.php?embedded=1" data-private-login aria-label="Open private catalog access">Private access</a>
        <?php endif; ?>
    </div>


    <button class="theme-toggle" id="themeToggle" type="button" aria-label="Switch to dark mode" aria-pressed="false">
        <span>Dark mode</span>
    </button>
</nav>

<div class="access-modal" id="accessModal" role="dialog" aria-modal="true" aria-label="Private catalog access">
    <div class="access-dialog">
        <button class="access-close" type="button" data-close-access aria-label="Close employee access">&times;</button>
        <iframe id="accessFrame" title="Supervisor password access" loading="lazy"></iframe>
    </div>
</div>

<!-- ========== HERO ========== -->
<header class="hero">
    <div class="hero-content">
        <div class="hero-tag">Fresh &amp; Packaged Catalog</div>
        <h1>Shop at <span class="accent">Grocery Store</span></h1>
        <p>Browse our full inventory before visiting the store. Out-of-stock items are clearly flagged in real-time so you plan every trip effortlessly.</p>
    </div>
</header>

<!-- ========== STORE INFO ========== -->
<section class="store-info-bar">
    <div class="store-info-item">
        📍 <span><strong>Store:</strong> Philippine Christian University (PCU), Dasmariñas, Cavite</span>
    </div>
    <div class="store-info-item">
        📞 <span><strong>Hotline:</strong> 09948966218</span>
    </div>
    <div class="store-info-item">
        🕒 <span><strong>Store Hours:</strong> 6:00 AM – 5:00 PM Mon to Sun</span>
    </div>
</section>

<!-- ========== PAGE BODY ========== -->
<div class="page-body">

    <aside class="filter-sidebar">
        <div class="filter-sidebar-head">
            <h3>Categories</h3>
            <span class="total-badge">Total: <?= (int)$total_products ?></span>
        </div>
        <div class="filter-list">
            <a href="catalog.php<?= !empty($search) ? '?search='.urlencode($search) : '' ?>"
               class="filter-item <?= empty($category) ? 'active' : '' ?>">
                All Products
                <span class="count"><?= (int)$total_products ?></span>
            </a>
            <?php foreach ($categories as $cat):
                $cnt = $cat_counts[$cat['category_name']] ?? 0;
                if ($cnt == 0) continue;
            ?>
                <a href="catalog.php?category=<?= urlencode($cat['category_name']) ?><?= !empty($search) ? '&search='.urlencode($search) : '' ?>"
                   class="filter-item <?= $category == $cat['category_name'] ? 'active' : '' ?>">
                    <?= clean($cat['category_name']) ?>
                    <span class="count"><?= (int)$cnt ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="filter-sidebar-head" style="margin-top:20px">
            <h3>Allergen safety</h3>
        </div>
        <form method="get" class="filter-list">
            <?php if ($search !== ''): ?><input type="hidden" name="search" value="<?= clean($search) ?>"><?php endif; ?>
            <?php if ($category !== ''): ?><input type="hidden" name="category" value="<?= clean($category) ?>"><?php endif; ?>
            <label style="font-size:.75rem;color:var(--text-muted)" for="exclude_allergen">Hide products containing</label>
            <select id="exclude_allergen" name="exclude_allergen" onchange="this.form.submit()" style="padding:9px;border:1px solid var(--border);border-radius:10px;background:var(--surface);color:var(--text-body)">
                <option value="">No exclusion</option>
                <?php foreach ($allowedAllergens as $allergen): ?>
                    <option value="<?= clean($allergen) ?>" <?= strtolower($excludeAllergen) === $allergen ? 'selected' : '' ?>><?= clean(ucwords($allergen)) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($excludeAllergen !== ''): ?><div class="notice" role="note">Filtered on verified label information only. Products without verified allergen data are hidden from these results. Always check the package.</div><?php endif; ?>
        </form>
    </aside>

    <div class="catalog-area">
        <div class="catalog-header">
            <div>
                <h2>
                    <?php if (!empty($category)): ?>
                        <?= clean($category) ?>
                    <?php elseif (!empty($search)): ?>
                        Results for "<?= clean($search) ?>"
                    <?php else: ?>
                        All Products
                    <?php endif; ?>
                </h2>
                <p><?= count($products) ?> product<?= count($products) != 1 ? 's' : '' ?> found</p>
            </div>
            <form method="GET" action="catalog.php" id="catalogSearchForm" class="catalog-search-form">
                <?php if (!empty($category)): ?><input type="hidden" name="category" value="<?= clean($category) ?>"><?php endif; ?>
                <input type="text" name="search" id="catalogSearchInput" class="search-input" placeholder="Search products..." value="<?= clean($search) ?>">
                <button type="submit" class="btn-search">Search</button>
            </form>
            <?php if (!empty($search) || !empty($category) || !empty($excludeAllergen)): ?>
                <a href="catalog.php" class="clear-link">Clear filters</a>
            <?php endif; ?>
        </div>

        <div class="product-grid">
            <?php if (empty($products)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                        <circle cx="11" cy="11" r="8"/>
                        <path d="m21 21-4.35-4.35"/>
                    </svg>
                    <h3>No products found</h3>
                    <p>Try a different search term or category.</p>
                    <a href="catalog.php">View all products</a>
                </div>
            <?php else: ?>
                <?php foreach ($products as $p):
                    $img_path   = 'uploads/products/' . basename((string) $p['image']);
                    $img_url    = public_image_url($p['image']);
                    $has_image  = $p['image'] && $p['image'] != 'default.png' && file_exists(__DIR__ . '/' . $img_path);
                    $in_stock   = $p['stock'] > 0;
                ?>
                    <a class="product-card <?= $in_stock ? '' : 'out-of-stock' ?>"
                       href="product_detail.php?id=<?= clean(enc_id((int) $p['id'])) ?>"
                       aria-label="View <?= clean($p['product_name']) ?>">
                        <div class="product-img">
                            <?php if ($has_image): ?>
                                <img src="<?= clean($img_url) ?>" alt="<?= clean($p['product_name']) ?>">
                            <?php else: ?>
                                <div class="product-img-placeholder">🛒</div>
                            <?php endif; ?>
                            <?php if (!$in_stock): ?>
                                <div class="out-of-stock-overlay"><span>Out of Stock</span></div>
                            <?php endif; ?>
                        </div>
                        <div class="product-body">
                            <div class="product-name"><?= clean($p['product_name']) ?></div>
                            <div class="product-price"><?= peso($p['price']) ?></div>
                            <?php if ($in_stock): ?>
                                <span class="product-available"><?= (int)$p['stock'] ?> left</span>
                            <?php else: ?>
                                <span class="product-outofstock-badge">Out of stock</span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ========== FOOTER ========== -->
<footer class="footer">
    <strong>HCI GroceryOne</strong> &mdash; Your Everyday Needs &mdash;
    &copy; <?= date('Y') ?> All rights reserved.
</footer>

<script>
(function () {
    var input = document.getElementById('catalogSearchInput');
    var form  = document.getElementById('catalogSearchForm');
    if (!input || !form) return;
    var timer = null;
    input.addEventListener('input', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () { form.submit(); }, 450);
    });
}());
</script>
</body>
<script>
(function () {
    var modal = document.getElementById('accessModal');
    var frame = document.getElementById('accessFrame');
    var openLink = document.querySelector('[data-private-login], [data-private-exit]');
    if (!modal || !frame || !openLink) return;
    function close() { modal.classList.remove('is-open'); frame.src = 'about:blank'; }
    openLink.addEventListener('click', function (event) {
        event.preventDefault();
        frame.src = openLink.href;
        modal.classList.add('is-open');
    });
    document.querySelector('[data-close-access]').addEventListener('click', close);
    modal.addEventListener('click', function (event) { if (event.target === modal) close(); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape') close(); });
    frame.addEventListener('load', function () {
        try {
            var url = frame.contentWindow.location.href;
            var privateCatalog = url.indexOf('catalog.php?private=1') !== -1 || url.indexOf('/catalog?private=1') !== -1;
            var publicCatalog = url.indexOf('catalog.php') !== -1 || /\/catalog(?:\?|$)/.test(url);
            if (privateCatalog || (openLink.hasAttribute('data-private-exit') && publicCatalog)) {
                window.location.href = openLink.hasAttribute('data-private-exit') && publicCatalog
                    ? 'catalog.php'
                    : 'catalog.php?private=1';
            }
        } catch (error) {}
    });
}());
</script>
</html>