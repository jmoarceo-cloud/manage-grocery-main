<?php
declare(strict_types=1);

if (!defined('APP_LOADED') && PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/env.php';
loadEnv(__DIR__ . '/../.env');

function ai_pdo(?PDO $inject = null): PDO
{
    static $pdo;
    if ($inject instanceof PDO) {
        $pdo = $inject;
        return $pdo;
    }
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    global $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        'mysql:host=' . env('DB_HOST', 'localhost') . ';dbname=' . env('DB_NAME_POS', 'grocery_pos') . ';charset=utf8mb4',
        env('DB_USERNAME', ''),
        env('DB_PASSWORD', ''),
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}

function ai_result(bool $ok, ?array $data = null, ?string $raw = null, ?string $error = null, bool $cached = false, int $in = 0, int $out = 0): array
{
    return ['ok' => $ok, 'data' => $data, 'raw' => $raw, 'cached' => $cached, 'error' => $error, 'tokens' => ['in' => $in, 'out' => $out]];
}

function ai_model(): ?string
{
    $model = ai_pdo()->query('SELECT model FROM ai_settings WHERE id = 1')->fetchColumn();
    return $model !== false && trim((string) $model) !== '' ? trim((string) $model) : null;
}

function ai_provider(): string
{
    return strtolower(trim((string) env('AI_PROVIDER', 'gemini')));
}

function ai_api_key(): string
{
    $provider = ai_provider();
    if ($provider === 'groq') {
        $groqKey = trim((string) env('GROQ_API_KEY', ''));
        if ($groqKey !== '') {
            return $groqKey;
        }
        return trim((string) env('OPENAI_API_KEY', ''));
    }

    return trim((string) env($provider === 'openai' ? 'OPENAI_API_KEY' : 'GEMINI_API_KEY', ''));
}

function ai_is_openai_compatible(string $provider): bool
{
    return in_array($provider, ['openai', 'groq'], true);
}

function ai_provider_name(string $provider): string
{
    if ($provider === 'groq') {
        return 'Groq';
    }
    if (ai_is_openai_compatible($provider)) {
        return 'OpenAI';
    }
    return 'Gemini';
}

function ai_openai_base_url(string $provider): string
{
    $baseUrl = trim((string) env('OPENAI_BASE_URL', ''));
    if ($baseUrl !== '') {
        return rtrim($baseUrl, '/');
    }
    return $provider === 'groq' ? 'https://api.groq.com/openai/v1' : 'https://api.openai.com/v1';
}

function ai_settings(): array
{
    $row = ai_pdo()->query('SELECT model, daily_call_cap, daily_search_cap, enabled FROM ai_settings WHERE id = 1')->fetch();
    return $row ?: ['model' => null, 'daily_call_cap' => 100, 'daily_search_cap' => 20, 'enabled' => 0];
}

function ai_log_call(array $row): void
{
    $stmt = ai_pdo()->prepare('INSERT INTO ai_usage_log (task, model, image_hash, prompt_hash, input_tokens, output_tokens, user_id, status, error) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $row['task'] ?? 'unknown', $row['model'] ?? null, $row['image_hash'] ?? null,
        $row['prompt_hash'] ?? null, $row['input_tokens'] ?? 0, $row['output_tokens'] ?? 0,
        $row['user_id'] ?? null, $row['status'] ?? 'error', $row['error'] ?? null,
    ]);
}

function ai_cache_get(string $hash): ?array
{
    $stmt = ai_pdo()->prepare('SELECT response FROM ai_cache WHERE hash = ?');
    $stmt->execute([$hash]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return null;
    }
    $decoded = json_decode((string) $value, true);
    return is_array($decoded) ? $decoded : null;
}

