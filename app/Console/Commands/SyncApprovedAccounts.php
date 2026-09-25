<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SyncApprovedAccounts extends Command
{
    protected $signature = 'accounts:sync-approved-list {--apply} {--sync-admin-names : Samakan nama tampilan admin tanpa mengubah login}';

    protected $description = 'Cocokkan 23 akun daftar pemilik 24 September 2026; default dry-run';

    public const ROWS = [
        ['A', 'HOME PUTRA INTERIOR', 'OMEN'], ['A', 'HEYA INTERIOR', 'RAGIL'],
        ['A', 'PUTRA INTERIOR', 'TIO'], ['A', 'KURNIA INTERIOR', 'DENA'],
        ['B', 'HOME INTERIOR BANDUNG', 'HASAN'], ['B', 'AKBAR INTERIOR', 'YONAS'],
        ['B', 'RAYSA INTERIOR', 'ANO'], ['B', 'NISCALA FURNITURE', 'ANGBILAL'],
        ['C', 'PARTNER INTERIOR', 'YANWAR'], ['C', 'FULLHOME ID', 'DIAN'],
        ['C', 'FURNITURE CIMAHI', 'SRI'], ['C', 'INTERHOUSE', 'DIKA'],
        ['D', 'SAVOY INTERIOR', 'AZAM'], ['D', 'MEWAH INTERIOR', 'LISA'],
        ['D', 'KEJORA INTERIOR', 'OCAN'], ['D', 'ZODIAK INTERIOR', 'BILAL'],
        ['E', 'NACIRA INTERIOR', 'PAK HAS'], ['E', 'DEKOR INTERIOR', 'DIKI'],
        ['E', 'PORTO INTERIOR', 'PAK HAS'], ['E', 'ARGO INTERIOR', 'DIKI'],
        ['E', 'PUTRA MOULDING', 'ANNISA'], ['E', 'KITCHENSET SOLUTION BANDUNG', 'ANNISA'],
        ['E', 'MEDIAN INTERIOR', 'PAK HAS'],
    ];

    private const ALIASES = [
        'NACIRA INTERIOR' => ['NACIRA STUDIO'],
        'PUTRA MOULDING' => ['PUTRA M'],
        'KITCHENSET SOLUTION BANDUNG' => ['KITCHENSET BANDUNG BARAT', 'KSB'],
        'MEDIAN INTERIOR' => ['MEDIAN'],
    ];

    public function handle(): int
    {
        $plan = $this->plan();
        $this->table(['ID', 'Nama sekarang', 'Nama sesuai daftar', 'Team'], array_map(
            fn ($row) => [$row['id'], $row['old_name'], $row['name'], $row['team']], $plan['keep']
        ));
        $this->table(['ID diarsipkan', 'Nama'], array_map(fn ($row) => [$row->id, $row->name], $plan['retire']));
        if (! $this->option('apply')) {
            $this->info('Dry-run; tidak ada perubahan.');
            return self::SUCCESS;
        }
        if (! app()->isDownForMaintenance()) {
            throw new RuntimeException('Aktifkan maintenance sebelum apply.');
        }
        if ($this->call('survey:sync-teams', ['--backup-only' => true]) !== self::SUCCESS) {
            throw new RuntimeException('Backup gagal; data tidak diubah.');
        }
        $counts = $this->applyPlan((bool) $this->option('sync-admin-names'));
        Artisan::call('cache:clear');
        $this->info('Selesai: '.json_encode($counts, JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }

    private function plan(bool $lock = false): array
    {
        $accounts = DB::table('accounts')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $keep = [];
        foreach (self::ROWS as [$team, $name, $admin]) {
            $names = [$name, ...(self::ALIASES[$name] ?? [])];
            $matches = $accounts->filter(fn ($a) => in_array(strtoupper(trim($a->name)), $names, true));
            if ($matches->count() !== 1 || $matches->first()->deleted_at !== null) {
                throw new RuntimeException('Akun hilang, nonaktif atau ambigu: '.$name);
            }
            $account = $matches->first();
            $keep[] = ['id' => $account->id, 'old_name' => $account->name, 'name' => $name, 'team' => $team, 'admin' => $admin];
        }
        $ids = array_column($keep, 'id');
        $retire = $accounts->filter(fn ($a) => $a->deleted_at === null && ! in_array($a->id, $ids, true))->values()->all();
        return compact('keep', 'retire');
    }

    private function applyPlan(bool $syncAdminNames): array
    {
        return DB::transaction(function () use ($syncAdminNames) {
            $plan = $this->plan(true);
            $leadCount = DB::table('consultations')->count();
            $renamed = $adminsRenamed = 0;
            foreach ($plan['keep'] as $row) {
                $account = Account::findOrFail($row['id']);
                $account->name = $row['name'];
                $account->account_group = $row['team'];
                if ($account->isDirty()) {
                    $account->save();
                    $renamed++;
                }
                if ($syncAdminNames) {
                    $admins = User::where('role', 'admin')->where('account_id', $account->id)->lockForUpdate()->get();
                    if ($admins->count() !== 1) {
                        throw new RuntimeException('Admin akun tidak tunggal: '.$account->name);
                    }
                    $admin = $admins->first();
                    if ($admin->name !== $row['admin']) {
                        $admin->name = $row['admin'];
                        $admin->save();
                        $adminsRenamed++;
                    }
                }
            }
            $retireIds = array_map(fn ($a) => $a->id, $plan['retire']);
            $admins = User::where('role', 'admin')->whereIn('account_id', $retireIds)->lockForUpdate()->get();
            foreach ($admins as $admin) {
                $admin->tokens()->delete();
                $admin->remember_token = null;
                $admin->save();
                $admin->delete();
            }
            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->whereIn('user_id', $admins->modelKeys())->delete();
            }
            foreach ($retireIds as $id) {
                Account::findOrFail($id)->delete();
            }
            if (Account::count() !== 23 || DB::table('consultations')->count() !== $leadCount) {
                throw new RuntimeException('Pemeriksaan jumlah akun/riwayat gagal; transaksi dibatalkan.');
            }
            foreach ($plan['keep'] as $row) {
                if (! Account::whereKey($row['id'])->where('name', $row['name'])->where('account_group', $row['team'])->exists()
                    || ($syncAdminNames && ! User::where('role', 'admin')->where('account_id', $row['id'])->where('name', $row['admin'])->exists())) {
                    throw new RuntimeException('Hasil tidak sesuai daftar; transaksi dibatalkan.');
                }
            }
            return ['active_accounts' => 23, 'renamed_accounts' => $renamed,
                'archived_accounts' => count($retireIds), 'disabled_admins' => $admins->count(),
                'renamed_admins' => $adminsRenamed, 'preserved_leads' => $leadCount];
        });
    }
}
