<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\StatusCategory;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Memeriksa apakah skema database masih sinkron dengan kode aplikasi.
 *
 * Dibuat setelah dua insiden nyata yang sama-sama lolos ke UI sebagai error
 * generik dan sulit dilacak:
 *   1. `users.role` masih enum('super_admin','admin') padahal App\Enums\UserRole
 *      sudah punya surveyor & manager_surveyor  -> "Data truncated for column 'role'".
 *   2. Seluruh tabel survey hilang padahal model/route-nya ada -> endpoint 404.
 *
 * Keduanya akan tertangkap command ini. Exit code non-zero saat ada masalah,
 * jadi aman dipakai sebagai gerbang sebelum deploy.
 */
class AuditDatabase extends Command
{
    protected $signature = 'app:audit-db
                            {--strict : Anggap peringatan sebagai kegagalan juga}';

    protected $description = 'Audit sinkronisasi database dengan aplikasi (migrasi, model/tabel/kolom, enum, data referensi).';

    /** @var array<int,string> */
    private array $problems = [];

    /** @var array<int,string> */
    private array $warnings = [];

    public function handle(): int
    {
        $this->newLine();
        $this->components->info('Audit sinkronisasi Database <-> Aplikasi');

        $this->auditMigrations();
        $this->auditModels();
        $this->auditEnums();
        $this->auditReferenceData();

        return $this->report();
    }

    /**
     * Migrasi: ada yang belum jalan? ada record tanpa file (mis. akibat revert)?
     */
    private function auditMigrations(): void
    {
        $this->line(' <fg=cyan>1. Migrasi</>');

        $files = collect(File::files(database_path('migrations')))
            ->map(fn ($f) => $f->getFilenameWithoutExtension())->sort()->values();
        $ran = collect(DB::table('migrations')->pluck('migration'))->sort()->values();

        $pending = $files->diff($ran)->values();
        $orphan = $ran->diff($files)->values();

        $this->components->twoColumnDetail('File migrasi / tercatat di DB', $files->count() . ' / ' . $ran->count());

        if ($pending->isEmpty()) {
            $this->components->twoColumnDetail('Migrasi pending', '<fg=green>tidak ada</>');
        } else {
            $this->problems[] = 'Migrasi belum dijalankan: ' . $pending->implode(', ');
            $this->components->twoColumnDetail('Migrasi pending', '<fg=red>' . $pending->count() . '</>');
        }

        if ($orphan->isEmpty()) {
            $this->components->twoColumnDetail('Record migrasi tanpa file', '<fg=green>tidak ada</>');
        } else {
            // Gejala khas repo ter-revert: DB pikir sudah jalan, filenya raib.
            $this->warnings[] = 'Record migrasi tanpa file: ' . $orphan->implode(', ');
            $this->components->twoColumnDetail('Record migrasi tanpa file', '<fg=yellow>' . $orphan->count() . '</>');
        }
        $this->newLine();
    }

    /**
     * Tiap model: tabelnya ada? kolom $fillable & casts benar-benar ada?
     */
    private function auditModels(): void
    {
        $this->line(' <fg=cyan>2. Model vs Tabel / Kolom</>');

        $names = collect(File::allFiles(app_path('Models')))
            ->map(fn ($f) => $f->getFilenameWithoutExtension())->sort();

        foreach ($names as $name) {
            $class = 'App\\Models\\' . $name;
            if (! class_exists($class)) {
                continue;
            }

            try {
                $model = new $class;
            } catch (Throwable $e) {
                $this->warnings[] = "Model {$name} tidak bisa diinstansiasi: " . $e->getMessage();
                $this->components->twoColumnDetail($name, '<fg=yellow>tidak bisa diinstansiasi</>');
                continue;
            }

            if (! $model instanceof Model) {
                continue;
            }

            $table = $model->getTable();

            if (! Schema::hasTable($table)) {
                $this->problems[] = "Model {$name}: tabel '{$table}' tidak ada";
                $this->components->twoColumnDetail($name, "<fg=red>tabel '{$table}' TIDAK ADA</>");
                continue;
            }

            $columns = Schema::getColumnListing($table);

            // fillable tanpa kolom = FATAL: create()/update() akan melempar
            // "Unknown column" saat atribut itu diisi.
            $badFillable = array_values(array_diff($model->getFillable(), $columns));

            // cast tanpa kolom = tidak berbahaya (cast hanya jalan bila atribut ada),
            // tapi menandakan sisa kode yang perlu dibersihkan.
            $badCasts = array_values(array_filter(
                array_diff(array_keys($model->getCasts()), $columns),
                fn ($c) => ! str_contains($c, '.')
            ));

            if ($badFillable) {
                $this->problems[] = "Model {$name}: fillable tanpa kolom [" . implode(', ', $badFillable) . ']';
            }
            if ($badCasts) {
                $this->warnings[] = "Model {$name}: cast tanpa kolom [" . implode(', ', $badCasts) . ']';
            }

            $status = match (true) {
                (bool) $badFillable => '<fg=red>fillable: ' . implode(', ', $badFillable) . '</>',
                (bool) $badCasts => '<fg=yellow>cast: ' . implode(', ', $badCasts) . '</>',
                default => '<fg=green>ok</> <fg=gray>(' . count($columns) . ' kolom)</>',
            };
            $this->components->twoColumnDetail($name . ' <fg=gray>-> ' . $table . '</>', $status);
        }
        $this->newLine();
    }

