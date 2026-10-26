<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();

$days = isset($_GET['days']) && ctype_digit((string) $_GET['days']) ? (int) $_GET['days'] : 30;
$days = max(7, min(90, $days));
$limit = isset($_GET['limit']) && ctype_digit((string) $_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit = max(10, min(200, $limit));

$stmt = $pdo->prepare("
    SELECT p.id, p.product_name, p.category, p.stock, p.cost_price,
           COALESCE(SUM(CASE WHEN s.status IN ('completed', 'partial_refunded')
                 AND s.transaction_date >= DATE_SUB(NOW(), INTERVAL :days DAY)
                 THEN sd.quantity ELSE 0 END), 0) AS units_sold,
           COALESCE((
               SELECT SUM(pb.quantity_remaining)
               FROM product_batches pb
               WHERE pb.product_id = p.id
                 AND pb.quantity_remaining > 0
                 AND (pb.expiry_date IS NULL OR pb.expiry_date >= CURDATE())
           ), 0) AS sellable_stock
    FROM products p
    LEFT JOIN sales_details sd ON sd.product_id = p.id
    LEFT JOIN sales s ON s.id = sd.sale_id
    WHERE p.is_active = 1
    GROUP BY p.id, p.product_name, p.category, p.stock, p.cost_price
    ORDER BY units_sold DESC, p.stock ASC
    LIMIT {$limit}
");
$stmt->bindValue(':days', $days, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$recommendations = [];
foreach ($rows as $row) {
    $dailyRate = (float) $row['units_sold'] / $days;
    $targetStock = (int) ceil($dailyRate * 14);
    $sellableStock = (int) $row['sellable_stock'];
    $recommendedQty = max(0, $targetStock - $sellableStock);
    if ($recommendedQty > 0 || $sellableStock === 0) {
        $row['daily_rate'] = $dailyRate;
        $row['target_stock'] = $targetStock;
        $row['recommended_qty'] = $recommendedQty;
        $recommendations[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock Recommendations</title>
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
</head>
<body>
<div class="app-shell">
    <?php $active = 'inventory'; include 'includes/sidebar.php'; ?>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_inventory ?></div>
            <h1>Stock Recommendations</h1>
            <div class="topbar-user"><span class="user-name"><?= clean($_SESSION['full_name'] ?? '') ?></span></div>
        </header>
        <main class="content">
            <div class="panel">
                <div class="panel-header">
                    <h2>Read-only planning report</h2>
                    <span>Based on the last <?= $days ?> days; target is 14 days of sellable stock.</span>
                </div>
                <div class="panel-body">
                    <p>This report is advisory only. It does not change stock, submit requests, or create purchase orders.</p>
                    <table class="data-table">
                        <thead><tr><th>Product</th><th>Category</th><th>Sold</th><th>Daily rate</th><th>Sellable stock</th><th>Target</th><th>Suggested order</th></tr></thead>
                        <tbody>
                        <?php if (!$recommendations): ?>
                            <tr><td colspan="7" class="empty-row">No replenishment recommendations.</td></tr>
                        <?php else: foreach ($recommendations as $recommendation): ?>
                            <tr>
                                <td><?= clean($recommendation['product_name']) ?></td>
                                <td><?= clean($recommendation['category']) ?></td>
                                <td><?= (int) $recommendation['units_sold'] ?></td>
                                <td><?= number_format($recommendation['daily_rate'], 2) ?></td>
                                <td><?= $recommendation['sellable_stock'] ?></td>
                                <td><?= $recommendation['target_stock'] ?></td>
                                <td><strong><?= $recommendation['recommended_qty'] ?></strong></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
</html>
