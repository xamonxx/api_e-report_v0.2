<?php

namespace App\Support;

/**
 * Satu sumber kebenaran untuk penamaan wilayah.
 *
 * Format kanonik mengikuti master Excel tim: "Kab. Bandung" / "Kota Bandung",
 * "Jakarta", "Yogyakarta". Nilai apa pun yang masuk lewat form, import, atau
 * data lama dipetakan ke bentuk itu supaya dropdown, DB, export, dan import
 * memakai istilah yang sama.
 *
 * Dipakai bersama oleh LeadsExcelExporter (menulis template) dan
 * ConsultationImportService (membaca template).
 */
final class WilayahNormalizer
{
    /**
     * Singkatan yang lazim diketik tim untuk kota tertentu.
     * Kunci = nama kota tanpa awalan "Kab."/"Kota".
     */
    public const SHORT_ALIASES = [
        'Jakarta Barat' => 'Jakbar',
        'Jakarta Pusat' => 'Jakpus',
        'Jakarta Selatan' => 'Jaksel',
        'Jakarta Timur' => 'Jaktim',
        'Jakarta Utara' => 'Jakut',
        'Bandung Barat' => 'KBB',
        'Tangerang Selatan' => 'Tangsel',
    ];

    /**
     * Nama provinsi versi lama/panjang yang masih mungkin ada di data.
     */
    private const PROVINCE_ALIASES = [
        'dki jakarta' => 'Jakarta',
        'dki' => 'Jakarta',
        'daerah khusus ibukota jakarta' => 'Jakarta',
        'daerah istimewa yogyakarta' => 'Yogyakarta',
        'di yogyakarta' => 'Yogyakarta',
        'diy' => 'Yogyakarta',
        'jogjakarta' => 'Yogyakarta',
        'jogja' => 'Yogyakarta',
        // Singkatan yang lazim ditulis tim di laporan Excel lama.
        'jabar' => 'Jawa Barat',
        'jateng' => 'Jawa Tengah',
        'jatim' => 'Jawa Timur',
        'sumut' => 'Sumatera Utara',
        'sumbar' => 'Sumatera Barat',
        'sumsel' => 'Sumatera Selatan',
        'kalbar' => 'Kalimantan Barat',
        'kaltim' => 'Kalimantan Timur',
        'kalsel' => 'Kalimantan Selatan',
        'kalteng' => 'Kalimantan Tengah',
        'sulsel' => 'Sulawesi Selatan',
        'sulut' => 'Sulawesi Utara',
        'ntb' => 'Nusa Tenggara Barat',
        'ntt' => 'Nusa Tenggara Timur',
    ];

    /**
     * Salah eja dan penyebutan pendek yang muncul di data lama, dipetakan ke
     * nama kota kanonik. Berbeda dari SHORT_ALIASES yang hanya menampung satu
     * singkatan resmi per kota; di sini satu kota boleh punya banyak ejaan.
     *
     * Kunci ditulis dalam bentuk looseKey() - huruf kecil, tanpa titik.
     */
    private const CITY_ALIASES = [
        'tanggerang' => 'Kota Tangerang',
        'tanggerang selatan' => 'Kota Tangerang Selatan',
        'tangsel' => 'Kota Tangerang Selatan',
        'jakarta' => 'Kota Jakarta Pusat',
        'tasik' => 'Kota Tasikmalaya',
        'b a t a m' => 'Kota Batam',
        'kota b a t a m' => 'Kota Batam',
        // Nama resminya "Mahakam Ulu", kerap tertulis "Mahakam Hulu".
        'kab mahakam hulu' => 'Kab. Mahakam Ulu',
        'mahakam hulu' => 'Kab. Mahakam Ulu',
        'mahulu' => 'Kab. Mahakam Ulu',
    ];

    /** @var array<string, string>|null kunci longgar => nama provinsi kanonik */
    private static ?array $provinceIndex = null;

    /** @var array<string, string>|null kunci kota (berawalan) => nama kota kanonik */
    private static ?array $cityIndex = null;

    /** @var array<string, string>|null kunci kota tanpa awalan => nama kota kanonik */
    private static ?array $cityPlainIndex = null;

    /** @var array<string, array<string, string>>|null [kunci kota][kunci kecamatan] => nama kecamatan */
    private static ?array $districtByCity = null;

    /** @var array<string, string>|null kunci longgar => nama kecamatan (lintas kota) */
    private static ?array $districtIndex = null;

    // ── Kunci pembanding ────────────────────────────────────────────

