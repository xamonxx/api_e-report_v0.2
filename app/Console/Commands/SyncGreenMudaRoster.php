<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use App\Support\GreenMudaRoster;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

class SyncGreenMudaRoster extends Command
{
    protected $signature = 'app:sync-green-muda-roster {--apply : Terapkan perubahan (default dry-run)} {--credentials= : Path file kredensial baru di luar Git}';

    protected $description = 'Sinkronkan 31 akun, super admin, admin multi-akun, manager global, dan 7 surveyor Green Muda';

    private const ALIASES = [
        'FULLHOME' => 'FULLHOME ID',
        'FULLHOME INTERIOR' => 'FULLHOME ID',
        'NACIRA STUDIO' => 'NACIRA INTERIOR',
        'NISCALA FURN' => 'NISCALA FURNITURE',
        'PUTRA M' => 'PUTRA MOULDING',
        'KITCHENSET BANDUNG BARAT' => 'KITCHENSET SOLUTION BANDUNG',
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

        $adminCount = collect($rows)->pluck('admin')->unique()->count();
        $this->info(($this->option('apply') ? 'APPLY' : 'DRY-RUN').": 31 akun, 5 grup, {$adminCount} admin unik, 1 super admin, 7 surveyor global.");
        if (! $this->option('apply')) {
            foreach ($rows as $row) {
                $this->line("{$row['group']} | {$row['admin']} -> {$row['account']}");
            }

            return self::SUCCESS;
        }

        $credentials = [];
        $credentialsPath = DB::transaction(function () use ($rows, &$credentials) {
            $accounts = $this->syncAccounts($rows);
            $this->syncSuperAdmin($credentials);
            $this->syncAdmins($rows, $accounts, $credentials);
            $this->syncSurveyTeam($credentials);

            return $credentials === [] ? null : $this->writeCredentials($credentials);
        });

        if ($credentialsPath) {
            $this->warn("Kredensial baru disimpan di luar Git: {$credentialsPath}");
        }

        $this->info('Roster Green Muda berhasil disinkronkan.');

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

        foreach ($rows as $row) {
            $target = $row['account'];
            $lookup = $this->normalize($target);
            $account = $byNormalized->get($lookup);
            if (! $account) {
                $legacy = collect(self::ALIASES)->search($target, true);
                $account = $legacy ? $byNormalized->get($this->normalize($legacy)) : null;
            }
            $account ??= new Account;
            $account->name = $target;
            $account->account_group = $row['group'];
            $account->save();
            if ($account->trashed()) {
                $account->restore();
            }
            $activeIds[] = $account->id;
            $resolved[$target] = $account;
        }

        Account::query()->whereNotIn('id', $activeIds)->each(fn (Account $account) => $account->delete());

        return $resolved;
    }

    private function syncAdmins(array $rows, array $accounts, array &$credentials): void
    {
        $byAdmin = collect($rows)->groupBy('admin');
        $canonicalIds = [];

        foreach ($byAdmin as $displayName => $adminRows) {
            $accountIds = $adminRows->map(fn ($row) => $accounts[$row['account']]->id)->values()->all();
            $defaultId = $accountIds[0];
            $candidates = User::withTrashed()->where('role', UserRole::Admin->value)
                ->where(function ($query) use ($displayName, $accountIds) {
                    $query->whereIn('account_id', $accountIds)->orWhereRaw('LOWER(name) = ?', [mb_strtolower($displayName)]);
                })->orderByRaw('account_id = ? desc', [$defaultId])->orderBy('id')->get();

            $user = $candidates->first();
            if (! $user) {
                $email = Str::slug($displayName, '.').'.admin@ereport.app';
                if (User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                    throw new RuntimeException("Konflik email {$email}; sinkronisasi dihentikan.");
                }
                $password = Str::password(20);
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

    private function syncSurveyTeam(array &$credentials): void
    {
        User::query()->where('role', UserRole::ManagerSurveyor->value)->update(['survey_team' => null]);
        $keep = [];
        foreach (GreenMudaRoster::SURVEYORS as $name) {
            $user = User::withTrashed()->where('role', UserRole::Surveyor->value)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
            if (! $user) {
                $email = Str::slug($name, '.').'.surveyor@ereport.app';
                if (User::withTrashed()->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                    throw new RuntimeException("Konflik email {$email}; sinkronisasi dihentikan.");
                }
                $password = Str::password(20);
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
