<?php

namespace Tests\Reference;

use App\Http\Controllers\PublicController;
use App\Support\IsolatedDatabase;
use Illuminate\Http\Request;

class OriginalProductReference
{
    public static function render(Request $request, int $id)
    {
        IsolatedDatabase::verify();
        $source = file_get_contents(base_path('project/product_detail.php'));
        if (hash('sha256', $source) !== 'fd9ed808a01e13c6df50412271b04ee4bd249931f3ffc0329dbf541f92693080') {
            throw new \RuntimeException('Original product presentation changed; review before reference rendering.');
        }
        // The source fingerprint is pinned before extracting presentation PHP.
        // No bootstrap, config, query, scan-log call or include is evaluated.
        $html = substr($source, strpos($source, '<!doctype html>'));
        $html = str_replace("<?php include __DIR__ . '/includes/public_theme.php'; ?>", '', $html);
        $html = preg_replace('~<link\b.*?>~s', '', $html);
        $html = preg_replace('~<script\b[^>]*src=[^>]*>\s*</script>~s', '', $html);
        $html = str_replace(['public_escape(', 'public_peso(', 'public_image_url(', 'enc_id('], ['$escape(', '$money(', '$imageUrl(', '$identity('], $html);
        preg_match('~<style>(.*?)</style>~s', file_get_contents(base_path('project/includes/public_theme.php')), $publicTheme);
        $css = $publicTheme[1]."\n";
        // Original public-theme CSS precedes the page's own CSS.
        $html = str_replace('<style>', '<style>'.$css, $html);
        $css = file_get_contents(base_path('project/theme-toggle.css'));
        foreach (['Plus Jakarta Sans' => 'plus-jakarta-sans/PlusJakartaSans-Variable.ttf', 'Fraunces' => 'fraunces/Fraunces-Variable.ttf'] as $family => $file) {
            $font = base64_encode(file_get_contents(resource_path('fonts/'.$file)));
            $css .= "\n@font-face{font-family:'$family';font-style:normal;font-weight:100 900;font-display:swap;src:url(data:font/ttf;base64,$font) format('truetype')}";
        }
        $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        $html = str_replace('</body>', '<script>'.file_get_contents(base_path('project/theme-sync.js')).'</script></body>', $html);
        $data = app(PublicController::class)->product($request, $id)->getData();
        $images = [$data['display']['image'] ?? '' => $data['productImage']];
        foreach ($data['relatedProducts'] as $item) {
            $images[$item['image'] ?? ''] = $item['image_url'];
        }
        $path = storage_path('testing/original-product-presentation.php');
        file_put_contents($path, $html, LOCK_EX);

        return view()->file($path, array_merge($data, [
            'product' => $data['display'], 'related' => $data['relatedProducts'], 'detailReturnQuery' => '',
            'escape' => static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
            'money' => static fn ($value) => '₱'.number_format((float) $value, 2),
            'imageUrl' => static fn ($image) => $images[$image ?? ''] ?? '',
            'identity' => static fn ($value) => $value,
        ]));
    }
}