    /**
     * Kunci longgar: huruf kecil, tanpa titik/apostrof, tanda hubung jadi
     * spasi, spasi dirapatkan. "Kaway Xvi" dan "KAWAY XVI" bertemu di sini.
     */
    public static function looseKey(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        $value = str_replace(['.', "'", '`'], '', $value);
        $value = str_replace(['-', '_', '/'], ' ', $value);

        return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    }

    /**
     * Kunci rapat: kunci longgar tanpa spasi sama sekali. Menangkap pasangan
     * seperti "Blang Pidie" vs "Blangpidie".
     */
    public static function squashKey(?string $value): string
    {
        return str_replace(' ', '', self::looseKey($value));
    }

    /**
     * Kunci kota: awalan jenis wilayah diseragamkan, TIDAK dibuang.
     * "Kabupaten Bandung" / "Kab Bandung" / "Kab. Bandung" -> "kab bandung",
     * "Kota Bandung" -> "kota bandung".
     *
     * Awalan wajib dipertahankan: membuangnya membuat "Kab. Bandung" dan
     * "Kota Bandung" bertabrakan di kunci yang sama.
     */
    public static function cityKey(?string $value): string
    {
        $key = self::looseKey($value);
        $key = preg_replace('/^kabupaten administrasi\s+/u', 'kab ', $key) ?? $key;
        $key = preg_replace('/^kota administrasi\s+/u', 'kota ', $key) ?? $key;
        $key = preg_replace('/^kabupaten\s+/u', 'kab ', $key) ?? $key;

        return trim($key);
    }

    /**
     * Kunci kota tanpa awalan sama sekali - hanya dipakai sebagai cadangan bila
     * user mengetik nama polos ("Bandung") tanpa menyebut Kab./Kota.
     */
    public static function cityPlainKey(?string $value): string
    {
        $key = self::cityKey($value);

        return trim(preg_replace('/^(kab|kota)\s+/u', '', $key) ?? $key);
    }

    // ── Provinsi ────────────────────────────────────────────────────

    public static function canonicalProvince(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if (PendingConfirmation::matches($value)) {
            return PendingConfirmation::REGION_LABEL;
        }

        $key = self::looseKey($value);

        if (isset(self::PROVINCE_ALIASES[$key])) {
            return self::PROVINCE_ALIASES[$key];
        }

        return self::provinceIndex()[$key] ?? null;
    }

    // ── Kota / Kabupaten ────────────────────────────────────────────

    public static function canonicalCity(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }

        if (PendingConfirmation::matches($value)) {
            return PendingConfirmation::REGION_LABEL;
        }

        $exact = self::cityIndex()[self::cityKey($value)] ?? null;
        if ($exact !== null) {
            return $exact;
        }

        // Nama polos tanpa Kab./Kota, mis. "Bandung".
        $plain = self::cityPlainIndex()[self::cityPlainKey($value)] ?? null;
        if ($plain !== null) {
            return $plain;
        }

