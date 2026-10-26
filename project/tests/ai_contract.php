<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only\n"); exit(1); }
$root = dirname(__DIR__);
$failures = [];
function check(bool $ok, string $message): void { global $failures; if (!$ok) $failures[] = $message; }
function source(string $file): string { global $root; $data = file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file)); if ($data === false) throw new RuntimeException($file); return $data; }
$ingest = source('ai_product_ingest.php');
check(strpos($ingest, "\$status = (\$frontPhoto || \$nutritionSource === 'web' || \$webSearchUsed || !empty(\$issues)) ? 'needs_review' : 'ai_filled';") !== false, 'front-photo and web-derived data must remain in review');
check(strpos($ingest, 'ai_gemini_search_product_info') !== false && strpos($ingest, 'ai_structure_product_info_text') !== false, 'Panel ingest must support optional web product enrichment');
check(strpos($ingest, 'SELECT p.image, p.product_name, d.net_weight FROM products p LEFT JOIN product_details d') !== false
    && strpos($ingest, '$searchPackageSize') !== false, 'front-photo search must include the product name and saved package size as matching hints');
check(strpos($ingest, "\$nutritionSizeHint") !== false && strpos($ingest, 'exact brand and product variant') !== false, 'web nutrition must be matched to the exact product variant');
check(strpos($ingest, "\$matchesExactIdentity(\$productInfo['raw'])") !== false, 'web product details must be gated by exact-product evidence');
check(strpos($ingest, "function ai_ingest_text_value") !== false && strpos($ingest, "\$data['ingredients'] = ai_ingest_text_value") !== false, 'AI ingredient lists must be serialized before writing text fields');
check(strpos($ingest, 'No complete ingredients list was found for this exact product') !== false && strpos($ingest, 'No explicit allergen statement was found for this exact product') !== false, 'missing exact-product ingredients and allergen data must be explained instead of guessed');
check(strpos($ingest, 'Package size could not be read from the front photo or saved product data') !== false, 'a missing package-size hint must be explained when exact product search results cannot be matched');
check(strpos($ingest, "ai_ingest_response(false, ['error' => 'No reliable web nutrition") === false, 'missing web nutrition must not abort front-photo identification');
check(strpos($ingest, '$hasPositiveNutrition') !== false && strpos($ingest, '&& $hasPositiveNutrition') !== false, 'all-zero web nutrition output must not be written as verified facts');
check(strpos($ingest, '$hasExactNutritionCitation') !== false && strpos($ingest, 'in_array($structuredSourceUrl, $searchCitations, true)') !== false, 'web nutrition values must cite a source included in the exact-product search results');
check(strpos($ingest, 'No exact product description was found for this package size') !== false, 'missing exact-product descriptions must be reported rather than mistaken for an AI request failure');
check(strpos($ingest, '$replacePreviousWebNutrition') !== false
    && strpos($ingest, '$field . \'=COALESCE(VALUES(\'') !== false, 'partial AI responses must preserve existing/manual nutrition values while replacing stale AI web values');
