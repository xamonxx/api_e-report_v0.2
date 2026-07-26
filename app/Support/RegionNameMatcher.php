<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Mencocokkan nama wilayah dari kolom consultations ke identitas wilayah
 * kanonik yang dipakai peta (feature.id GeoJSON).
 *
 * Daftar acuan diambil dari config/wilayah.php + resources/data/wilayah_kota.php
 * yang sudah ada (lihat modul Wilayah), bukan daftar baru. Karena form
 * konsultasi mengisi province/city dari daftar kanonik itu juga, mayoritas
 * nilai cocok langsung tanpa alias.
 *
 * `region_id` = nama kanonik yang dinormalisasi (lowercase, ascii,
 * non-alfanumerik jadi spasi, squish) — sama persis dengan kunci normalisasi di
 * AnalyticsReportService, agar bisa di-join di frontend.
 *
 * Nilai placeholder ("Belum konfirmasi") dan nama yang tak dikenali TIDAK
 * dibuang diam-diam: caller memisahkannya lewat kembalian null lalu
 * menampilkannya sebagai "belum berlokasi" / "tak dikenali".
 */
final class RegionNameMatcher
{
    /** @var array<string, string>|null  region_id => nama kanonik provinsi */
    private static ?array $provinceById = null;

    /** @var array<string, string>|null  region_id kota => nama provinsi kanonik */
    private static ?array $cityToProvince = null;

    /**
     * Alias provinsi: normalisasi nama non-kanonik (ejaan BPS / singkatan
     * umum) ke region_id kanonik. Ditambah hanya untuk perbedaan penamaan
     * nyata; daftar kanonik aplikasi memakai "Jakarta", bukan "DKI Jakarta".
     *
     * @var array<string, string> normalized alias => region_id kanonik
     */
    private const PROVINCE_ALIASES = [
        'dki jakarta' => 'jakarta',
        'daerah khusus ibukota jakarta' => 'jakarta',
        'di yogyakarta' => 'yogyakarta',
        'daerah istimewa yogyakarta' => 'yogyakarta',
        'bangka belitung' => 'kepulauan bangka belitung',
    ];

    /**
     * Cocokkan nama provinsi.
     *
     * @return array{region_id: string, name: string}|null
     *         null bila placeholder atau tak dikenali (bedakan lewat
     *         PendingConfirmation::matches() di caller bila perlu).
     */
    public static function matchProvince(?string $value): ?array
    {
        if ($value === null || PendingConfirmation::matches($value)) {
            return null;
        }

        $key = self::normalize($value);
        $key = self::PROVINCE_ALIASES[$key] ?? $key;

        $provinces = self::provinces();

        if (! isset($provinces[$key])) {
            return null;
        }

        return ['region_id' => $key, 'name' => $provinces[$key]];
    }

    /**
     * Provinsi kanonik untuk sebuah kota, dari mapping kota->provinsi yang
     * sudah ada — tidak bergantung kolom `province` yang 23% placeholder.
     *
     * @return array{region_id: string, name: string}|null
     */
    public static function provinceForCity(?string $city): ?array
    {
        if ($city === null || PendingConfirmation::matches($city)) {
            return null;
        }

        $province = self::cityToProvince()[self::normalize($city)] ?? null;

        return self::matchProvince($province);
    }

    /**
     * Cocokkan nama kota/kabupaten ke identitas peta kabupaten.
     *
     * `region_id` = nama kota dinormalisasi, sama dengan feature.id di
     * idn-kabkota.geojson (mis. "Kota Bandung" -> "kota bandung",
     * "Kab. Bogor" -> "kab bogor"). Kolom `city` di DB sudah memakai penamaan
     * kanonik itu, jadi tak perlu tabel alias terpisah.
     *
     * @return array{region_id: string, name: string, province: string|null}|null
     */
    public static function matchCity(?string $value): ?array
    {
        if ($value === null || PendingConfirmation::matches($value)) {
            return null;
        }

        $province = self::provinceForCity($value);

        return [
            'region_id' => self::normalize($value),
            'name' => trim(preg_replace('/\s+/u', ' ', $value) ?? ''),
            'province' => $province['name'] ?? null,
        ];
    }

    public static function normalize(?string $value): string
    {
        return (string) Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    /**
     * Semua provinsi kanonik sebagai [region_id => nama].
     *
     * @return array<string, string>
     */
    public static function provinces(): array
    {
        if (self::$provinceById !== null) {
            return self::$provinceById;
        }

        $map = [];
        foreach ((array) config('wilayah.provinces', []) as $name) {
            $map[self::normalize($name)] = $name;
        }

        return self::$provinceById = $map;
    }

    /**
     * region_id provinsi yang TIDAK terwakili oleh kumpulan nama masukan —
     * berguna untuk "wilayah mana yang belum pernah dikunjungi".
     *
     * @param  iterable<string>  $matchedRegionIds
     * @return list<array{region_id: string, name: string}>
     */
    public static function missingProvinces(iterable $matchedRegionIds): array
    {
        $seen = [];
        foreach ($matchedRegionIds as $id) {
            $seen[$id] = true;
        }

        $missing = [];
        foreach (self::provinces() as $id => $name) {
            if (! isset($seen[$id])) {
                $missing[] = ['region_id' => $id, 'name' => $name];
            }
        }

        return $missing;
    }

    /**
     * @return array<string, string> region_id kota => nama provinsi
     */
    private static function cityToProvince(): array
    {
        if (self::$cityToProvince !== null) {
            return self::$cityToProvince;
        }

        $data = require resource_path('data/wilayah_kota.php');
        $mapping = $data['mapping'] ?? [];

        $map = [];
        foreach ($mapping as $city => $province) {
            $map[self::normalize($city)] = $province;
        }

        return self::$cityToProvince = $map;
    }

    public static function flush(): void
    {
        self::$provinceById = null;
        self::$cityToProvince = null;
    }
}
