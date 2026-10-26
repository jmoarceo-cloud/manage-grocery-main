<?php
require_once __DIR__ . '/includes/config.php';
requireInventoryStaff();
require_once __DIR__ . '/includes/ai.php';
header('Content-Type: application/json; charset=utf-8');
requireCSRFJson();

$productId = dec_id((string) ($_POST['product_id'] ?? ''));
$productId = $productId > 0 ? $productId : 0;
$previewOnly = !empty($_POST['preview_only']);

$rateTable = 'CREATE TABLE IF NOT EXISTS ai_ingest_rate_limits (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    hit_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ai_ingest_user_hit (user_id, hit_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
$pdo->exec($rateTable);
$rateCutoff = date('Y-m-d H:i:s', time() - 3600);
$rateStmt = $pdo->prepare('SELECT COUNT(*) FROM ai_ingest_rate_limits WHERE user_id = ? AND hit_at >= ?');
$rateStmt->execute([currentPosUserId(), $rateCutoff]);
if ((int) $rateStmt->fetchColumn() >= 60) {
    ai_ingest_response(false, ['error' => "You've reached the limit of 60 AI product reads per hour. Please try again later."]);
}
$pdo->exec('
    CREATE TABLE IF NOT EXISTS ai_ingest_product_cooldowns (
        user_id INT NOT NULL,
        product_id INT NOT NULL,
        last_attempt_at DATETIME NOT NULL,
        PRIMARY KEY (user_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
');
$cooldownStmt = $pdo->prepare(
    'INSERT IGNORE INTO ai_ingest_product_cooldowns (user_id, product_id, last_attempt_at) VALUES (?, ?, NOW())'
);
$cooldownStmt->execute([currentPosUserId(), $productId]);
$cooldownInserted = $cooldownStmt->rowCount() === 1;
$pdo->beginTransaction();
try {
    $cooldownRead = $pdo->prepare(
        'SELECT last_attempt_at, last_attempt_at > DATE_SUB(NOW(), INTERVAL 10 SECOND) AS is_recent
         FROM ai_ingest_product_cooldowns WHERE user_id = ? AND product_id = ? FOR UPDATE'
    );
    $cooldownRead->execute([currentPosUserId(), $productId]);
    $cooldown = $cooldownRead->fetch();
    if (!$cooldownInserted && !empty($cooldown['is_recent'])) {
        $pdo->rollBack();
        ai_ingest_response(false, ['error' => 'An AI read for this product was just attempted. Wait 10 seconds before retrying.']);
    }
    $pdo->prepare(
        'UPDATE ai_ingest_product_cooldowns SET last_attempt_at = NOW() WHERE user_id = ? AND product_id = ?'
    )->execute([currentPosUserId(), $productId]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}
$pdo->prepare('INSERT INTO ai_ingest_rate_limits (user_id) VALUES (?)')->execute([currentPosUserId()]);
$pdo->exec('DELETE FROM ai_ingest_rate_limits WHERE hit_at < DATE_SUB(NOW(), INTERVAL 2 HOUR)');

function ai_ingest_response(bool $ok, array $extra = []): void
{
    echo json_encode(array_merge(['ok' => $ok], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_ingest_text_value($value): ?string
{
    if (is_array($value)) {
        $items = array_values(array_filter(array_map(static function ($item): string {
            return is_scalar($item) ? trim((string) $item) : '';
        }, $value), static function (string $item): bool {
            return $item !== '';
        }));
        return $items ? implode(', ', $items) : null;
    }
    return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
}

function ai_guess_category_for_name(string $name, array $allowedCategories): string
{
    $normalized = strtolower(trim($name));
    if ($normalized === '') {
        return '';
    }

    $categoryKeywords = [
        'Beverages' => ['beer', 'gin', 'rum', 'whiskey', 'vodka', 'liquor', 'wine', 'spirits', 'soft drink', 'soda', 'juice', 'coffee', 'tea', 'energy', 'water', 'milk', 'drink'],
        'Snacks' => ['chips', 'cracker', 'cookie', 'nuts', 'snack', 'biscuits', 'chocolate', 'candy', 'wafer'],
        'Canned Goods' => ['canned', 'tuna', 'sardines', 'beans', 'corned', 'soup', 'tomato', 'vegetable'],
        'Rice & Grains' => ['rice', 'grain', 'cereal', 'oats', 'wheat'],
        'Condiments' => ['sauce', 'soy', 'vinegar', 'ketchup', 'mayo', 'seasoning', 'condiment'],
        'Bakery' => ['bread', 'bun', 'cake', 'roll', 'pastry', 'loaf'],
        'Household' => ['soap', 'detergent', 'cleaner', 'fabric', 'toilet', 'bleach', 'tissue'],
        'Personal Care' => ['shampoo', 'toothpaste', 'soap', 'powder', 'lotion', 'cream', 'bath'],
        'Frozen Goods' => ['frozen', 'ice cream', 'pizza', 'dumpling', 'meat'],
        'Fresh Produce' => ['banana', 'apple', 'orange', 'vegetable', 'carrot', 'onion', 'tomato', 'fruit'],
    ];

    foreach ($categoryKeywords as $category => $keywords) {
        if (!in_array($category, $allowedCategories, true)) {
            continue;
        }
        foreach ($keywords as $keyword) {
            if (strpos($normalized, strtolower($keyword)) !== false) {
                return $category;
            }
        }
    }

    return '';
}

$upload = $_FILES['label_image'] ?? null;
$path = '';
$mime = '';
$expectedPackageSize = '';
$imageWasUploaded = $upload
    && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
    && is_uploaded_file($upload['tmp_name'] ?? '');

if ($imageWasUploaded) {
    if ((int) $upload['size'] > 2097152) {
        ai_ingest_response(false, ['error' => 'Upload a JPEG or PNG label image up to 2 MB.']);
    }
    $extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
        ai_ingest_response(false, ['error' => 'Only JPEG and PNG images are supported.']);
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($upload['tmp_name']);
    $imageInfo = @getimagesize($upload['tmp_name']);
    $mimeByExtension = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    if (
        !in_array($mime, ['image/jpeg', 'image/png'], true)
        || $mime !== ($mimeByExtension[$extension] ?? null)
        || $imageInfo === false
        || ($imageInfo['mime'] ?? null) !== $mime
    ) {
        ai_ingest_response(false, ['error' => 'Only JPEG and PNG images are supported.']);
    }
    $dir = __DIR__ . '/uploads/ai_labels';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
        ai_ingest_response(false, ['error' => 'Could not prepare the private label upload folder.']);
    }
    $storedExtension = $mime === 'image/png' ? 'png' : 'jpg';
    $path = $dir . '/label_' . bin2hex(random_bytes(16)) . '.' . $storedExtension;
    if (!move_uploaded_file($upload['tmp_name'], $path)) {
        ai_ingest_response(false, ['error' => 'Could not store the label image.']);
    }
} else {
    if ($productId < 1) {
        ai_ingest_response(false, ['error' => 'A product image is required for automatic details.']);
    }
    $imageStmt = $pdo->prepare('SELECT p.image, p.product_name, d.net_weight FROM products p LEFT JOIN product_details d ON d.product_id = p.id WHERE p.id = ? LIMIT 1');
    $imageStmt->execute([$productId]);
    $storedProduct = $imageStmt->fetch();
    if (!$storedProduct) {
        ai_ingest_response(false, ['error' => 'Product not found.']);
    }
    $imageName = basename((string) $storedProduct['image']);
    $expectedProductName = trim((string) $storedProduct['product_name']);
    $expectedPackageSize = trim((string) ($storedProduct['net_weight'] ?? ''));
    $path = __DIR__ . '/uploads/products/' . $imageName;
    $mime = is_file($path) ? (new finfo(FILEINFO_MIME_TYPE))->file($path) : '';
    if (!is_file($path) || !in_array($mime, ['image/jpeg', 'image/png'], true)) {
        ai_ingest_response(false, ['error' => 'Automatic details require a valid JPG or PNG product image.']);
    }
}
$categories = $pdo->query('SELECT category_name FROM categories ORDER BY category_name')->fetchAll(PDO::FETCH_COLUMN);
$photoType = ($_POST['photo_type'] ?? 'nutrition_panel') === 'front' ? 'front' : 'nutrition_panel';
$nutritionSource = 'label_photo';
$nutritionSourceUrl = null;
$webSourceUrl = null;
$webFields = [];
$webSearchUsed = false;
$frontPhoto = $photoType === 'front';
$partialEnrichment = false;
$enrichmentWarnings = [];
if ($photoType === 'front') {
    $identityHint = isset($expectedProductName) && $expectedProductName !== ''
        ? "\nProduct name already entered by staff (use only as a search hint, do not treat it as visible text): " . $expectedProductName
        : '';
    $front = ai_gemini_vision(
        $path,
        'Read the front-of-package photo. Extract only text clearly visible on the package. Reply in plain text using exactly: BRAND: ...; NAME: ...; SIZE: ... . Include the product variant in NAME. SIZE is the printed package volume or weight such as 700ml or 1kg, or UNKNOWN if not visible. If brand or product name cannot be read, say UNKNOWN. Do not infer ingredients, nutrition, or facts not visible.' . $identityHint,
        ['task' => 'vision_front']
    );
    if (!$front['ok'] || !is_string($front['raw']) || trim($front['raw']) === '') {
        ai_ingest_response(false, ['error' => $front['error'] ?? 'The front photo could not be read. Retake a clear photo of the product front.']);
    }
    $brand = '';
    $name = '';
    $packageSize = '';
    $categorySuggestion = '';
    if (preg_match('/BRAND:\s*(.+?)(?:;|$)/i', $front['raw'], $match)) {
        $brand = trim($match[1]);
    }
    if (preg_match('/NAME:\s*(.+?)(?:;|$)/i', $front['raw'], $match)) {
        $name = trim($match[1]);
    }
    if (preg_match('/CATEGORY:\s*(.+?)(?:;|$)/i', $front['raw'], $match)) {
        $categorySuggestion = trim($match[1]);
    }
    if (preg_match('/SIZE:\s*(.+)$/i', $front['raw'], $match)) {
        $packageSize = trim($match[1]);
    }
    if ($brand === '' || $name === '' || stripos($brand . ' ' . $name, 'UNKNOWN') !== false) {
        if (!isset($expectedProductName) || $expectedProductName === '') {
            ai_ingest_response(false, ['error' => 'The front photo did not clearly show the brand and product name. Retake a clear front photo.']);
        }
        $brand = $brand !== '' ? $brand : $expectedProductName;
        $name = $name !== '' ? $name : $expectedProductName;
        $partialEnrichment = true;
    }
    if (in_array(strtoupper($packageSize), ['UNKNOWN', 'NOT VISIBLE', 'N/A'], true)) {
        $packageSize = '';
    }
    $searchName = $name;
    if (
        isset($expectedProductName) && $expectedProductName !== ''
        && stripos($searchName, $expectedProductName) === false
    ) {
        $searchName .= ' (' . $expectedProductName . ')';
    }
    $searchPackageSize = $packageSize;
    if ($searchPackageSize === '' && preg_match('/\b\d+(?:\.\d+)?\s?(?:ml|l|g|kg|oz)\b/i', $expectedPackageSize, $storedSizeMatch)) {
        $searchPackageSize = $storedSizeMatch[0];
    }
    if ($searchPackageSize !== '' && stripos($searchName, $searchPackageSize) === false) {
        $searchName .= ' ' . $searchPackageSize;
    }
    if ($categorySuggestion === '' || stripos($categorySuggestion, 'UNKNOWN') !== false) {
        $categorySuggestion = ai_guess_category_for_name($name, $categories);
    }

    if ($previewOnly) {
        ai_ingest_response(true, [
            'preview_only' => true,
            'suggested_name' => $name,
            'suggested_category' => $categorySuggestion !== '' ? $categorySuggestion : (count($categories) > 0 ? $categories[0] : ''),
            'suggested_brand' => $brand,
            'suggested_size' => $packageSize,
            'message' => 'Front-photo AI suggested a product name and category for review.',
        ]);
    }

    $nutritionSizeHint = $searchPackageSize;
    if (
        $nutritionSizeHint === '' && isset($expectedProductName)
        && preg_match('/\b\d+(?:\.\d+)?\s?(?:ml|l|g|kg|oz)\b/i', $expectedProductName, $sizeMatch)
    ) {
        $nutritionSizeHint = $sizeMatch[0];
    }
    $variantNameHint = trim(str_ireplace($brand, '', $name));
    if ($variantNameHint === '' && isset($expectedProductName)) {
        $variantNameHint = trim(str_ireplace($brand, '', $expectedProductName));
        if ($nutritionSizeHint !== '') {
            $variantNameHint = trim(str_ireplace($nutritionSizeHint, '', $variantNameHint));
        }
    }
    $matchesExactIdentity = static function (string $text) use ($brand, $nutritionSizeHint, $variantNameHint): bool {
        if ($brand === '' || stripos($text, $brand) === false) {
            return false;
        }
        if ($nutritionSizeHint !== '') {
            $compactText = preg_replace('/[^a-z0-9]/i', '', strtolower($text));
            $compactSize = preg_replace('/[^a-z0-9]/i', '', strtolower($nutritionSizeHint));
            return $compactSize !== '' && strpos((string) $compactText, $compactSize) !== false;
        }
        return $variantNameHint !== '' && stripos($text, $variantNameHint) !== false;
    };

    $productInfo = ai_gemini_search_product_info($brand, $searchName);
    if (!$productInfo['ok']) {
        $enrichmentWarnings[] = 'Online product lookup was unavailable.';
    }
    $productInfoMatches = $productInfo['ok'] && is_string($productInfo['raw'] ?? null)
        && $matchesExactIdentity($productInfo['raw']);
    if ($productInfo['ok'] && !$productInfoMatches && $searchPackageSize === '') {
        $enrichmentWarnings[] = 'Package size could not be read from the front photo or saved product data; confirm it from the package before retrying the lookup.';
    }
    $structuredInfo = $productInfoMatches
        ? ai_structure_product_info_text($productInfo['raw'], trim($brand . ' ' . $searchName))
        : ai_result(false, null, null, $productInfo['error'] ?? 'Product information was not found.');
    $web = $structuredInfo['ok'] && is_array($structuredInfo['data'] ?? null)
        && !empty($structuredInfo['data']['found'])
        ? $structuredInfo['data']
        : [];
    if (!$web) {
        $partialEnrichment = true;
        if ($productInfo['ok']) {
            $enrichmentWarnings[] = $productInfoMatches
                ? 'No reliable exact-product details were found online.'
                : 'Online product results did not confirm the exact brand and package variant.';
        }
    }

    $nutrition = [];
    $nutritionSearchMessage = '';
    $nutritionSearchTerm = trim($brand . ' ' . $searchName);
    $search = ai_gemini_search_nutrition(
        "Find published nutrition facts for the exact packaged product \"{$nutritionSearchTerm}\". Search for the exact brand, product, and package variant, prioritizing manufacturer data, the label, or a reputable nutrition database. " .
        "Only return nutrition values that the cited source explicitly publishes for this product; do not fill missing values with zero, estimate, or use generic category facts. " .
        "Return calories, protein, carbohydrates, fat, sugar, sodium, serving size, nutrition basis, the source URL, and a short quote or evidence snippet for the facts. " .
        "If you cannot find explicit exact-product nutrition facts, say NOT_FOUND. A store listing that only confirms product identity is not nutrition evidence.\n\nBRAND: {$brand}\nPRODUCT: {$searchName}"
    );
    if (!$search['ok']) {
        $enrichmentWarnings[] = 'Online nutrition lookup was unavailable.';
    }
    if ($search['ok'] && is_string($search['raw'] ?? null) && stripos($search['raw'], 'NOT_FOUND') === false) {
        $hasExactIdentity = $matchesExactIdentity($search['raw']);
        $structured = $hasExactIdentity
            ? ai_structure_nutrition_text($search['raw'])
            : ai_result(false, null, null, 'Search results did not confirm the exact product variant.');
        $structuredSourceUrl = trim((string) ($structured['data']['source_url'] ?? ''));
        $searchCitations = array_values(array_filter((array) ($search['citations'] ?? []), 'is_string'));
        $hasExactNutritionCitation = $structuredSourceUrl !== ''
            && in_array($structuredSourceUrl, $searchCitations, true);
        $numericNutritionFields = ['calories', 'protein_g', 'carbs_g', 'fat_g', 'sugar_g', 'sodium_mg'];
        $hasNumericNutrition = $structured['ok'] && is_array($structured['data'] ?? null)
            && count(array_filter($numericNutritionFields, static function ($field) use ($structured) {
                return isset($structured['data'][$field]) && is_numeric($structured['data'][$field]);
            })) > 0;
        $hasPositiveNutrition = $hasNumericNutrition
            && count(array_filter($numericNutritionFields, static function ($field) use ($structured) {
                return isset($structured['data'][$field]) && is_numeric($structured['data'][$field])
                    && (float) $structured['data'][$field] > 0;
            })) > 0;
        if (
            $structured['ok'] && is_array($structured['data'] ?? null) && !empty($structured['data']['found'])
            && $hasPositiveNutrition && $hasExactNutritionCitation
        ) {
            $nutrition = $structured['data'];
            $nutrition['basis'] = $nutrition['nutrition_basis'] ?? null;
            unset($nutrition['nutrition_basis']);
            $nutritionSource = 'web';
            $nutritionSourceUrl = $structuredSourceUrl;
        } elseif (!$hasExactIdentity) {
            $enrichmentWarnings[] = 'Nutrition results did not confirm the exact brand and product variant, so they were not saved.';
            $nutritionSearchMessage = 'Search results were not specific enough to confirm nutrition facts for this exact product, so the nutrition fields were left blank.';
        } elseif (!$hasExactNutritionCitation) {
            $enrichmentWarnings[] = 'The selected nutrition source was not among the exact-product search citations, so those values were not saved.';
            $nutritionSearchMessage = 'Nutrition values were left blank because the selected source did not match a cited source for this exact product and package.';
        } elseif ($hasNumericNutrition && !$hasPositiveNutrition) {
            $enrichmentWarnings[] = 'The web result returned zero for every nutrition value. Those results were not saved because the exact product facts could not be confirmed.';
            $nutritionSearchMessage = 'The online result only returned zero values for every nutrition field. Those values were not saved because the product-specific facts could not be confirmed.';
        } else {
            $enrichmentWarnings[] = 'No structured nutrition values were available for the exact product.';
            $nutritionSearchMessage = 'No explicit, reliable nutrition facts for this exact product were found, so the nutrition fields were left blank rather than guessed.';
        }
    } elseif ($search['ok']) {
        $enrichmentWarnings[] = 'No exact-product nutrition information was found online.';
        $nutritionSearchMessage = 'No nutrition facts for this exact product were found online, so the nutrition fields were left blank rather than guessed.';
    }
    if (!$search['ok']) {
        $searchError = trim((string) ($search['error'] ?? ''));
        $nutritionSearchMessage = 'The online nutrition search was unavailable'
            . ($searchError !== '' ? ': ' . $searchError : '')
            . ', so the nutrition fields were left blank rather than guessed.';
    } elseif ($nutrition && $nutritionSearchMessage === '') {
        $nutritionSearchMessage = 'Nutrition facts were found online; compare them with the package before using them.';
    }
    if (!$nutrition) {
        $partialEnrichment = true;
    }

    $webValues = [
        'description' => ai_ingest_text_value($web['description'] ?? null),
        'ingredients' => ai_ingest_text_value($web['ingredients'] ?? null),
        'allergens' => ai_ingest_text_value($web['allergens'] ?? null),
        'storage' => ai_ingest_text_value($web['storage_notes'] ?? null),
        'unit_name' => ai_ingest_text_value($web['unit_name'] ?? null),
        'net_weight' => ai_ingest_text_value($web['net_weight'] ?? null),
    ];
    if ($frontPhoto && $web) {
        if ($webValues['description'] === null) {
            $enrichmentWarnings[] = 'No exact product description was found for this package size.';
        }
        if ($webValues['ingredients'] === null) {
            $enrichmentWarnings[] = 'No complete ingredients list was found for this exact product and package size.';
        }
        if ($webValues['allergens'] === null) {
            $enrichmentWarnings[] = 'No explicit allergen statement was found for this exact product and package size.';
        }
        if ($webValues['description'] === null || $webValues['ingredients'] === null || $webValues['allergens'] === null) {
            $partialEnrichment = true;
        }
    }
    $data = [
        'name' => $name,
        'brand' => $brand,
        'net_weight' => $webValues['net_weight'] ?? ($packageSize !== '' ? $packageSize : null),
        'barcode' => null,
        'ingredients' => $webValues['ingredients'],
        'allergens' => $webValues['allergens'],
        'storage' => $webValues['storage'],
        'description' => $webValues['description'],
        'unit_name' => $webValues['unit_name'],
        'category_suggestion' => null,
        'nutrition' => $nutrition,
    ];
    $webFieldValues = [
        'description' => $webValues['description'],
        'ingredients' => $webValues['ingredients'],
        'allergens' => $webValues['allergens'],
        'storage_notes' => $webValues['storage'],
        'unit_name' => $webValues['unit_name'],
        'net_weight' => $webValues['net_weight'],
    ];
    $webFields = array_values(array_filter(array_keys($webFieldValues), static function ($field) use ($webFieldValues) {
        return $webFieldValues[$field] !== null;
    }));
    if ($packageSize !== '' && !in_array('net_weight', $webFields, true)) {
        $webFields[] = 'net_weight';
    }
    if ($web) {
        $webSourceUrl = $productInfo['citations'][0] ?? ($web['source_url'] ?? null);
    }
    $webSearchUsed = $web !== [] || $nutrition !== [];
    $nutritionSource = $nutrition ? 'web' : null;
} else {
    $prompt = "You are reading a product label photo. Extract ONLY what is visible. Return valid JSON with keys name, brand, net_weight, barcode, ingredients, allergens, storage, nutrition, category_suggestion. Nutrition keys: basis, serving_size, servings_per_pack, calories, protein_g, carbs_g, fat_g, sodium_mg, sugar_g. Allowed categories: " . implode(', ', $categories) . ". If a field is not clearly visible, return null. Do not guess or invent. If this is not a label, return {\"error\":\"not_a_label\"}.";
    $result = ai_gemini_vision($path, $prompt, ['task' => 'vision_product', 'response_json' => true]);
    if (!$result['ok'] || !is_array($result['data'])) {
        ai_ingest_response(false, ['error' => $result['error'] ?? 'Gemini did not return usable label data.']);
    }
    $data = $result['data'];
    if (isset($data['error'])) {
        ai_ingest_response(false, ['error' => 'The uploaded image was not recognized as a product label.']);
    }
    if (!empty($data['brand']) || !empty($data['name'])) {
        $info = ai_gemini_search_product_info((string) ($data['brand'] ?? ''), (string) ($data['name'] ?? ''));
        if ($info['ok'] && is_string($info['raw'] ?? null) && stripos($info['raw'], 'NOT_FOUND') === false) {
            $structuredInfo = ai_structure_product_info_text($info['raw'], trim((string) ($data['brand'] ?? '') . ' ' . (string) ($data['name'] ?? '')));
            if (!empty($structuredInfo['ok']) && is_array($structuredInfo['data']) && !empty($structuredInfo['data']['found'])) {
                $web = $structuredInfo['data'];
                foreach (['description', 'ingredients', 'storage_notes', 'unit_name', 'net_weight'] as $field) {
                    $target = $field === 'storage_notes' ? 'storage' : $field;
                    if (($data[$target] ?? null) === null || trim((string) $data[$target]) === '') {
                        if (isset($web[$field]) && trim((string) $web[$field]) !== '') {
                            $data[$target] = $web[$field];
                            $webFields[] = $field;
                        }
                    }
                }
                $webSourceUrl = $info['citations'][0] ?? ($web['source_url'] ?? null);
                $webSearchUsed = $webFields !== [];
            }
        }
    }
}
$data['ingredients'] = ai_ingest_text_value($data['ingredients'] ?? null);
$data['allergens'] = ai_ingest_text_value($data['allergens'] ?? null);
$issues = ai_validate_product($data, $categories);
$barcode = trim((string) ($data['barcode'] ?? ''));
if (!$pdo->inTransaction()) {
    $pdo->beginTransaction();
}
try {
    if ($barcode !== '') {
        $duplicate = $pdo->prepare('SELECT id, product_name FROM products WHERE barcode = ? LIMIT 1 FOR UPDATE');
        $duplicate->execute([$barcode]);
        $existing = $duplicate->fetch();
        if ($existing && (int) $existing['id'] !== $productId) {
            $pdo->rollBack();
            ai_ingest_response(true, ['duplicate' => true, 'product_id' => enc_id((int) $existing['id']), 'message' => 'This barcode already belongs to ' . $existing['product_name'] . '.']);
        }
    }

    $before = null;
    if ($productId < 1) {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $name = '[AI draft]';
        }
        $stmt = $pdo->prepare("INSERT INTO products (product_name, barcode, category, price, cost_price, stock, image, is_active) VALUES (?, ?, ?, 0, 0, 0, 'default.png', 0)");
        $stmt->execute([$name, $barcode !== '' ? $barcode : null, in_array($data['category_suggestion'] ?? '', $categories, true) ? $data['category_suggestion'] : 'Uncategorized']);
        $productId = (int) $pdo->lastInsertId();
        if ($barcode === '') {
            $pdo->prepare('UPDATE products SET barcode = ? WHERE id = ?')
                ->execute([generateInternalProductBarcode($productId), $productId]);
        }
    } else {
        $beforeStmt = $pdo->prepare('SELECT * FROM product_details WHERE product_id = ? FOR UPDATE');
        $beforeStmt->execute([$productId]);
        $before = $beforeStmt->fetch() ?: null;
    }

    $current = $pdo->prepare('SELECT review_status FROM product_details WHERE product_id = ? FOR UPDATE');
    $current->execute([$productId]);
    if ($current->fetchColumn() === 'verified') {
        $pdo->commit();
        ai_ingest_response(true, ['product_id' => enc_id($productId), 'proposed' => true, 'issues' => $issues, 'fields' => $data, 'image' => basename($path)]);
    }
    $nutrition = is_array($data['nutrition'] ?? null) ? $data['nutrition'] : [];
    $status = ($frontPhoto || $nutritionSource === 'web' || $webSearchUsed || !empty($issues)) ? 'needs_review' : 'ai_filled';
    $replacePreviousWebFields = [];
    $replacePreviousWebNutrition = false;
    $latestAuditStmt = $pdo->prepare('SELECT action, after_json FROM product_detail_audit WHERE product_id = ? ORDER BY audit_id DESC LIMIT 1');
    $latestAuditStmt->execute([$productId]);
    $latestAudit = $latestAuditStmt->fetch();
    if (($latestAudit['action'] ?? '') === 'ai_extract') {
        $previousExtraction = json_decode((string) ($latestAudit['after_json'] ?? ''), true);
        if (is_array($previousExtraction)) {
            $replacePreviousWebFields = array_values(array_intersect(
                (array) ($previousExtraction['web_fields'] ?? []),
                ['description', 'ingredients', 'allergens', 'storage_notes', 'unit_name', 'net_weight']
            ));
            $replacePreviousWebNutrition = ($previousExtraction['nutrition_source'] ?? '') === 'web';
        }
    }
    $webFieldUpdate = static function (string $field) use ($replacePreviousWebFields): string {
        return in_array($field, $replacePreviousWebFields, true)
            ? $field . '=VALUES(' . $field . ')'
            : $field . '=COALESCE(VALUES(' . $field . '), ' . $field . ')';
    };
    $nutritionFields = ['nutrition_basis', 'serving_size', 'servings_per_pack', 'calories', 'protein_g', 'carbs_g', 'fat_g', 'sugar_g', 'sodium_mg', 'nutrition_source', 'nutrition_source_url'];
    $nutritionFieldUpdates = array_map(static function (string $field) use ($replacePreviousWebNutrition): string {
        return $replacePreviousWebNutrition
            ? $field . '=VALUES(' . $field . ')'
            : $field . '=COALESCE(VALUES(' . $field . '), ' . $field . ')';
    }, $nutritionFields);
    $updates = [
        $webFieldUpdate('allergens'),
        'brand=COALESCE(VALUES(brand), brand)',
        $webFieldUpdate('unit_name'),
        $webFieldUpdate('net_weight'),
        $webFieldUpdate('storage_notes'),
        $webFieldUpdate('description'),
        $webFieldUpdate('ingredients'),
    ];
    $updates = array_merge($updates, $nutritionFieldUpdates, ['review_status=VALUES(review_status)', 'updated_by=VALUES(updated_by)']);
    $save = $pdo->prepare('
    INSERT INTO product_details
        (product_id, description, ingredients, allergens, brand, unit_name, net_weight, storage_notes,
         nutrition_basis, serving_size, servings_per_pack, calories, protein_g, carbs_g, fat_g, sugar_g, sodium_mg,
         nutrition_source, nutrition_source_url, review_status, updated_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE ' . implode(",\n        ", $updates));
    $save->execute([$productId, $data['description'] ?? null, $data['ingredients'] ?? null, $data['allergens'] ?? null, $data['brand'] ?? null, $data['unit_name'] ?? null, $data['net_weight'] ?? null, $data['storage'] ?? null, $nutrition['basis'] ?? null, $nutrition['serving_size'] ?? null, $nutrition['servings_per_pack'] ?? null, $nutrition['calories'] ?? null, $nutrition['protein_g'] ?? null, $nutrition['carbs_g'] ?? null, $nutrition['fat_g'] ?? null, $nutrition['sugar_g'] ?? null, $nutrition['sodium_mg'] ?? null, $nutritionSource, $nutritionSourceUrl, $status, currentPosUserId()]);
    $after = array_merge($data, [
        'nutrition' => $nutrition,
        'nutrition_source' => $nutritionSource,
        'nutrition_source_url' => $nutritionSourceUrl,
        'nutrition_searched' => $frontPhoto,
        'nutrition_note' => $nutritionSearchMessage,
        'web_search_used' => $webSearchUsed,
        'web_fields' => $webFields,
        'web_source_url' => $webSourceUrl,
        'partial' => $partialEnrichment,
        'warnings' => $enrichmentWarnings,
        'review_status' => $status,
        'image' => basename($path),
    ]);
    $audit = $pdo->prepare("INSERT INTO product_detail_audit (product_id, changed_by, action, source, review_status, before_json, after_json) VALUES (?, ?, 'ai_extract', 'ai', ?, ?, ?)");
    $audit->execute([$productId, currentPosUserId(), $status, json_encode($before, JSON_UNESCAPED_UNICODE), json_encode($after, JSON_UNESCAPED_UNICODE)]);
    $pdo->commit();
    $enrichmentWarningText = trim(implode(' ', array_unique($enrichmentWarnings)));
    $resultMessage = $partialEnrichment
        ? 'AI identified the front photo. Some online details were unavailable or could not be matched to the exact product; review and complete the missing fields. ' . $enrichmentWarningText
        : 'AI identified the front photo and prepared matching online details. Review them before submitting or publishing.';
    if ($frontPhoto) {
        $_SESSION['flash_ai'] = ['partial' => $partialEnrichment, 'message' => $resultMessage];
    }
    ai_ingest_response(true, [
        'product_id' => enc_id($productId),
        'status' => $status,
        'issues' => $issues,
        'fields' => $data,
        'image' => basename($path),
        'partial' => $partialEnrichment,
        'warnings' => $enrichmentWarnings,
        'message' => $resultMessage,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ai_product_ingest: ' . $e->getMessage());
    ai_ingest_response(false, ['error' => 'Could not save the extracted details.']);
}
