<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Run after the response is sent — zero added latency.
     * Updates last_seen_at at most once per minute per user to limit DB writes.
     * Uses raw DB update to bypass model observers and timestamps.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (!$user) {
            return;
        }

        // Use abs() to handle signed/negative difference results
        if ($user->last_seen_at && abs(now()->diffInSeconds($user->last_seen_at, false)) < 60) {
            return;
        }

        DB::table('users')
            ->where('id', $user->id)
            ->update(['last_seen_at' => now()]);
    }
}
