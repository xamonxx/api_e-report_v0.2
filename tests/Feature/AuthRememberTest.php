<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthRememberTest extends TestCase
{
    use RefreshDatabase;

    private function createUser(string $email, string $rawPassword): User
    {
        $user = new User([
            'name' => 'Test User',
            'email' => $email,
            'password' => Hash::make($rawPassword),
        ]);
        $user->role = UserRole::Admin;
        $user->save();

        return $user->fresh();
    }

    public function test_login_without_remember_does_not_issue_remember_cookie(): void
    {
        $password = 'Secret123456!';
        $user = $this->createUser('user_no_remember@test.com', $password);

        $response = $this->withHeaders([
            'referer' => 'http://localhost:3000',
            'origin' => 'http://localhost:3000',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'remember' => false,
        ]);

        $response->assertOk();
        $this->assertNull($user->fresh()->remember_token);

        // Verify remember_web cookie is NOT sent in headers
        $rememberCookie = collect($response->headers->getCookies())
            ->first(fn ($c) => Str::startsWith($c->getName(), 'remember_web_'));

        $this->assertNull($rememberCookie, 'Remember cookie should not be set when remember is false');
    }

    public function test_login_with_remember_sets_token_and_cookie_and_authenticates_without_session(): void
    {
        $password = 'Secret123456!';
        $user = $this->createUser('user_with_remember@test.com', $password);

        $response = $this->withHeaders([
            'referer' => 'http://localhost:3000',
            'origin' => 'http://localhost:3000',
        ])->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => $password,
            'remember' => true,
        ]);

        $response->assertOk();

        // 1. Database check: remember_token must be populated
        $freshUser = $user->fresh();
        $this->assertNotNull($freshUser->remember_token, 'remember_token column in database must be set');

        // 2. Cookie check: remember_web_* cookie must be present with future expiration
        $rememberCookie = collect($response->headers->getCookies())
            ->first(fn ($c) => Str::startsWith($c->getName(), 'remember_web_'));

        $this->assertNotNull($rememberCookie, 'Remember cookie must be present when remember is true');
        $this->assertGreaterThan(time() + 86400 * 30, $rememberCookie->getExpiresTime(), 'Remember cookie must expire well in the future');

        // 3. Session restore check: Access /api/v1/auth/me WITHOUT session cookie, ONLY using remember cookie
        // Simulate browser restart where session cookie is discarded but remember cookie remains
        $reauthResponse = $this->withHeaders([
            'referer' => 'http://localhost:3000',
            'origin' => 'http://localhost:3000',
        ])->withUnencryptedCookie($rememberCookie->getName(), $rememberCookie->getValue())
            ->getJson('/api/v1/auth/me');

        $reauthResponse->assertOk();
        $reauthResponse->assertJsonPath('user.id', $user->id);
    }
}
