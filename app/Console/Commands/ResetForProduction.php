<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\LoginAttempt;
use App\Models\Reminder;
use App\Models\ReportAttendance;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetForProduction extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-for-production {--force : Force the operation to run without confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean all transaction data and admin accounts for production launch, keeping only super admins.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->warn('⚠️  PERINGATAN: Perintah ini akan menghapus semua Klien, Leads, Akun Interior, dan Admin biasa.');
        $this->warn('Hanya Super Admin, Master Data Kategori Kebutuhan, dan Status yang akan dipertahankan.');
        
        if (!$this->option('force') && !$this->confirm('Apakah Anda yakin ingin melanjutkan dan menghapus data secara permanen?')) {
            $this->info('Operasi dibatalkan.');
            return 1;
        }

        $this->info('Memulai pembersihan data...');

        try {
            // Matikan pengecekan Foreign Key sementara
            Schema::disableForeignKeyConstraints();

            // 1. Hapus semua data transaksi
            $this->info('- Menghapus tabel pivot kategori...');
            if (Schema::hasTable('consultation_needs_category')) {
                DB::table('consultation_needs_category')->truncate();
            }

            $this->info('- Menghapus pengingat (reminders)...');
            Reminder::truncate();

            $this->info('- Menghapus catatan konsultasi (notes)...');
            ConsultationNote::truncate();

            $this->info('- Menghapus kehadiran (report_attendances)...');
            ReportAttendance::truncate();

            $this->info('- Menghapus jejak audit (audit_logs)...');
            AuditLog::truncate();

            $this->info('- Menghapus percobaan login (login_attempts)...');
            if (class_exists(LoginAttempt::class)) {
                LoginAttempt::truncate();
            }

            $this->info('- Menghapus semua leads (consultations)...');
            Consultation::truncate();

            // 2. Hapus semua Admin biasa dan lepaskan relasi akun dari Super Admin
            $this->info('- Menghapus pengguna Admin...');
            User::where('role', '!=', UserRole::SuperAdmin)->forceDelete();
            
            // Pastikan Super Admin tidak terikat ke akun manapun (karena akunnya akan dihapus)
            User::where('role', UserRole::SuperAdmin)->update(['account_id' => null]);

            // 3. Hapus semua Akun / Cabang
            $this->info('- Menghapus semua Akun Interior...');
            Account::truncate();

        } finally {
            // Nyalakan kembali pengecekan Foreign Key
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info('✅ Berhasil! Database telah dibersihkan dan siap untuk Production.');
        $this->info('Sisa data: User Super Admin, Master Data Kategori dan Status.');
        
        return 0;
    }
}
