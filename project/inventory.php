<?php

require_once 'includes/config.php';
requireInventoryStaff();

// Admin can still restock directly (e.g. corrections, emergencies).
// Inventory Staff can't — restocking spends business cash, so it now
// has to go through Finance approval first (see inventory_requests.php
// / finance.php) instead of happening automatically here.
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

$success_message = $_SESSION['flash_success'] ?? '';
$error_message = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action = $_POST['action'];

    if ($action == 'restock') {
        $product_id = $_POST['product_id'] ?? '';
        $quantity = trim($_POST['quantity'] ?? '');
        $cost_price = trim($_POST['cost_price'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        $expiry_date = trim($_POST['expiry_date'] ?? '');

        if (!$isAdmin) {
            $error_message = "Restocking isn't done directly here anymore — it spends business cash, so it needs Finance's approval first. Please submit a request from Inventory Requests instead.";
        } elseif (!ctype_digit((string) $quantity) || $quantity <= 0) {
            $error_message = "Quantity must be a whole number greater than 0.";
        } elseif ($cost_price !== '' && (!is_numeric($cost_price) || $cost_price < 0)) {
            $error_message = "Cost per unit must be a valid positive number.";
        } elseif (!empty($expiry_date) && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
            $error_message = "Invalid expiry date format.";
        } elseif (!empty($expiry_date) && strtotime($expiry_date) < strtotime('today')) {
            $error_message = "Expiry date cannot be in the past.";
        } else {
            $quantity = (int) $quantity;
            $stmt = $pdo->prepare("SELECT product_name, stock, cost_price FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();

            if (!$product) {
                $error_message = "Product not found.";
            } else {
                $unit_cost = ($cost_price !== '') ? (float) $cost_price : (float) $product['cost_price'];

                $previous_stock = $product['stock'];
                $new_stock = $previous_stock + $quantity;
                $total_cost = $unit_cost * $quantity;

                try {
                    $pdo->beginTransaction();

                    $pdo->prepare("UPDATE products SET stock = stock + :qty, cost_price = :cp WHERE id = :id")
                        ->execute([':qty' => $quantity, ':cp' => $unit_cost, ':id' => $product_id]);

                    // Bagong batch — hiwalay ang expiry nito sa mga naunang
                    // batch, kaya kahit magkaiba ang expiry ng bagong
                    // delivery sa lumang stock, hindi na sila magkakahalo.
                    addProductBatch($pdo, $product_id, $quantity, $unit_cost, $expiry_date ?: null, currentPosUserId(), !empty($reason) ? $reason : 'Restock');

                    $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_change, previous_stock, new_stock, reason, total_cost, user_id) VALUES (:pid, 'restock', :qc, :ps, :ns, :r, :tc, :uid)")
                        ->execute([
                            ':pid' => $product_id,
                            ':qc' => $quantity,
                            ':ps' => $previous_stock,
                            ':ns' => $new_stock,
                            ':r' => !empty($reason) ? $reason : 'Restock',
                            ':tc' => $total_cost,
                            ':uid' => currentPosUserId(),
                        ]);

                    $notif_message = "Restocked: " . $product['product_name'] . " — +" . $quantity . " pcs"
                        . " @ " . peso($unit_cost) . " = " . peso($total_cost) . " total cost."
                        . " New stock: " . $new_stock . " pcs."
                        . (!empty($expiry_date) ? (" Batch expiry: " . date('M d, Y', strtotime($expiry_date)) . ".") : "");
                    $pdo->prepare("INSERT INTO notifications (product_id, message) VALUES (:pid, :msg)")
                        ->execute([':pid' => $product_id, ':msg' => $notif_message]);

                    $pdo->commit();
                    $success_message = "Restocked: " . clean($product['product_name']) . " (+" . $quantity . " pcs, " . peso($total_cost) . " total cost)";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    error_log("Restock error: " . $e->getMessage());
                    $error_message = "Could not restock. Please try again.";
                }
            }
        }
    } elseif ($action == 'adjust') {
        $product_id = $_POST['product_id'];
        $new_stock = trim($_POST['new_stock']);
        $reason_choice = trim($_POST['reason_choice'] ?? '');
        $reason_other = trim($_POST['reason_other'] ?? '');

        $valid_reasons = [
            'Damaged goods',
            'Expired products',
            'Stock count correction',
            'Returned to supplier',
            'Lost / missing items',
            'Theft / shrinkage',
            'Free samples given',
            'Internal use / consumed',
            'Wrong quantity received',
            'Other',
        ];

        if ($reason_choice === 'Other') {
            $reason = !empty($reason_other) ? 'Other: ' . $reason_other : '';
        } else {
            $reason = $reason_choice;
        }

        if (!ctype_digit((string) $new_stock)) {
            $error_message = "Stock must be a whole number and cannot be negative.";
        } elseif (!in_array($reason_choice, $valid_reasons)) {
            $error_message = "Please select a valid reason for the adjustment.";
        } elseif ($reason_choice === 'Other' && empty($reason_other)) {
            $error_message = "Please describe the reason when selecting 'Other'.";
        } else {
            $new_stock = (int) $new_stock;
            $stmt = $pdo->prepare("SELECT product_name, stock, cost_price FROM products WHERE id = ?");
            $stmt->execute([$product_id]);
            $product = $stmt->fetch();

            if (!$product) {
                $error_message = "Product not found.";
            } else {
                $previous_stock = $product['stock'];
                $quantity_change = $new_stock - $previous_stock;
                try {
                    $pdo->beginTransaction();
                    $pdo->prepare("UPDATE products SET stock = :ns WHERE id = :id")
                        ->execute([':ns' => $new_stock, ':id' => $product_id]);

                    // Panatilihing tugma ang batches sa bagong stock total:
                    // bawas -> kunin sa pinaka-malapit mag-expire na batch
                    // (FEFO); dagdag -> bagong batch na walang tiyak na
                    // expiry (stock count correction, hindi bagong delivery).
                    if ($quantity_change < 0) {
                        deductStockFEFO($pdo, $product_id, abs($quantity_change));
                    } elseif ($quantity_change > 0) {
                        addProductBatch($pdo, $product_id, $quantity_change, (float) $product['cost_price'], null, currentPosUserId(), 'Adjustment: ' . $reason);
                    }

                    $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_change, previous_stock, new_stock, reason, user_id) VALUES (:pid, 'adjust', :qc, :ps, :ns, :r, :uid)")
                        ->execute([':pid' => $product_id, ':qc' => $quantity_change, ':ps' => $previous_stock, ':ns' => $new_stock, ':r' => $reason, ':uid' => currentPosUserId()]);
                    $pdo->commit();
                    $success_message = "Adjusted stock of " . clean($product['product_name']) . " to " . $new_stock . " pcs.";
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error_message = "Could not adjust stock. Please try again.";
                }
            }
        }
    } elseif ($action == 'issue') {
        $product_id = $_POST['product_id'];
        $quantity = trim($_POST['issue_quantity'] ?? '');
        $purpose = trim($_POST['issue_purpose'] ?? '');
        $issued_to = trim($_POST['issued_to'] ?? '');
        $notes = trim($_POST['issue_notes'] ?? '');

        $valid_purposes = [
            'Internal Use / Store Consumption',
            'Staff Use',
            'Free Sample / Promo',
            'Store Display',
            'Donation',
            'Other',
        ];

        if ($purpose === 'Other') {
            $reason = !empty($notes) ? 'Other: ' . $notes : '';
        } else {
            $reason = $purpose . (!empty($notes) ? ' — ' . $notes : '');
        }

        $stmt = $pdo->prepare("SELECT product_name, stock, cost_price FROM products WHERE id = ?");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if (!ctype_digit((string) $quantity) || $quantity <= 0) {
            $error_message = "Quantity must be a whole number greater than 0.";
        } elseif (!in_array($purpose, $valid_purposes, true)) {
            $error_message = "Please select a valid purpose for the issuance.";
        } elseif ($purpose === 'Other' && empty($notes)) {
            $error_message = "Please describe the purpose when selecting 'Other'.";
        } elseif (!$product) {
            $error_message = "Product not found.";
        } elseif ((int) $quantity > (int) $product['stock']) {
            $error_message = "Cannot issue more than the current stock (" . $product['stock'] . " pcs).";
        } else {
            $quantity = (int) $quantity;
            $previous_stock = (int) $product['stock'];
            $new_stock = $previous_stock - $quantity;

            try {
                $pdo->beginTransaction();

                // Same FEFO rule as a sale — the batch closest to expiry
                // is issued out first.
                deductStockFEFO($pdo, $product_id, $quantity);

                $pdo->prepare("UPDATE products SET stock = :ns WHERE id = :id")
                    ->execute([':ns' => $new_stock, ':id' => $product_id]);

                $full_reason = $reason . (!empty($issued_to) ? ' (Issued to: ' . $issued_to . ')' : '');

                $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_change, previous_stock, new_stock, reason, user_id) VALUES (:pid, 'issuance', :qc, :ps, :ns, :r, :uid)")
                    ->execute([
                        ':pid' => $product_id,
                        ':qc' => -$quantity,
                        ':ps' => $previous_stock,
                        ':ns' => $new_stock,
                        ':r' => $full_reason,
                        ':uid' => currentPosUserId(),
                    ]);

                $notif_message = "Issued: " . $product['product_name'] . " — " . $quantity . " pcs for " . $purpose
                    . (!empty($issued_to) ? " (to: " . $issued_to . ")" : "") . ". New stock: " . $new_stock . " pcs.";
                $pdo->prepare("INSERT INTO notifications (product_id, message) VALUES (:pid, :msg)")
                    ->execute([':pid' => $product_id, ':msg' => $notif_message]);

                $pdo->commit();
                $success_message = "Issued " . $quantity . " pcs of " . clean($product['product_name']) . " (" . clean($purpose) . ").";
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Issue items error: " . $e->getMessage());
                $error_message = "Could not process the issuance. Please try again.";
            }
        }
    }
    checkLowStock($pdo);

    // Post/Redirect/Get — without this, hitting reload/F5 after a
    // restock or adjustment re-sends the same POST and repeats it,
    // which was duplicating the stock change on reload.
    $_SESSION['flash_success'] = $success_message;
    $_SESSION['flash_error'] = $error_message;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: inventory.php' . ($qs !== '' ? ('?' . $qs) : ''));
    exit;
}