function ai_cache_put(string $hash, array $result): void
{
    ai_pdo()->prepare('INSERT INTO ai_cache (hash, response) VALUES (?, ?) ON DUPLICATE KEY UPDATE response = VALUES(response)')->execute([$hash, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
}

function ai_check_cap(): bool
{
    $settings = ai_settings();
    $stmt = ai_pdo()->query("SELECT COUNT(*) FROM ai_usage_log WHERE status IN ('ok', 'error', 'rate_limited', 'no_model') AND created_at >= CURDATE()");
    return (int) $stmt->fetchColumn() < max(0, (int) $settings['daily_call_cap']);
}

function ai_check_search_cap(string $task): bool
{
    if (!in_array($task, ['nutrition_search', 'product_info_search'], true)) {
        return true;
    }
    $settings = ai_settings();
    $searchCap = max(0, (int) ($settings['daily_search_cap'] ?? 20));
    $searchStmt = ai_pdo()->prepare(
        "SELECT COUNT(*) FROM ai_usage_log
         WHERE task IN ('nutrition_search', 'product_info_search')
           AND status IN ('ok', 'error', 'rate_limited', 'no_model')
           AND created_at >= CURDATE()"
    );
    $searchStmt->execute();
    if ((int) $searchStmt->fetchColumn() < $searchCap) {
        return true;
    }
    ai_log_call(['task' => $task, 'model' => ai_model(), 'status' => 'capped', 'error' => 'Daily search cap reached']);
    return false;
}

function ai_call(array $parts, array $opts = []): array
{
    $settings = ai_settings();
    $model = ai_model();
    $task = (string) ($opts['task'] ?? 'unknown');
    if ((int) $settings['enabled'] !== 1) {
        return ai_result(false, null, null, 'AI is disabled in AI Settings.');
    }
    if ($model === null) {
        ai_log_call(['task' => $task, 'status' => 'no_model']);
        return ai_result(false, null, null, 'No ' . ai_provider_name(ai_provider()) . ' model is selected. Choose one in AI Settings.');
    }
    $key = ai_api_key();
    if ($key === '') {
        return ai_result(false, null, null, ai_provider_name(ai_provider()) . ' API key is not configured. Set ' . (ai_provider() === 'groq' ? 'GROQ_API_KEY' : (ai_provider() === 'openai' ? 'OPENAI_API_KEY' : 'GEMINI_API_KEY')) . ' in the project .env file.');
    }
    $prompt = '';
    $imageHash = null;
    foreach ($parts as $part) {
        if (isset($part['text'])) {
            $prompt .= (string) $part['text'];
        }
        if (isset($part['inline_data']['data'])) {
            $imageHash = hash('sha256', base64_decode((string) $part['inline_data']['data'], true) ?: '');
        }
    }
    $hash = hash('sha256', $model . '|' . $prompt . '|' . ($imageHash ?? ''));
    $cached = ai_cache_get($hash);
    if ($cached !== null) {
        ai_log_call(['task' => $task, 'model' => $model, 'image_hash' => $imageHash, 'prompt_hash' => hash('sha256', $prompt), 'status' => 'cached']);
        $cached['cached'] = true;
        return $cached;
    }
    if (!ai_check_cap()) {
        ai_log_call(['task' => $task, 'model' => $model, 'status' => 'capped', 'error' => 'Daily cap reached']);
        return ai_result(false, null, null, 'Today’s AI call limit has been reached.');
    }
    if (!ai_check_search_cap($task)) {
        return ai_result(false, null, null, 'Today’s web-search limit has been reached.');
    }
    $provider = ai_provider();
    if (ai_is_openai_compatible($provider)) {
        $content = [];
        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $content[] = ['type' => 'text', 'text' => (string) $part['text']];
            } elseif (isset($part['inline_data']['data'])) {
                $content[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $part['inline_data']['mime_type'] . ';base64,' . $part['inline_data']['data']]];
            }
        }
        $message = ['role' => 'user', 'content' => $content];
        $payload = ['model' => $model, 'messages' => [$message], 'temperature' => 0.1, 'max_tokens' => 2048];
        if (!empty($opts['response_json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }
        $url = ai_openai_base_url($provider) . '/chat/completions';
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $key];
    } else {
        $generationConfig = ['temperature' => 0.1, 'maxOutputTokens' => 2048];
        if (!empty($opts['response_json'])) {
            $generationConfig['responseMimeType'] = 'application/json';
        }
        $payload = ['contents' => [['parts' => $parts]], 'generationConfig' => $generationConfig];
        if (!empty($opts['tools'])) {
            $payload['tools'] = $opts['tools'];
        }
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $key];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => json_encode($payload)]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) {
        ai_log_call(['task' => $task, 'model' => $model, 'image_hash' => $imageHash, 'status' => 'error', 'error' => 'Request failed']);
        return ai_result(false, null, null, $curlError !== '' ? ai_provider_name($provider) . ' did not respond in time. Try again.' : ai_provider_name($provider) . ' request failed.');
    }
    $response = json_decode($body, true);
    $text = ai_is_openai_compatible($provider)
        ? ($response['choices'][0]['message']['content'] ?? null)
        : ($response['candidates'][0]['content']['parts'][0]['text'] ?? null);
    $providerMessage = is_array($response['error'] ?? null) ? (string) ($response['error']['message'] ?? '') : '';
    if ($code === 404) {
        $message = 'Model ' . $model . ' is unavailable. Pick a new one in AI Settings.';
        ai_log_call(['task' => $task, 'model' => $model, 'status' => 'no_model', 'error' => $message]);
        return ai_result(false, null, null, $message);
    }
    if ($code === 401 || $code === 403) {
        ai_log_call(['task' => $task, 'model' => $model, 'status' => 'error', 'error' => 'HTTP ' . $code . ': API key refused']);
        return ai_result(false, null, null, 'The ' . ai_provider_name($provider) . ' API key was refused. Check ' . ($provider === 'groq' ? 'GROQ_API_KEY' : ($provider === 'openai' ? 'OPENAI_API_KEY' : 'GEMINI_API_KEY')) . ' in the project .env file.');
    }
    if ($code === 429) {
        ai_log_call(['task' => $task, 'model' => $model, 'status' => 'rate_limited', 'error' => 'HTTP 429: ' . substr($providerMessage, 0, 500)]);
        return ai_result(false, null, null, 'Too many requests or quota exceeded. Try again later.');
    }
    if ($code === 503) {
        ai_log_call(['task' => $task, 'model' => $model, 'status' => 'error', 'error' => 'HTTP 503: ' . substr($providerMessage, 0, 500)]);
        return ai_result(false, null, null, 'The selected ' . ai_provider_name($provider) . ' model is temporarily unavailable. Try another model.');
    }
    if ($code < 200 || $code >= 300 || !is_string($text)) {
        ai_log_call(['task' => $task, 'model' => $model, 'status' => 'error', 'error' => 'HTTP ' . $code . ': ' . substr($providerMessage, 0, 500)]);
        return ai_result(false, null, null, 'The ' . ai_provider_name($provider) . ' request was rejected. Try again.');
    }
    $data = json_decode($text, true);
    $result = ai_result(true, is_array($data) ? $data : null, $text, null, false, (int) (ai_is_openai_compatible($provider) ? ($response['usage']['prompt_tokens'] ?? 0) : ($response['usageMetadata']['promptTokenCount'] ?? 0)), (int) (ai_is_openai_compatible($provider) ? ($response['usage']['completion_tokens'] ?? 0) : ($response['usageMetadata']['candidatesTokenCount'] ?? 0)));
    $result['citations'] = [];
    foreach (($response['groundingMetadata']['groundingChunks'] ?? []) as $chunk) {
        $uri = $chunk['web']['uri'] ?? '';
        if (is_string($uri) && $uri !== '') {
            $result['citations'][] = $uri;
        }
    }
    ai_log_call(['task' => $task, 'model' => $model, 'image_hash' => $imageHash, 'prompt_hash' => hash('sha256', $prompt), 'input_tokens' => $result['tokens']['in'], 'output_tokens' => $result['tokens']['out'], 'status' => 'ok']);
    ai_cache_put($hash, $result);
    return $result;
}

