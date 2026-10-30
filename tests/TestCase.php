<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /** Satu-satunya database yang boleh di-migrate:fresh oleh test. */
    private const TEST_DATABASE = 'e_report_audit';

    public function createApplication()
    {
        $app = parent::createApplication();

        $this->guardAgainstNonTestDatabase($app);

        return $app;
    }

    /**
     * RefreshDatabase menjalankan migrate:fresh. Kalau env salah baca (mis.
     * DB_DATABASE dari env_file Docker), test akan menghapus database dev.
     * Hentikan sebelum trait apa pun menyentuh database.
     */
    private function guardAgainstNonTestDatabase(Application $app): void
    {
        $connection = $app['config']->get('database.default');
        $config = $app['config']->get("database.connections.{$connection}", []);
        $driver = $config['driver'] ?? null;
        $database = $config['database'] ?? null;

        if ($driver === 'sqlite' && $database === ':memory:') {
            return;
        }

        if ($database !== self::TEST_DATABASE) {
            throw new RuntimeException(sprintf(
                'Test dihentikan: koneksi "%s" menunjuk database "%s", bukan "%s". '
                .'Cek <server name="DB_DATABASE"> di phpunit.xml.dist.',
                $connection,
                (string) $database,
                self::TEST_DATABASE,
            ));
        }
    }
}
