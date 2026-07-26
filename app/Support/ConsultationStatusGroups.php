<?php

namespace App\Support;

use App\Models\StatusCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Satu sumber kebenaran untuk "status apa yang dihitung sebagai survey / deal /
 * pending / batal".
 *
 * Sebelumnya logika ini terduplikasi di AnalyticsReportService dan
 * DashboardService dengan isi yang sudah berbeda, sehingga angka antar halaman
 * berpotensi tak sama. Kelas ini membaca daftar nama dari config/statuses.php
 * lalu mencocokkannya ke baris status_categories nyata; nama yang tidak cocok
 * diabaikan, bukan bikin id palsu.
 *
 * config/statuses.php boleh berisi string tunggal atau array nama.
 */
final class ConsultationStatusGroups
{
    /** Cache hasil query status_categories per request. */
    private static ?Collection $all = null;

    /**
     * ID status untuk sebuah grup config (survey/deal/pending/cancelled).
     *
     * @return list<int>
     */
    public static function ids(string $group): array
    {
        $wanted = collect(self::names($group))
            ->map(fn (string $name) => self::normalize($name))
            ->filter()
            ->unique();

        if ($wanted->isEmpty()) {
            return [];
        }

        return self::allStatuses()
            ->filter(fn (StatusCategory $status) => $wanted->contains(self::normalize($status->name)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    public static function surveyIds(): array
    {
        return self::ids('survey');
    }

    public static function dealIds(): array
    {
        return self::ids('deal');
    }

    public static function pendingIds(): array
    {
        return self::ids('pending');
    }

    public static function cancelledIds(): array
    {
        return self::ids('cancelled');
    }

    /**
     * Nama status mentah dari config untuk sebuah grup, selalu sebagai list.
     *
     * @return list<string>
     */
    public static function names(string $group): array
    {
        $configured = config("statuses.{$group}", []);

        $names = is_array($configured) ? $configured : [$configured];

        return array_values(array_filter(
            array_map(fn ($name) => (string) $name, $names),
            fn (string $name) => trim($name) !== ''
        ));
    }

    /**
     * Reset cache — dipakai test yang menambah/mengubah status_categories dalam
     * satu proses.
     */
    public static function flush(): void
    {
        self::$all = null;
    }

    private static function allStatuses(): Collection
    {
        return self::$all ??= StatusCategory::query()->get(['id', 'name']);
    }

    /**
     * Kunci pencocokan sama persis dengan normalizeStatusName() lama:
     * lowercase, ascii, pemisah jadi spasi, squish.
     */
    private static function normalize(?string $value): string
    {
        return (string) Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replace(['/', '-', '_'], ' ')
            ->squish();
    }
}