    /**
     * Enum PHP vs kolom DB â€” sumber insiden "Data truncated for column 'role'".
     */
    private function auditEnums(): void
    {
        $this->line(' <fg=cyan>3. Enum aplikasi vs kolom DB</>');

        $appRoles = array_column(UserRole::cases(), 'value');
        $type = $this->columnType('users', 'role');

        $this->components->twoColumnDetail('UserRole (aplikasi)', implode(', ', $appRoles));
        $this->components->twoColumnDetail('users.role (database)', $type ?? '<fg=red>kolom tidak ada</>');

        if ($type === null) {
            $this->problems[] = 'Kolom users.role tidak ada';
        } elseif (str_starts_with($type, 'enum(')) {
            preg_match_all("/'([^']+)'/", $type, $m);
            $missing = array_diff($appRoles, $m[1] ?? []);
            if ($missing) {
                $this->problems[] = 'users.role enum tidak memuat: ' . implode(', ', $missing);
                $this->components->twoColumnDetail('Cakupan enum', '<fg=red>kurang: ' . implode(', ', $missing) . '</>');
            } else {
                $this->components->twoColumnDetail('Cakupan enum', '<fg=green>lengkap</>');
            }
        } else {
            $this->components->twoColumnDetail('Cakupan enum', '<fg=green>kolom string â€” semua role muat</>');
        }
        $this->newLine();
    }

    /**
     * Data referensi yang diasumsikan ada oleh aplikasi.
     */
    private function auditReferenceData(): void
    {
        $this->line(' <fg=cyan>4. Data referensi</>');

        // Dipakai untuk menghitung rasio konversi (deal) di dashboard/analytics.
        $deal = config('statuses.deal');
        $found = StatusCategory::whereIn('name', array_filter([$deal, 'Selesai/Deal', 'Selesai Deal']))->pluck('name');

        if ($found->isEmpty()) {
            $this->problems[] = "Status deal (config statuses.deal = '{$deal}') tidak ada di status_categories â€” perhitungan konversi akan selalu 0";
            $this->components->twoColumnDetail("Status deal ('{$deal}')", '<fg=red>tidak ditemukan</>');
        } else {
            $this->components->twoColumnDetail("Status deal ('{$deal}')", '<fg=green>ada</>');
        }

        foreach (['status_categories', 'needs_categories', 'survey_statuses'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->components->twoColumnDetail($table, '<fg=red>tabel tidak ada</>');
                continue;
            }
            $count = DB::table($table)->count();
            if ($count === 0) {
                $this->warnings[] = "Tabel referensi {$table} kosong â€” dropdown terkait akan kosong";
                $this->components->twoColumnDetail($table, '<fg=yellow>kosong</>');
            } else {
                $this->components->twoColumnDetail($table, '<fg=green>' . $count . ' baris</>');
            }
        }
        $this->newLine();
    }

    /**
     * Tipe kolom lengkap, mis. "varchar(32)" atau "enum('a','b')".
     * Null bila kolom/tabel tidak ada.
     *
     * Sengaja memakai Schema::getColumns() dan BUKAN
     * `SHOW COLUMNS FROM x LIKE ?` â€” MySQL/PDO tidak mengikat parameter pada
     * statement SHOW, sehingga hasilnya selalu kosong (false negative).
     */
    private function columnType(string $table, string $column): ?string
    {
        try {
            foreach (Schema::getColumns($table) as $col) {
                if (($col['name'] ?? null) === $column) {
                    return $col['type'] ?? $col['type_name'] ?? null;
                }
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    private function report(): int
    {
        $strict = (bool) $this->option('strict');

        if (! $this->problems && ! $this->warnings) {
            $this->components->info('Database SINKRON dengan aplikasi - tidak ada masalah.');

            return self::SUCCESS;
        }

        foreach ($this->problems as $p) {
            $this->components->error($p);
        }
        foreach ($this->warnings as $w) {
            $this->components->warn($w);
        }

        $this->components->twoColumnDetail(
            '<options=bold>Ringkasan</>',
            '<fg=red>' . count($this->problems) . ' masalah</> - <fg=yellow>' . count($this->warnings) . ' peringatan</>'
        );
        $this->newLine();

        if ($this->problems || ($strict && $this->warnings)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