function ai_gemini_text(string $prompt, array $opts = []): array
{
    return ai_call([['text' => $prompt]], $opts + ['task' => 'text']);
}

function ai_gemini_vision(string $imagePath, string $prompt, array $opts = []): array
{
    $bytes = file_get_contents($imagePath);
    if ($bytes === false) {
        return ai_result(false, null, null, 'The label image could not be read.');
    }

    $mime = mime_content_type($imagePath);
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return ai_result(false, null, null, 'Only JPEG and PNG images are supported.');
    }

    return ai_call([['text' => $prompt], ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]],], $opts + ['task' => 'vision_product']);
}

function ai_gemini_search_nutrition(string $prompt): array
{
    if (trim((string) env('TAVILY_API_KEY', '')) !== '') {
        preg_match('/^BRAND:\s*(.+)$/mi', $prompt, $brandMatch);
        preg_match('/^PRODUCT:\s*(.+)$/mi', $prompt, $productMatch);
        $brand = trim((string) ($brandMatch[1] ?? ''));
        $product = trim((string) ($productMatch[1] ?? ''));
        $query = trim($brand . ' ' . $product . ' Philippines nutrition facts label');
        return ai_tavily_search($query, 'nutrition_search', $brand, $product);
    }
    return ai_call([['text' => $prompt]], ['task' => 'nutrition_search', 'tools' => [['google_search' => (object) []]]]);
}

