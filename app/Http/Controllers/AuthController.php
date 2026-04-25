<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Models\LoginAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    /**
     * Show login form.
     * Redirect to dashboard if already authenticated.
     */
    public function showLogin()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }
        return view('auth.login');
    }

    /**
     * Authenticate user with brute force protection.
     *
     * Security improvements:
     * - Rate limiting: max 5 failed attempts per 15 minutes per email+IP
     * - Login attempt logging (success + failure)
     * - Session regeneration on successful login (prevents session fixation)
     * - Last login tracking (IP + timestamp)
     */
    public function authenticate(LoginRequest $request)
    {
        $email = $request->validated('email');
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // ── Brute Force Protection ───────────────────────────────
        if (LoginAttempt::isTooManyAttempts($email, $ip, maxAttempts: 5, minutes: 15)) {
            // Log the blocked attempt too
            LoginAttempt::record($email, $ip, $userAgent, false);

            return back()->withErrors([
                'email' => 'Terlalu banyak percobaan login yang gagal. Silakan coba lagi dalam 15 menit.',
            ])->onlyInput('email');
        }

        $credentials = $request->safe()->only(['email', 'password']);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            // ── Successful Login ─────────────────────────────────
            // 1. Regenerate session to prevent session fixation
            $request->session()->regenerate();

            // 2. Log successful attempt
            LoginAttempt::record($email, $ip, $userAgent, true);

            // 3. Track last login info on the user record
            $user = Auth::user();
            $user->timestamps = false; // Don't update updated_at for login tracking
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $ip,
            ]);
            $user->timestamps = true;

            return redirect()->intended(route('dashboard'));
        }

        // ── Failed Login ─────────────────────────────────────────
        LoginAttempt::record($email, $ip, $userAgent, false);

        return back()->withErrors([
            'email' => 'Email atau password tidak sesuai.',
        ])->onlyInput('email');
    }

    /**
     * Logout user with full session destruction.
     *
     * Security improvements:
     * - Complete server-side session invalidation
     * - CSRF token regeneration
     * - Cookie destruction via session invalidate()
     */
    public function logout(Request $request)
    {
        Auth::logout();

        // Invalidate the session entirely (server-side + cookie)
        $request->session()->invalidate();

        // Regenerate CSRF token to prevent token reuse
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
