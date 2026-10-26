<?php

namespace Tests\Reference;

use App\Http\Controllers\Auth\StaffAuthController;
use App\Services\StaffAuthentication;
use App\Support\IsolatedDatabase;
use Illuminate\Http\Request;

class OriginalMfaReference
{
    public static function render(Request $request, StaffAuthentication $authentication)
    {
        IsolatedDatabase::verify();
        abort_unless($request->session()->has('mfa_pending'), 401);
        $data = app(StaffAuthController::class)->challenge($request, $authentication)->getData();
        abort_if(($data['needsEmail'] ?? false) || $data['mailUnavailable'], 422);
        $domain = $request->session()->get('mfa_pending.domain');
        $file = $domain === 'pos' ? 'project/verify_2fa.php' : 'project/HRMS/verify_2fa.php';
        $fingerprint = $domain === 'pos' ? 'f32f4304a26e772a65552278ada0beaaf893b86272002b06beb51419a9a171d0' : '9efc32955c0adde525c8642b6ef720ebf6429e11c4e13b133a64485d9c0019a8';
        $source = file_get_contents(base_path($file));
        if (hash('sha256', $source) !== $fingerprint) {
            throw new \RuntimeException('Original MFA source changed; review before rendering.');
        }
        $html = substr($source, stripos($source, '<!DOCTYPE html>'));
        $html = preg_replace_callback("~<link\\b(?:[^>\"']+|\"[^\"]*\"|'[^']*')*>~s", static function ($match) {
            if (str_contains($match[0], 'theme-toggle.css')) {
                return '<style>'.file_get_contents(base_path('project/theme-toggle.css')).'</style>';
            }
            if (str_contains($match[0], 'assets/css/theme.css')) {
                return '<style>'.file_get_contents(base_path('project/assets/css/theme.css')).'</style>';
            }

            return '';
        }, $html);
        $html = preg_replace('~<script\b[^>]*src=[^>]*>\s*</script>~s', '', $html);
        $html = str_replace('clean(', '$escape(', $html);
        $fonts = $domain === 'pos' ? ['Inter' => ['inter/Inter-Variable.ttf', '100 900'], 'Sora' => ['sora/Sora-Variable.ttf', '100 800']] : ['Plus Jakarta Sans' => ['plus-jakarta-sans/PlusJakartaSans-Variable.ttf', '200 800']];
        $css = '';
        foreach ($fonts as $family => [$path, $weight]) {
            $font = base64_encode(file_get_contents(resource_path('fonts/'.$path)));
            $css .= "@font-face{font-family:'$family';font-style:normal;font-weight:$weight;font-display:swap;src:url(data:font/ttf;base64,$font) format('truetype')}\n";
        }
        $html = str_replace('</head>', '<style>'.$css.'</style></head>', $html);
        $html = str_replace('</body>', '<script>'.file_get_contents(base_path('project/theme-sync.js')).'</script></body>', $html);
        $path = storage_path('testing/original-mfa-'.$domain.'-presentation.php');
        file_put_contents($path, $html, LOCK_EX);

        return view()->file($path, array_merge($data, [
            'error' => '', 'info' => '', 'csrf_token' => 'synthetic-reference-only',
            'escape' => static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'),
        ]));
    }
}