function ai_structure_nutrition_text(string $text): array
{
    $prompt = "Convert the following web-search findings into JSON only. Use exactly these keys: found, calories, protein_g, carbs_g, fat_g, sugar_g, sodium_mg, nutrition_basis, serving_size, source_url. Every non-null numeric value must be explicitly present in the findings for the exact product; never invent values, estimate, or change a missing value to zero. Set found=false and all numeric values null when the source does not explicitly provide exact-product nutrition facts. nutrition_basis must be per_serving or per_100g or null. source_url must be the cited source that states the nutrition facts, not merely a retailer page identifying the product.\n\nSEARCH FINDINGS:\n" . $text;
    return ai_call([['text' => $prompt]], ['task' => 'nutrition_structure', 'response_json' => true]);
}

function ai_gemini_search_product_info(string $brand, string $name): array
{
    $product = trim($brand . ' ' . $name);
    $prompt = "Search the web for official product information for this exact product and package variant: \"" . $product . "\". Focus on the Philippines-market version and Philippine manufacturer, official label, and retailer sources. Prioritize the manufacturer or a reputable seller listing the same variant and package size. Do not combine details from different flavors, sizes, markets, or products with similar names. Return plain text with these fields if found:\nDESCRIPTION: one or two sentences about the product\nINGREDIENTS: the ingredients list\nALLERGENS: allergen statement, or 'None declared' only when the source explicitly says so\nSTORAGE: storage instructions\nUNIT: package type\nNET_WEIGHT: net weight or volume as printed\nOnly report facts stated by a matching source. If you cannot find a confident exact match, respond with NOT_FOUND. Do not invent values or use general knowledge.";
    if (trim((string) env('TAVILY_API_KEY', '')) !== '') {
        $query = $product . ' Philippines product label ingredients allergens manufacturer';
        return ai_tavily_search($query, 'product_info_search', $brand, $name);
    }
    return ai_call([['text' => $prompt]], ['task' => 'product_info_search', 'tools' => [['google_search' => (object) []]]]);
}

