<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class SyncWilayah extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-wilayah';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch complete Indonesian region data from emsifa/api-wilayah-indonesia and generate PHP config files.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Memulai pengambilan data wilayah Indonesia dari emsifa/api-wilayah-indonesia (CSV format)...');

        // 1. Fetch Provinces
        $this->info('Mengunduh data Provinsi...');
        $provincesResponse = Http::withoutVerifying()->get('https://raw.githubusercontent.com/emsifa/api-wilayah-indonesia/master/data/provinces.csv');
        if (!$provincesResponse->successful()) {
            $this->error('Gagal mengunduh data Provinsi.');
            return 1;
        }

        $provincesCsv = $provincesResponse->body();
        $provinces = [];
        $provinceMap = []; // id => normalized_name

        $lines = explode("\n", $provincesCsv);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = str_getcsv($line);
            if (count($parts) < 2) continue;
            $id = $parts[0];
            $name = $parts[1];
            
            $normalizedProv = $this->normalizeName($name);
            // Special case for Yogyakarta
            if (str_contains(strtolower($normalizedProv), 'yogyakarta')) {
                $normalizedProv = 'Daerah Istimewa Yogyakarta';
            }
            $provinces[] = $normalizedProv;
            $provinceMap[$id] = $normalizedProv;
        }

        // Sort provinces alphabetically
        sort($provinces);

        // Save config/wilayah.php
        $wilayahConfigPath = config_path('wilayah.php');
        $wilayahContent = "<?php\n\nreturn [\n    'provinces' => " . var_export($provinces, true) . ",\n];\n";
        file_put_contents($wilayahConfigPath, $wilayahContent);
        $this->info('Berhasil memperbarui config/wilayah.php');

        // 2. Fetch Regencies/Cities
        $this->info('Mengunduh data Kabupaten/Kota...');
        $regenciesResponse = Http::withoutVerifying()->get('https://raw.githubusercontent.com/emsifa/api-wilayah-indonesia/master/data/regencies.csv');
        if (!$regenciesResponse->successful()) {
            $this->error('Gagal mengunduh data Kabupaten/Kota.');
            return 1;
        }

        $regenciesCsv = $regenciesResponse->body();
        $citiesMapping = [];
        $cityMap = []; // id => [normalized_name, province_name]

        $lines = explode("\n", $regenciesCsv);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = str_getcsv($line);
            if (count($parts) < 3) continue;
            $id = $parts[0];
            $provinceId = $parts[1];
            $name = $parts[2];

            $normalizedCity = $this->normalizeName($name);
            $provName = $provinceMap[$provinceId] ?? '';
            if ($provName) {
                $citiesMapping[$normalizedCity] = $provName;
                $cityMap[$id] = [
                    'name' => $normalizedCity,
                    'province' => $provName
                ];
            }
        }

        // Sort cities mapping by key
        ksort($citiesMapping);

        // Save resources/data/wilayah_kota.php
        $wilayahKotaConfigPath = \App\Support\Wilayah::path('wilayah_kota.php');
        $this->ensureDataDir($wilayahKotaConfigPath);
        $wilayahKotaContent = "<?php\n\nreturn [\n    'mapping' => " . var_export($citiesMapping, true) . ",\n];\n";
        file_put_contents($wilayahKotaConfigPath, $wilayahKotaContent);
        $this->info('Berhasil memperbarui resources/data/wilayah_kota.php');

        // 3. Fetch Districts/Kecamatan
        $this->info('Mengunduh data Kecamatan...');
        $districtsResponse = Http::withoutVerifying()->get('https://raw.githubusercontent.com/emsifa/api-wilayah-indonesia/master/data/districts.csv');
        if (!$districtsResponse->successful()) {
            $this->error('Gagal mengunduh data Kecamatan.');
            return 1;
        }

        $districtsCsv = $districtsResponse->body();
        $districtsMapping = [];

        $lines = explode("\n", $districtsCsv);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            $parts = str_getcsv($line);
            if (count($parts) < 3) continue;
            $id = $parts[0];
            $regencyId = $parts[1];
            $name = $parts[2];

            $normalizedDist = $this->normalizeName($name);
            $cityInfo = $cityMap[$regencyId] ?? null;
            if ($cityInfo) {
                $districtsMapping[] = [
                    'district' => $normalizedDist,
                    'city' => $cityInfo['name'],
                    'province' => $cityInfo['province']
                ];
            }
        }

        // Save resources/data/wilayah_kecamatan.php
        $wilayahKecConfigPath = \App\Support\Wilayah::path('wilayah_kecamatan.php');
        $this->ensureDataDir($wilayahKecConfigPath);
        $wilayahKecContent = "<?php\n\nreturn [\n    'mapping' => " . var_export($districtsMapping, true) . ",\n];\n";
        file_put_contents($wilayahKecConfigPath, $wilayahKecContent);
        $this->info('Berhasil memperbarui resources/data/wilayah_kecamatan.php');

        $this->info('✅ Sinkronisasi data wilayah selesai dengan sukses!');
        return 0;
    }

    /**
     * Ensure the target data directory exists before writing.
     */
    private function ensureDataDir(string $filePath): void
    {
        $dir = dirname($filePath);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Normalize name to Title Case.
     */
    private function normalizeName(string $name): string
    {
        $name = ucwords(strtolower($name));
        $name = str_replace('Dki ', 'DKI ', $name);
        $name = str_replace('D.i. ', 'DI ', $name);
        return trim($name);
    }
}
