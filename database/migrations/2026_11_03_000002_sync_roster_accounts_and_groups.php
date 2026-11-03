<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\User;
use App\Support\GreenMudaRoster;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $defaultPassword = env('SEED_DEFAULT_PASSWORD', 'Putraramadhan93');
        $hashedDefaultPassword = Hash::make($defaultPassword);

        // 1. Pastikan 5 grup akun aktif
        $groups = [
            ['code' => 'PC', 'name' => 'PC', 'sort_order' => 10],
            ['code' => 'INSIP', 'name' => 'INSIP', 'sort_order' => 20],
            ['code' => 'MITRA SDK', 'name' => 'MITRA SDK', 'sort_order' => 30],
            ['code' => 'MITRA BSR', 'name' => 'MITRA BSR', 'sort_order' => 40],
            ['code' => 'PRIBADI', 'name' => 'PRIBADI', 'sort_order' => 50],
        ];

        $groupModels = [];
        foreach ($groups as $g) {
            $groupModels[$g['code']] = AccountGroup::firstOrCreate(
                ['code' => $g['code']],
                [
                    'name' => $g['name'],
                    'sort_order' => $g['sort_order'],
                    'is_active' => true,
                ]
            );
            $groupModels[$g['code']]->update([
                'sort_order' => $g['sort_order'],
                'is_active' => true,
            ]);
        }

        // 2. Alias penamaan akun historis ke nama baru
        $aliases = [
            'FULLHOME ID' => 'FULLHOME',
            'FULLHOME INTERIOR' => 'FULLHOME',
            'DEMOAKUN INTERIOR' => 'SOLUTION INTERIOR',
            'demoakun INTERIOR' => 'SOLUTION INTERIOR',
            'NISCALA FURN' => 'NISCALA FURNITURE',
            'NACIRA STUDIO' => 'NACIRA INTERIOR',
            'PUTRA M' => 'PUTRA MOULDING',
            'KITCHENSET BANDUNG BARAT' => 'KITCHENSET SOLUTION BANDUNG',
        ];

        foreach ($aliases as $legacy => $canonical) {
            $existing = Account::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($legacy)])->first();
            $target = Account::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($canonical)])->first();
            if ($existing && ! $target) {
                $existing->name = $canonical;
                $existing->save();
            }
        }

        // 3. Pastikan 31 akun ada, grupnya sesuai, dan ID-nya sesuai GreenMudaRoster::ACCOUNT_IDS
        $rosterAccounts = GreenMudaRoster::accounts();
        $accountMap = [];

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        try {
            $matchedAccounts = [];
            foreach ($rosterAccounts as $row) {
                $accountName = $row['account'];
                $account = Account::withTrashed()->whereRaw('LOWER(name) = ?', [mb_strtolower($accountName)])->first();
                if ($account) {
                    $matchedAccounts[$accountName] = $account;
                }
            }

            $targetIds = collect($rosterAccounts)->pluck('id')->filter()->all();
            $matchedCurrentIds = collect($matchedAccounts)->pluck('id')->all();
            Account::withTrashed()
                ->whereIn('id', $targetIds)
                ->whereNotIn('id', $matchedCurrentIds)
                ->orderByDesc('id')
                ->get()
                ->each(function (Account $other) {
                    $tempId = 2000 + (int) $other->id;
                    $this->remapAccountReferences((int) $other->id, $tempId);
                    DB::table('accounts')->where('id', $other->id)->update(['id' => $tempId]);
                });

            foreach ($matchedAccounts as $accountName => $account) {
                $expectedId = GreenMudaRoster::ACCOUNT_IDS[$accountName] ?? null;
                if ($expectedId && (int) $account->id !== $expectedId) {
                    $tempId = 1000 + $expectedId;
                    $this->remapAccountReferences((int) $account->id, $tempId);
                    DB::table('accounts')->where('id', $account->id)->update(['id' => $tempId]);
                    $account->id = $tempId;
                }
            }

            foreach ($rosterAccounts as $row) {
                $groupCode = $row['group'];
                $accountName = $row['account'];
                $expectedId = $row['id'] ?? GreenMudaRoster::ACCOUNT_IDS[$accountName] ?? null;
                $groupId = $groupModels[$groupCode]->id ?? null;

                $account = $matchedAccounts[$accountName] ?? null;
                if ($account) {
                    if ($expectedId && (int) $account->id !== $expectedId) {
                        $oldId = (int) $account->id;
                        DB::table('accounts')->where('id', $oldId)->update(['id' => $expectedId]);
                        $this->remapAccountReferences($oldId, $expectedId);
                    }
                    $account = Account::withTrashed()->findOrFail($expectedId ?: $account->id);
                    $account->name = $accountName;
                    $account->account_group = $groupCode;
                    $account->account_group_id = $groupId;
                    if (! $account->description) {
                        $account->description = 'PUTRA CORPORATION';
                    }
                    $account->save();
                } else {
                    $insertData = [
                        'name' => $accountName,
                        'description' => 'PUTRA CORPORATION',
                        'account_group' => $groupCode,
                        'account_group_id' => $groupId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if ($expectedId) {
                        $insertData['id'] = $expectedId;
                    }
                    $insertedId = DB::table('accounts')->insertGetId($insertData);
                    $account = Account::withTrashed()->findOrFail($expectedId ?: $insertedId);
                }

                if ($account->trashed()) {
                    $account->restore();
                }

                $accountMap[$accountName] = $account;
            }
        } finally {
            \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();
        }

        // 4. Nonaktifkan akun yang sudah tidak ada di roster (INTERIOR KITA & ALLFAR INTERIOR)
        $activeAccountNames = array_keys($accountMap);
        Account::query()
            ->whereIn('name', ['INTERIOR KITA', 'ALLFAR INTERIOR'])
            ->whereNotIn('name', $activeAccountNames)
            ->each(function (Account $account) {
                $account->members()->detach();
                $account->delete();
            });

        // 5. Admin Mapping & Keanggotaan Akun
        $adminAliases = [
            'SANDIKA' => ['Sandika'],
            'DIAN G' => ['DIAN'],
            'HASNI' => ['PAK HAS'],
        ];

        // Kelompokkan akun per admin
        $byAdmin = collect($rosterAccounts)->groupBy('admin');

        $activeAdminIds = [];

        foreach ($byAdmin as $adminName => $rows) {
            $accountIds = $rows->map(fn ($r) => $accountMap[$r['account']]->id)->values()->all();
            $defaultAccountId = $accountIds[0] ?? null;

            $canonicalEmail = Str::slug($adminName, '.').'.admin@ereport.app';
            $aliasNames = array_map('mb_strtolower', [$adminName, ...($adminAliases[$adminName] ?? [])]);

            // Cari user admin yang sudah ada
            $user = User::withTrashed()
                ->where('role', UserRole::Admin->value)
                ->where(function ($query) use ($canonicalEmail, $aliasNames) {
                    $query->whereRaw('LOWER(email) = ?', [mb_strtolower($canonicalEmail)])
                        ->orWhereIn(DB::raw('LOWER(name)'), $aliasNames);
                })
                ->orderByRaw('CASE WHEN LOWER(email) = ? THEN 0 ELSE 1 END', [mb_strtolower($canonicalEmail)])
                ->first();

            if (! $user) {
                $user = new User([
                    'name' => $adminName,
                    'email' => $canonicalEmail,
                    'password' => $hashedDefaultPassword,
                ]);
                $user->role = UserRole::Admin;
            }

            if ($user->trashed()) {
                $user->restore();
            }

            $user->name = $adminName;
            $user->email = $canonicalEmail;
            $user->account_id = $defaultAccountId;
            $user->save();

            // Sinkronkan relasi multi-akun
            $user->accounts()->sync($accountIds);
            $activeAdminIds[] = $user->id;
        }

        // 6. Lepas akun dari admin non-aktif (SRI & LISA)
        User::query()
            ->where('role', UserRole::Admin->value)
            ->whereNotIn('id', $activeAdminIds)
            ->each(function (User $admin) {
                $admin->accounts()->detach();
                $admin->account_id = null;
                $admin->save();
            });
    }

    public function down(): void
    {
        // Migration aditif tidak merusak data saat rollback
    }

    private function remapAccountReferences(int $oldId, int $newId): void
    {
        foreach (['users', 'account_user', 'consultations', 'consultation_sequences', 'consultation_imports', 'surveys', 'report_attendances'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                DB::table($table)->where('account_id', $oldId)->update(['account_id' => $newId]);
            }
        }
    }
};