function ai_tavily_result_matches_product(string $brand, string $productName, string $title, string $url): bool
{
    $normalize = static function (string $value): string {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return strtolower((string) preg_replace('/[^a-z0-9]+/i', ' ', $ascii !== false ? $ascii : $value));
    };
    $heading = $normalize($title . ' ' . $url);
    if ($heading === '') {
        return false;
    }

    $stopWords = ['and', 'bottle', 'can', 'drink', 'flavored', 'flavoured', 'for', 'milk', 'of', 'pack', 'product', 'ready', 'the', 'to'];
    $identityWords = array_values(array_filter(explode(' ', $normalize($productName)), static function (string $word) use ($stopWords): bool {
        return strlen($word) >= 3 && !in_array($word, $stopWords, true) && !preg_match('/^\d+(?:\.?\d+)?(?:ml|l|g|kg|oz)?$/', $word);
    }));
    $brandWords = array_values(array_filter(explode(' ', $normalize($brand)), static function (string $word): bool {
        return strlen($word) >= 3;
    }));
    $matchesIdentity = false;
    foreach (array_merge($identityWords, $brandWords) as $word) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $heading)) {
            $matchesIdentity = true;
            break;
        }
    }
    if (!$matchesIdentity) {
        return false;
    }

    if (preg_match('/\b(\d+(?:\.\d+)?)\s*(ml|l|g|kg|oz)\b/i', $productName, $sizeMatch)) {
        $compactHeading = preg_replace('/[^a-z0-9]/', '', $heading);
        $compactSize = preg_replace('/[^a-z0-9]/', '', strtolower($sizeMatch[0]));
        if ($compactSize === '' || strpos((string) $compactHeading, $compactSize) === false) {
            return false;
        }
    }

    $normalizedName = $normalize($productName);
    foreach (['ice cream', 'powder', 'powdered', 'refill', 'sachet'] as $otherVariant) {
        if (strpos($heading, $otherVariant) !== false && strpos($normalizedName, $otherVariant) === false) {
            return false;
        }
    }
    return true;
}

function ai_tavily_search(string $prompt, string $task, string $brand = '', string $productName = ''): array
{
    $key = trim((string) env('TAVILY_API_KEY', ''));
    if ($key === '') {
        return ai_result(false, null, null, 'Tavily API key is not configured.');
    }
    $cachePrefix = in_array($task, ['product_info_search', 'nutrition_search'], true) ? 'tavily-exact-v2|' : 'tavily|';
    $hash = hash('sha256', $cachePrefix . $prompt);
    $cached = ai_cache_get($hash);
    if ($cached !== null) {
        ai_log_call(['task' => $task, 'model' => 'tavily', 'prompt_hash' => hash('sha256', $prompt), 'status' => 'cached']);
        $cached['cached'] = true;
        return $cached;
    }
    if (!ai_check_search_cap($task)) {
        return ai_result(false, null, null, 'Today’s web-search limit has been reached.');
    }
    $payload = json_encode([
        'api_key' => $key,
        'query' => $prompt,
        'search_depth' => 'advanced',
        'topic' => 'general',
        'max_results' => 5,
        'include_answer' => true,
        'include_raw_content' => false,
        'include_images' => false,
    ]);
    $ch = curl_init('https://api.tavily.com/search');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $response = json_decode((string) $body, true);
    if ($body === false || $code < 200 || $code >= 300 || !is_array($response)) {
        $message = $code === 429 ? 'Tavily search quota has been reached.' : 'Tavily search failed.';
        ai_log_call(['task' => $task, 'model' => 'tavily', 'status' => 'error', 'error' => $curlError !== '' ? $curlError : 'HTTP ' . $code]);
        return ai_result(false, null, null, $message);
    }
    $chunks = [];
    $citations = [];
    if (!in_array($task, ['product_info_search', 'nutrition_search'], true)
        && is_string($response['answer'] ?? null) && trim($response['answer']) !== '') {
        $chunks[] = $response['answer'];
    }
    foreach (($response['results'] ?? []) as $result) {
        $title = trim((string) ($result['title'] ?? ''));
        $url = trim((string) ($result['url'] ?? ''));
        $content = trim((string) ($result['content'] ?? ''));
        if (in_array($task, ['product_info_search', 'nutrition_search'], true)
            && !ai_tavily_result_matches_product($brand, $productName, $title, $url)) {
            continue;
        }
        if ($url !== '') {
            $citations[] = $url;
        }
        if ($title !== '' || $content !== '') {
            $chunks[] = $title . "\n" . $content . ($url !== '' ? "\nSOURCE: " . $url : '');
        }
    }
    $raw = trim(implode("\n\n", $chunks));
    if ($raw === '') {
        $raw = 'NOT_FOUND';
    }
    $result = ai_result(true, null, $raw, null, false);
    $result['citations'] = array_values(array_unique($citations));
    ai_log_call(['task' => $task, 'model' => 'tavily', 'prompt_hash' => hash('sha256', $prompt), 'status' => 'ok']);
    ai_cache_put($hash, $result);
    return $result;
}

