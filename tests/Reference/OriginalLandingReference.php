<?php

namespace Tests\Reference;

use App\Support\IsolatedDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OriginalLandingReference
{
    public static function render(Request $request)
    {
        IsolatedDatabase::verify();
        $ids = $request->validate(['ids' => ['required', 'array', 'max:4'], 'ids.*' => ['required', 'integer', 'min:1', 'max:2147483647', 'distinct']])['ids'];
        $source = file_get_contents(base_path('project/index.php'));
        if (hash('sha256', $source) !== '94f4b74ae49874556370831cdd12397cb6c4914971609eb44451098515f8bd0f') {
            throw new \RuntimeException('Original landing source changed; review the presentation before rendering.');
        }
        $html = substr($source, strpos($source, '<!doctype html>'));
        // Only the original presentation is rendered: no bootstrap/config/PDO code.
        $html = preg_replace('~^\h*<link[^\r\n]+[\r\n]~m', '', $html);
        preg_match_all('~<\?(.*?)\?>~s', $html, $blocks);
        $functions = ['array_slice', 'empty', 'rawurlencode', 'basename', 'urlencode', 'number_format', 'date'];
        $variables = ['$featuredProducts', '$featuredCategories', '$product', '$category', '$imagePath', '$brandIcon', '$escape'];
        foreach ($blocks[1] as $block) {
            $code = str_starts_with($block, '=') ? 'echo '.substr($block, 1) : preg_replace('/^php\s*/', '', $block);
            foreach (token_get_all('<?php '.$code) as $token) {
                if (! is_array($token)) {
                    continue;
                }
                if (($token[0] === T_STRING && ! in_array($token[1], $functions, true)) || ($token[0] === T_VARIABLE && ! in_array($token[1], $variables, true)) || in_array($token[0], [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_NEW, T_FUNCTION], true)) {
                    throw new \RuntimeException('Original presentation changed; review before reference rendering.');
                }
            }
        }
        preg_match('~\$brandIcon = \'([^\']+)\';~s', $source, $icon);
        $font = base64_encode(file_get_contents(resource_path('fonts/plus-jakarta-sans/PlusJakartaSans-Variable.ttf')));
        $css = file_get_contents(base_path('project/assets/css/theme.css'))."\n".file_get_contents(base_path('project/assets/css/landing.css'));
        $css .= "\n@font-face{font-family:'Plus Jakarta Sans';font-style:normal;font-weight:200 800;font-display:swap;src:url(data:font/ttf;base64,$font) format('truetype')}";
        $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        $path = storage_path('testing/original-landing-presentation.php');
        file_put_contents($path, $html, LOCK_EX);
        $db = DB::connection('pos');
        $products = $db->table('products')->where('is_active', 1)->where('stock', '>', 0)->whereIn('id', $ids)->get(['id', 'product_name', 'category', 'price', 'image', 'stock'])->keyBy('id');
        abort_unless($products->count() === count($ids), 422);

        return view()->file($path, ['brandIcon' => $icon[1], 'escape' => static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'featuredProducts' => array_map(fn ($id) => (array) $products->get($id), $ids),
            'featuredCategories' => $db->table('products')->where('is_active', 1)->where('stock', '>', 0)->whereNotNull('category')->where('category', '<>', '')->distinct()->orderBy('category')->limit(9)->pluck('category')->all()]);
    }
}
