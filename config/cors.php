<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for Next.js SPA running on localhost:3000.
    | Sanctum SPA auth requires credentials: true and explicit origin.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    // F-010: Restrict to only the HTTP methods actually used by this API.
    // Using ['*'] is overly permissive and exposes unnecessary attack surface.
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('FRONTEND_URLS', env('FRONTEND_URL', 'http://localhost:3000,http://127.0.0.1:3000')))
    ))),

    'allowed_origins_patterns' => [
        '#^http://192\.168\.\d+\.\d+:3000$#',
        '#^http://10\.\d+\.\d+\.\d+:3000$#',
        '#^http://172\.(1[6-9]|2\d|3[01])\.\d+\.\d+:3000$#',
    ],

    // F-010: Restrict to only headers actually sent by the Next.js SPA.
    // Using ['*'] is overly permissive.
    'allowed_headers' => ['Content-Type', 'X-XSRF-TOKEN', 'Accept', 'Authorization', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
