<?php

namespace Tests\Reference;

use App\Support\IsolatedDatabase;

class OriginalLoginReference
{
    public static function render()
    {
        IsolatedDatabase::verify();
        // Render a static initial screen from source text. Never execute PHP from it.
        $source = file_get_contents(base_path('project/login.php'));
        $html = substr($source, strpos($source, '<!DOCTYPE html>'));
        $html = preg_replace('~<link\b.*?>~s', '', $html);
        $html = preg_replace('~<script\b[^>]*src=[^>]*>\s*</script>~s', '', $html);
        $html = preg_replace('~<script>\s*// Password eye toggle.*?</script>~s', '', $html);
        $html = preg_replace('~<\?php if \(!empty\(\$(?:expired_message|error)\)\): \?>.*?<\?php endif; \?>~s', '', $html);
        $html = str_replace([
            '<?= htmlspecialchars(systemUrl(\'login\'), ENT_QUOTES, \'UTF-8\') ?>',
            '<?php csrfField(); ?>',
            '<?= isset($_POST[\'username\']) ? clean($_POST[\'username\']) : \'\' ?>',
        ], ['#', '', ''], $html);
        if (str_contains($html, '<?')) {
            throw new \RuntimeException('Original login presentation changed; review static reference.');
        }
        $css = file_get_contents(base_path('project/theme-toggle.css'))."\n".file_get_contents(base_path('project/assets/css/page-controls.css'));
        foreach (['Plus Jakarta Sans' => 'plus-jakarta-sans/PlusJakartaSans-Variable.ttf', 'Fraunces' => 'fraunces/Fraunces-Variable.ttf'] as $family => $file) {
            $font = base64_encode(file_get_contents(resource_path('fonts/'.$file)));
            $css .= "\n@font-face{font-family:'$family';font-style:normal;font-weight:100 900;font-display:swap;src:url(data:font/ttf;base64,$font) format('truetype')}";
        }
        $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        $html = str_replace('</body>', '<script>'.file_get_contents(base_path('project/theme-sync.js')).'</script></body>', $html);

        return response($html)->header('Cache-Control', 'no-store, private');
    }
}
