<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add security-related HTTP headers to all responses.
 *
 * Addresses:
 * - XSS protection (CSP + legacy X-XSS-Protection)
 * - Clickjacking prevention
 * - MIME type sniffing
 * - Cache control after logout
 * - Referrer policy
 * - HSTS (production + HTTPS only)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Enable XSS filter in older browsers (deprecated but harmless)
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Control referrer information
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // F-006: Content-Security-Policy — pure JSON API, no inline scripts needed.
        // frame-ancestors 'none' provides clickjacking defense at CSP level.
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; frame-ancestors 'none'"
        );

        // F-007: HSTS — only enabled in production over HTTPS to avoid breaking
        // local HTTP development. Never set Secure-related headers over plain HTTP.
        if (app()->environment('production') && $request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000'
            );
        }

        // Prevent caching of authenticated pages
        if ($request->user()) {
            $response->headers->set('Cache-Control', 'no-cache, no-store, max-age=0, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', 'Sat, 01 Jan 2000 00:00:00 GMT');
        }

        return $response;
    }
}