check(strpos($ingest, "'partial' => \$partialEnrichment") !== false && strpos($ingest, "'warnings' => \$enrichmentWarnings") !== false, 'partial front-photo enrichment must be reported to the UI');
check(strpos($ingest, "'nutrition_searched' => \$frontPhoto") !== false && strpos($ingest, "'nutrition_note' => \$nutritionSearchMessage") !== false, 'front-photo nutrition search outcome must be recorded for review');
check(strpos($ingest, "product_detail_audit") !== false && strpos($ingest, "'ai_extract'") !== false, 'AI ingest must audit writes');
check(strpos($ingest, 'beginTransaction') !== false && strpos($ingest, 'rollBack') !== false, 'AI ingest must be transactional');
check(strpos($ingest, 'preview_only') !== false && strpos($ingest, 'suggested_name') !== false && strpos($ingest, 'suggested_category') !== false, 'front-photo AI preview must return suggested product name/category without creating a product row');
$public = source('includes/public_bootstrap.php');
check(preg_match('/^[ \t]+function pub_/m', $public) === 0, 'public helpers must not be nested');
check(strpos($public, 'function pub_clean') === false && strpos($public, 'function pub_peso') === false, 'duplicate public helpers must be removed');
$ai = source('includes/ai.php');
require_once $root . '/includes/ai.php';
$originalEnv = [];
foreach (['AI_PROVIDER', 'GROQ_API_KEY', 'OPENAI_API_KEY', 'OPENAI_BASE_URL'] as $key) {
    $originalEnv[$key] = getenv($key);
}
putenv('AI_PROVIDER=groq');
putenv('GROQ_API_KEY=test-groq-key');
putenv('OPENAI_API_KEY=test-legacy-key');
putenv('OPENAI_BASE_URL=');
check(ai_provider() === 'groq', 'Groq must be selectable as an AI provider');
check(ai_api_key() === 'test-groq-key', 'Groq provider must use GROQ_API_KEY');
check(ai_openai_base_url('groq') === 'https://api.groq.com/openai/v1', 'Groq must use its OpenAI-compatible endpoint by default');
putenv('GROQ_API_KEY=');
check(ai_api_key() === 'test-legacy-key', 'Groq must preserve legacy OPENAI_API_KEY configuration when GROQ_API_KEY is empty');
putenv('AI_PROVIDER=openai');
check(ai_openai_base_url('openai') === 'https://api.openai.com/v1', 'OpenAI must keep its default API endpoint');
foreach ($originalEnv as $key => $value) {
    putenv($key . ($value === false ? '' : '=' . $value));
}
check(ai_is_openai_compatible('groq') && ai_is_openai_compatible('openai'), 'Groq and OpenAI must use the OpenAI-compatible request format');
$extract = static function (string $name) use ($ai): string {
    return preg_match('/function ' . preg_quote($name, '/') . '\b(.*?)(?=\nfunction |\z)/s', $ai, $matches) ? $matches[1] : '';
};
$searchNutrition = $extract('ai_gemini_search_nutrition');
$searchProduct = $extract('ai_gemini_search_product_info');
$structureNutrition = $extract('ai_structure_nutrition_text');
$structureProduct = $extract('ai_structure_product_info_text');
check(strpos($searchNutrition, 'google_search') !== false, 'nutrition search must use tools');
check(strpos($searchProduct, 'google_search') !== false, 'product-info search must use tools');
check(strpos($searchProduct, "' Philippines product label ingredients allergens manufacturer'") !== false, 'Tavily product-info searches must use concise Philippine-market label terms');
check(ai_tavily_result_matches_product('Nestle', 'MILO Chocomalt Milk Drink 110ml', 'Nestlé Milo Chocomalt Milk Drink 110mL', 'https://example.ph/milo-110ml'), 'exact Philippine product and pack-size results must be eligible for enrichment');
check(!ai_tavily_result_matches_product('Nestle', 'MILO Chocomalt Milk Drink 110ml', 'Nestle Milo Powdered Drink Refill 600g', 'https://example.com/milo-powder'), 'search results for a different Milo product or package size must be rejected');
check(ai_tavily_result_matches_product('Lucky Me!', 'Pancit Canton Sweet & Spicy 65 g', 'Lucky Me Pancit Canton Sweet & Spicy 6 pack 65g', 'https://example.ph/lucky-me-pancit-canton-65g'), 'stored package size must allow exact 65 g Pancit Canton sources');
check(!ai_tavily_result_matches_product('Lucky Me!', 'Pancit Canton Sweet & Spicy 65 g', 'Lucky Me Instant Pancit Canton Sweet & Spicy Nutrition Facts', 'https://example.com/lucky-me-pancit-canton-nutrition'), 'Pancit Canton nutrition for an unspecified or different package size must be rejected');
check(strpos($structureNutrition, "'response_json' => true") !== false, 'nutrition structuring must use JSON response mode');
check(strpos($structureProduct, "'response_json' => true") !== false, 'product-info structuring must use JSON response mode');
check(strpos($searchNutrition, 'response_json') === false && strpos($searchProduct, 'response_json') === false, 'search calls must not use JSON response mode');
check(strpos($structureNutrition, 'tools') === false && strpos($structureProduct, 'tools') === false, 'structuring calls must not use tools');
check(strpos($ai, "groundingMetadata']['groundingChunks") !== false, 'citation extraction must read grounding chunks');
$callStart = strpos($ai, 'function ai_call(');
$callEnd = strpos($ai, "\nfunction ai_gemini_text(", $callStart);
$callFunction = $callStart !== false && $callEnd !== false ? substr($ai, $callStart, $callEnd - $callStart) : '';
check(strpos($ai, "status IN ('ok', 'error', 'rate_limited', 'no_model')") !== false, 'daily AI quotas must count failed provider attempts as well as successes');
check(strpos($ai, "status IN ('ok', 'error', 'rate_limited', 'no_model')") !== false
    && strpos($ai, "AND status IN ('ok', 'error', 'rate_limited', 'no_model')") !== false, 'search quotas must count real failed attempts but exclude cache hits');
