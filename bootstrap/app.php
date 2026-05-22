<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->statefulApi();

        $middleware->alias([
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);

        // ── Security Headers: applied to all web requests ────────
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\PreventBackHistory::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // ── Production Error Handling ─────────────────────────────
        // Prevent stack traces from leaking to the browser.
        // Laravel already handles this via APP_DEBUG=false in .env,
        // but we add an extra safety net for API/JSON responses.
        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->expectsJson() && !config('app.debug')) {
                $status = method_exists($e, 'getStatusCode')
                    ? $e->getStatusCode()
                    : 500;

                return response()->json([
                    'success' => false,
                    'message' => $status === 500
                        ? 'Terjadi kesalahan internal. Silakan coba lagi nanti.'
                        : $e->getMessage(),
                ], $status);
            }
        });
    })->create();
