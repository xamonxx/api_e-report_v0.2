<?php

namespace App\Support;

/**
 * Taksonomi grup akun.
 *
 * Sebelum ada kolom `accounts.account_group`, grup ditebak dari teks bebas
 * `accounts.description` di dalam AdminReportAttendanceExcelExporter. Kolom
 * sekarang menjadi satu-satunya sumber kebenaran; kelas ini memegang daftar
 * nilainya beserta peta labelnya.
 */
final class AccountGroup
{
    public const PC = 'PC';
    public const NPP1 = 'NPP1';
    public const NPP2 = 'NPP2';

    /** Label untuk dropdown dan tampilan. */
    private const LABELS = [
        self::PC => 'PC',
        self::NPP1 => 'NPP 1',
        self::NPP2 => 'NPP 2',
    ];

    /**
     * Nama payung yang dipakai di judul laporan. INI SATU-SATUNYA tempat nama
     * itu ditulis â€” subtitleLabel() menurunkan sisanya dari sini.
     */
    private const UMBRELLA = [
        self::PC => 'PUTRA CORPORATION',
        self::NPP1 => 'BISNIS PRIBADI',
        self::NPP2 => 'BISNIS PRIBADI',
    ];

    /** Dipakai saat laporan tidak difilter ke satu grup. */
    public const ALL_LABEL = 'SEMUA GRUP';

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::LABELS);
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function label(?string $group): ?string
    {
        $group = self::normalize($group);

        return $group === null ? null : self::LABELS[$group];
    }

    /**
     * Judul laporan untuk sebuah grup.
     *
     * Payung sengaja tidak dipakai mentah: NPP1 dan NPP2 sama-sama "BISNIS
     * PRIBADI", jadi dua export dengan data berbeda akan berjudul identik â€”
     * berbahaya untuk laporan. Label grup ditambahkan dalam kurung hanya bila
     * payungnya memang dipakai lebih dari satu grup, sehingga peta UMBRELLA
     * tetap satu-satunya yang perlu diubah bila penamaan berubah.
     */
    public static function subtitleLabel(?string $group): string
    {
        $group = self::normalize($group);

        if ($group === null) {
            return self::ALL_LABEL;
        }

        $umbrella = self::UMBRELLA[$group];
        $shared = count(array_keys(self::UMBRELLA, $umbrella, true)) > 1;

        return $shared
            ? sprintf('%s (%s)', $umbrella, self::LABELS[$group])
            : $umbrella;
    }

    public static function umbrella(?string $group): string
    {
        $group = self::normalize($group);

        return $group === null ? self::ALL_LABEL : self::UMBRELLA[$group];
    }

    /**
     * Menyeragamkan input pengguna/permintaan ke nilai kanonis.
     * "npp 1", "NPP 1", "npp1" â†’ "NPP1". Nilai tak dikenal â†’ null.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = str_replace(' ', '', mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value))));

        return in_array($clean, self::values(), true) ? $clean : null;
    }

    /**
     * Menurunkan grup dari teks `description` warisan.
     *
     * Predikat PUTRA/PC dan NPP disalin persis dari logika lama
     * AdminReportAttendanceExcelExporter::accountGroupLabel() agar pembagian
     * PC-vs-NPP identik setelah backfill.
     *
     * Satu perbedaan yang disengaja: logika lama melempar kasus kosong/tak
     * dikenal ke NPP, di sini ke PC â€” sesuai keputusan bahwa akun tanpa
     * penanda adalah bagian dari grup utama.
     */
    public static function fromDescription(?string $description): string
    {
        $normalized = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string) $description)));
        $compact = str_replace(' ', '', $normalized);

        if (str_contains($normalized, 'PUTRA') || $normalized === self::PC) {
            return self::PC;
        }

        if ($compact === self::NPP1) {
            return self::NPP1;
        }

        if ($compact === self::NPP2) {
            return self::NPP2;
        }

        // "NPP" tanpa angka: ikut NPP2, grup massal di AccountGroupSeeder.
        if (str_contains($normalized, 'NPP')) {
            return self::NPP2;
        }

        return self::PC;
    }

    /**
     * Memetakan grup ke dua ember lama (PC/NPP) yang masih dipakai kontrak
     * export absensi admin. NPP1 dan NPP2 keduanya masuk "NPP".
     */
    public static function legacyBucket(?string $group): string
    {
        return self::normalize($group) === self::PC ? 'PC' : 'NPP';
    }

    /**
     * Grup mana saja yang tercakup oleh sebuah ember lama.
     *
     * @return list<string>
     */
    public static function groupsInLegacyBucket(string $bucket): array
    {
        return mb_strtoupper(trim($bucket)) === 'PC'
            ? [self::PC]
            : [self::NPP1, self::NPP2];
    }
}