function ai_structure_product_info_text(string $text, string $productIdentity = ''): array
{
    $identityRule = $productIdentity !== '' ? " The exact target is: \"{$productIdentity}\"." : '';
    $prompt = "Convert the following web-search findings into JSON only. Use exactly these keys: found, description, ingredients, allergens, storage_notes, unit_name, net_weight, source_url. Set found=false and null values when a field is not stated. Never invent. Use only sources for the exact product and package variant{$identityRule} and do not combine facts across different products, sizes, markets, or product types. A marketing highlight such as 'made with milk and cocoa' is not a complete ingredients list; set ingredients to null unless the source explicitly gives the ingredients for the exact product. Set allergens to null unless the exact product source explicitly states its allergen information; never infer allergens from ingredients or related products. source_url must be a filtered source that explicitly supports the returned fields.\n\nSEARCH FINDINGS:\n" . $text;
    return ai_call([['text' => $prompt]], ['task' => 'product_info_structure', 'response_json' => true]);
}

function ai_list_models(): array
{
    $provider = ai_provider();
    $providerName = $provider === 'groq' ? 'Groq' : ($provider === 'openai' ? 'OpenAI' : 'Gemini');
    $key = ai_api_key();
    if ($key === '') {
        $keyName = $provider === 'groq' ? 'GROQ_API_KEY' : ($provider === 'openai' ? 'OPENAI_API_KEY' : 'GEMINI_API_KEY');
        return ai_result(false, null, null, $providerName . ' API key is not configured. Set ' . $keyName . ' in the project .env file.');
    }

    if (in_array($provider, ['openai', 'groq'], true)) {
        $baseUrl = trim((string) env('OPENAI_BASE_URL', ''));
        if ($baseUrl === '') {
            $baseUrl = $provider === 'groq' ? 'https://api.groq.com/openai/v1' : 'https://api.openai.com/v1';
        }
        $ch = curl_init(rtrim($baseUrl, '/') . '/models');
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key]]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return ai_result(false, null, null, $providerName . ' model-list request failed: ' . ($curlError !== '' ? $curlError : 'connection error'));
        }
        $data = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            if ($code === 401 || $code === 403) {
                $message = $providerName . ' rejected the API key. Check ' . ($provider === 'groq' ? 'GROQ_API_KEY' : 'OPENAI_API_KEY') . ' in the project .env file.';
            } elseif ($code === 404) {
                $message = $providerName . ' model-list endpoint was not found. Check OPENAI_BASE_URL.';
            } elseif ($code === 429) {
                $message = $providerName . ' model-list request exceeded the API rate limit.';
            } else {
                $message = $providerName . ' model-list request failed (HTTP ' . $code . ').';
            }
            return ai_result(false, null, null, $message);
        }

        $models = [];
        foreach (($data['data'] ?? []) as $model) {
            if (!empty($model['id'])) {
                $models[] = ['name' => (string) $model['id'], 'displayName' => (string) $model['id']];
            }
        }
        if (!$models) {
            return ai_result(false, null, null, $providerName . ' returned no available models for this API key. Check the key and endpoint.');
        }
        return ai_result(true, $models);
    }

    $models = [];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models';
    do {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => ['x-goog-api-key: ' . $key]]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode((string) $body, true);
        if ($code < 200 || $code >= 300 || !is_array($data)) {
            return ai_result(true, [
                ['name' => 'gemini-3.5-flash', 'displayName' => 'Gemini 3.5 Flash (recommended)'],
                ['name' => 'gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash'],
                ['name' => 'gemini-2.5-pro', 'displayName' => 'Gemini 2.5 Pro'],
            ], null, 'Gemini did not expose its model list; showing compatible model defaults.');
        }
        foreach (($data['models'] ?? []) as $model) {
            if (in_array('generateContent', $model['supportedGenerationMethods'] ?? [], true)) {
                $models[] = ['name' => preg_replace('/^models\\//', '', (string) ($model['name'] ?? '')), 'displayName' => (string) ($model['displayName'] ?? '')];
            }
        }
        $token = $data['nextPageToken'] ?? '';
        $url = $token !== '' ? 'https://generativelanguage.googleapis.com/v1beta/models?pageToken=' . rawurlencode($token) : '';
    } while ($url !== '');
    return ai_result(true, $models);
}

