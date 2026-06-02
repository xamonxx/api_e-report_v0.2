<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persiapan data untuk LAUNCH (sesuai instruksi pemilik):
 *
 *   ✅ DIPERTAHANKAN : Akun Interior, User Super Admin & Admin,
 *                      Master Data (Kategori Kebutuhan & Status Pipeline).
 *   ❌ DIHAPUS       : Semua leads/konsultasi + data turunannya, data dummy,
 *                      dan user non-admin (Surveyor / Manager Surveyor / lainnya).
 *
 * Berbeda dengan `app:reset-for-production` (yang menghapus Akun & Admin) —
 * gunakan command INI untuk launch agar Akun & Admin tetap aman.
 */
class ResetForLaunch extends Command
{
    protected $signature = 'app:reset-for-launch {--force : Jalankan tanpa konfirmasi}';

    protected $description = 'Bersihkan leads & data dummy untuk launch. PERTAHANKAN Akun, Super Admin, Admin, dan Master Data.';

    public function handle(): int
    {
        $keepRoles = [UserRole::SuperAdmin->value, UserRole::Admin->value];

        $this->warn('⚠️  Perintah ini akan MENGHAPUS PERMANEN:');
        $this->line('   - Semua leads/konsultasi + catatan, riwayat status, reminder, absensi, import, audit log, bug report, langganan push.');
        $this->line('   - Semua user SELAIN role: ' . implode(', ', $keepRoles));
        $this->info('✅ Yang DIPERTAHANKAN: Akun Interior, Super Admin, Admin, Kategori Kebutuhan, Status Pipeline.');

        if (! $this->option('force') && ! $this->confirm('Lanjutkan menghapus data secara permanen?')) {
            $this->info('Operasi dibatalkan.');
            return self::FAILURE;
        }

        $this->info('Memulai pembersihan...');

        // Tabel transaksi/dummy yang ditruncate (urutan aman karena FK dimatikan).
        $transactionTables = [
            'consultation_needs_category',
            'consultation_status_history',
            'consultation_notes',
            'reminders',
            'report_attendances',
            'consultation_imports',
            'push_subscriptions',
            'audit_logs',
            'login_attempts',
            'bug_reports',
            'consultations',
        ];

        try {
            Schema::disableForeignKeyConstraints();

            foreach ($transactionTables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                    $this->line("- Truncate: {$table}");
                }
            }

            // Lepas tautan user yang akan dihapus dari pivot akun (jaga kebersihan
            // relasi; akun TIDAK dihapus).
            $deletedUserIds = User::whereNotIn('role', $keepRoles)->pluck('id');
            if ($deletedUserIds->isNotEmpty() && Schema::hasTable('account_user')) {
                DB::table('account_user')->whereIn('user_id', $deletedUserIds)->delete();
            }

            // Hapus user non-admin (Surveyor / Manager Surveyor / dummy lainnya).
            $deletedCount = User::whereNotIn('role', $keepRoles)->forceDelete();
            $this->line("- Hapus user non-admin: {$deletedCount}");
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $keptUsers = User::whereIn('role', $keepRoles)->count();
        $keptAccounts = Schema::hasTable('accounts') ? DB::table('accounts')->count() : 0;

        $this->newLine();
        $this->info('✅ Selesai. Database siap untuk launch.');
        $this->line("   Sisa user (Super Admin + Admin): {$keptUsers}");
        $this->line("   Sisa Akun Interior              : {$keptAccounts}");
        $this->line('   Master Data (kategori & status) : dipertahankan.');

        return self::SUCCESS;
    }
}
