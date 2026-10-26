<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();

function productDetailsRedirect($productId, $message = '', $error = '') {
    if ($message !== '') {
        $_SESSION['flash_success'] = $message;
    }
    if ($error !== '') {
        $_SESSION['flash_error'] = $error;
    }
    header('Location: product_details_edit.php?product_id=' . enc_id($productId));
    exit;
}

$encodedProductId = (string) ($_GET['product_id'] ?? $_POST['product_id'] ?? '');
$productId = dec_id($encodedProductId);
if (!ctype_digit((string) $productId) || (int) $productId < 1) {
    http_response_code(400);
    exit('Invalid product.');
}
$productId = (int) $productId;
$autoAi = ($_GET['ai'] ?? '') === '1';

$productStmt = $pdo->prepare('SELECT id, product_name, barcode, category, is_active FROM products WHERE id = ? LIMIT 1');
$productStmt->execute([$productId]);
$product = $productStmt->fetch();
if (!$product) {
    http_response_code(404);
    exit('Product not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = (string) ($_POST['action'] ?? 'save');
    $allowedActions = ['save', 'submit', 'verify', 'reject', 'resubmit'];
    if (!in_array($action, $allowedActions, true)) {
        productDetailsRedirect($productId, '', 'Invalid product-details action.');
    }
    if (in_array($action, ['verify', 'reject'], true) && ($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Admin access required for this action.');
    }

    $description = trim((string) ($_POST['description'] ?? ''));
    $brand = trim((string) ($_POST['brand'] ?? ''));
    $unitName = trim((string) ($_POST['unit_name'] ?? ''));
    $storageNotes = trim((string) ($_POST['storage_notes'] ?? ''));
    $rejectedReason = trim((string) ($_POST['rejected_reason'] ?? ''));
    $errors = [];

    if (in_array($action, ['save', 'submit', 'resubmit'], true)) {
        if (mb_strlen($description) > 5000) {
            $errors[] = 'Description must be 5,000 characters or fewer.';
        }
        if (mb_strlen($brand) > 100) {
            $errors[] = 'Brand must be 100 characters or fewer.';
        }
        if (mb_strlen($unitName) > 50) {
            $errors[] = 'Unit name must be 50 characters or fewer.';
        }
        if (mb_strlen($storageNotes) > 5000) {
            $errors[] = 'Storage notes must be 5,000 characters or fewer.';
        }
    }
    if ($action === 'reject' && ($rejectedReason === '' || mb_strlen($rejectedReason) > 1000)) {
        $errors[] = 'A rejection reason of 1,000 characters or fewer is required.';
    }
    if ($errors) {
        productDetailsRedirect($productId, '', implode(' ', $errors));
    }

    try {
        $pdo->beginTransaction();
        $currentStmt = $pdo->prepare('
            SELECT detail_id, description, brand, unit_name, storage_notes,
                   review_status, verified_by, verified_at, rejected_reason
            FROM product_details
            WHERE product_id = ?
            FOR UPDATE
        ');
        $currentStmt->execute([$productId]);
        $current = $currentStmt->fetch() ?: null;
        $currentStatus = $current['review_status'] ?? 'draft';
        $nextStatus = $currentStatus;
        $nextVerifiedBy = $current['verified_by'] ?? null;
        $nextVerifiedAt = $current['verified_at'] ?? null;
        $nextRejectedReason = $current['rejected_reason'] ?? null;
        $before = $current ? [
            'description' => $current['description'],
            'brand' => $current['brand'],
            'unit_name' => $current['unit_name'],
            'storage_notes' => $current['storage_notes'],
            'review_status' => $currentStatus,
            'rejected_reason' => $nextRejectedReason,
        ] : null;
        $after = $before ?: [
            'description' => null,
            'brand' => null,
            'unit_name' => null,
            'storage_notes' => null,
            'review_status' => 'draft',
            'rejected_reason' => null,
        ];

        if (in_array($action, ['save', 'submit', 'resubmit'], true)) {
            if ($action === 'submit' && !in_array($currentStatus, ['draft', 'needs_review'], true)) {
                throw new RuntimeException('Only draft details can be submitted for review.');
            }
            if ($action === 'resubmit' && $currentStatus !== 'rejected') {
                throw new RuntimeException('Only rejected details can be resubmitted.');
            }
            $after['description'] = $description !== '' ? $description : null;
            $after['brand'] = $brand !== '' ? $brand : null;
            $after['unit_name'] = $unitName !== '' ? $unitName : null;
            $after['storage_notes'] = $storageNotes !== '' ? $storageNotes : null;
            $nextStatus = $action === 'submit' || $action === 'resubmit' ? 'needs_review' : $currentStatus;
            if (in_array($currentStatus, ['verified', 'rejected'], true) && $action === 'save') {
                $nextStatus = 'needs_review';
            }
            $nextVerifiedBy = null;
            $nextVerifiedAt = null;
            $nextRejectedReason = null;
        } elseif ($action === 'verify') {
            if ($currentStatus !== 'needs_review') {
                throw new RuntimeException('Only details awaiting review can be verified.');
            }
            $nextStatus = 'verified';
            $nextVerifiedBy = currentPosUserId();
            $nextVerifiedAt = date('Y-m-d H:i:s');
            $nextRejectedReason = null;
        } elseif ($action === 'reject') {
            if ($currentStatus !== 'needs_review') {
                throw new RuntimeException('Only details awaiting review can be rejected.');
            }
            $nextStatus = 'rejected';
            $nextVerifiedBy = null;
            $nextVerifiedAt = null;
            $nextRejectedReason = $rejectedReason;
        }

        $after['review_status'] = $nextStatus;
        $after['rejected_reason'] = $nextRejectedReason;
        $saveStmt = $pdo->prepare('
            INSERT INTO product_details
                (product_id, description, brand, unit_name, storage_notes, review_status,
                 updated_by, verified_by, verified_at, rejected_reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                description = VALUES(description),
                brand = VALUES(brand),
                unit_name = VALUES(unit_name),
                storage_notes = VALUES(storage_notes),
                review_status = VALUES(review_status),
                updated_by = VALUES(updated_by),
                verified_by = VALUES(verified_by),
                verified_at = VALUES(verified_at),
                rejected_reason = VALUES(rejected_reason)
        ');
        $saveStmt->execute([
            $productId,
            $after['description'],
            $after['brand'],
            $after['unit_name'],
            $after['storage_notes'],
            $nextStatus,
            currentPosUserId(),
            $nextVerifiedBy,
            $nextVerifiedAt,
            $nextRejectedReason,
        ]);

        $auditStmt = $pdo->prepare('
            INSERT INTO product_detail_audit
                (product_id, changed_by, action, review_status, before_json, after_json)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $auditStmt->execute([
            $productId,
            currentPosUserId(),
            $action,
            $nextStatus,
            $before ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $pdo->commit();
        $messages = [
            'save' => 'Product details saved.',
            'submit' => 'Product details submitted for review.',
            'verify' => 'Product details verified.',
            'reject' => 'Product details rejected.',
            'resubmit' => 'Product details resubmitted for review.',
        ];
        productDetailsRedirect($productId, $messages[$action]);
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Product details workflow failed: ' . $exception->getMessage());
        productDetailsRedirect($productId, '', $exception instanceof RuntimeException ? $exception->getMessage() : 'Could not save product details. Please try again.');
    }
}

$successMessage = $_SESSION['flash_success'] ?? '';
$errorMessage = $_SESSION['flash_error'] ?? '';
$aiMessage = $_SESSION['flash_ai'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error'], $_SESSION['flash_ai']);

$detailsStmt = $pdo->prepare('
    SELECT description, brand, unit_name, storage_notes, review_status,
           verified_by, verified_at, rejected_reason
    FROM product_details
    WHERE product_id = ?
');
$detailsStmt->execute([$productId]);
$details = $detailsStmt->fetch() ?: [
    'description' => '',
    'brand' => '',
    'unit_name' => '',
    'storage_notes' => '',
    'review_status' => 'draft',
    'verified_by' => null,
    'verified_at' => null,
    'rejected_reason' => null,
];
$status = (string) $details['review_status'];
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Product Details - <?= clean($product['product_name']) ?></title>
<link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
<style>
.details-wrap { max-width: 860px; margin: 0 auto; }
.details-meta { display: flex; flex-wrap: wrap; gap: 8px 18px; color: var(--ink-soft); font-size: 13px; margin-top: 6px; }
.details-form { display: grid; gap: 18px; }
.details-form input, .details-form textarea { width: 100%; box-sizing: border-box; }
.details-form textarea { min-height: 130px; resize: vertical; }
.details-actions { display: flex; gap: 10px; flex-wrap: wrap; }
.details-alert { padding: 12px 16px; border-radius: 10px; margin-bottom: 16px; }
.details-alert--success { background: #e6f4ea; color: #1a7a3b; }
.details-alert--warning { background: #fff4d6; color: #795600; }
.details-alert--error { background: #fbe7e2; color: #c8472d; }
.details-status { font-weight: 700; text-transform: capitalize; }
</style>
</head>
<body>
<div class="app-shell">
    <?php $active = 'products'; include 'includes/sidebar.php'; ?>
    <div class="main-area">
        <header class="topbar">
            <div class="topbar-icon"><?= $__icon_products ?></div>
            <h1>Product Details</h1>
            <div class="topbar-user"><span class="user-name"><?= clean($_SESSION['full_name'] ?? 'Administrator') ?></span></div>
        </header>
        <main class="content">
            <div class="details-wrap">
                <?php if ($successMessage !== ''): ?><div class="details-alert details-alert--success"><?= clean($successMessage) ?></div><?php endif; ?>
                <?php if ($errorMessage !== ''): ?><div class="details-alert details-alert--error"><?= clean($errorMessage) ?></div><?php endif; ?>
                <?php if (is_array($aiMessage) && !empty($aiMessage['message'])): ?><div class="details-alert <?= !empty($aiMessage['partial']) ? 'details-alert--warning' : 'details-alert--success' ?>"><?= clean($aiMessage['message']) ?></div><?php endif; ?>
                <div class="panel">
                    <div class="panel-header">
                        <h2><?= clean($product['product_name']) ?></h2>
                        <div class="details-meta">
                            <span class="badge badge--neutral">Category: <?= clean($product['category']) ?></span>
                            <span class="badge badge--neutral">Barcode: <?= clean($product['barcode'] ?? 'Not assigned') ?></span>
                            <span class="badge <?= (int) $product['is_active'] === 1 ? 'badge--success' : 'badge--neutral' ?>"><?= (int) $product['is_active'] === 1 ? 'Active' : 'Archived' ?></span>
                            <span class="badge <?= $status === 'verified' ? 'badge--success' : ($status === 'rejected' ? 'badge--danger' : 'badge--warning') ?>">Review: <?= clean(str_replace('_', ' ', $status)) ?></span>
                        </div>
                    </div>
                    <div class="panel-body">
                        <?php if ($status === 'rejected' && $details['rejected_reason']): ?>
                            <div class="details-alert details-alert--error">Rejection reason: <?= clean($details['rejected_reason']) ?></div>
                        <?php endif; ?>
                        <?php if ($autoAi): ?>
                            <div id="auto-ai-status" class="details-alert details-alert--success">AI is identifying the front photo, checking reliable product information, and preparing details for your review...</div>
                        <?php endif; ?>
                        <form method="POST" action="product_details_edit.php?product_id=<?= enc_id($productId) ?>" class="details-form">
                            <?php csrfField(); ?>
                            <input type="hidden" name="product_id" value="<?= $productId ?>">
                            <p class="field-hint">Product image is managed in the main Product form. This page is only for description, brand, unit, and storage details.</p>
                            <a href="product_ai_review.php?product_id=<?= enc_id($productId) ?>" class="action-btn action-btn--secondary">Review AI-filled information</a>
                            <div class="field-group"><label class="field-label" for="details-description">Description</label><textarea class="field-input" id="details-description" name="description" maxlength="5000"><?= clean($details['description'] ?? '') ?></textarea></div>
                            <div class="field-group"><label class="field-label" for="details-brand">Brand</label><input class="field-input" type="text" id="details-brand" name="brand" maxlength="100" value="<?= clean($details['brand'] ?? '') ?>"></div>
                            <div class="field-group"><label class="field-label" for="details-unit">Unit name</label><input class="field-input" type="text" id="details-unit" name="unit_name" maxlength="50" value="<?= clean($details['unit_name'] ?? '') ?>"></div>
                            <div class="field-group"><label class="field-label" for="details-storage">Storage notes</label><textarea class="field-input" id="details-storage" name="storage_notes" maxlength="5000"><?= clean($details['storage_notes'] ?? '') ?></textarea></div>
                            <?php if ($isAdmin && $status === 'needs_review'): ?>
                                <div class="field-group"><label class="field-label" for="details-reason">Rejection reason <span class="field-hint">(required only when rejecting)</span></label><textarea class="field-input" id="details-reason" name="rejected_reason" maxlength="1000"></textarea></div>
                            <?php endif; ?>
                            <div class="details-actions">
                                <?php if ($status !== 'verified' || $isAdmin): ?><button type="submit" name="action" value="save" class="action-btn">Save Draft</button><?php endif; ?>
                                <?php if (in_array($status, ['draft', 'needs_review'], true)): ?><button type="submit" name="action" value="submit" class="action-btn">Submit for Review</button><?php endif; ?>
                                <?php if ($status === 'rejected'): ?><button type="submit" name="action" value="resubmit" class="action-btn">Resubmit</button><?php endif; ?>
                                <?php if ($isAdmin && $status === 'needs_review'): ?>
                                    <button type="submit" name="action" value="verify" class="action-btn">Verify</button>
                                    <button type="submit" name="action" value="reject" class="action-btn action-btn--danger">Reject</button>
                                <?php endif; ?>
                                <a href="products.php?edit=<?= enc_id($productId) ?>" class="action-btn action-btn--secondary">Back to Product</a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>
</body>
<?php if ($autoAi): ?>
<script>
(function () {
    var status = document.getElementById('auto-ai-status');
    if (!status) return;
    var running = false;
    var pageUrl = new URL(window.location.href);
    pageUrl.searchParams.delete('ai');
    window.history.replaceState({}, '', pageUrl.toString());

    function runAi() {
        if (running) return;
        running = true;
        status.textContent = 'AI is identifying the front photo and searching for matching product details...';
        var data = new FormData();
        data.append('product_id', <?= json_encode(enc_id($productId)) ?>);
        data.append('photo_type', 'front');
        data.append('csrf_token', <?= json_encode(generateCSRFToken()) ?>);
        fetch('ai_product_ingest.php', {method: 'POST', body: data, credentials: 'same-origin'})
            .then(function (response) {
                return response.json().then(function (body) {
                    if (!response.ok || !body.ok) throw new Error(body.error || 'AI could not fill the product details.');
                    status.className = 'details-alert ' + (body.partial ? 'details-alert--warning' : 'details-alert--success');
                    status.textContent = body.message || 'AI filled the available product details. Please review them before submitting or publishing.';
                    window.setTimeout(function () {
                        window.location.href = 'product_details_edit.php?product_id=' + encodeURIComponent(<?= json_encode(enc_id($productId)) ?>);
                    }, 900);
                });
            })
            .catch(function (error) {
                running = false;
                status.className = 'details-alert details-alert--error';
                status.textContent = error.message + ' You can retry explicitly in a moment, or enter the details manually.';
                var retry = document.createElement('button');
                retry.type = 'button';
                retry.className = 'action-btn action-btn--secondary';
                retry.textContent = 'Retry AI fill';
                retry.addEventListener('click', function () {
                    retry.remove();
                    runAi();
                });
                status.appendChild(document.createElement('br'));
                status.appendChild(retry);
            });
    }

    runAi();
}());
</script>
<?php endif; ?>
</html>
