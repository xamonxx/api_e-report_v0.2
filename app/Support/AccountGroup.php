<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
    /**
     * PC/NPP1/NPP2 dipertahankan sebagai konstanta (bukan dihapus total)
     * karena migration lama `2026_07_17_000001_add_account_group_to_accounts_table`
     * masih memanggil fromDescription(), yang me-return konstanta ini secara
     * literal. Menghapus konstantanya akan meledakkan migration itu kalau
     * database di-migrate dari nol. Grupnya sendiri sudah dihapus permanen
     * dari data pada 2026-09-24 (lihat Change-Log) - tidak lagi valid untuk
     * dipilih/difilter, sengaja dikeluarkan dari LABELS/UMBRELLA di bawah.
     */
    public const PC = 'PC';
    public const NPP1 = 'NPP1';
    public const NPP2 = 'NPP2';
    public const TEAM_A = 'A';
    public const TEAM_B = 'B';
    public const TEAM_C = 'C';
    public const TEAM_D = 'D';
    public const TEAM_E = 'E';
    public const TEAM_F = 'F';
    public const INSIP = 'INSIP';
    public const MITRA_SDK = 'MITRA SDK';
    public const MITRA_BSR = 'MITRA BSR';
    public const PRIBADI = 'PRIBADI';

    /** Fallback saat migration awal berjalan sebelum tabel master tersedia. */
    private const DEFAULT_LABELS = [
        self::PC => 'PC',
        self::INSIP => 'INSIP',
        self::MITRA_SDK => 'MITRA SDK',
        self::MITRA_BSR => 'MITRA BSR',
        self::PRIBADI => 'PRIBADI',
    ];

    /** Dipakai saat laporan tidak difilter ke satu grup. */
    public const ALL_LABEL = 'SEMUA GRUP';

    private const MEMO = 'account-group.memo';

    /** @return list<string> */
    public static function values(): array
    {
        return array_keys(self::labels());
    }

    /** @return array<string, string> */
    public static function labels(): array
    {
        return self::remember('labels', function () {
            if (! Schema::hasTable('account_groups')) {
                return self::DEFAULT_LABELS;
            }

            return DB::table('account_groups')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('name', 'code')
                ->all();
        });
    }

    /** @return array<string, string> */
    public static function activeLabels(): array
    {
        return self::remember('active_labels', function () {
            if (! Schema::hasTable('account_groups')) {
                return self::DEFAULT_LABELS;
            }

            return DB::table('account_groups')
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->pluck('name', 'code')
                ->all();
        });
    }

    /**
     * Dibuang saat master grup berubah (lihat App\Models\AccountGroup::booted).
     */
    public static function flushCache(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Memo per request/job: label() dan normalize() dipanggil per baris ekspor,
     * tanpa memo tiap baris memicu Schema::hasTable + pluck berulang (N+1).
     * Binding scoped di-reset Laravel antar request, job antrean, dan test.
     */
    private static function remember(string $key, callable $load): array
    {
        $app = app();

        if (! $app->bound(self::MEMO)) {
            $app->scoped(self::MEMO, fn () => new \ArrayObject());
        }

        $memo = $app->make(self::MEMO);

        if (! isset($memo[$key])) {
            $memo[$key] = $load();
        }

        return $memo[$key];
    }

    public static function label(?string $group): ?string
    {
        $group = self::normalize($group);

        return $group === null ? null : self::labels()[$group];
    }

    /**
     * Judul laporan untuk sebuah grup.
     *
     * Payung sengaja tidak dipakai mentah: NPP1 dan NPP2 sama-sama "BISNIS
     * PRIBADI", jadi dua export dengan data berbeda akan berjudul identik —
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

        return self::labels()[$group];
    }

    public static function umbrella(?string $group): string
    {
        $group = self::normalize($group);

        return $group === null ? self::ALL_LABEL : self::labels()[$group];
    }

    /**
     * Menyeragamkan input pengguna/permintaan ke nilai kanonis.
     * "npp 1", "NPP 1", "npp1" → "NPP1". Nilai tak dikenal → null.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', $value)));

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
     * dikenal ke NPP, di sini ke PC — sesuai keputusan bahwa akun tanpa
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
