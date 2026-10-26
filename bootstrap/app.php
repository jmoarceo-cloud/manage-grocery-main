<?php

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\RequireRole;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php', health: '/up')
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['staff' => EnsureStaff::class, 'role' => RequireRole::class]);
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'supervisor_password', 'code', 'backup_code']);
        $exceptions->report(function (QueryException $exception): void {
            logger()->warning('Database operation failed', ['code' => (string) $exception->getCode()]);
        })->stop();
        $exceptions->render(function (QueryException $exception, Request $request) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1045, 2002, 2006, 2013], true)) {
                $headers = ['Retry-After' => '60', 'Cache-Control' => 'no-store'];

                return $request->expectsJson() ? response()->json(['message' => 'The database is temporarily unavailable. Please try again shortly.'], 503, $headers) : response()->view('errors.503', [], 503, $headers);
            }
        });
    })->create();