check(strpos($callFunction, '$cached = ai_cache_get($hash);') < strpos($callFunction, 'if (!ai_check_cap())'), 'cache hits must not consume provider-call quota');
check(strpos($ai, 'if (!ai_check_search_cap($task))') !== false, 'provider web-search calls must enforce the daily search cap');
$tavilyStart = strpos($ai, 'function ai_tavily_search(');
$tavilyEnd = strpos($ai, "\nfunction ai_structure_product_info_text(", $tavilyStart);
$tavilyFunction = $tavilyStart !== false && $tavilyEnd !== false ? substr($ai, $tavilyStart, $tavilyEnd - $tavilyStart) : '';
check(strpos($tavilyFunction, 'if (!ai_check_search_cap($task))') !== false, 'Tavily search calls must enforce the shared daily search cap');
$ingestLimit = source('ai_product_ingest.php');
check(strpos($ingestLimit, 'fetchColumn() >= 60') !== false && strpos($ingestLimit, 'limit of 60 AI product reads per hour') !== false, 'AI product-ingest attempts must be capped at sixty per user per hour');
check(strpos($ingestLimit, 'INTERVAL 10 SECOND') !== false && strpos($ingestLimit, 'ai_ingest_product_cooldowns') !== false, 'repeated AI reads for one product must have an atomic 10-second cooldown');
check(strpos($ingestLimit, 'An AI read for this product was just attempted') !== false, 'duplicate product runs must report the cooldown instead of calling the provider');
$detailsPage = source('product_details_edit.php');
check(strpos($detailsPage, "pageUrl.searchParams.delete('ai')") !== false && strpos($detailsPage, 'var running = false') !== false, 'automatic AI ingestion must not repeat on refresh or concurrently');
check(strpos($detailsPage, "'Retry AI fill'") !== false, 'AI retry must require an explicit user action');
check(strpos($detailsPage, "data.append('photo_type', 'front')") !== false, 'front product photo must be the default AI mode');
$reviewPage = source('product_ai_review.php');
check(strpos($reviewPage, 'Nutrition details were not filled') !== false && strpos($reviewPage, '$nutritionSearchNote') !== false, 'review page must explain why exact-product nutrition remains blank');
check(strpos($reviewPage, '$webSearchWarnings') !== false && strpos($reviewPage, 'Some product details were not confirmed online') !== false, 'review page must explain when exact-product ingredients or allergen information is unavailable');
check(strpos($reviewPage, 'Product front photo used for AI lookup') !== false, 'review page must display the saved front photo used by AI');
if ($failures) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: $failure\n"); exit(1); }
echo "ai_contract: OK\n";
