<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class ResetOperationalData extends Command
{
    public const CONFIRMATION = 'RESET-OPERASIONAL-LOKAL';

    protected $signature = 'app:reset-operational-data {--apply : Hapus data setelah backup} {--backup-only : Buat dan verifikasi backup tanpa menghapus data} {--confirm= : Token konfirmasi} {--backup-dir= : Folder backup di luar Git} {--dump-binary= : Path mysqldump}';

    protected $description = 'Backup lalu hapus data operasional lokal tanpa mengubah akun, user roster, master data, atau sequence';

    private const TABLES = [
        'survey_reminder_deliveries', 'survey_notifications', 'survey_activity_logs',
        'survey_status_histories', 'survey_reschedules', 'survey_loan_approvals', 'surveys',
        'attendance_notifications', 'report_attendances', 'reminders', 'consultation_notes',
        'consultation_status_histories', 'consultation_needs_category', 'consultation_imports',
        'consultations', 'push_subscriptions', 'jobs', 'failed_jobs', 'job_batches',
    ];

    public function handle(): int
    {
        $database = (string) config('database.connections.'.config('database.default').'.database');
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Ditolak: APP_ENV wajib local/testing.');

            return self::FAILURE;
        }
        if ($database === 'sql_api_ereport_interiorcustom_id' || str_contains(mb_strtolower($database), 'sql_api_ereport')) {
            $this->error('Ditolak: nama database produksi terdeteksi.');

            return self::FAILURE;
        }

        $counts = collect(self::TABLES)->filter(fn ($table) => Schema::hasTable($table))
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
        $this->table(['Tabel', 'Baris akan dihapus'], $counts->map(fn ($count, $table) => [$table, $count])->values());
        $this->info('Dipertahankan: account/user/membership/master data/reminder settings/audit/bug/login history/consultation_sequences.');

        if ($this->option('apply') && $this->option('backup-only')) {
            $this->error('--apply dan --backup-only tidak boleh digabung.');

            return self::FAILURE;
        }

        if ($this->option('backup-only')) {
            try {
                $backup = $this->backup((string) config('database.default'), $database);
                $this->info("Backup terverifikasi: {$backup}");

                return self::SUCCESS;
            } catch (\Throwable $exception) {
                $this->error('Backup dibatalkan: '.$exception->getMessage());

                return self::FAILURE;
            }
        }

        if (! $this->option('apply')) {
            $this->warn('Dry-run saja. Gunakan --apply --confirm='.self::CONFIRMATION.' untuk menerapkan.');

            return self::SUCCESS;
        }
        if ($this->option('confirm') !== self::CONFIRMATION) {
            $this->error('Token konfirmasi salah atau kosong.');

            return self::FAILURE;
        }

        $driver = config('database.default');
        $locked = false;
        try {
            if ($driver === 'mysql') {
                $locked = (bool) (DB::selectOne("SELECT GET_LOCK('ereport_reset_operational', 5) AS acquired")?->acquired ?? false);
                if (! $locked) {
                    throw new RuntimeException('Lock reset sedang dipakai proses lain.');
                }
            }

            $backup = $this->backup($driver, $database);
            $this->info("Backup terverifikasi: {$backup}");

            DB::transaction(function () use ($counts) {
                foreach ($counts->keys() as $table) {
                    DB::table($table)->delete();
                }
            });
        } catch (\Throwable $exception) {
            $this->error('Reset dibatalkan: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($locked) {
                DB::select("SELECT RELEASE_LOCK('ereport_reset_operational')");
            }
        }

        $this->info('Data operasional lokal berhasil dihapus; ID akun dan consultation_sequences tetap.');

        return self::SUCCESS;
    }

    private function backup(string $driver, string $database): string
    {
        $directory = $this->option('backup-dir') ?: storage_path('app/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Folder backup gagal dibuat: {$directory}");
        }
        $path = $directory.DIRECTORY_SEPARATOR.'before-operational-reset-'.now()->format('Ymd-His').($driver === 'sqlite' ? '.sqlite' : '.sql');

        if ($driver === 'sqlite') {
            if (! is_file($database) || ! copy($database, $path)) {
                throw new RuntimeException('Backup SQLite gagal.');
            }
        } elseif ($driver === 'mysql') {
            $cfg = config('database.connections.mysql');
            $binary = $this->resolveMysqlDumpBinary();
            if (! $binary) {
                throw new RuntimeException('mysqldump tidak ditemukan; reset tidak dijalankan.');
            }
            $process = new Process([
                $binary, '--single-transaction', '--quick', '--routines', '--triggers',
                '--host='.$cfg['host'], '--port='.$cfg['port'], '--user='.$cfg['username'],
                '--result-file='.$path, $database,
            ]);
            $process->setEnv(['MYSQL_PWD' => (string) $cfg['password']]);
            $process->setTimeout(600);
            $process->mustRun();
        } else {
            throw new RuntimeException("Driver {$driver} belum didukung untuk backup aman.");
        }

        clearstatcache(true, $path);
        if (! is_file($path) || filesize($path) < 128) {
            throw new RuntimeException('File backup tidak lolos verifikasi ukuran.');
        }

        return realpath($path) ?: $path;
    }

    private function resolveMysqlDumpBinary(): ?string
    {
        $configured = $this->option('dump-binary');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $finder = new ExecutableFinder;
        $fromPath = $finder->find('mysqldump') ?: $finder->find('mariadb-dump');
        if ($fromPath) {
            return $fromPath;
        }

        if (PHP_OS_FAMILY !== 'Windows') {
            return null;
        }

        $laragonCandidates = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe') ?: [];
        rsort($laragonCandidates, SORT_NATURAL);

        return $laragonCandidates[0] ?? null;
    }
}
