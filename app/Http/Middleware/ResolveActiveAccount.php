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
        $activeId = $requested !== null && $requested !== ''
            ? filter_var($requested, FILTER_VALIDATE_INT)
            : (int) $user->account_id;

        abort_unless($activeId && $allowedIds->contains((int) $activeId), 403, 'Akun aktif tidak termasuk akses admin ini.');

        $account = Account::query()->find($activeId);
        abort_unless($account, 403, 'Akun aktif tidak tersedia.');

        app(ActiveAccount::class)->set($account);
        $request->attributes->set('active_account_id', (int) $activeId);

        return $next($request);
    }
}
