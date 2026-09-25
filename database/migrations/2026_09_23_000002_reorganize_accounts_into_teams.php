<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\User;
use App\Support\AccountGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * One-off: reorganisasi 23 (akun, team, admin) sesuai split Team A-E
 * September 2026. Bikin 12 akun baru, bikin 20 baris admin baru,
 * soft-delete 7 admin lama yang digantikan (by id), update account_group
 * 11 akun existing (termasuk 3 yang admin-nya sudah benar, cuma
 * account_group-nya yang berubah).
 *
 * Catatan historis, bukan untuk di-rerun sengaja. Aman kalau ke-rerun
 * TIDAK sengaja ke database yang SAMA (guard di bawah skip baris yang
 * sudah dibuat). BUKAN untuk dijalankan ke database lain yang id akunnya
 * beda -- Account::findOrFail() akan throw dengan sengaja, bukan diam-diam
 * membuat data salah pasang.
 */
return new class extends Migration
{
    private const ROWS = [
        // Team A
        ['team' => 'A', 'account_name' => 'HOME PUTRA INTERIOR',      'existing_account_id' => 1,    'admin' => ['name' => 'OMEN',     'email' => 'omen@homeputrainterior.com'],        'displaced_user_id' => 55],
        ['team' => 'A', 'account_name' => 'HEYA INTERIOR',            'existing_account_id' => 12,   'admin' => null,                                                                  'displaced_user_id' => null],
        ['team' => 'A', 'account_name' => 'PUTRA INTERIOR',           'existing_account_id' => 9,    'admin' => ['name' => 'TIO',      'email' => 'tio@putrainterior.com'],             'displaced_user_id' => 63],
        ['team' => 'A', 'account_name' => 'KURNIA INTERIOR',          'existing_account_id' => null, 'admin' => ['name' => 'DENA',     'email' => 'dena@kurniainterior.com'],           'displaced_user_id' => null],

        // Team B
        ['team' => 'B', 'account_name' => 'HOME INTERIOR BANDUNG',    'existing_account_id' => 7,    'admin' => null,                                                                  'displaced_user_id' => null],
        ['team' => 'B', 'account_name' => 'AKBAR INTERIOR',           'existing_account_id' => 20,   'admin' => ['name' => 'YONAS',    'email' => 'yonas@akbarinterior.com'],           'displaced_user_id' => null],
        ['team' => 'B', 'account_name' => 'RAYSA INTERIOR',           'existing_account_id' => 4,    'admin' => ['name' => 'ANO',      'email' => 'ano@raysainterior.com'],             'displaced_user_id' => 58],
        ['team' => 'B', 'account_name' => 'NISCALA FURNITURE',        'existing_account_id' => null, 'admin' => ['name' => 'ANGBILAL', 'email' => 'angbilal@niscalafurniture.com'],     'displaced_user_id' => null],

        // Team C
        ['team' => 'C', 'account_name' => 'PARTNER INTERIOR',         'existing_account_id' => null, 'admin' => ['name' => 'YANWAR',   'email' => 'yanwar@partnerinterior.com'],        'displaced_user_id' => null],
        ['team' => 'C', 'account_name' => 'FULLHOME ID',              'existing_account_id' => 16,   'admin' => ['name' => 'DIAN',     'email' => 'dian@fullhomeid.com'],               'displaced_user_id' => 70],
        ['team' => 'C', 'account_name' => 'FURNITURE CIMAHI',         'existing_account_id' => null, 'admin' => ['name' => 'SRI',      'email' => 'sri@furniturecimahi.com'],           'displaced_user_id' => null],
        ['team' => 'C', 'account_name' => 'INTERHOUSE',               'existing_account_id' => 10,   'admin' => null,                                                                  'displaced_user_id' => null],

        // Team D
        ['team' => 'D', 'account_name' => 'SAVOY INTERIOR',           'existing_account_id' => null, 'admin' => ['name' => 'AZAM',     'email' => 'azam@savoyinterior.com'],            'displaced_user_id' => null],
        ['team' => 'D', 'account_name' => 'MEWAH INTERIOR',           'existing_account_id' => null, 'admin' => ['name' => 'LISA',     'email' => 'lisa@mewahinterior.com'],            'displaced_user_id' => null],
        ['team' => 'D', 'account_name' => 'KEJORA INTERIOR',          'existing_account_id' => null, 'admin' => ['name' => 'OCAN',     'email' => 'ocan@kejorainterior.com'],           'displaced_user_id' => null],
        ['team' => 'D', 'account_name' => 'ZODIAK INTERIOR',          'existing_account_id' => null, 'admin' => ['name' => 'BILAL',    'email' => 'bilal@zodiakinterior.com'],          'displaced_user_id' => null],

        // Team E
        ['team' => 'E', 'account_name' => 'NACIRA STUDIO',            'existing_account_id' => 17,   'admin' => ['name' => 'HASNI',    'email' => 'hasni@nacirastudio.com'],            'displaced_user_id' => 71],
        ['team' => 'E', 'account_name' => 'DEKOR INTERIOR',           'existing_account_id' => null, 'admin' => ['name' => 'DIKI',     'email' => 'diki@dekorinterior.com'],            'displaced_user_id' => null],
        ['team' => 'E', 'account_name' => 'PORTO INTERIOR',           'existing_account_id' => null, 'admin' => ['name' => 'HASNI',    'email' => 'hasni@portointerior.com'],           'displaced_user_id' => null],
        ['team' => 'E', 'account_name' => 'ARGO INTERIOR',            'existing_account_id' => null, 'admin' => ['name' => 'DIKI',     'email' => 'diki@argointerior.com'],             'displaced_user_id' => null],
        ['team' => 'E', 'account_name' => 'PUTRA M',                  'existing_account_id' => null, 'admin' => ['name' => 'ANNISA',   'email' => 'annisa@putram.com'],                 'displaced_user_id' => null],
        ['team' => 'E', 'account_name' => 'KITCHENSET BANDUNG BARAT', 'existing_account_id' => 11,   'admin' => ['name' => 'ANNISA',   'email' => 'annisa@kitchensetbandungbarat.com'], 'displaced_user_id' => 65],
        ['team' => 'E', 'account_name' => 'MEDIAN INTERIOR',          'existing_account_id' => 13,   'admin' => ['name' => 'HASNI',    'email' => 'hasni@medianinterior.com'],          'displaced_user_id' => 67],
    ];

    /** Id admin lama yang akunnya dipindah ke admin baru. */
    private const DISPLACED_USER_IDS = [55, 63, 58, 70, 65, 67, 71];

    public function up(): void
    {
        // This is an operational data migration for the September 2026
        // production roster. A fresh installation has no legacy account IDs to
        // reorganize, so leave provisioning to seeders/commands instead of
        // making migrate:fresh fail on Account::findOrFail().
        if (Account::query()->doesntExist()) {
            return;
        }

        DB::transaction(function () {
            $hashedPassword = Hash::make(env('SEED_DEFAULT_PASSWORD') ?: Str::random(32));

            foreach (self::ROWS as $row) {
                $account = $this->resolveAccount($row);

                $currentGroup = AccountGroup::normalize($account->account_group);
                if ($currentGroup !== $row['team']) {
                    $account->account_group = $row['team'];
                    $account->save();
                }

                if ($row['admin'] !== null) {
                    $this->createAdminIfMissing($row['admin'], $account->id, $hashedPassword);
                }
            }

            $this->softDeleteDisplacedAdmins();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException(
            'reorganize_accounts_into_teams tidak bisa di-auto-revert dengan aman: sudah '
            . 'membuat akun & login admin nyata dan me-soft-delete user nyata yang mungkin '
            . 'sudah dipakai aktif. Pulihkan accounts/users/consultations dari backup '
            . 'sebelum migration ini, jangan pakai migrate:rollback pada file ini.'
        );
    }

    private function resolveAccount(array $row): Account
    {
        if ($row['existing_account_id'] !== null) {
            return Account::findOrFail($row['existing_account_id']);
        }

        $existing = Account::where('name', $row['account_name'])->first();
        if ($existing !== null) {
            return $existing;
        }

        $account = new Account(['name' => $row['account_name']]);
        $account->account_group = $row['team'];
        $account->save();

        return $account;
    }

    private function createAdminIfMissing(array $admin, int $accountId, string $hashedPassword): void
    {
        if (User::withTrashed()->where('email', $admin['email'])->exists()) {
            return;
        }

        $user = new User([
            'name' => $admin['name'],
            'email' => $admin['email'],
            'password' => $hashedPassword,
            'account_id' => $accountId,
        ]);
        $user->role = UserRole::Admin;
        $user->save();
    }

    private function softDeleteDisplacedAdmins(): void
    {
        User::whereIn('id', self::DISPLACED_USER_IDS)
            ->whereNull('deleted_at')
            ->get()
            ->each(fn (User $user) => $user->delete());
    }
};
