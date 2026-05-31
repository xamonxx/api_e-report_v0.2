<?php

namespace App\Support;

/**
 * Lazy-loading accessor for Indonesian geographic reference data.
 *
 * The kota (~23 KB) and kecamatan (~945 KB) datasets used to live in config/,
 * which forced Laravel to parse them on EVERY request during bootstrap — even
 * for endpoints that never touch geography (dashboard, notification polling,
 * consultation lists, ...). They now live in resources/data/ and are required
 * only when a request actually needs them, then memoized for the rest of the
 * request lifecycle.
 */
class Wilayah
{
    private static ?array $cityMapping = null;

    private static ?array $districtMapping = null;

    /**
     * Province list remains a tiny config file (<1 KB) — cheap to keep there.
     */
    public static function provinces(): array
    {
        return config('wilayah.provinces', []);
    }

    /**
     * @return array<string, string> map of "City" => "Province"
     */
    public static function cityMapping(): array
    {
        return self::$cityMapping ??= self::load('wilayah_kota.php');
    }

    /**
     * @return array<int, array{district: string, city: string, province: string}>
     */
    public static function districtMapping(): array
    {
        return self::$districtMapping ??= self::load('wilayah_kecamatan.php');
    }

    public static function path(string $file): string
    {
        return base_path('resources/data/' . $file);
    }

    /**
     * Cache-busting token derived from the dataset files' mtime. Changes
     * automatically whenever `php artisan wilayah:sync` rewrites them, so any
     * cached, pre-computed responses invalidate themselves without manual work.
     */
    public static function version(): string
    {
        $kota = @filemtime(self::path('wilayah_kota.php')) ?: 0;
        $kecamatan = @filemtime(self::path('wilayah_kecamatan.php')) ?: 0;

        return $kota . '-' . $kecamatan;
    }

    private static function load(string $file): array
    {
        $path = self::path($file);

        if (! is_file($path)) {
            return [];
        }

        $data = require $path;

        return is_array($data) && isset($data['mapping']) && is_array($data['mapping'])
            ? $data['mapping']
            : [];
    }
}
