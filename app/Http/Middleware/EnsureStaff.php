<?php

namespace App\Http\Middleware;

use App\Services\StaffAuthentication;
use App\Support\StaffSessionVersion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $domain = $request->session()->get('staff_domain');
        $user = in_array($domain, ['pos', 'hrms'], true) ? Auth::guard($domain)->user() : null;
        if ($user && $user->force_logout) {
            StaffSessionVersion::revoke($domain, (int) $user->id);
        }
        $valid = $user && $request->session()->get('staff_mfa') === true && StaffAuthentication::eligible($user, $domain)
         && hash_equals((string) $request->session()->get('staff_password_signature'), hash('sha256', $user->password))
         && time() - (int) $request->session()->get('staff_activity', 0) <= 1800 && ! (bool) $user->force_logout
         && hash_equals(StaffSessionVersion::current($domain, (int) $user->id), (string) $request->session()->get('staff_session_version', '0'));
        if (! $valid) {
            foreach (['pos', 'hrms'] as $guard) {
                Auth::guard($guard)->logout();
            }$request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Authentication required.'], 401) : redirect()->route('login');
        }
        Auth::shouldUse($domain);
        $request->session()->put('staff_activity', time());
        $request->attributes->set('staff', $user);
        $request->attributes->set('staff_domain', $domain);

        return $next($request);
    }
}
