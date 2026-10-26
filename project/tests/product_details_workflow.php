<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}
/**
 * Read-only contract test for the product-details review workflow.
 */
require_once __DIR__ . '/../includes/config.php';

$requiredColumns = ['review_status', 'verified_by', 'verified_at', 'rejected_reason'];
$columnStmt = $pdo->query("SHOW COLUMNS FROM product_details");
$columns = [];
foreach ($columnStmt->fetchAll() as $column) {
    $columns[(string) $column['Field']] = (string) $column['Type'];
}
foreach ($requiredColumns as $column) {
    if (!isset($columns[$column])) {
        throw new RuntimeException("Missing product_details column: {$column}");
    }
}
if (strpos($columns['review_status'], 'draft') === false
    || strpos($columns['review_status'], 'needs_review') === false
    || strpos($columns['review_status'], 'verified') === false
    || strpos($columns['review_status'], 'rejected') === false) {
    throw new RuntimeException('review_status does not contain the complete state set.');
}

$auditColumnStmt = $pdo->query("SHOW COLUMNS FROM product_detail_audit");
$auditColumns = array_map(static function ($column) {
    return (string) $column['Field'];
}, $auditColumnStmt->fetchAll());
foreach (['action', 'review_status'] as $column) {
    if (!in_array($column, $auditColumns, true)) {
        throw new RuntimeException("Missing product_detail_audit column: {$column}");
    }
}

$duplicateStmt = $pdo->query('
    SELECT product_id, COUNT(*) AS detail_count
    FROM product_details
    GROUP BY product_id
    HAVING COUNT(*) > 1
');
if ($duplicateStmt->fetch()) {
    throw new RuntimeException('Duplicate product detail rows detected.');
}

$transitions = [
    'draft' => ['submit' => 'needs_review', 'save' => 'draft'],
    'needs_review' => ['verify' => 'verified', 'reject' => 'rejected', 'save' => 'needs_review'],
    'rejected' => ['resubmit' => 'needs_review', 'save' => 'needs_review'],
    'verified' => ['save' => 'needs_review'],
];
foreach ($transitions as $from => $actions) {
    foreach ($actions as $action => $to) {
        if ($to === 'verified' && $action !== 'verify') {
            throw new RuntimeException('Verified status has a non-human transition.');
        }
    }
}

$editorSource = file_get_contents(__DIR__ . '/../product_details_edit.php');
if ($editorSource === false) {
    throw new RuntimeException('Could not read product_details_edit.php.');
}
foreach (['requireCSRF()', 'value="submit"', 'value="verify"', 'value="reject"', 'value="resubmit"'] as $requiredText) {
    if (strpos($editorSource, $requiredText) === false) {
        throw new RuntimeException("Product-details editor is missing {$requiredText}.");
    }
}
if (strpos($editorSource, "(\$_SESSION['role'] ?? '') !== 'admin'") === false) {
    throw new RuntimeException('Verification/rejection authorization guard is missing.');
}
$productsSource = file_get_contents(__DIR__ . '/../products.php');
if ($productsSource === false
    || strpos($productsSource, 'product_details_review_status') === false
    || strpos($productsSource, 'product_details_edit.php?product_id=') === false
    || strpos($productsSource, 'clean($detailsReviewLabel)') === false) {
    throw new RuntimeException('Product list does not expose the details review status.');
}

$publicDetailSource = file_get_contents(__DIR__ . '/../product_detail.php');
if ($publicDetailSource === false || strpos($publicDetailSource, "d.review_status = 'verified'") === false) {
    throw new RuntimeException('Public product detail page is not enforcing verified review status.');
}

echo "Product-details workflow contract passed.\n";
