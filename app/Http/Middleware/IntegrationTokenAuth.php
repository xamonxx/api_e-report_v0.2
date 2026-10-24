<?php

namespace App\Http\Middleware;

use App\Models\IntegrationClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates read-only integration clients by bearer token.
 * - Only GET/HEAD are allowed (defense in depth; integration routes are GET-only too).
 * - The resolved client is exposed as the `integration_client` request attribute, never as a user.
 */
class IntegrationTokenAuth
{
    public function handle(Request $request, Closure $next, string $ability = IntegrationClient::ABILITY_CONSULTATIONS_READ): Response
    {
        if (! in_array($request->method(), ['GET', 'HEAD'], true)) {
            return response()->json(['message' => 'Integrasi hanya mengizinkan akses baca.'], 405, ['Allow' => 'GET, HEAD']);
        }

        $token = (string) $request->bearerToken();
        if ($token === '' || ! str_starts_with($token, 'erpi_')) {
            return response()->json(['message' => 'Token integrasi tidak valid.'], 401);
        }

        $hash = hash('sha256', $token);
        $client = IntegrationClient::query()->where('token_hash', $hash)->first();

        if (! $client || ! hash_equals($client->token_hash, $hash) || ! $client->isUsable()) {
            return response()->json(['message' => 'Token integrasi tidak valid.'], 401);
        }

        if (! $client->can($ability)) {
            return response()->json(['message' => 'Token integrasi tidak memiliki izin ini.'], 403);
        }

        // Record usage at most once per minute; raw update skips model events/timestamps.
        if (! $client->last_used_at || $client->last_used_at->lt(now()->subMinute())) {
            DB::table('integration_clients')->where('id', $client->id)->update([
                'last_used_at' => now(),
                'last_used_ip' => $request->ip(),
            ]);
        }

        $request->attributes->set('integration_client', $client);

        return $next($request);
    }
}
