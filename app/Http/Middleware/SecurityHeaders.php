<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = ['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=(self)', 'Content-Security-Policy-Report-Only' => "default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'"];
        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        if (($request->hasSession() && $request->session()->has('staff_domain')) || str_contains($request->path(), 'login') || str_contains($request->path(), 'two-factor')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
