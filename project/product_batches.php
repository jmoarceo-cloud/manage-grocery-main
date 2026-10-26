<?php
// ============================================================
// product_batches.php — Per-batch stock & expiry para sa isang
// product. Dito nakikita ang bawat restock/delivery bilang
// hiwalay na "batch", may sarili itong quantity_remaining at
// expiry_date — kaya kahit iba ang expiry ng bagong dating stock
// sa luma, hindi na sila magkakahalo.
// ============================================================

require_once 'includes/config.php';
requireInventoryStaff();

$success_message = $_SESSION['flash_success'] ?? '';
$error_message   = $_SESSION['flash_error']   ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

$product_id = dec_id($_GET['product_id'] ?? '');

$stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
$stmt->execute([$product_id]);
$product = $stmt->fetch();

if (!$product) {
    $_SESSION['flash_error'] = "Product not found.";
    redirect('products.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireCSRF();
    $action   = $_POST['action'];
    $batch_id = (int) ($_POST['batch_id'] ?? 0);

    if ($action == 'mark_waste') {
        // Isang batch lang ang naaapektuhan dito — hindi na kailangang
        // hulaan kung aling stock ang sira/expired, dahil alam na natin
        // kung saang batch (at expiry) ito galing.
        $qty = trim($_POST['waste_qty'] ?? '');

        $bstmt = $pdo->prepare("SELECT * FROM product_batches WHERE id = ? AND product_id = ?");
        $bstmt->execute([$batch_id, $product_id]);
        $batch = $bstmt->fetch();

        if (!$batch) {
            $error_message = "Batch not found.";
        } elseif (!ctype_digit((string) $qty) || (int) $qty <= 0) {
            $error_message = "Quantity must be a whole number greater than 0.";
        } elseif ((int) $qty > (int) $batch['quantity_remaining']) {
            $error_message = "Cannot remove more than what's left in this batch (" . $batch['quantity_remaining'] . " pcs).";
        } else {
            $qty = (int) $qty;
            try {
                $pdo->beginTransaction();

                $pdo->prepare("UPDATE product_batches SET quantity_remaining = quantity_remaining - ? WHERE id = ?")
                    ->execute([$qty, $batch_id]);

                $prev_total = (int) $product['stock'];
                $new_total  = $prev_total - $qty;
                $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?")
                    ->execute([$qty, $product_id]);

                $reason = "Expired products (batch #" . $batch_id
                    . (!empty($batch['expiry_date']) ? ", exp. " . date('M d, Y', strtotime($batch['expiry_date'])) : "")
                    . ")";
                $pdo->prepare("INSERT INTO stock_logs (product_id, change_type, quantity_change, previous_stock, new_stock, reason, user_id) VALUES (?, 'adjust', ?, ?, ?, ?, ?)")
                    ->execute([$product_id, -$qty, $prev_total, $new_total, $reason, currentPosUserId()]);

                $pdo->commit();
                checkLowStock($pdo);
                $success_message = "Removed " . $qty . " pcs from this batch (marked as expired/waste).";
            } catch (Exception $e) {
                $pdo->rollBack();
                error_log("Mark waste error: " . $e->getMessage());
                $error_message = "Could not update this batch. Please try again.";
            }
        }
    }

    $_SESSION['flash_success'] = $success_message;
    $_SESSION['flash_error']   = $error_message;
    redirect('product_batches.php?product_id=' . enc_id($product_id));
}

$batches = $pdo->prepare("
    SELECT pb.*, u.full_name AS added_by_name
    FROM product_batches pb
    LEFT JOIN users u ON u.id = pb.added_by
    WHERE pb.product_id = ?
    ORDER BY (pb.quantity_remaining = 0), (pb.expiry_date IS NULL), pb.expiry_date ASC, pb.date_received ASC
");
$batches->execute([$product_id]);
$batches = $batches->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Batches — <?= clean($product['product_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<style>
.expiry-badge { display:inline-flex; align-items:center; gap:4px; font-size:12px; font-weight:600; }
.alert { padding:12px 16px; border-radius:12px; font-size:14px; font-weight:500; }
.alert--success { background:var(--green-pale); color:#1a7a3b; }
.alert--error   { background:var(--red-pale); color:var(--red-alert); }
html[data-theme="dark"] .alert--success { color: var(--green-leaf); }
.empty-row { text-align:center; color:var(--ink-soft); padding:24px 0 !important; font-size:14px; }
.batch-navigation {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin: 0 0 16px;
}
.back-link {
    display: inline-flex;
    align-items: center;
    min-height: 34px;
    padding: 7px 11px;
    border: 1.5px solid var(--green-mid);
    border-radius: 9px;
    background: var(--white);
    color: var(--green-deep);
    font-size: 13px;
    font-weight: 700;
    line-height: 1.2;
    text-decoration: none;
    transition: background-color .15s ease, border-color .15s ease, transform .15s ease;
}
.back-link:hover {
    border-color: var(--green-deep);
    background: var(--green-mid);
    color: #fff;
    transform: translateY(-1px);
}
html[data-theme="dark"] .back-link { background: var(--white); color: var(--green-leaf); }
html[data-theme="dark"] .back-link:hover { background: var(--green-mid); color: #fff; }
.batch-navigation__separator { color: var(--ink-soft); font-size: 13px; }
.batch-row.is-depleted { opacity:.5; }
.modal-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:200; align-items:center; justify-content:center; }
.modal-overlay.open { display:flex; }
.modal-box { background:var(--white); border-radius:18px; padding:32px 30px 28px; width:100%; max-width:400px; box-shadow:0 20px 60px rgba(0,0,0,.2); }
.modal-box h3 { font-family:var(--font-display); font-size:18px; font-weight:700; color: var(--text-accent); margin:0 0 6px; }
.modal-box .modal-sub { font-size:13px; color:var(--ink-soft); margin-bottom:20px; }
.modal-actions { display:flex; gap:10px; margin-top:22px; }
.btn-cancel { flex:1; padding:11px; border-radius:10px; border:1.5px solid var(--line); background:var(--white); color:var(--ink-soft); font-size:14px; font-weight:600; cursor:pointer; }
.btn-confirm { flex:1; padding:11px; border-radius:10px; border:none; background:var(--red-alert); color: #FFFFFF; font-size:14px; font-weight:600; cursor:pointer; }
.field-input { padding:10px 14px; border:1.5px solid var(--line); border-radius:10px; font-family:var(--font-body); font-size:14px; color:var(--ink); background:var(--cream); width:100%; }
.field-label { font-size:13px; font-weight:600; color:var(--ink); display:block; margin-bottom:6px; }
</style>
</head>
<body>

<div class="app-shell">

    <?php $active = 'products'; include 'includes/sidebar.php'; ?>

    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_inventory ?></div>
            <h1>Batches — <?= clean($product['product_name']) ?></h1>
            <div class="topbar-user"><span class="user-avatar"><?php if (!empty($_SESSION['profile_picture'])): ?><img src="uploads/avatars/<?= clean($_SESSION['profile_picture']) ?>" alt=""><?php else: ?><?= clean(mb_strtoupper(mb_substr(trim($_SESSION['full_name'] ?? ''), 0, 1)) ?: 'A') ?><?php endif; ?></span>
                <span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span></div>
        </header>

        <main class="content">

            <?php if (!empty($success_message)): ?>
                <script>Swal.fire({ icon:'success', title: <?= json_encode(strip_tags($success_message)) ?>, toast:true, position:'top-end', showConfirmButton:false, timer:2200, timerProgressBar:true });</script>
            <?php endif; ?>
            <?php if (!empty($error_message)): ?>
                <script>Swal.fire({ icon:'error', title: <?= json_encode(clean($error_message)) ?>, toast:true, position:'top-end', showConfirmButton:false, timer:3200, timerProgressBar:true });</script>
            <?php endif; ?>

            <nav class="batch-navigation" aria-label="Batch navigation">
                <a href="products.php" class="back-link">← Back to Products</a>
                <span class="batch-navigation__separator" aria-hidden="true">or</span>
                <a href="inventory.php" class="back-link">Go to Inventory</a>
            </nav>

            <div class="panel" style="margin-top:14px;">
                <div class="panel-header">
                    <h2><?= clean($product['product_name']) ?> — Total Stock: <?= $product['stock'] ?> pcs</h2>
                </div>
                <div class="panel-body panel-body--table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Batch #</th>
                                <th>Received</th>
                                <th>Qty Received</th>
                                <th>Qty Remaining</th>
                                <th>Cost / Unit</th>
                                <th>Expiry</th>
                                <th>Added By</th>
                                <th>Note</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($batches)): ?>
                                <tr><td colspan="9" class="empty-row">No batches recorded yet. Restock this product from Inventory to create one.</td></tr>
                            <?php else: ?>
                                <?php foreach ($batches as $b): ?>
                                    <?php
                                    $is_depleted = ((int) $b['quantity_remaining']) <= 0;
                                    $status = getExpiryStatus($b['expiry_date']);
                                    ?>
                                    <tr class="batch-row <?= $is_depleted ? 'is-depleted' : '' ?>">
                                        <td>#<?= $b['id'] ?></td>
                                        <td style="font-size:13px"><?= date('M d, Y', strtotime($b['date_received'])) ?></td>
                                        <td><?= $b['quantity_received'] ?> pcs</td>
                                        <td style="font-weight:700"><?= $b['quantity_remaining'] ?> pcs</td>
                                        <td><?= peso($b['cost_price']) ?></td>
                                        <td>
                                            <?php if ($status): ?>
                                                <span class="expiry-badge" style="color:<?= $status['color'] ?>"><?= $status['icon'] ?> <?= $status['label'] ?></span>
                                            <?php else: ?>
                                                <span style="color:var(--ink-soft); font-size:12px">No expiry</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="font-size:13px"><?= clean($b['added_by_name'] ?? '—') ?></td>
                                        <td style="font-size:13px; color:var(--ink-soft)"><?= clean($b['notes'] ?? '') ?></td>
                                        <td class="actions-cell">
                                            <?php if (!$is_depleted): ?>
                                                <button class="link-btn link-btn--danger" onclick="openWaste(<?= $b['id'] ?>, <?= $b['quantity_remaining'] ?>)">Mark Expired / Waste</button>
                                            <?php else: ?>
                                                <span style="color:var(--ink-soft); font-size:12px">Depleted</span>
                                            <?php endif; ?>
                                        </td>
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

<div class="modal-overlay" id="wasteModal">
    <div class="modal-box">
        <h3>Mark Batch as Expired / Waste</h3>
        <p class="modal-sub" id="wasteSub"></p>
        <form method="POST" action="product_batches.php?product_id=<?= enc_id($product_id) ?>">
            <?php csrfField(); ?>
            <input type="hidden" name="action" value="mark_waste">
            <input type="hidden" name="batch_id" id="wasteBatchId">
            <label class="field-label">Quantity to remove</label>
            <input type="number" name="waste_qty" id="wasteQty" class="field-input" min="1" required>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeModal('wasteModal')">Cancel</button>
                <button type="submit" class="btn-confirm">Remove from Stock</button>
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
function openWaste(batchId, remaining) {
    document.getElementById('wasteBatchId').value = batchId;
    document.getElementById('wasteSub').textContent = 'This batch has ' + remaining + ' pcs remaining.';
    var qtyInput = document.getElementById('wasteQty');
    qtyInput.max = remaining;
    qtyInput.value = remaining;
    document.getElementById('wasteModal').classList.add('open');
}
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}
document.querySelectorAll('.modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === el) closeModal(el.id); });
});
</script>

</body>
</html>