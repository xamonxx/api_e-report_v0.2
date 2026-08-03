<?php

namespace Tests\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_production_http_proxy_request_redirects_to_configured_https_origin(): void
    {
        $originalEnvironment = app()->environment();
        $originalUrl = config('app.url');

        try {
            app()->detectEnvironment(fn () => 'production');
            config()->set('app.url', 'https://api.audit.test');

            $request = Request::create(
                '/api/v1/auth/me?source=audit',
                'GET',
                server: [
                    'HTTP_HOST' => 'attacker.example',
                    'HTTP_X_FORWARDED_PROTO' => 'http',
                ],
            );

            $response = (new SecurityHeaders)->handle(
                $request,
                fn () => response()->json(['unexpected' => true]),
            );

            $this->assertSame(308, $response->getStatusCode());
            $this->assertSame(
                'https://api.audit.test/api/v1/auth/me?source=audit',
                $response->headers->get('Location'),
            );
        } finally {
            app()->detectEnvironment(fn () => $originalEnvironment);
            config()->set('app.url', $originalUrl);
        }
    }
}
