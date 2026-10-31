<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\ActiveAccount;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user?->isAdmin()) {
            return $next($request);
        }

        $allowedIds = $user->accounts()->pluck('accounts.id')->map(fn ($id) => (int) $id);
        if ($allowedIds->isEmpty() && $user->account_id) {
            $allowedIds->push((int) $user->account_id);
        }

        $requested = $request->header('X-Account-Id');

        // An admin with no account at all has nothing to scope to yet. This group
        // also covers /auth/me, /auth/logout and the push endpoints, so aborting
        // here would lock such an admin out of the app entirely. Per-endpoint
        // authorization still applies; only the account scope stays unset.
        if ($allowedIds->isEmpty()) {
            abort_if($request->headers->has('X-Account-Id'), 403, 'Anda tidak memiliki akses ke akun ini.');

            if ($request->is('api/v1/auth/*', 'api/v1/settings/*', 'api/v1/notifications/*', 'api/v1/push/*')) {
                return $next($request);
            }

            if ($request->isMethodSafe() && ! $request->is('api/v1/consultations/preview-id', 'api/v1/consultations/id-preview')) {
                return $next($request);
            }

            abort(403, 'Akun Anda belum ditugaskan ke akun interior mana pun.');
        }

        // Tanpa header: pakai akun primer bila masih termasuk akses admin. Akun
        // primer bisa null/terlepas (mis. akunnya dihapus) padahal admin masih
        // memegang akun lain di pivot; jangan kunci admin itu dengan 403.
        $activeId = $requested !== null && $requested !== ''
            ? filter_var($requested, FILTER_VALIDATE_INT)
            : ($allowedIds->contains((int) $user->account_id) ? (int) $user->account_id : $allowedIds->first());

        abort_unless($activeId && $allowedIds->contains((int) $activeId), 403, 'Akun aktif tidak termasuk akses admin ini.');

        $account = Account::query()->find($activeId);
        abort_unless($account, 403, 'Akun aktif tidak tersedia.');

        app(ActiveAccount::class)->set($account);
        $request->attributes->set('active_account_id', (int) $activeId);

        return $next($request);
    }
}
