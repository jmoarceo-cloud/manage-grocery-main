<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();

$productId = dec_id((string) ($_GET['product_id'] ?? $_POST['product_id'] ?? ''));
if ($productId < 1) {
    http_response_code(400);
    exit('Invalid product.');
}
$productStmt = $pdo->prepare('SELECT id, product_name, barcode, category, image FROM products WHERE id = ?');
$productStmt->execute([$productId]);
$product = $productStmt->fetch();
if (!$product) {
    http_response_code(404);
    exit('Product not found.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    $action = (string) ($_POST['action'] ?? 'draft');
    $reason = trim((string) ($_POST['rejected_reason'] ?? ''));
    if ($action === 'reject' && mb_strlen($reason) < 10) {
        $error = 'A rejection reason of at least 10 characters is required.';
    } else {
        $sourceStmt = $pdo->prepare('SELECT nutrition_source FROM product_details WHERE product_id = ?');
        $sourceStmt->execute([$productId]);
        $nutritionSource = (string) ($sourceStmt->fetchColumn() ?: '');
        $webAuditStmt = $pdo->prepare("SELECT after_json FROM product_detail_audit WHERE product_id = ? AND action = 'ai_extract' ORDER BY audit_id DESC LIMIT 1");
        $webAuditStmt->execute([$productId]);
        $webAudit = json_decode((string) $webAuditStmt->fetchColumn(), true);
        $hasWebFields = is_array($webAudit) && !empty($webAudit['web_fields']);
        $status = ($nutritionSource === 'web' || $hasWebFields) ? 'needs_review' : ($action === 'verify' ? 'verified' : ($action === 'reject' ? 'rejected' : 'needs_review'));
        $fields = [
            'description' => trim((string) ($_POST['description'] ?? '')),
            'ingredients' => trim((string) ($_POST['ingredients'] ?? '')),
            'allergens' => trim((string) ($_POST['allergens'] ?? '')),
            'brand' => trim((string) ($_POST['brand'] ?? '')),
            'unit_name' => trim((string) ($_POST['unit_name'] ?? '')),
            'net_weight' => trim((string) ($_POST['net_weight'] ?? '')),
            'storage_notes' => trim((string) ($_POST['storage_notes'] ?? '')),
            'nutrition_basis' => trim((string) ($_POST['nutrition_basis'] ?? '')),
            'serving_size' => trim((string) ($_POST['serving_size'] ?? '')),
            'servings_per_pack' => ($_POST['servings_per_pack'] ?? '') !== '' ? (float) $_POST['servings_per_pack'] : null,
            'calories' => ($_POST['calories'] ?? '') !== '' ? (float) $_POST['calories'] : null,
            'protein_g' => ($_POST['protein_g'] ?? '') !== '' ? (float) $_POST['protein_g'] : null,
            'carbs_g' => ($_POST['carbs_g'] ?? '') !== '' ? (float) $_POST['carbs_g'] : null,
            'fat_g' => ($_POST['fat_g'] ?? '') !== '' ? (float) $_POST['fat_g'] : null,
            'sugar_g' => ($_POST['sugar_g'] ?? '') !== '' ? (float) $_POST['sugar_g'] : null,
            'sodium_mg' => ($_POST['sodium_mg'] ?? '') !== '' ? (float) $_POST['sodium_mg'] : null,
        ];
        $oldStmt = $pdo->prepare('SELECT * FROM product_details WHERE product_id = ?');
        $oldStmt->execute([$productId]);
        $old = $oldStmt->fetch() ?: [];
        $stmt = $pdo->prepare('INSERT INTO product_details (product_id, description, ingredients, allergens, brand, unit_name, net_weight, storage_notes, nutrition_basis, serving_size, servings_per_pack, calories, protein_g, carbs_g, fat_g, sugar_g, sodium_mg, review_status, updated_by, verified_by, verified_at, rejected_reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE description=VALUES(description), ingredients=VALUES(ingredients), allergens=VALUES(allergens), brand=VALUES(brand), unit_name=VALUES(unit_name), net_weight=VALUES(net_weight), storage_notes=VALUES(storage_notes), nutrition_basis=VALUES(nutrition_basis), serving_size=VALUES(serving_size), servings_per_pack=VALUES(servings_per_pack), calories=VALUES(calories), protein_g=VALUES(protein_g), carbs_g=VALUES(carbs_g), fat_g=VALUES(fat_g), sugar_g=VALUES(sugar_g), sodium_mg=VALUES(sodium_mg), review_status=VALUES(review_status), updated_by=VALUES(updated_by), verified_by=VALUES(verified_by), verified_at=VALUES(verified_at), rejected_reason=VALUES(rejected_reason)');
        $userId = currentPosUserId();
        $stmt->execute(array_merge([$productId], array_map(fn($v) => $v === '' ? null : $v, array_values($fields)), [$status, $userId, $status === 'verified' ? $userId : null, $status === 'verified' ? date('Y-m-d H:i:s') : null, $status === 'rejected' ? $reason : null]));
        $audit = $pdo->prepare('INSERT INTO product_detail_audit (product_id, changed_by, action, source, review_status, before_json, after_json) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $audit->execute([$productId, $userId, $action, 'ai', $status, json_encode($old), json_encode(array_merge($fields, ['review_status' => $status]))]);
        header('Location: product_details_edit.php?product_id=' . rawurlencode(enc_id($productId)));
        exit;
    }
}
$detailsStmt = $pdo->prepare('SELECT description, ingredients, allergens, brand, unit_name, net_weight, storage_notes, nutrition_basis, serving_size, servings_per_pack, calories, protein_g, carbs_g, fat_g, sugar_g, sodium_mg, nutrition_source, nutrition_source_url, review_status, rejected_reason FROM product_details WHERE product_id = ?');
$detailsStmt->execute([$productId]);
$details = $detailsStmt->fetch() ?: array_fill_keys(['description','ingredients','allergens','brand','unit_name','net_weight','storage_notes','nutrition_basis','serving_size','servings_per_pack','calories','protein_g','carbs_g','fat_g','sugar_g','sodium_mg','nutrition_source','nutrition_source_url','review_status','rejected_reason'], '');
$isWebNutrition = ($details['nutrition_source'] ?? '') === 'web';
$imageStmt = $pdo->prepare("SELECT after_json FROM product_detail_audit WHERE product_id = ? AND action = 'ai_extract' ORDER BY audit_id DESC LIMIT 1");
$imageStmt->execute([$productId]);
$auditAfter = json_decode((string) $imageStmt->fetchColumn(), true);
$labelImage = is_array($auditAfter) && preg_match('/^[a-f0-9]{64}\.(?:jpg|png)$/', (string) ($auditAfter['image'] ?? '')) ? $auditAfter['image'] : '';
$productImageName = basename((string) ($product['image'] ?? ''));
$productImagePath = __DIR__ . '/uploads/products/' . $productImageName;
$productImageMime = is_file($productImagePath) ? (new finfo(FILEINFO_MIME_TYPE))->file($productImagePath) : '';
$productImage = preg_match('/^product_[a-f0-9]{16}\.(?:jpe?g|png)$/i', $productImageName)
    && in_array($productImageMime, ['image/jpeg', 'image/png'], true)
    ? $productImageName
    : '';
$webFields = is_array($auditAfter) && is_array($auditAfter['web_fields'] ?? null) ? $auditAfter['web_fields'] : [];
$webSearchWarnings = is_array($auditAfter) && is_array($auditAfter['warnings'] ?? null)
    ? array_values(array_filter($auditAfter['warnings'], static function ($warning): bool {
        return is_string($warning) && preg_match('/description|ingredients|allergen|package size|product results|product details|online product lookup/i', $warning);
    }))
    : [];
if (is_array($auditAfter) && !empty($auditAfter['web_search_used'])) {
    if (!in_array('ingredients', $webFields, true)) {
        $webSearchWarnings[] = 'No complete ingredients list was confirmed for this exact product and package size.';
    }
    if (!in_array('allergens', $webFields, true)) {
        $webSearchWarnings[] = 'No explicit allergen statement was confirmed for this exact product and package size.';
    }
}
$webSourceUrl = is_array($auditAfter) ? (string) ($auditAfter['web_source_url'] ?? '') : '';
$nutritionSearchNote = is_array($auditAfter) && !empty($auditAfter['nutrition_searched'])
    ? trim((string) ($auditAfter['nutrition_note'] ?? ''))
    : '';
$nutritionSourceUrl = (string) ($details['nutrition_source_url'] ?? '');
$webFieldLabels = [
    'description' => 'Description',
    'ingredients' => 'Ingredients',
    'allergens' => 'Allergen information',
    'storage_notes' => 'Storage notes',
    'unit_name' => 'Package unit',
    'net_weight' => 'Package size',
];
$webFieldNames = array_values(array_filter(array_map(static function ($field) use ($webFieldLabels): ?string {
    return $webFieldLabels[(string) $field] ?? null;
}, $webFields)));
$safeWebSourceUrl = filter_var($webSourceUrl, FILTER_VALIDATE_URL)
    && strtolower((string) parse_url($webSourceUrl, PHP_URL_SCHEME)) === 'https'
    ? $webSourceUrl
    : '';
$safeNutritionSourceUrl = filter_var($nutritionSourceUrl, FILTER_VALIDATE_URL)
    && strtolower((string) parse_url($nutritionSourceUrl, PHP_URL_SCHEME)) === 'https'
    ? $nutritionSourceUrl
    : '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Review AI details</title>
    <link rel="stylesheet" href="style.css?v=<?= @filemtime(__DIR__ . '/style.css') ?: time() ?>">
    <style>
        .review-intro { display:flex; align-items:center; gap:14px; min-height:75px; margin:-24px -32px 0; padding:18px 32px; background:var(--white); border-bottom:1px solid var(--line); box-shadow:0 2px 8px rgba(18,60,92,.04); }
        .review-intro .topbar-icon { flex:0 0 38px; }
        .review-intro .page-header { display:grid; gap:2px; }
        .review-intro h1 { margin:0; color:var(--text-accent); font-family:var(--font-display); font-size:20px; font-weight:700; line-height:1.25; }
        .review-intro .page-subtitle { margin:0; color:var(--ink-soft); font-size:12px; line-height:1.4; }
        .review-product { margin:0; padding:11px 14px; border-left:3px solid var(--green-mid); border-radius:0 var(--radius-sm) var(--radius-sm) 0; background:var(--green-pale); color:var(--ink-soft); font-size:13px; line-height:1.5; }
        .review-product strong { color:var(--text-accent); }
        .review-notices { display:grid; gap:10px; }
        .source-alert { display:block; margin:0; padding:14px 16px; border:1px solid var(--line); border-left:3px solid var(--mustard); border-radius:var(--radius-md); font-size:13px; line-height:1.5; }
        .source-alert__title { display:block; margin-bottom:3px; color:var(--text-accent); font-size:13px; font-weight:700; }
        .source-alert__copy { margin:0; }
        .source-alert__fields { display:flex; flex-wrap:wrap; gap:6px; margin-top:9px; }
        .source-alert__fields .badge { white-space:nowrap; }
        .source-alert__link { display:inline-block; margin-top:7px; color:var(--green-mid); font-size:13px; font-weight:700; }
        .review-grid { display:grid; grid-template-columns:minmax(220px, .6fr) minmax(0, 1.4fr); gap:20px; align-items:start; }
        .review-photo { min-height:260px; display:flex; align-items:center; justify-content:center; padding:14px; border:1px solid var(--line); border-radius:var(--radius-md); background:var(--cream); }
        .review-photo img { display:block; width:100%; max-height:380px; object-fit:contain; border-radius:8px; }
        .review-photo .field-hint { text-align:center; }
        .review-form { display:grid; gap:14px; min-width:0; }
        .review-form-grid { display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:12px; }
        .review-form .field-group { margin:0; }
        .review-form textarea.field-input { min-height:88px; resize:vertical; line-height:1.5; }
        .review-actions { display:flex; justify-content:flex-end; gap:10px; flex-wrap:wrap; padding-top:12px; border-top:1px solid var(--line); }
        .review-actions .action-btn { min-width:138px; }
        @media (max-width:980px) { .review-grid { grid-template-columns:minmax(0, 1fr); } .review-photo { min-height:210px; } .review-photo img { max-height:300px; } }
        @media (max-width:620px) {
            .review-intro { min-height:64px; margin:-16px -14px 0; padding:14px; }
            .review-intro .topbar-icon { flex-basis:34px; width:34px; height:34px; }
            .review-intro h1 { font-size:18px; }
            .review-form-grid { grid-template-columns:1fr; }
            .review-form-grid > .field-group[style] { grid-column:1 / -1 !important; }
            .review-actions .action-btn { width:100%; }
        }
        @media (min-width:621px) and (max-width:900px) {
            .review-intro { margin-right:-18px; margin-left:-18px; padding-right:18px; padding-left:18px; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <?php $active = "products"; include 'includes/sidebar.php'; ?>
    <div class="main-area">
        <main class="content">
            <div class="review-intro">
                <div class="topbar-icon" aria-hidden="true"><svg class="nav-icon" viewBox="0 0 20 20" fill="none"><path d="M6 3.5h5.5L15 7v9.5H5V3.5h1Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"></path><path d="M11 3.8V7h3.2M7.7 10h4.8M7.7 12.8h4.8" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"></path></svg></div>
                <div class="page-header"><h1>AI details review</h1><p class="page-subtitle">Review extracted label data before publishing it to customers.</p></div>
            </div>
            <p class="review-product">Product: <strong><?= clean($product['product_name']) ?></strong> — review every extracted value before publishing.</p>
            <div class="review-notices">
            <?php if (($details['nutrition_source'] ?? '') === 'web'): ?>
                <div class="alert alert--warning source-alert">
                    <strong class="source-alert__title">Nutrition came from an online source</strong>
                    <p class="source-alert__copy">These values were not read from this product's label. Check them against the package before saving or publishing. This item will stay in review.</p>
                    <?php if ($safeNutritionSourceUrl !== ''): ?><a class="source-alert__link" href="<?= clean($safeNutritionSourceUrl) ?>" target="_blank" rel="noopener">Open nutrition source</a><?php endif; ?>
                </div>
            <?php elseif ($webFieldNames): ?>
                <div class="alert alert--warning source-alert">
                    <strong class="source-alert__title">AI found some details online</strong>
                    <p class="source-alert__copy">These details did not come from reading the product label. Compare them with the actual package before saving or publishing. The product stays in review until an authorized reviewer checks it.</p>
                    <div class="source-alert__fields"><?php foreach ($webFieldNames as $fieldLabel): ?><span class="badge badge--warning"><?= clean($fieldLabel) ?></span><?php endforeach; ?></div>
                    <?php if ($safeWebSourceUrl !== ''): ?><a class="source-alert__link" href="<?= clean($safeWebSourceUrl) ?>" target="_blank" rel="noopener">Open the online source</a><?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($nutritionSearchNote !== '' && empty($details['nutrition_source'])): ?><div class="alert alert--warning source-alert"><strong class="source-alert__title">Nutrition details were not filled</strong><p class="source-alert__copy"><?= clean($nutritionSearchNote) ?></p></div><?php endif; ?>
            <?php if ($webSearchWarnings): ?><div class="alert alert--warning source-alert"><strong class="source-alert__title">Some product details were not confirmed online</strong><p class="source-alert__copy"><?= clean(implode(' ', array_unique($webSearchWarnings))) ?> Check the package label directly before verifying these fields.</p></div><?php endif; ?>
            <?php if (!empty($error)): ?><div class="alert alert--error"><?= clean($error) ?></div><?php endif; ?>
            </div>
            <section class="panel">
                <div class="panel-header"><div><h2>Extracted label information</h2><span class="field-hint">Confirm each value before saving, verifying, or rejecting this product.</span></div></div>
                <div class="panel-body">
                    <div class="review-grid">
                        <div class="review-photo">
                            <?php if ($labelImage): ?><img src="label_photo.php?f=<?= clean($labelImage) ?>" alt="Original product label photo"><?php elseif ($productImage): ?><img src="uploads/products/<?= rawurlencode($productImage) ?>" alt="Product front photo used for AI lookup"><?php else: ?><span class="field-hint">No product photo is on file for this extraction.</span><?php endif; ?>
                        </div>
                        <form method="post" class="review-form">
                            <?php csrfField(); ?>
                            <input type="hidden" name="product_id" value="<?= clean(enc_id($productId)) ?>">
                            <?php foreach (['description'=>'Description','ingredients'=>'Ingredients','allergens'=>'Allergens','storage_notes'=>'Storage notes'] as $name=>$label): ?>
                                <div class="field-group"><label class="field-label" for="review-<?= $name ?>"><?= $label ?><?php if (in_array($name, $webFields, true)): ?> <span class="badge badge--warning">WEB</span><?php endif; ?></label><textarea class="field-input" id="review-<?= $name ?>" name="<?= $name ?>"><?= clean($details[$name]) ?></textarea></div>
                            <?php endforeach; ?>
                            <div class="review-form-grid">
                                <?php foreach (['brand'=>'Brand','unit_name'=>'Unit','net_weight'=>'Net weight','serving_size'=>'Serving size','servings_per_pack'=>'Servings per pack','calories'=>'Calories','protein_g'=>'Protein (g)','carbs_g'=>'Carbs (g)','fat_g'=>'Fat (g)','sugar_g'=>'Sugar (g)','sodium_mg'=>'Sodium (mg)'] as $name=>$label): ?>
                                    <div class="field-group"><label class="field-label" for="review-<?= $name ?>"><?= $label ?><?php if (in_array($name, $webFields, true)): ?> <span class="badge badge--warning">WEB</span><?php endif; ?></label><input class="field-input" id="review-<?= $name ?>" name="<?= $name ?>" value="<?= clean($details[$name]) ?>"></div>
                                <?php endforeach; ?>
                            </div>
                            <div class="review-form-grid">
                                <div class="field-group"><label class="field-label" for="review-nutrition-basis">Nutrition basis</label><select class="field-input" id="review-nutrition-basis" name="nutrition_basis"><option value="">Not listed</option><option value="per_serving" <?= $details['nutrition_basis']==='per_serving'?'selected':'' ?>>Per serving</option><option value="per_100g" <?= $details['nutrition_basis']==='per_100g'?'selected':'' ?>>Per 100g</option></select></div>
                                <div class="field-group" style="grid-column:span 2"><label class="field-label" for="review-rejected-reason">Rejection reason <span class="field-hint">(required only when rejecting)</span></label><textarea class="field-input" id="review-rejected-reason" name="rejected_reason" placeholder="Explain what needs to be corrected"></textarea></div>
                            </div>
                            <div class="review-actions"><button class="action-btn action-btn--secondary" name="action" value="draft">Save for review</button><?php if (!$isWebNutrition): ?><button class="action-btn" name="action" value="verify">Verify and publish</button><?php endif; ?><button class="action-btn action-btn--danger" name="action" value="reject">Reject</button></div>
                        </form>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>
</body>
</html>
