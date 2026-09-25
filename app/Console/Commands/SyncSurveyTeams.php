<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class SyncSurveyTeams extends Command
{
    protected $signature = 'survey:sync-teams
        {--apply : Terapkan perubahan setelah backup otomatis}
        {--backup-only : Backup lengkap tanpa memerlukan migration survey_team}
        {--backup-dir=C:/Users/Administrator/Music/E-Report-Team-Migration : Direktori artefak di luar Git}
        {--credentials= : File JSON baru di luar Git, path absolut}
        {--dump-binary= : Path mysqldump/mariadb-dump}';

    protected $description = 'Dry-run penggantian roster survey A-E; apply eksplisit dengan backup dan audit';

    private const ROSTER = [
        'A' => ['ADI', 'AGIL', 'LUKI'], 'B' => ['BAGJA', 'SAUT', 'RAMA'],
        'C' => ['FAJAR', 'ADEN', 'ADAM'], 'D' => ['DIAN C', 'RAFLI', 'WAWAN'],
        'E' => ['HASNI', 'DIKI', 'ANGGA'],
    ];

    // Mapping migration 2026_09_23_000002; tidak memakai ID database lama.
    private const ACCOUNTS = [
        'A' => ['HOME PUTRA INTERIOR', 'HEYA INTERIOR', 'PUTRA INTERIOR', 'KURNIA INTERIOR'],
        'B' => ['HOME INTERIOR BANDUNG', 'AKBAR INTERIOR', 'RAYSA INTERIOR', 'NISCALA FURNITURE'],
        'C' => ['PARTNER INTERIOR', 'FULLHOME ID', 'FURNITURE CIMAHI', 'INTERHOUSE'],
        'D' => ['SAVOY INTERIOR', 'MEWAH INTERIOR', 'KEJORA INTERIOR', 'ZODIAK INTERIOR'],
        'E' => ['NACIRA STUDIO', 'DEKOR INTERIOR', 'PORTO INTERIOR', 'ARGO INTERIOR', 'PUTRA M', 'KITCHENSET BANDUNG BARAT', 'MEDIAN INTERIOR'],
    ];

    private const ALIASES = [
        'NACIRA STUDIO' => ['NACIRA INTERIOR'],
        'KITCHENSET BANDUNG BARAT' => ['KSB', 'KITCHENSET SOLUTION BANDUNG'],
        'PUTRA M' => ['PUTRA MOULDING'],
        'MEDIAN INTERIOR' => ['MEDIAN'],
    ];

    public function handle(): int
    {
        $credentials = null;
        $written = false;
        $committed = false;
        $sessionCopies = [];
        $locked = false;
        try {
            $apply = (bool) $this->option('apply');
            if ($apply && $this->option('backup-only')) {
                throw new RuntimeException('--apply dan --backup-only tidak boleh digabung.');
            }
            if ($apply || $this->option('backup-only')) {
                $this->validateDatabase();
                if ((int) DB::selectOne("SELECT GET_LOCK('survey:sync-teams', 0) AS acquired")->acquired !== 1) {
                    throw new RuntimeException('Sinkronisasi/backup lain sedang berjalan.');
                }
                $locked = true;
                $directory = $this->outsideGit((string) $this->option('backup-dir'), true);
                if ($this->option('backup-only')) {
                    $this->info('Backup lengkap berhasil: '.$this->backup($directory));
                    return self::SUCCESS;
                }
                if (! app()->isDownForMaintenance()) {
                    throw new RuntimeException('Apply memerlukan php artisan down; hentikan worker dan tunggu request aktif selesai.');
                }
                if (! Schema::hasColumn('users', 'survey_team')) {
                    throw new RuntimeException('Migration users.survey_team belum dijalankan.');
                }
                $credentials = $this->outsideGit((string) $this->option('credentials'));
                $this->validateSessions();
            }
            $plan = $this->plan();
            $this->table(['Rencana', 'Jumlah'], [
                ['Akun tervalidasi', count($plan['accounts'])], ['Roster', count($plan['users'])],
                ['Baru/reaktivasi', count(array_filter($plan['users'], fn ($u) => ! $u['existing'] || $u['existing']->deleted_at !== null))],
                ['Di luar roster (termasuk nonaktif)', count($plan['retired'])], ['Reset survey aktif', count($plan['surveys'])],
            ]);
            if (! $apply) {
                $this->info('Dry-run selesai; tidak ada data atau artefak ditulis.');
                if (! Schema::hasColumn('users', 'survey_team')) {
                    $this->warn('Apply memerlukan migration users.survey_team.');
                }
                return self::SUCCESS;
            }
            $backup = $this->backup($directory);
            $this->info('Backup lengkap berhasil: '.$backup);
            $this->writeNewFile($backup.'.plan.json', json_encode([
                'accounts' => $plan['accounts'],
                'roster' => array_map(fn ($u) => [
                    'id' => $u['existing']?->id, 'name' => $u['name'],
                    'email' => $u['email'], 'role' => $u['role'], 'team' => $u['team'],
                ], $plan['users']),
                'retired' => array_values(array_map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role], $plan['retired'])),
                'reset_surveys' => array_map(fn ($entry) => $entry['survey']->id, $plan['surveys']),
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            DB::transaction(function () use ($credentials, $backup, &$written, &$sessionCopies) {
                $plan = $this->plan(true);
                $now = now();
                $secrets = [];
                $revoke = array_map(fn ($u) => $u->id, $plan['retired']);
                foreach ($plan['accounts'] as $id => $team) {
                    DB::table('accounts')->where('id', $id)->where(fn ($q) => $q->whereNull('account_group')->orWhere('account_group', '<>', $team))
                        ->update(['account_group' => $team, 'updated_at' => $now]);
                }
                foreach ($plan['users'] as $target) {
                    $old = $target['existing'];
                    $values = ['name' => $target['name'], 'email' => $target['email'], 'role' => $target['role'], 'survey_team' => $target['team'], 'account_id' => null, 'deleted_at' => null];
                    if (! $old || $old->deleted_at !== null) {
                        $password = bin2hex(random_bytes(24));
                        $values['password'] = Hash::make($password);
                        $values['remember_token'] = null;
                        $secrets[] = ['name' => $target['name'], 'email' => $target['email'], 'team' => $target['team'], 'password' => $password];
                        if ($old) {
                            $revoke[] = $old->id;
                        }
                    }
                    if (! $old) {
                        DB::table('users')->insert($values + ['created_at' => $now, 'updated_at' => $now]);
                    } elseif (array_filter($values, fn ($v, $k) => ($old->$k ?? null) !== $v, ARRAY_FILTER_USE_BOTH)) {
                        DB::table('users')->where('id', $old->id)->update($values + ['updated_at' => $now]);
                    }
                }
                foreach ($plan['retired'] as $old) {
                    if ($old->deleted_at === null || $old->remember_token !== null || ($old->survey_team ?? null) !== null) {
                        DB::table('users')->where('id', $old->id)->update([
                            'deleted_at' => $old->deleted_at ?? $now, 'remember_token' => null,
                            'survey_team' => null, 'updated_at' => $now,
                        ]);
                    }
                }
                foreach ($plan['surveys'] as $entry) {
                    $this->resetSurvey($entry, $plan['request_status']);
                }
                $this->revoke($revoke, $sessionCopies);
                $this->writeNewFile($credentials, json_encode(['backup' => $backup, 'generated_at' => now()->toIso8601String(), 'users' => $secrets], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $written = true;
            });
            $committed = true;
            $this->info('Selesai. Kredensial baru/reaktivasi: '.$credentials);
            return self::SUCCESS;
        } catch (Throwable $e) {
            if (! $committed) {
                foreach ($sessionCopies as $path => $raw) {
                    if (file_put_contents($path, $raw, LOCK_EX) === false) {
                        $this->error('Pemulihan sesi file gagal: '.$path);
                    }
                }
                if ($written) {
                    unlink($credentials);
                }
            }
            // Exception SQL/process dapat memuat password atau binding rahasia.
            $this->error(get_class($e) === RuntimeException::class ? $e->getMessage() : 'Operasi gagal ('.class_basename($e).'); detail sensitif disembunyikan.');
            return self::FAILURE;
        } finally {
            if ($locked) {
                DB::select("SELECT RELEASE_LOCK('survey:sync-teams')");
            }
        }
    }

    private function plan(bool $lock = false): array
    {
        $read = function ($table) use ($lock) {
            $query = DB::table($table)->orderBy('id');
            return ($lock ? $query->lockForUpdate() : $query)->get();
        };
        $accounts = $read('accounts');
        $users = $read('users');
        $surveys = $read('surveys');
        $consultations = $read('consultations')->keyBy('id');
        $statuses = $read('status_categories')->filter(fn ($s) => ($s->deleted_at ?? null) === null);
        $mapping = [];
        foreach (self::ACCOUNTS as $team => $names) {
            foreach ($names as $name) {
                $aliases = [$name, ...(self::ALIASES[$name] ?? [])];
                $matches = $accounts->filter(fn ($a) => in_array(strtoupper(trim($a->name)), $aliases, true));
                if ($matches->count() !== 1 || ($matches->first()->deleted_at ?? null) !== null) {
                    throw new RuntimeException('Akun hilang, nonaktif, atau ambigu: '.$name);
                }
                $mapping[$matches->first()->id] = $team;
            }
        }
        $targets = $keep = $surveyorTeams = [];
        foreach (self::ROSTER as $team => $names) {
            foreach ([...$names, 'Manager Surveyor Team '.$team] as $name) {
                $role = in_array($name, $names, true) ? 'surveyor' : 'manager_surveyor';
                $email = $role === 'surveyor' ? strtolower(str_replace(' ', '.', $name)).'-srvy@npp.com' : 'manager.team.'.strtolower($team).'-srvy@npp.com';
                // Exact equality preserves identity and never conflates DIAN with DIAN C.
                $matches = $users->filter(fn ($u) => $u->role === $role && $u->name === $name);
                if ($matches->count() > 1) {
                    throw new RuntimeException('Nama pengguna ambigu: '.$name);
                }
                $old = $matches->first();
                if ($role === 'surveyor' && $old) {
                    $email = $old->email;
                }
                if ($users->contains(fn ($u) => strcasecmp($u->email, $email) === 0 && (! $old || $u->id !== $old->id))) {
                    throw new RuntimeException('Email target dipakai identitas lain: '.$name);
                }
                $targets[] = ['name' => $name, 'email' => $email, 'role' => $role, 'team' => $team, 'existing' => $old];
                if ($old) {
                    $keep[] = $old->id;
                    if ($role === 'surveyor' && $old->deleted_at === null) {
                        $surveyorTeams[$old->id] = $team;
                    }
                }
            }
        }
        $retired = $users->filter(fn ($u) => in_array($u->role, ['surveyor', 'manager_surveyor'], true) && ! in_array($u->id, $keep, true))->all();
        $resets = [];
        $requestStatus = null;
        foreach ($surveys as $survey) {
            if ($survey->deleted_at !== null || ! in_array($survey->state, ['requested', 'scheduled', 'in_progress'], true)) {
                continue;
            }
            if ($survey->surveyor_id === null && $survey->state === 'requested' && $survey->assigned_by === null) {
                continue;
            }
            $team = $mapping[$survey->account_id] ?? null;
            if ($team !== null && ($surveyorTeams[$survey->surveyor_id] ?? null) === $team) {
                continue;
            }
            $consultation = $consultations->get($survey->consultation_id);
            if (! $consultation) {
                throw new RuntimeException('Konsultasi survey hilang: '.$survey->id);
            }
            $status = $statuses->firstWhere('id', $consultation->status_category_id);
            $resetStatus = $status && strcasecmp(trim($status->name), 'Sedang Survey') === 0;
            if ($resetStatus) {
                $requested = $statuses->filter(fn ($s) => strcasecmp(trim($s->name), 'Request Survey') === 0);
                if ($requested->count() !== 1) {
                    throw new RuntimeException('Status Request Survey hilang atau ambigu.');
                }
                $requestStatus = $requested->first()->id;
            }
            $resets[] = ['survey' => $survey, 'consultation' => $consultation, 'reset_status' => $resetStatus];
        }
        return ['accounts' => $mapping, 'users' => $targets, 'retired' => $retired, 'surveys' => $resets, 'request_status' => $requestStatus];
    }

    private function resetSurvey(array $entry, ?int $requestStatus): void
    {
        $s = $entry['survey'];
        $c = $entry['consultation'];
        $now = now();
        $values = array_fill_keys([
            'surveyor_id', 'assigned_by', 'assigned_at', 'scheduled_at', 'actual_start_at', 'actual_finish_at',
            'manager_notes', 'result_status_id', 'result_notes', 'completed_at', 'location_condition',
            'customer_notes', 'obstacles', 'recommendations', 'additional_notes', 'cancelled_at', 'cancellation_reason',
        ], null) + ['state' => 'requested', 'updated_at' => $now];
        DB::table('survey_activity_logs')->insert([
            'survey_id' => $s->id, 'consultation_id' => $s->consultation_id, 'user_id' => null, 'user_role' => null,
            'action' => 'status_changed', 'old_status' => $s->state, 'new_status' => 'requested',
            'old_values' => json_encode(['survey' => $s, 'consultation' => $c], JSON_THROW_ON_ERROR),
            'new_values' => json_encode($values, JSON_THROW_ON_ERROR),
            'notes' => 'survey:sync-teams: penugasan tidak memiliki surveyor aktif dalam tim akun tujuan. Snapshot lengkap tersimpan di old_values.',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('surveys')->where('id', $s->id)->update($values);
        if ($s->state !== 'requested') {
            DB::table('survey_status_histories')->insert([
                'survey_id' => $s->id, 'from_state' => $s->state, 'to_state' => 'requested',
                'changed_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ($entry['reset_status']) {
            DB::table('consultations')->where('id', $c->id)->update(['status_category_id' => $requestStatus, 'updated_at' => $now]);
            DB::table('consultation_status_histories')->insert([
                'consultation_id' => $c->id, 'account_id' => $c->account_id, 'from_status_id' => $c->status_category_id,
                'to_status_id' => $requestStatus, 'changed_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function outsideGit(string $path, bool $directory = false): string
    {
        if (! preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
            throw new RuntimeException('Path artefak harus absolut.');
        }
        $parent = realpath($directory ? $path : dirname($path));
        if (! $parent || ! is_dir($parent) || ! is_writable($parent)) {
            throw new RuntimeException('Buat direktori artefak privat di luar Git terlebih dahulu.');
        }
        $base = strtolower(str_replace('\\', '/', realpath(base_path()))).'/';
        if (str_starts_with(strtolower(str_replace('\\', '/', $parent)).'/', $base)) {
            throw new RuntimeException('Artefak tidak boleh berada dalam direktori aplikasi.');
        }
        for ($dir = $parent; ; $dir = dirname($dir)) {
            if (file_exists($dir.'/.git')) {
                throw new RuntimeException('Artefak tidak boleh berada dalam repository Git.');
            }
            if (dirname($dir) === $dir) {
                break;
            }
        }
        $resolved = $directory ? $parent : $parent.DIRECTORY_SEPARATOR.basename($path);
        if (! $directory && (file_exists($resolved) || is_link($resolved) || str_contains(basename($path), ':'))) {
            throw new RuntimeException('File kredensial harus baru, bukan symlink/alternate stream.');
        }
        return $resolved;
    }

    private function validateDatabase(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('Backup/apply mendukung MySQL/MariaDB proyek ini saja.');
        }
        if (DB::select("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND ENGINE <> 'InnoDB'", [DB::connection()->getDatabaseName()])) {
            throw new RuntimeException('Semua tabel harus InnoDB untuk transaksi dan backup konsisten.');
        }
    }

    private function backup(string $directory): string
    {
        $local = 'C:/laragon/bin/mysql/mysql-8.4.3-winx64/bin/mysqldump.exe';
        $binary = $this->option('dump-binary') ?: (new ExecutableFinder)->find('mysqldump') ?: (is_file($local) ? $local : (new ExecutableFinder)->find('mariadb-dump'));
        if (! $binary) {
            throw new RuntimeException('mysqldump tidak ditemukan; gunakan --dump-binary.');
        }
        $config = DB::connection()->getConfig();
        $path = $directory.'/database-'.date('Ymd-His').'-'.bin2hex(random_bytes(6)).'.sql';
        $this->writeNewFile($path, '');
        $args = [$binary, '--no-defaults', '--single-transaction', '--quick', '--hex-blob', '--routines', '--events', '--triggers', '--result-file='.$path, '--user='.$config['username']];
        if (! empty($config['unix_socket'])) {
            $args[] = '--socket='.$config['unix_socket'];
        } else {
            $args[] = '--host='.$config['host'];
            $args[] = '--port='.($config['port'] ?? 3306);
        }
        $args[] = '--databases';
        $args[] = $config['database'];
        $process = new Process($args, null, ['MYSQL_PWD' => $config['password'] ?? '']);
        $process->setTimeout(1800);
        $process->disableOutput();
        try {
            $process->run();
            clearstatcache(true, $path);
            if (! $process->isSuccessful() || filesize($path) === 0) {
                throw new RuntimeException('Backup gagal; tidak ada mutasi. Periksa akses dump dan konfigurasi database.');
            }
            $metadata = ['database' => $config['database'], 'host' => $config['host'], 'port' => $config['port'] ?? 3306,
                'created_at' => now()->toIso8601String(), 'server' => DB::selectOne('SELECT VERSION() AS version')->version,
                'sha256' => hash_file('sha256', $path), 'bytes' => filesize($path),
                'migrations' => Schema::hasTable('migrations') ? DB::table('migrations')->orderBy('id')->get()->all() : [],
            ];
            $this->writeNewFile($path.'.metadata.json', json_encode($metadata, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            unlink($path);
            throw $e;
        }
        return $path;
    }

    private function writeNewFile(string $path, string $contents): void
    {
        $mask = umask(0077);
        try {
            $file = fopen($path, 'xb');
        } finally {
            umask($mask);
        }
        if (! $file) {
            throw new RuntimeException('Tidak dapat membuat artefak privat.');
        }
        try {
            if (fwrite($file, $contents) !== strlen($contents) || ! fflush($file) || ! fsync($file)) {
                throw new RuntimeException('Penulisan artefak gagal.');
            }
        } catch (Throwable $e) {
            fclose($file);
            unlink($path);
            throw $e;
        }
        fclose($file);
    }

    private function validateSessions(): void
    {
        if (! in_array(config('session.driver'), ['file', 'database'], true)) {
            throw new RuntimeException('Apply hanya mendukung sesi file/database.');
        }
        if (config('session.driver') === 'database' && config('session.connection') && config('session.connection') !== config('database.default')) {
            throw new RuntimeException('Sesi database harus memakai koneksi transaksi yang sama.');
        }
    }

    private function revoke(array $ids, array &$sessionCopies): void
    {
        if (! $ids) {
            return;
        }
        DB::table('personal_access_tokens')->whereIn('tokenable_id', $ids)->whereIn('tokenable_type', [User::class, (new User)->getMorphClass()])->delete();
        DB::table('password_reset_tokens')->whereIn('email', DB::table('users')->whereIn('id', $ids)->select('email'))->delete();
        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->whereIn('user_id', $ids)->delete();
        }
        if (config('session.driver') === 'database') {
            DB::table(config('session.table'))->whereIn('user_id', $ids)->delete();
            return;
        }
        foreach (new \DirectoryIterator(config('session.files')) as $file) {
            if (! $file->isFile() || ! preg_match('/^[a-zA-Z0-9]{40}$/', $file->getFilename())) {
                continue;
            }
            $path = $file->getPathname();
            $raw = file_get_contents($path);
            if ($raw === false) {
                throw new RuntimeException('Sesi file tidak dapat dibaca.');
            }
            $payload = config('session.encrypt') ? app('encrypter')->decrypt($raw) : $raw;
            $data = config('session.serialization', 'php') === 'json' ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : @unserialize($payload, ['allowed_classes' => false]);
            if (! is_array($data)) {
                throw new RuntimeException('Payload sesi tidak dapat divalidasi.');
            }
            foreach ($data as $key => $value) {
                if (str_starts_with((string) $key, 'login_') && is_scalar($value) && in_array((int) $value, $ids)) {
                    $sessionCopies[$path] = $raw;
                    if (! unlink($path)) {
                        throw new RuntimeException('Pencabutan sesi file gagal.');
                    }
                    break;
                }
            }
        }
    }
}