        // Terakhir: salah eja yang sudah dikenal. Ditaruh paling belakang agar
        // tidak pernah mengalahkan nama asli dari dataset wilayah.
        return self::CITY_ALIASES[self::looseKey($value)] ?? null;
    }

    /**
     * Nama kota untuk Excel: "Kabupaten X" -> "Kab. X".
     */
    public static function excelCityName(?string $city): string
    {
        $city = trim((string) $city);
        $city = preg_replace('/^Kota Administrasi\s+/u', 'Kota ', $city) ?? $city;
        $city = preg_replace('/^Kabupaten Administrasi\s+/u', 'Kab. ', $city) ?? $city;

        return preg_replace('/^Kabupaten\s+/u', 'Kab. ', $city) ?? $city;
    }

    /**
     * Kebalikan excelCityName(): "Kab. X" -> "Kabupaten X".
     */
    public static function fullCityName(string $city): string
    {
        return preg_replace('/^Kab\.\s+/u', 'Kabupaten ', $city) ?? $city;
    }

    /**
     * Semua ejaan yang harus dikenali untuk satu kota — dipakai membangun
     * kolom lookup di sheet Opsi template.
     *
     * @return list<string>
     */
    public static function cityAliases(string $city): array
    {
        $plain = preg_replace('/^(Kota Administrasi|Kabupaten Administrasi|Kota|Kabupaten|Kab\.)\s+/u', '', $city) ?? $city;
        $names = [$city, $plain];

        if (str_starts_with($city, 'Kab. ')) {
            $names[] = str_replace('Kab. ', 'Kab ', $city);
        }

        $long = self::fullCityName($city);
        if ($long !== $city) {
            $names[] = $long;
        }

        if (isset(self::SHORT_ALIASES[$plain])) {
            $names[] = self::SHORT_ALIASES[$plain];
        }

        return array_values(array_unique(array_filter($names)));
    }

    // ── Kecamatan ───────────────────────────────────────────────────

    /**
     * Rapikan nama kecamatan ke ejaan dataset.
     *
     * Kecamatan boleh diketik manual di template, jadi hasilnya selalu berisi
     * nilai: bila tidak ditemukan padanannya, ketikan user dipakai apa adanya
     * (sudah dirapikan spasinya) dengan penanda matched = false.
     *
     * @return array{value: ?string, matched: bool}
     */
    public static function canonicalDistrict(?string $value, ?string $city = null): array
    {
        if (! filled($value)) {
            return ['value' => null, 'matched' => false];
        }

        if (PendingConfirmation::matches($value)) {
            return ['value' => PendingConfirmation::REGION_LABEL, 'matched' => true];
        }

        $tidy = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
        $loose = self::looseKey($tidy);
        $squash = self::squashKey($tidy);

        // Dibatasi kota terpilih lebih dulu supaya pencocokan rapat tidak
        // salah menebak kecamatan milik kabupaten lain.
        $canonicalCity = self::canonicalCity($city);
        if ($canonicalCity !== null) {
            $scoped = self::districtByCity()[self::cityKey($canonicalCity)] ?? [];

            if (isset($scoped[$loose])) {
                return ['value' => $scoped[$loose], 'matched' => true];
            }

            foreach ($scoped as $key => $name) {
                if (str_replace(' ', '', $key) === $squash) {
                    return ['value' => $name, 'matched' => true];
                }
            }
        }

        $global = self::districtIndex();

        if (isset($global[$loose])) {
            return ['value' => $global[$loose], 'matched' => true];
        }

        if (isset($global[$squash])) {
            return ['value' => $global[$squash], 'matched' => true];
        }

        return ['value' => $tidy, 'matched' => false];
    }

    /**
     * @return list<string> kecamatan milik satu kota, terurut
     */
    public static function districtsForCity(string $city): array
    {
        $names = array_values(self::districtByCity()[self::cityKey($city)] ?? []);
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    // ── Indeks ──────────────────────────────────────────────────────

    private static function provinceIndex(): array
    {
        if (self::$provinceIndex === null) {
            self::$provinceIndex = [];
            foreach (Wilayah::provinces() as $province) {
                self::$provinceIndex[self::looseKey($province)] = $province;
            }
        }

        return self::$provinceIndex;
    }

    private static function cityIndex(): array
    {
        if (self::$cityIndex === null) {
            self::$cityIndex = [];
            foreach (array_keys(Wilayah::cityMapping()) as $city) {
                self::$cityIndex[self::cityKey($city)] = $city;

                $plain = preg_replace('/^(Kota|Kabupaten|Kab\.)\s+/u', '', $city) ?? $city;
                if (isset(self::SHORT_ALIASES[$plain])) {
                    self::$cityIndex[self::looseKey(self::SHORT_ALIASES[$plain])] = $city;
                }
            }
        }

        return self::$cityIndex;
    }

    /**
     * Indeks cadangan untuk nama polos. Bila satu nama dipakai Kabupaten dan
     * Kota sekaligus (mis. Bandung), varian "Kota" dimenangkan karena itu yang
     * umumnya dimaksud saat orang menyebut nama kota tanpa awalan.
     */
    private static function cityPlainIndex(): array
    {
        if (self::$cityPlainIndex === null) {
            self::$cityPlainIndex = [];
            foreach (array_keys(Wilayah::cityMapping()) as $city) {
                $key = self::cityPlainKey($city);

                if (! isset(self::$cityPlainIndex[$key]) || str_starts_with($city, 'Kota ')) {
                    self::$cityPlainIndex[$key] = $city;
                }
            }
        }

        return self::$cityPlainIndex;
    }

    private static function districtByCity(): array
    {
        if (self::$districtByCity === null) {
            self::$districtByCity = [];
            foreach (Wilayah::districtMapping() as $row) {
                $city = $row['city'] ?? null;
                $district = $row['district'] ?? null;

                if (! $city || ! $district) {
                    continue;
                }

                self::$districtByCity[self::cityKey($city)][self::looseKey($district)] = $district;
            }
        }

        return self::$districtByCity;
    }

    private static function districtIndex(): array
    {
        if (self::$districtIndex === null) {
            self::$districtIndex = [];
            foreach (Wilayah::districtMapping() as $row) {
                $district = $row['district'] ?? null;
                if (! $district) {
                    continue;
                }

                self::$districtIndex[self::looseKey($district)] ??= $district;
                self::$districtIndex[self::squashKey($district)] ??= $district;
            }
        }

        return self::$districtIndex;
    }
}