// Filters
$status_filter = $_GET['status'] ?? 'all';
$search = trim($_GET['search'] ?? '');

$where_parts = [];
$params = [];

if (!empty($search)) {
    $where_parts[] = "product_name LIKE :search";
    $params[':search'] = '%' . $search . '%';
}
if ($status_filter == 'low') {
    $where_parts[] = "stock <= :low AND stock > 0";
    $params[':low'] = LOW_STOCK;
} elseif ($status_filter == 'out') {
    $where_parts[] = "stock = 0";
} elseif ($status_filter == 'in') {
    $where_parts[] = "stock > :low";
    $params[':low'] = LOW_STOCK;
}

$sql = "SELECT * FROM products" . (!empty($where_parts) ? " WHERE " . implode(' AND ', $where_parts) : "") . " ORDER BY category ASC, product_name ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$grouped_products = [];
foreach ($products as $p) {
    $grouped_products[$p['category']][] = $p;
}
ksort($grouped_products);

// Counts
$total_count = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
$low_count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE stock <= :l AND stock > 0");
$low_count->execute([':l' => LOW_STOCK]);
$low_count = $low_count->fetchColumn();
$out_count = $pdo->query("SELECT COUNT(*) FROM products WHERE stock = 0")->fetchColumn();
$in_count = $total_count - $low_count - $out_count;


