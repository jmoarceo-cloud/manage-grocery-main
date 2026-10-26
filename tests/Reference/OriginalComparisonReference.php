<?php

namespace Tests\Reference;

use App\Http\Controllers\PublicController;
use App\Support\IsolatedDatabase;
use Illuminate\Http\Request;

class OriginalComparisonReference
{
    public static function render(Request $request)
    {
        IsolatedDatabase::verify();
        $source = file_get_contents(base_path('project/nutrition_compare.php'));
        if (hash('sha256', $source) !== '9dfea13a3614ce8f171567a6171365b1a6ee262c78ad6f7053545feabd1ae762') {
            throw new \RuntimeException('Original comparison changed; review reference presentation.');
        }
        $html = substr($source, strpos($source, '<!doctype html>'));
        $html = preg_replace('~<link\b.*?>|<script\b[^>]*src=[^>]*>\s*</script>~s', '', $html);
        // Replace the legacy client redirect branch, which is not a UI state.
        $start = strpos($html, "<?php if (isset(\$_GET['pick0'])):");
        $end = strpos($html, "<?php elseif (\$comparisonNotice !== ''): ?>", $start);
        if ($start === false || $end === false) {
            throw new \RuntimeException('Original comparison branch changed.');
        }
        $html = substr_replace($html, "<?php if (\$comparisonNotice !== ''): ?>", $start, $end + strlen("<?php elseif (\$comparisonNotice !== ''): ?>") - $start);
        $html = str_replace(['public_escape(', 'enc_id('], ['$escape(', '$identity('], $html);
        $html = str_replace('</head>', '<style>'.file_get_contents(base_path('project/theme-toggle.css')).'</style></head>', $html);
        $html = str_replace('</body>', '<script>'.file_get_contents(base_path('project/theme-sync.js')).'</script></body>', $html);
        $data = app(PublicController::class)->compare($request)->getData();
        $path = storage_path('testing/original-comparison-presentation.php');
        file_put_contents($path, $html, LOCK_EX);

        return view()->file($path, [
            'products' => $data['facts']->map(fn ($item) => (array) $item)->all(),
            'choices' => $data['choices']->map(fn ($choice) => ['id' => $choice->product_id, 'product_name' => $choice->product_name])->all(),
            'comparisonNotice' => $data['notice'] ?? '', 'tokens' => $data['selected'],
            'escape' => static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'identity' => static fn ($value) => $value,
        ]);
    }
}
