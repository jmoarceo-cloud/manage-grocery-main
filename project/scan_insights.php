<?php
require_once __DIR__ . '/includes/config.php';
// Scan Insights is an inventory review tool, so it is available to Admin
// and Inventory Staff, matching the sidebar navigation.
requireInventoryStaff();
$from = trim((string) ($_GET['from'] ?? date('Y-m-d', strtotime('-6 days'))));
$to = trim((string) ($_GET['to'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-d', strtotime('-6 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-m-d');
$start = $from . ' 00:00:00';
$end = date('Y-m-d H:i:s', strtotime($to . ' +1 day'));
$summaryStmt = $pdo->prepare("SELECT COUNT(*) total, COUNT(DISTINCT product_id) products, SUM(source='kiosk') kiosk, SUM(source='phone') phone, SUM(source='catalog_click') catalog_click FROM product_scan_events WHERE scanned_at >= ? AND scanned_at < ?");
$summaryStmt->execute([$start, $end]);
$summary = $summaryStmt->fetch() ?: [];
$unverifiedStmt = $pdo->prepare("SELECT p.product_name, COUNT(e.id) scans, COALESCE(d.review_status,'draft') review_status FROM product_scan_events e JOIN products p ON p.id=e.product_id LEFT JOIN product_details d ON d.product_id=p.id WHERE e.scanned_at >= ? AND e.scanned_at < ? AND COALESCE(d.review_status,'draft') <> 'verified' GROUP BY p.id,p.product_name,d.review_status ORDER BY scans DESC LIMIT 20");
$unverifiedStmt->execute([$start, $end]);
$unverified = $unverifiedStmt->fetchAll();
$dailyStmt = $pdo->prepare("SELECT DATE(scanned_at) day, COUNT(*) scans FROM product_scan_events WHERE scanned_at >= ? AND scanned_at < ? GROUP BY DATE(scanned_at) ORDER BY day");
$dailyStmt->execute([$start, $end]);
$daily = $dailyStmt->fetchAll();
?><!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Scan Insights</title>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <style>
        .insights-intro { display:flex; align-items:flex-end; justify-content:space-between; gap:24px; flex-wrap:wrap; }
        .insights-kicker { color:var(--green-mid); font-size:12px; font-weight:800; letter-spacing:.12em; text-transform:uppercase; margin:0 0 6px; }
        .insights-period { color:var(--ink-soft); font-size:13px; white-space:nowrap; }
        .insights-filter { display:flex; align-items:flex-end; gap:14px; flex-wrap:wrap; }
        .insights-filter .field-group { min-width:170px; }
        .insights-filter .action-btn { margin-bottom:0; }
        .insights-source { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; }
        .insights-source-item { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px 14px; border:1px solid var(--line); border-radius:var(--radius-md); background:var(--cream); }
        .insights-source-label { color:var(--ink-soft); font-size:12px; font-weight:700; }
        .insights-source-value { color:var(--text-accent); font-size:18px; font-weight:800; }
        .insights-empty { color:var(--ink-soft); padding:22px; text-align:center; }
        @media (max-width:720px) { .insights-source { grid-template-columns:1fr; } .insights-filter .field-group { flex:1 1 100%; } }
    </style>
</head>
<body>
<div class="app-shell">
    <?php $active = 'scan_insights'; include 'includes/sidebar.php'; ?>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_reports ?></div>
            <h1>Scan Insights</h1>
            <div class="topbar-user">
                <span class="user-avatar"><?php if (!empty($__avatarFile)): ?><img src="uploads/avatars/<?= clean($__avatarFile) ?>" alt=""><?php else: ?><?= clean($__initial) ?><?php endif; ?></span>
                <span class="user-name"><?= clean($__full_name !== '' ? $__full_name : 'Administrator') ?></span>
            </div>
        </header>
        <main class="content">
            <div class="insights-intro">
                <div>
                    <p class="insights-kicker">Customer activity</p>
                    <div class="page-header"><h1>Scan Insights</h1>                    <p class="page-subtitle">Review customer product lookups and prioritize items that need better label information.</p></div>
                </div>
                <p class="insights-period"><?= clean($from) ?> <span aria-hidden="true">→</span> <?= clean($to) ?></p>
            </div>

            <section class="filter-bar" aria-label="Scan date range">
                <form method="get" class="insights-filter">
                    <div class="field-group"><label class="field-label" for="scan-from">From</label><input id="scan-from" class="field-input" type="date" name="from" value="<?= clean($from) ?>"></div>
                    <div class="field-group"><label class="field-label" for="scan-to">To</label><input id="scan-to" class="field-input" type="date" name="to" value="<?= clean($to) ?>"></div>
                    <button class="action-btn" type="submit">Apply range</button>
                </form>
            </section>

            <section class="stat-grid" aria-label="Scan summary">
                <article class="stat-card"><span class="stat-label">Total scans</span><strong class="stat-value"><?= (int)($summary['total'] ?? 0) ?></strong><span class="field-hint">All recorded lookups</span></article>
                <article class="stat-card"><span class="stat-label">Unique products</span><strong class="stat-value"><?= (int)($summary['products'] ?? 0) ?></strong><span class="field-hint">Products customers explored</span></article>
                <article class="stat-card"><span class="stat-label">Needs review</span><strong class="stat-value"><?= count($unverified) ?></strong><span class="field-hint">Frequently scanned items</span></article>
                <article class="stat-card"><span class="stat-label">Busiest day</span><strong class="stat-value"><?= $daily ? (int)max(array_column($daily, 'scans')) : 0 ?></strong><span class="field-hint">Peak scans in one day</span></article>
            </section>

            <section class="panel">
                <div class="panel-header"><div><h2>Where scans come from</h2><span class="field-hint">Compare activity across customer entry points.</span></div></div>
                <div class="panel-body"><div class="insights-source"><div class="insights-source-item"><span class="insights-source-label">Catalog</span><strong class="insights-source-value"><?= (int)($summary['catalog_click'] ?? 0) ?></strong></div><div class="insights-source-item"><span class="insights-source-label">Kiosk</span><strong class="insights-source-value"><?= (int)($summary['kiosk'] ?? 0) ?></strong></div><div class="insights-source-item"><span class="insights-source-label">Phone</span><strong class="insights-source-value"><?= (int)($summary['phone'] ?? 0) ?></strong></div></div></div>
            </section>

            <section class="panel">
                <div class="panel-header"><div><h2>Review queue</h2><span class="field-hint">Most scanned products without verified label information.</span></div><span class="badge badge--warning"><?= count($unverified) ?> items</span></div>
                <div class="panel-body panel-body--table"><table class="data-table"><thead><tr><th>Product</th><th>Scans</th><th>Status</th></tr></thead><tbody><?php foreach ($unverified as $row): ?><tr><td><strong><?= clean($row['product_name']) ?></strong></td><td><?= (int)$row['scans'] ?></td><td><span class="badge <?= $row['review_status'] === 'needs_review' ? 'badge--warning' : 'badge--neutral' ?>"><?= clean($row['review_status']) ?></span></td></tr><?php endforeach; ?><?php if (!$unverified): ?><tr><td class="insights-empty" colspan="3"><?= ($summary['total'] ?? 0) > 0 ? 'All scanned products are verified for this range.' : 'No scan activity was recorded for this date range. Scans are created when customers use the catalog, kiosk, or phone barcode scanner.' ?></td></tr><?php endif; ?></tbody></table></div>
            </section>

            <section class="panel">
                <div class="panel-header"><div><h2>Scans over time</h2><span class="field-hint">Daily lookup volume for the selected range.</span></div></div>
                <div class="panel-body panel-body--table"><table class="data-table"><thead><tr><th>Date</th><th>Scans</th></tr></thead><tbody><?php foreach ($daily as $row): ?><tr><td><?= clean($row['day']) ?></td><td><strong><?= (int)$row['scans'] ?></strong></td></tr><?php endforeach; ?><?php if (!$daily): ?><tr><td class="insights-empty" colspan="2">No scan activity recorded for this range.</td></tr><?php endif; ?></tbody></table></div>
            </section>
        </main>
    </div>
</div>
</body>
</html>