function ai_valid_ean(string $code): bool
{
    if (!in_array(strlen($code), [8, 12, 13], true) || !ctype_digit($code)) {
        return false;
    }
    $digits = array_reverse(str_split($code));
    $sum = 0;
    foreach ($digits as $index => $digit) {
        if ($index > 0) {
            $sum += (int) $digit * (($index % 2 === 1) ? 3 : 1);
        }
    }
    return ((10 - ($sum % 10)) % 10) === (int) $digits[0];
}

function ai_macros_match(array $nutrition): bool
{
    if (empty($nutrition['calories'])) {
        return true;
    }
    $macroCalories = ((float) ($nutrition['protein_g'] ?? 0) * 4)
        + ((float) ($nutrition['carbs_g'] ?? 0) * 4)
        + ((float) ($nutrition['fat_g'] ?? 0) * 9);
    $calories = (float) $nutrition['calories'];
    return $macroCalories <= 0 || $calories <= 0 || abs($macroCalories - $calories) / $calories <= 0.20;
}

function ai_validate_product(array $data, array $allowedCategories): array
{
    $issues = [];
    if (trim((string) ($data['name'] ?? '')) === '') {
        $issues[] = 'missing_name';
    }
    $barcode = trim((string) ($data['barcode'] ?? ''));
    if ($barcode !== '') {
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $barcode)) {
            $issues[] = 'bad_barcode_format';
        } elseif (ctype_digit($barcode) && !ai_valid_ean($barcode)) {
            $issues[] = 'bad_barcode_checksum';
        }
    }
    $nutrition = is_array($data['nutrition'] ?? null) ? $data['nutrition'] : [];
    if (empty($nutrition['basis']) && !empty($nutrition['calories'])) {
        $issues[] = 'nutrition_basis_missing';
    }
    if (!ai_macros_match($nutrition)) {
        $issues[] = 'macro_mismatch';
    }
    if (!empty($data['category_suggestion']) && !in_array($data['category_suggestion'], $allowedCategories, true)) {
        $issues[] = 'unknown_category';
    }
    foreach (['calories', 'protein_g', 'carbs_g', 'fat_g', 'sugar_g', 'sodium_mg'] as $field) {
        if (isset($nutrition[$field]) && $nutrition[$field] !== null && (!is_numeric($nutrition[$field]) || (float) $nutrition[$field] < 0)) {
            $issues[] = 'invalid_' . $field;
        }
    }
    if (!empty($nutrition['sugar_g']) && !empty($nutrition['carbs_g']) && (float) $nutrition['sugar_g'] > (float) $nutrition['carbs_g'] + 0.5) {
        $issues[] = 'sugar_exceeds_carbs';
    }
    return array_values(array_unique($issues));
}
