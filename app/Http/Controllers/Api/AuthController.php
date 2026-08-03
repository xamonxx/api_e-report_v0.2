<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Services\TelegramService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    /**
     * A valid bcrypt hash (cost 12) of a random string. Used only to burn the
     * same CPU time as a real Hash::check when the supplied email has no account,
     * defeating timing-based user enumeration. Never matches any real password.
     */
    private const DUMMY_HASH = '$2y$12$bVACSeOR0J3SFk2JgSTLLu701EDuZqFxlVl6JRvBGARKRzBBVOetG';

    /**
     * Authenticate via Sanctum SPA session.
     * Next.js calls POST /sanctum/csrf-cookie first, then POST /api/v1/auth/login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $email = $request->validated('email');
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // ── Brute Force Protection ───────────────────────────────
        // Layer 1 (per email+IP): blocks 5 failures in 15 min from the same IP
        if (LoginAttempt::isTooManyAttempts($email, $ip, maxAttempts: 5, minutes: 15)) {
            LoginAttempt::record($email, $ip, $userAgent, false);

            return response()->json([
                'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 15 menit.',
            ], 429);
        }

        // F-012 Layer 2 (per email, any IP): blocks distributed/credential stuffing
        // attacks where the attacker rotates IPs to bypass per-IP checks.
        if (LoginAttempt::isTooManyAttemptsByEmail($email, maxAttempts: 10, minutes: 60)) {
            LoginAttempt::record($email, $ip, $userAgent, false);

            return response()->json([
                'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 1 jam.',
            ], 429);
        }

        $credentials = $request->safe()->only(['email', 'password']);

        $user = User::where('email', $credentials['email'])->first();

        // F-013: Constant-time user enumeration defense. When the email does not
        // exist we still run a bcrypt comparison against a dummy hash so the
        // response time is indistinguishable from a wrong-password attempt.
        // Without this, "no such user" returns measurably faster than "bad
        // password", leaking which emails are registered.
        $passwordMatches = $user
            ? Hash::check($credentials['password'], $user->password)
            : Hash::check($credentials['password'], self::DUMMY_HASH);

        if ($user && $passwordMatches) {
            LoginAttempt::record($email, $ip, $userAgent, true);

            $user->timestamps = false;
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $ip,
            ]);
            $user->timestamps = true;

            // End the legacy bearer-token era before starting the browser session.
            // The session ID is stored in an HttpOnly cookie and cannot be read by JS.
            $user->tokens()->delete();
            Auth::guard('web')->login($user, (bool) $request->validated('remember'));
            $request->session()->regenerate();

            return response()->json([
                'user' => $this->formatUser($user),
                'message' => 'Login berhasil.',
            ]);
        }

        LoginAttempt::record($email, $ip, $userAgent, false);

        // Telegram alert: notify ONLY on the 5th consecutive failed attempt (exactly 5, not 6+).
        // This prevents spamming the Telegram chat if the user keeps trying after being blocked.
        $failedCount = LoginAttempt::recentFailedCount($email, $ip, minutes: 15);
        if ($failedCount === 5) {
            app(TelegramService::class)->sendFailedLoginAlert(
                $email,
                $ip,
                $failedCount,
                $userAgent
            );
        }

        return response()->json([
            'message' => 'Email atau password tidak sesuai.',
        ], 401);
    }

    /**
     * Look up primary color for a user by email before login.
     * SECURITY FIX 2026-07-29: Always return default color to prevent user enumeration.
     * Domain-based fallback lookup removed to prevent organization discovery attacks.
     */
    public function colorLookup(Request $request): JsonResponse
    {
        $email = $request->query('email');

        if (empty($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['primary_color' => '#f59e0b']);
        }

        // Always return default color to prevent user enumeration via color fingerprinting
        // Frontend can still call this endpoint for UX consistency, but no data is leaked
        return response()->json(['primary_color' => '#f59e0b']);
    }

    /**
     * Return authenticated user info.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->loadMissing('account:id,name,logo_path');

        return response()->json([
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Logout and revoke current token.
     */
    public function logout(Request $request): JsonResponse
    {
        // Also revoke a legacy bearer token when an older client logs out.
        $accessToken = $request->user()->currentAccessToken();
        if ($accessToken && method_exists($accessToken, 'delete')) {
            $accessToken->delete();
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    private function formatUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role instanceof UserRole ? $user->role->value : $user->role,
            'account_id' => $user->account_id,
            'account' => $user->account ? [
                'id' => $user->account->id,
                'name' => $user->account->name,
                'logo' => $user->account->logo_path,
            ] : null,
            'primary_color' => $user->primary_color,
            'avatar' => $user->avatar_path,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