// Completed lang — hindi kasama ang na-void/na-refund na benta, dahil
// ibinalik na ang pera sa mga iyon (tingnan din ang mas detalyadong
// Profit & Business Capital sa reports.php, na batay sa aktwal na
// cost ng mga naibentang item — ito dito ay mas simpleng cash-flow
// view: Total Sales minus Total Restock Cost).
$total_sales = (float) $pdo->query("
    SELECT COALESCE(SUM(s.total_amount - COALESCE(sr.refunded_amount, 0)), 0)
    FROM sales s
    LEFT JOIN (SELECT sale_id, SUM(amount) AS refunded_amount FROM sale_refunds GROUP BY sale_id) sr ON sr.sale_id = s.id
    WHERE s.status IN ('completed', 'partial_refunded')
")->fetchColumn();

// See getTotalRestockCost() in includes/config.php — counts requests
// Finance has approved immediately, not just ones already received.
$total_restock_cost = getTotalRestockCost($pdo);

$net_profit = $total_sales - $total_restock_cost;

// Stock history — per day (dati "Latest 20" lang, pero pag maraming
// laman ang isang araw super haba na siya sa isang tingin, kaya
// isang araw muna ang ipapakita, may Prev/Next Day para makabalik-
// balik sa ibang araw).
$__stockLogDates = $pdo->query("
    SELECT DISTINCT DATE(date_created) AS d
    FROM stock_logs
    ORDER BY d DESC
")->fetchAll(PDO::FETCH_COLUMN);

$stock_date = $_GET['stock_date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $stock_date) || !in_array($stock_date, $__stockLogDates, true)) {
    // Kung walang valid na date sa URL (o wala nang laman sa araw na
    // yun), sa pinakabagong araw na may laman lumapag by default.
    $stock_date = $__stockLogDates[0] ?? date('Y-m-d');
}

$__dateIndex = array_search($stock_date, $__stockLogDates, true);
$__prevStockDate = ($__dateIndex !== false && isset($__stockLogDates[$__dateIndex + 1])) ? $__stockLogDates[$__dateIndex + 1] : null; // mas lumang araw
$__nextStockDate = ($__dateIndex !== false && $__dateIndex > 0) ? $__stockLogDates[$__dateIndex - 1] : null; // mas bagong araw

// Para hindi mawala yung status/search filters (kung meron) pag
// nag-Prev/Next Day sa Stock History.
$__stockNavParams = array_filter([
    'status' => $status_filter !== 'all' ? $status_filter : null,
    'search' => $search !== '' ? $search : null,
]);
function stockHistoryNavUrl($date, $extraParams)
{
    return '?' . http_build_query(array_merge($extraParams, ['stock_date' => $date])) . '#stock-history';
}

$stmt = $pdo->prepare("
    SELECT sl.*, p.product_name, u.full_name AS done_by
    FROM stock_logs sl
    JOIN products p ON p.id = sl.product_id
    JOIN users u    ON u.id = sl.user_id
    WHERE DATE(sl.date_created) = ?
    ORDER BY sl.date_created DESC
");
$stmt->execute([$stock_date]);
$stock_history = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory — HCI GroceryOne</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-tab {
            padding: 8px 18px;
            border-radius: 20px;
            font-size: 13.5px;
            font-weight: 600;
            border: 1.5px solid var(--line);
            background: var(--white);
            color: var(--ink-soft);
            text-decoration: none;
            transition: all .15s;
        }

        .filter-tab:hover {
            border-color: var(--green-mid);
            color: var(--green-mid);
        }

        .filter-tab.active {
            background: var(--green-deep);
            color: #FFFFFF;
            border-color: var(--green-deep);
        }

        .filter-tab .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 255, 255, 0.25);
            border-radius: 10px;
            font-size: 11px;
            font-weight: 700;
            padding: 1px 7px;
            margin-left: 5px;
        }

        .filter-tab:not(.active) .badge {
            background: var(--green-pale);
            color: var(--text-accent);
        }

        .stock-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .stock-badge.in {
            background: #e6f4ea;
            color: #1a7a3b;
        }

        .stock-badge.low {
            background: var(--mustard-pale);
            color: #8a5c10;
        }

        html[data-theme="dark"] .stock-badge.in {
            background: var(--green-pale);
            color: var(--green-leaf);
        }

        html[data-theme="dark"] .stock-badge.low {
            color: var(--mustard);
        }

        .stock-badge.out {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .change-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 700;
        }

        .change-badge.restock {
            background: #e6f4ea;
            color: #1a7a3b;
        }

        .change-badge.sale {
            background: #e8eeff;
            color: #2d4ecf;
        }

        .change-badge.adjust {
            background: var(--mustard-pale);
            color: #8a5c10;
        }

        .change-badge.void {
            background: #f3e8ff;
            color: #7b2fbe;
        }

        .change-badge.issuance {
            background: #fde8e8;
            color: #b8391f;
        }

        html[data-theme="dark"] .change-badge.restock {
            background: var(--green-pale);
            color: var(--green-leaf);
        }

        html[data-theme="dark"] .change-badge.sale {
            background: #182449;
            color: #8fa4ff;
        }

        html[data-theme="dark"] .change-badge.adjust {
            color: var(--mustard);
        }

        html[data-theme="dark"] .change-badge.void {
            background: #2a1a40;
            color: #c79bf0;
        }

        html[data-theme="dark"] .change-badge.issuance {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            z-index: 200;
            align-items: center;
            justify-content: center;
        }

        .modal-overlay.open {
            display: flex;
        }

        .modal-box {
            background: var(--white);
            border-radius: 18px;
            padding: 32px 30px 28px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.2);
        }

        .modal-box h3 {
            font-family: var(--font-display);
            font-size: 18px;
            font-weight: 700;
            color: var(--text-accent);
            margin: 0 0 6px;
        }

        .modal-box .modal-sub {
            font-size: 13px;
            color: var(--ink-soft);
            margin-bottom: 20px;
        }

        .modal-actions {
            display: flex;
            gap: 10px;
            margin-top: 22px;
        }

        .btn-cancel {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            border: 1.5px solid var(--line);
            background: var(--white);
            color: var(--ink-soft);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-cancel:hover {
            background: var(--cream);
        }

        .btn-confirm {
            flex: 1;
            padding: 11px;
            border-radius: 10px;
            border: none;
            background: var(--green-deep);
            color: #FFFFFF;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background .15s;
        }

        .btn-confirm:hover {
            background: var(--green-mid);
        }

        .btn-confirm.danger {
            background: var(--red-alert);
        }

        .btn-confirm.danger:hover {
            background: #a3391f;
        }

        .action-btn--small {
            padding: 9px 16px;
            font-size: 13px;
        }

        .action-btn--outline {
            background: var(--white);
            color: var(--text-accent);
            border: 1.5px solid var(--green-deep);
            box-shadow: none;
        }

        .action-btn--outline:hover {
            background: var(--green-pale);
            box-shadow: none;
        }

        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }

        .search-form {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
        }

        .alert--success {
            background: #e6f4ea;
            color: #1a7a3b;
        }

        html[data-theme="dark"] .alert--success {
            background: var(--green-pale);
            color: var(--green-leaf);
        }

        .alert--error {
            background: var(--red-pale);
            color: var(--red-alert);
        }

        .finance-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        @media (max-width: 800px) {
            .finance-grid {
                grid-template-columns: 1fr;
            }
        }

        .finance-card {
            background: var(--white);
            border: 1.5px solid var(--line);
            border-radius: 16px;
            padding: 18px 20px;
        }

        .finance-card .f-label {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--ink-soft);
            text-transform: uppercase;
            letter-spacing: .03em;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .finance-card .f-value {
            font-family: var(--font-display);
            font-size: 24px;
            font-weight: 800;
            margin-top: 6px;
        }

        .finance-card.sales .f-value {
            color: var(--green-mid);
        }

        .finance-card.cost .f-value {
            color: var(--red-alert);
        }

        .finance-card.profit .f-value.positive {
            color: var(--text-accent);
        }

        .finance-card.profit .f-value.negative {
            color: var(--red-alert);
        }

        .finance-card .f-note {
            font-size: 11.5px;
            color: var(--ink-soft);
            margin-top: 4px;
        }

        .cost-preview {
            font-size: 12.5px;
            color: var(--ink-soft);
            margin-top: 6px;
            padding: 8px 10px;
            background: var(--cream);
            border-radius: 8px;
        }

        .cost-preview strong {
            color: var(--text-accent);
        }

        /* Category grouping styles */
        .category-group {
            margin-bottom: 2rem;
        }

        .category-group-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .category-group-label {
            background: #e6f4ea;
            color: #1a7a3b;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            padding: 4px 14px;
            display: inline-block;
        }

        html[data-theme="dark"] .category-group-label {
            background: var(--green-pale);
            color: var(--green-leaf);
        }

        .category-group-count {
            font-size: 12px;
            color: var(--ink-soft);
        }

        .stock-history-nav {
            display: flex;
            gap: 8px;
            margin-bottom: 14px;
        }

        .sh-nav-btn {
            font-family: var(--font-body);
            font-size: 12.5px;
            font-weight: 700;
            color: var(--green-mid);
            background: var(--green-pale);
            border: 1px solid transparent;
            border-radius: 8px;
            padding: 7px 13px;
            text-decoration: none;
            white-space: nowrap;
            transition: background .15s ease, color .15s ease;
        }

        .sh-nav-btn:hover {
            background: var(--green-mid);
            color: #FFFFFF;
        }

        .sh-nav-btn--disabled {
            color: var(--ink-soft);
            background: transparent;
            border: 1px solid var(--line);
            opacity: .55;
            cursor: not-allowed;
        }

        .sh-nav-btn--disabled:hover {
            background: transparent;
            color: var(--ink-soft);
        }
    </style>
