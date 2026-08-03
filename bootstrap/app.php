<?php

use App\Http\Middleware\PreventBackHistory;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\UpdateLastSeen;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    // Wajib agar routes/channels.php dimuat; tanpa ini otorisasi channel privat
    // tidak pernah terdaftar dan broadcast terpaksa memakai channel publik.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        // First-party SPA authentication uses Laravel's encrypted HttpOnly
        // session cookie and CSRF protection instead of browser-stored tokens.
        $middleware->statefulApi();

        $middleware->alias([
            'role' => RoleMiddleware::class,
        ]);

        $middleware->api(append: [
            UpdateLastSeen::class,
            SecurityHeaders::class,
        ]);

        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');

        // Security Headers: applied to all web requests
        $middleware->web(append: [
            SecurityHeaders::class,
            PreventBackHistory::class,
            UpdateLastSeen::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        // Production Error Handling
        $exceptions->renderable(function (Throwable $e, $request) {
            if (($request->is('api/*') || $request->expectsJson()) && ! config('app.debug')) {
                $status = match (true) {
                    $e instanceof AuthenticationException => 401,
                    $e instanceof AuthorizationException => 403,
                    $e instanceof ValidationException => 422,
                    $e instanceof HttpExceptionInterface => $e->getStatusCode(),
                    default => 500,
                };

                $payload = [
                    'success' => false,
                    'message' => $status === 500
                        ? 'Terjadi kesalahan internal. Silakan coba lagi nanti.'
                        : $e->getMessage(),
                ];

                if ($e instanceof ValidationException) {
                    $payload['errors'] = $e->errors();
                }

                return response()->json($payload, $status);
            }
        });
    })->create();
