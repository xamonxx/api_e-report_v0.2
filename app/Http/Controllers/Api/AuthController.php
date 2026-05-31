<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\LoginAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
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
        if (LoginAttempt::isTooManyAttemptsByEmail($email, maxAttempts: 20, minutes: 60)) {
            LoginAttempt::record($email, $ip, $userAgent, false);

            return response()->json([
                'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 1 jam.',
            ], 429);
        }

        $credentials = $request->safe()->only(['email', 'password']);

        $user = \App\Models\User::where('email', $credentials['email'])->first();

        if ($user && \Illuminate\Support\Facades\Hash::check($credentials['password'], $user->password)) {
            LoginAttempt::record($email, $ip, $userAgent, true);

            $user->timestamps = false;
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $ip,
            ]);
            $user->timestamps = true;

            // Revoke old tokens and issue a fresh Sanctum Bearer token
            $user->tokens()->delete();
            $token = $user->createToken('spa-token')->plainTextToken;

            return response()->json([
                'user' => $this->formatUser($user),
                'token' => $token,
                'message' => 'Login berhasil.',
            ]);
        }

        LoginAttempt::record($email, $ip, $userAgent, false);

        return response()->json([
            'message' => 'Email atau password tidak sesuai.',
        ], 401);
    }

    /**
     * Look up primary color for a user by email before login.
     */
    public function colorLookup(Request $request): JsonResponse
    {
        $email = $request->query('email');
        
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['primary_color' => '#f59e0b']);
        }

        $user = \App\Models\User::where('email', $email)->first();

        if (!$user) {
            $domain = substr(strrchr($email, "@"), 1);
            if ($domain) {
                $user = \App\Models\User::where('email', 'like', "%@{$domain}")
                    ->whereNotNull('primary_color')
                    ->first();
            }
        }
        
        return response()->json([
            'primary_color' => $user && $user->primary_color ? $user->primary_color : '#f59e0b'
        ]);
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
        // Revoke the token that was used for this request
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logout berhasil.']);
    }

    private function formatUser($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role instanceof \App\Enums\UserRole ? $user->role->value : $user->role,
            'account_id' => $user->account_id,
            'account' => $user->account ? [
                'id' => $user->account->id,
                'name' => $user->account->name,
                'logo' => $user->account->logo_path,
            ] : null,
            'primary_color' => $user->primary_color,
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];
    }
}
