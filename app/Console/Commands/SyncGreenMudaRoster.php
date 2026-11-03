<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AccountGroup;
use App\Models\User;
use App\Support\GreenMudaRoster;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class SyncGreenMudaRoster extends Command
{
    protected $signature = 'app:sync-green-muda-roster {--apply : Terapkan perubahan (default dry-run)} {--credentials= : Path file kredensial baru di luar Git} {--since=2026-10-21 : Tanggal awal migrasi grup data konsultasi}';

    protected $description = 'Sinkronkan 31 akun, super admin, admin multi-akun, manager global, dan 7 surveyor Green Muda';

    private const ALIASES = [
        'FULLHOME ID' => 'FULLHOME',
        'FULLHOME INTERIOR' => 'FULLHOME',
        'DEMOAKUN INTERIOR' => 'SOLUTION INTERIOR',
        'NACIRA STUDIO' => 'NACIRA INTERIOR',
        'NISCALA FURN' => 'NISCALA FURNITURE',
        'PUTRA M' => 'PUTRA MOULDING',
        'KITCHENSET BANDUNG BARAT' => 'KITCHENSET SOLUTION BANDUNG',
    ];

    private const ADMIN_NAME_ALIASES = [
        'SANDIKA' => ['Sandika'],
        'DIAN G' => ['DIAN'],
        'HASNI' => ['PAK HAS'],
    ];

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Command ini hanya boleh dijalankan di local/testing.');

            return self::FAILURE;
        }

        $rows = GreenMudaRoster::accounts();
        if (count($rows) !== 31 || count(GreenMudaRoster::GROUPS) !== 5) {
            throw new RuntimeException('Kontrak roster rusak: harus tepat 31 akun dan 5 grup.');
        }

        $since = $this->option('since') ?: '2026-10-21';
        $pendingConsultations = $this->pendingConsultationsCount($rows, $since);
        $adminCount = collect($rows)->pluck('admin')->unique()->count();
        $this->info(($this->option('apply') ? 'APPLY' : 'DRY-RUN').": 31 akun, 5 grup, {$adminCount} admin unik, 1 super admin, 7 surveyor global. Konsultasi sejak {$since} butuh update: {$pendingConsultations}.");
        if (! $this->option('apply')) {
            foreach ($rows as $row) {
                $this->line("{$row['group']} | {$row['admin']} -> {$row['account']}");
            }

            return self::SUCCESS;
        }

        $credentials = [];
        $consultationUpdates = 0;
        $credentialsPath = DB::transaction(function () use ($rows, $since, &$credentials, &$consultationUpdates) {
            $accounts = $this->syncAccounts($rows);
            $consultationUpdates = $this->syncConsultations($since);
            $this->syncSuperAdmin($credentials);
            $this->syncAdmins($rows, $accounts, $credentials);
            $this->syncSurveyTeam($credentials);

            return $credentials === [] ? null : $this->writeCredentials($credentials);
        });

        if ($credentialsPath) {
            $this->warn("Kredensial baru disimpan di luar Git: {$credentialsPath}");
        }

        $this->info("Roster Green Muda berhasil disinkronkan. ({$consultationUpdates} data konsultasi sejak {$since} berhasil dipindahkan ke grup baru).");

        return self::SUCCESS;
    }

    private function syncSuperAdmin(array &$credentials): void
    {
        $identity = GreenMudaRoster::SUPER_ADMIN;
        $user = User::withTrashed()
            ->where('role', UserRole::SuperAdmin->value)
            ->where(function ($query) use ($identity) {
                $query->whereRaw('LOWER(email) = ?', [mb_strtolower($identity['email'])])
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($identity['name'])]);
            })->orderBy('id')->first();

        if (! $user) {
            if (User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($identity['email'])])->exists()) {
                throw new RuntimeException("Konflik email {$identity['email']}; sinkronisasi dihentikan.");
            }
            $password = Str::password(20);
            $user = new User(['email' => $identity['email'], 'password' => Hash::make($password)]);
            $user->role = UserRole::SuperAdmin;
            $credentials[] = [$identity['name'], $identity['email'], $password, 'super_admin'];
        }
        if ($user->trashed()) {
            $user->restore();
        }
        $user->name = $identity['name'];
        $user->account_id = null;
        $user->survey_team = null;
        $user->save();
        $user->accounts()->detach();
        $user->tokens()->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    private function syncAccounts(array $rows): array
    {
        $all = Account::withTrashed()->get();
        $byNormalized = $all->keyBy(fn (Account $account) => $this->normalize($account->name));
        $activeIds = [];
        $resolved = [];

        $hasAccountGroupsTable = Schema::hasTable('account_groups');

        Schema::disableForeignKeyConstraints();
        try {
            // Fase 1: Geser semua akun yang ID-nya belum sesuai target ke offset +1000 agar bebas tabrakan PK
            $matchedAccounts = [];
            foreach ($rows as $row) {
                $target = $row['account'];
                $expectedId = $row['id'] ?? GreenMudaRoster::ACCOUNT_IDS[$target] ?? null;
                $lookup = $this->normalize($target);
                $account = $byNormalized->get($lookup);
                if (! $account) {
                    $legacy = collect(self::ALIASES)->search($target, true);
                    $account = $legacy ? $byNormalized->get($this->normalize($legacy)) : null;
                }
                if ($account) {
                    $matchedAccounts[$target] = $account;
                }
            }

            // Geser juga akun non-roster yang kebetulan menduduki expectedId
            $targetIds = collect($rows)->map(fn ($r) => $r['id'] ?? GreenMudaRoster::ACCOUNT_IDS[$r['account']])->filter()->all();
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

            foreach ($matchedAccounts as $target => $account) {
                $expectedId = GreenMudaRoster::ACCOUNT_IDS[$target] ?? null;
                if ($expectedId && (int) $account->id !== $expectedId) {
                    $tempId = 1000 + $expectedId;
                    $this->remapAccountReferences((int) $account->id, $tempId);
                    DB::table('accounts')->where('id', $account->id)->update(['id' => $tempId]);
                    $account->id = $tempId;
                }
            }

            // Fase 2: Tempatkan setiap akun roster ke expectedId eksaknya
            foreach ($rows as $row) {
                $target = $row['account'];
                $expectedId = $row['id'] ?? GreenMudaRoster::ACCOUNT_IDS[$target] ?? null;
                $account = $matchedAccounts[$target] ?? null;

                $groupId = null;
                if ($hasAccountGroupsTable) {
                    $groupModel = AccountGroup::firstOrCreate(
                        ['code' => $row['group']],
                        ['name' => $row['group'], 'is_active' => true]
                    );
                    $groupId = $groupModel->id;
                }

                if ($account) {
                    if ($expectedId && (int) $account->id !== $expectedId) {
                        $oldId = (int) $account->id;
                        DB::table('accounts')->where('id', $oldId)->update(['id' => $expectedId]);
                        $this->remapAccountReferences($oldId, $expectedId);
                    }
                    $account = Account::withTrashed()->findOrFail($expectedId ?: $account->id);
                    $account->name = $target;
                    $account->account_group = $row['group'];
                    if ($hasAccountGroupsTable) {
                        $account->account_group_id = $groupId;
                    }
                    $account->save();
                } else {
                    $insertData = [
                        'name' => $target,
                        'account_group' => $row['group'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                    if ($expectedId) {
                        $insertData['id'] = $expectedId;
                    }
                    if ($hasAccountGroupsTable) {
                        $insertData['account_group_id'] = $groupId;
                    }
                    $insertedId = DB::table('accounts')->insertGetId($insertData);
                    $account = Account::withTrashed()->findOrFail($expectedId ?: $insertedId);
                }

                if ($account->trashed()) {
                    $account->restore();
                }
                $activeIds[] = $account->id;
                $resolved[$target] = $account;
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        Account::query()->whereNotIn('id', $activeIds)->each(fn (Account $account) => $account->delete());

        return $resolved;
    }

    private function remapAccountReferences(int $oldId, int $newId): void
    {
        foreach (['users', 'account_user', 'consultations', 'consultation_sequences', 'consultation_imports', 'surveys', 'report_attendances'] as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where('account_id', $oldId)->update(['account_id' => $newId]);
            }
        }
    }

    private function syncAdmins(array $rows, array $accounts, array &$credentials): void
    {
        $byAdmin = collect($rows)->groupBy('admin');
        $canonicalIds = [];

        foreach ($byAdmin as $displayName => $adminRows) {
            $accountIds = $adminRows->map(fn ($row) => $accounts[$row['account']]->id)->values()->all();
            $defaultId = $accountIds[0];
            $candidates = $this->activeAdminCandidates($displayName, $accountIds);

            $user = $candidates->first();
            if (! $user) {
                $email = Str::slug($displayName, '.').'.admin@ereport.app';
                if (User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                    throw new RuntimeException("Konflik email {$email}; sinkronisasi dihentikan.");
                }
                $password = env('SEED_DEFAULT_PASSWORD', 'Putraramadhan93');
                $user = new User(['email' => $email, 'password' => Hash::make($password)]);
                $user->role = UserRole::Admin;
                $credentials[] = [$displayName, $email, $password, 'admin'];
            }
            if ($user->trashed()) {
                $user->restore();
            }
            $user->name = $displayName;
            $user->account_id = $defaultId;
            $user->survey_team = null;
            $user->save();
            $user->accounts()->sync($accountIds);
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $canonicalIds[] = $user->id;

            $candidates->where('id', '!=', $user->id)->each(function (User $duplicate) {
                $duplicate->tokens()->delete();
                DB::table('sessions')->where('user_id', $duplicate->id)->delete();
                $duplicate->accounts()->detach();
                $duplicate->delete();
            });
        }

        User::query()->where('role', UserRole::Admin->value)->whereNotIn('id', $canonicalIds)->each(function (User $user) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->accounts()->detach();
            $user->delete();
        });
    }

    private function activeAdminCandidates(string $displayName, array $accountIds = []): Collection
    {
        $normalizedName = mb_strtolower($displayName);
        $normalizedNames = collect([$displayName, ...(self::ADMIN_NAME_ALIASES[$displayName] ?? [])])
            ->map(fn (string $name) => mb_strtolower($name))
            ->all();
        $canonicalEmail = mb_strtolower(Str::slug($displayName, '.').'.admin@ereport.app');

        $identityCandidates = User::query()
            ->where('role', UserRole::Admin->value)
            ->where(function ($query) use ($normalizedNames, $canonicalEmail) {
                $query->whereIn(DB::raw('LOWER(name)'), $normalizedNames)
                    ->orWhereRaw('LOWER(email) = ?', [$canonicalEmail]);
            })
            ->orderByRaw('CASE WHEN LOWER(email) = ? THEN 0 WHEN LOWER(name) = ? THEN 1 ELSE 2 END', [
                $canonicalEmail,
                $normalizedName,
            ])
            ->orderBy('id')
            ->get();

        if ($identityCandidates->count() <= 1) {
            return $identityCandidates;
        }

        $canonicalEmailCandidates = $identityCandidates->filter(
            fn (User $candidate) => mb_strtolower($candidate->email) === $canonicalEmail
        )->values();

        if ($canonicalEmailCandidates->count() === 1) {
            return $canonicalEmailCandidates;
        }

        if ($accountIds !== []) {
            $defaultId = $accountIds[0] ?? null;
            $accountMatched = $identityCandidates->filter(
                fn (User $candidate) => in_array($candidate->account_id, $accountIds, true)
            )->values();

            if ($accountMatched->isNotEmpty()) {
                return $accountMatched->sortBy(function (User $candidate) use ($defaultId) {
                    return $candidate->account_id === $defaultId ? 0 : 1;
                })->values();
            }
        }

        throw new RuntimeException("Admin {$displayName} ambigu; ditemukan beberapa identitas user aktif.");
    }

    private function syncConsultations(string $since): int
    {
        $accounts = Account::query()->get(['id', 'account_group', 'account_group_id'])->keyBy('id');
        $affected = 0;

        foreach ($accounts as $accountId => $account) {
            if (! $account->account_group || ! $account->account_group_id) {
                continue;
            }

            $count = DB::table('consultations')
                ->where('account_id', $accountId)
                ->where('consultation_date', '>=', $since)
                ->where(function ($query) use ($account) {
                    $query->where('account_group', '!=', $account->account_group)
                        ->orWhereNull('account_group')
                        ->orWhere('account_group_id', '!=', $account->account_group_id)
                        ->orWhereNull('account_group_id');
                })
                ->update([
                    'account_group' => $account->account_group,
                    'account_group_id' => $account->account_group_id,
                    'updated_at' => now(),
                ]);

            $affected += $count;
        }

        return $affected;
    }

    private function pendingConsultationsCount(array $rows, string $since): int
    {
        $all = Account::withTrashed()->get();
        $byNormalized = $all->keyBy(fn (Account $account) => $this->normalize($account->name));

        $targetGroupIds = Schema::hasTable('account_groups')
            ? AccountGroup::query()->pluck('id', 'code')->all()
            : [];

        $count = 0;
        foreach ($rows as $row) {
            $target = $row['account'];
            $lookup = $this->normalize($target);
            $account = $byNormalized->get($lookup);
            if (! $account) {
                $legacy = collect(self::ALIASES)->search($target, true);
                $account = $legacy ? $byNormalized->get($this->normalize($legacy)) : null;
            }

            if (! $account) {
                continue;
            }

            $targetGroupCode = $row['group'];
            $targetGroupId = $targetGroupIds[$targetGroupCode] ?? null;

            $count += DB::table('consultations')
                ->where('account_id', $account->id)
                ->where('consultation_date', '>=', $since)
                ->where(function ($query) use ($targetGroupCode, $targetGroupId) {
                    $query->where('account_group', '!=', $targetGroupCode)
                        ->orWhereNull('account_group')
                        ->orWhere('account_group_id', '!=', $targetGroupId)
                        ->orWhereNull('account_group_id');
                })
                ->count();
        }

        return $count;
    }

    private function syncSurveyTeam(array &$credentials): void
    {
        User::query()->where('role', UserRole::ManagerSurveyor->value)->update(['survey_team' => null]);
        $keep = [];
        foreach (GreenMudaRoster::SURVEYORS as $name) {
            $email = Str::slug($name, '.').'.surveyor@ereport.app';
            $user = User::withTrashed()->where('role', UserRole::Surveyor->value)
                ->where(function ($query) use ($name, $email) {
                    $query->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                        ->orWhereRaw('LOWER(email) = ?', [mb_strtolower($email)]);
                })->first();
            if (! $user) {
                if (User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                    throw new RuntimeException("Konflik email {$email}; sinkronisasi dihentikan.");
                }
                $password = env('SEED_DEFAULT_PASSWORD', 'Putraramadhan93');
                $user = new User(['email' => $email, 'password' => Hash::make($password)]);
                $user->role = UserRole::Surveyor;
                $credentials[] = [$name, $email, $password, 'surveyor'];
            }
            if ($user->trashed()) {
                $user->restore();
            }
            $user->name = $name;
            $user->account_id = null;
            $user->survey_team = null;
            $user->save();
            $keep[] = $user->id;
        }
        User::query()->where('role', UserRole::Surveyor->value)->whereNotIn('id', $keep)->each(function (User $user) {
            $user->tokens()->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->delete();
        });
    }

    private function writeCredentials(array $rows): string
    {
        $path = $this->option('credentials') ?: storage_path('app/private/green-muda-credentials-'.now()->format('Ymd-His').'.csv');
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Folder kredensial gagal dibuat: {$directory}");
        }
        $handle = fopen($path, 'xb');
        if (! $handle) {
            throw new RuntimeException("File kredensial gagal dibuat: {$path}");
        }
        fputcsv($handle, ['name', 'email', 'password', 'role']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
        chmod($path, 0600);

        return realpath($path) ?: $path;
    }

    private function normalize(string $value): string
    {
        return mb_strtoupper(trim(preg_replace('/[^\pL\pN]+/u', ' ', $value)));
    }
}