</head>

<body>
    <div class="app-shell">

        <!-- SIDEBAR -->
        <?php $active = 'inventory';
        include 'includes/sidebar.php'; ?>

        <!-- MAIN -->
        <div class="main-area">
            <header class="topbar">
                <div class="topbar-icon"><?= $__icon_inventory ?></div>
                <h1>Inventory</h1>
                <div class="topbar-user">
                    <span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img
                                src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>"
                                alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                    <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span>
                </div>
            </header>

            <main class="content">

                <?php if (!empty($success_message)): ?>
                    <script>
                        Swal.fire({ icon: 'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 2200, timerProgressBar: true });
                    </script>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <script>
                        Swal.fire({ icon: 'error', title: <?= json_encode(clean($error_message)) ?>, toast: true, position: 'top-end', showConfirmButton: false, timer: 3200, timerProgressBar: true });
                    </script>
                <?php endif; ?>

                <!-- FINANCIAL SUMMARY -->
                <section class="finance-grid">
                    <div class="finance-card sales">
                        <span class="f-label">Total Sales</span>
                        <div class="f-value"><?= peso($total_sales) ?></div>
                        <div class="f-note">All-time income from completed sales</div>
                    </div>
                    <div class="finance-card cost">
                        <span class="f-label">Total Restock Cost</span>
                        <div class="f-value"><?= peso($total_restock_cost) ?></div>
                        <div class="f-note">All-time spent on restocking products</div>
                    </div>
                    <div class="finance-card profit">
                        <span class="f-label">Cash Flow</span>
                        <div class="f-value <?= $net_profit >= 0 ? 'positive' : 'negative' ?>"><?= peso($net_profit) ?>
                        </div>
                        <div class="f-note">Total Sales − Total Restock Cost </div>
                    </div>
                </section>

                <!-- STAT CARDS -->
                <section class="stat-grid">
                    <div class="stat-card">
                        <span class="stat-label">Total Products</span>
                        <span class="stat-value"><?= $total_count ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">In Stock</span>
                        <span class="stat-value" style="color:var(--green-mid)"><?= $in_count ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Low Stock</span>
                        <span class="stat-value" style="color:var(--mustard)"><?= $low_count ?></span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Out of Stock</span>
                        <span class="stat-value" style="color:var(--red-alert)"><?= $out_count ?></span>
                    </div>
                </section>


                <div class="toolbar">
                    <div class="filter-tabs">
                        <a href="inventory.php?status=all<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                            class="filter-tab <?= $status_filter == 'all' ? 'active' : '' ?>">All<span
                                class="badge"><?= $total_count ?></span></a>
                        <a href="inventory.php?status=in<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                            class="filter-tab <?= $status_filter == 'in' ? 'active' : '' ?>">In Stock<span
                                class="badge"><?= $in_count ?></span></a>
                        <a href="inventory.php?status=low<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                            class="filter-tab <?= $status_filter == 'low' ? 'active' : '' ?>">Low Stock<span
                                class="badge"><?= $low_count ?></span></a>
                        <a href="inventory.php?status=out<?= !empty($search) ? '&search=' . urlencode($search) : '' ?>"
                            class="filter-tab <?= $status_filter == 'out' ? 'active' : '' ?>">Out of Stock<span
                                class="badge"><?= $out_count ?></span></a>
                    </div>
                    <form method="GET" action="inventory.php" class="search-form">
                        <input type="hidden" name="status" value="<?= clean($status_filter) ?>">
                        <input type="text" name="search" id="inventorySearchInput" placeholder="Search products..."
                            value="<?= clean($search) ?>" class="field-input" style="width:220px;" autocomplete="off">
                        <button type="submit" class="action-btn action-btn--small">Search</button>
                    </form>
                    <a href="stock_recommendations.php" class="action-btn action-btn--small action-btn--outline">Stock
                        Recommendations</a>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2>Stock Levels (<?= count($products) ?>)</h2>
                    </div>
                    <div class="panel-body" style="padding: 20px;">

                        <?php if (empty($grouped_products)): ?>
                            <p style="color:var(--ink-soft); text-align:center; padding: 2rem 0;">No products found.</p>
                        <?php else: ?>
                            <?php foreach ($grouped_products as $category => $items): ?>
                                <div class="category-group">
                                    <div class="category-group-header">
                                        <span class="category-group-label"><?= clean($category) ?></span>
                                        <span class="category-group-count"><?= count($items) ?>
                                            item<?= count($items) !== 1 ? 's' : '' ?></span>
                                    </div>
                                    <table class="data-table stock-levels-table">
                                        <colgroup>
                                            <col class="col-product">
                                            <col class="col-price">
                                            <col class="col-cost">
                                            <col class="col-stock">
                                            <col class="col-status">
                                            <col class="col-actions">
                                        </colgroup>
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>Price</th>
                                                <th>Cost Price</th>
                                                <th>Current Stock</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($items as $p): ?>
                                                <?php
                                                if ($p['stock'] == 0) {
                                                    $badge = '<span class="stock-badge out">Out of Stock</span>';
                                                } elseif ($p['stock'] <= LOW_STOCK) {
                                                    $badge = '<span class="stock-badge low">Low Stock</span>';
                                                } else {
                                                    $badge = '<span class="stock-badge in">In Stock</span>';
                                                }
                                                $current_cost = (float) ($p['cost_price'] ?? 0);
                                                ?>
                                                <tr>
                                                    <td style="font-weight:600"><?= clean($p['product_name']) ?></td>
                                                    <td><?= peso($p['price']) ?></td>
                                                    <td style="color:var(--ink-soft)"><?= peso($current_cost) ?></td>
                                                    <td style="font-weight:700; font-size:15px"><?= $p['stock'] ?> pcs</td>
                                                    <td><?= $badge ?></td>
                                                    <td class="actions-cell" style="display:flex; gap:8px;">
                                                        <?php if ($isAdmin): ?>
                                                            <button class="action-btn action-btn--small"
                                                                onclick="openRestock(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode((string) $p['product_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= $current_cost ?>)">
                                                                Restock
                                                            </button>
                                                        <?php else: ?>
                                                            <a href="inventory_requests.php?product_id=<?= enc_id($p['id']) ?>"
                                                                class="action-btn action-btn--small action-btn--outline"
                                                                title="Restocking needs Finance approval — submit a request instead">
                                                                Request Restock
                                                            </a>
                                                        <?php endif; ?>
                                                        <button class="action-btn action-btn--small action-btn--outline"
                                                            onclick="openAdjust(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode((string) $p['product_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $p['stock'] ?>)">
                                                            Adjust
                                                        </button>
                                                        <button class="action-btn action-btn--small action-btn--outline"
                                                            onclick="openIssue(<?= (int) $p['id'] ?>, <?= htmlspecialchars(json_encode((string) $p['product_name'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>, <?= (int) $p['stock'] ?>)">
                                                            Issue
                                                        </button>
                                                        <a class="action-btn action-btn--small action-btn--outline"
                                                            href="product_batches.php?product_id=<?= enc_id($p['id']) ?>">
                                                            Batches
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header" id="stock-history">
                        <h2>Stock History — <?= date('F d, Y', strtotime($stock_date)) ?></h2>
                        <div class="stock-history-nav">
                            <?php if ($__prevStockDate): ?>
                                <a class="sh-nav-btn"
                                    href="<?= clean(stockHistoryNavUrl($__prevStockDate, $__stockNavParams)) ?>">&larr; Prev
                                    Day</a>
                            <?php else: ?>
                                <span class="sh-nav-btn sh-nav-btn--disabled">&larr; Prev Day</span>
                            <?php endif; ?>
                            <?php if ($__nextStockDate): ?>
                                <a class="sh-nav-btn"
                                    href="<?= clean(stockHistoryNavUrl($__nextStockDate, $__stockNavParams)) ?>">Next Day
                                    &rarr;</a>
                            <?php else: ?>
                                <span class="sh-nav-btn sh-nav-btn--disabled">Next Day &rarr;</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="panel-body panel-body--table">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Type</th>
                                    <th>Product</th>
                                    <th>Change</th>
                                    <th>Before → After</th>
                                    <th>Cost</th>
                                    <th>Reason</th>
                                    <th>By</th>
                                    <th>Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($stock_history)): ?>
                                    <tr>
                                        <td colspan="8" class="empty-row">No stock changes on this day.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($stock_history as $log): ?>
                                        <?php
                                        $type_map = [
                                            'restock' => 'restock',
                                            'sale' => 'sale',
                                            'void' => 'void',
                                            'adjust' => 'adjust',
                                            'issuance' => 'issuance',
                                        ];
                                        $type_class = $type_map[$log['change_type']] ?? 'adjust';
                                        $sign = $log['quantity_change'] >= 0 ? '+' : '';
                                        ?>
                                        <tr>
                                            <td><span
                                                    class="change-badge <?= $type_class ?>"><?= strtoupper($log['change_type']) ?></span>
                                            </td>
                                            <td style="font-weight:600"><?= clean($log['product_name']) ?></td>
                                            <td
                                                style="font-weight:700; color:<?= $log['quantity_change'] >= 0 ? 'var(--green-mid)' : 'var(--red-alert)' ?>">
                                                <?= $sign . $log['quantity_change'] ?> pcs
                                            </td>
                                            <td style="color:var(--ink-soft); font-size:13px"><?= $log['previous_stock'] ?> →
                                                <?= $log['new_stock'] ?></td>
                                            <td style="font-size:13px; font-weight:600; color:var(--red-alert)">
                                                <?= !empty($log['total_cost']) ? peso($log['total_cost']) : '—' ?>
                                            </td>
                                            <td style="font-size:13px"><?= clean($log['reason']) ?></td>
                                            <td style="font-size:13px"><?= clean($log['done_by']) ?></td>
                                            <td style="font-size:13px; color:var(--ink-soft)">
                                                <?= date('M d, Y h:i A', strtotime($log['date_created'])) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <div class="modal-overlay" id="restockModal">
        <div class="modal-box">
            <h3>Restock Product</h3>
            <p class="modal-sub" id="restockProductName"></p>
            <form method="POST" action="inventory.php" id="restockForm">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="restock">
                <input type="hidden" name="product_id" id="restockProductId">
                <div class="field-group" style="margin-bottom:14px">
                    <label class="field-label">Quantity to Add</label>
                    <input type="number" name="quantity" id="restockQuantity" class="field-input" min="1"
                        placeholder="e.g. 50" required oninput="updateCostPreview()">
                </div>
                <div class="field-group" style="margin-bottom:14px">
                    <label class="field-label">Cost per Unit (₱) <span
                            style="color:var(--ink-soft); font-weight:400">(Original Price.)</span></label>
                    <input type="number" name="cost_price" id="restockCostPrice" class="field-input" min="0" step="0.01"
                        placeholder="0.00" oninput="updateCostPreview()">
                    <div class="cost-preview" id="costPreview">Total cost: <strong>₱ 0.00</strong></div>
                </div>
                <div class="field-group" style="margin-bottom:14px">
                    <label class="field-label">Expiry Date of This Batch <span
                            style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                    <input type="date" name="expiry_date" id="restockExpiryDate" class="field-input"
                        min="<?= date('Y-m-d') ?>">
                    <div class="field-hint">This batch keeps its own expiry — it won't overwrite older stock's expiry
                        date.</div>
                </div>
                <div class="field-group">
                    <label class="field-label">Reason / Note <span
                            style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                    <input type="text" name="reason" class="field-input" placeholder="e.g. New delivery from supplier">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('restockModal')">Cancel</button>
                    <button type="submit" class="btn-confirm">Restock</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="adjustModal">
        <div class="modal-box">
            <h3>Adjust Stock</h3>
            <p class="modal-sub" id="adjustProductName"></p>
            <form method="POST" action="inventory.php">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="adjust">
                <input type="hidden" name="product_id" id="adjustProductId">
                <div class="field-group" style="margin-bottom:14px">
                    <label class="field-label">New Stock Count</label>
                    <input type="number" name="new_stock" id="adjustNewStock" class="field-input" min="0" required>
                </div>
                <div class="field-group">
                    <label class="field-label">Reason <span style="color:var(--red-alert)">*</span></label>
                    <select name="reason_choice" id="adjustReasonChoice" class="field-input" required
                        onchange="toggleOtherReason()">
                        <option value="" disabled selected>— Select a reason —</option>
                        <optgroup label="Stock Decrease">
                            <option value="Damaged goods">Damaged goods</option>
                            <option value="Expired products">Expired products</option>
                            <option value="Lost / missing items">Lost / missing items</option>
                            <option value="Theft / shrinkage">Theft / shrinkage</option>
                            <option value="Returned to supplier">Returned to supplier</option>
                            <option value="Free samples given">Free samples given</option>
                            <option value="Internal use / consumed">Internal use / consumed</option>
                        </optgroup>
                        <optgroup label="Stock Correction">
                            <option value="Stock count correction">Stock count correction</option>
                            <option value="Wrong quantity received">Wrong quantity received</option>
                        </optgroup>
                        <optgroup label="Other">
                            <option value="Other">Other (please specify)</option>
                        </optgroup>
                    </select>
                </div>
                <div class="field-group" id="otherReasonGroup" style="display:none; margin-top:10px;">
                    <label class="field-label">Please specify <span style="color:var(--red-alert)">*</span></label>
                    <input type="text" name="reason_other" id="adjustReasonOther" class="field-input"
                        placeholder="Describe the reason...">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('adjustModal')">Cancel</button>
                    <button type="submit" class="btn-confirm danger">Apply Adjustment</button>
                </div>
            </form>
        </div>
    </div>

    <div class="modal-overlay" id="issueModal">
        <div class="modal-box">
            <h3>Issue Items</h3>
            <p class="modal-sub" id="issueProductName"></p>
            <form method="POST" action="inventory.php">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="issue">
                <input type="hidden" name="product_id" id="issueProductId">
                <div class="field-group" style="margin-bottom:14px">
                    <label class="field-label">Quantity to Issue</label>
                    <input type="number" name="issue_quantity" id="issueQuantity" class="field-input" min="1"
                        placeholder="e.g. 5" required>
                </div>
                <div class="field-group" style="margin-bottom:14px">
                    <label class="field-label">Purpose <span style="color:var(--red-alert)">*</span></label>
                    <select name="issue_purpose" id="issuePurpose" class="field-input" required
                        onchange="toggleIssueOther()">
                        <option value="" disabled selected>— Select a purpose —</option>
                        <option value="Internal Use / Store Consumption">Internal Use / Store Consumption</option>
                        <option value="Staff Use">Staff Use</option>
                        <option value="Free Sample / Promo">Free Sample / Promo</option>
                        <option value="Store Display">Store Display</option>
                        <option value="Donation">Donation</option>
                        <option value="Other">Other (please specify)</option>
                    </select>
                </div>
                <div class="field-group" style="margin-bottom:14px">
                    <label class="field-label">Issued To <span
                            style="color:var(--ink-soft); font-weight:400">(optional)</span></label>
                    <input type="text" name="issued_to" class="field-input"
                        placeholder="e.g. Kitchen dept., Juan Dela Cruz">
                </div>
                <div class="field-group" id="issueOtherGroup" style="display:none;">
                    <label class="field-label">Please specify <span style="color:var(--red-alert)">*</span></label>
                    <input type="text" name="issue_notes" id="issueNotes" class="field-input"
                        placeholder="Describe the purpose...">
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn-cancel" onclick="closeModal('issueModal')">Cancel</button>
                    <button type="submit" class="btn-confirm danger">Issue Items</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function confirmNav(e, url, opts) {
            e.preventDefault();
            Swal.fire(Object.assign({
                showCancelButton: true,
                cancelButtonText: 'Cancel'
            }, opts)).then(function (result) {
                if (result.isConfirmed) window.location.href = url;
            });
            return false;
        }
        function openRestock(id, name, currentCost) {
            document.getElementById('restockProductId').value = id;
            document.getElementById('restockProductName').textContent = name;
            document.getElementById('restockQuantity').value = '';
            document.getElementById('restockCostPrice').value = currentCost > 0 ? currentCost.toFixed(2) : '';
            document.getElementById('restockExpiryDate').value = '';
            document.getElementById('restockModal').classList.add('open');
            updateCostPreview();
        }
        function updateCostPreview() {
            const qty = parseFloat(document.getElementById('restockQuantity').value) || 0;
            const cost = parseFloat(document.getElementById('restockCostPrice').value) || 0;
            const total = qty * cost;
            document.getElementById('costPreview').innerHTML =
                'Total cost: <strong>₱ ' + total.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + '</strong>';
        }
        function toggleOtherReason() {
            var choice = document.getElementById('adjustReasonChoice').value;
            var otherGroup = document.getElementById('otherReasonGroup');
            var otherInput = document.getElementById('adjustReasonOther');
            if (choice === 'Other') {
                otherGroup.style.display = 'block';
                otherInput.required = true;
            } else {
                otherGroup.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }
        function toggleIssueOther() {
            var choice = document.getElementById('issuePurpose').value;
            var otherGroup = document.getElementById('issueOtherGroup');
            var otherInput = document.getElementById('issueNotes');
            if (choice === 'Other') {
                otherGroup.style.display = 'block';
                otherInput.required = true;
            } else {
                otherGroup.style.display = 'none';
                otherInput.required = false;
                otherInput.value = '';
            }
        }
        function openIssue(id, name, currentStock) {
            document.getElementById('issueProductId').value = id;
            document.getElementById('issueProductName').textContent = name + ' — Current: ' + currentStock + ' pcs';
            document.getElementById('issueQuantity').value = '';
            document.getElementById('issueQuantity').max = currentStock;
            document.getElementById('issuePurpose').value = '';
            document.getElementById('issueOtherGroup').style.display = 'none';
            document.getElementById('issueNotes').value = '';
            document.getElementById('issueModal').classList.add('open');
        }
        function openAdjust(id, name, currentStock) {
            document.getElementById('adjustProductId').value = id;
            document.getElementById('adjustProductName').textContent = name + ' — Current: ' + currentStock + ' pcs';
            document.getElementById('adjustNewStock').value = currentStock;
            document.getElementById('adjustReasonChoice').value = '';
            document.getElementById('otherReasonGroup').style.display = 'none';
            document.getElementById('adjustReasonOther').value = '';
            document.getElementById('adjustModal').classList.add('open');
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('open');
        }
        document.querySelectorAll('.modal-overlay').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (e.target === el) closeModal(el.id);
            });
        });
    </script>
</body>

</html>