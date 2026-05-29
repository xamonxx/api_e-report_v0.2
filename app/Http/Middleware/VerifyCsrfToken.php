<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * F-011: Login route no longer excluded. The Next.js SPA always calls
     * GET /sanctum/csrf-cookie first and sends X-XSRF-TOKEN on all requests,
     * so the login endpoint is protected like all other endpoints.
     *
     * @var array<int, string>
     */
    protected $except = [
        //
    ];
}
