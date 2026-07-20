<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Nama hari & bulan Bahasa Indonesia.
 *
 * Carbon->locale('id') butuh ekstensi intl untuk translatedFormat yang
 * konsisten; peta eksplisit ini sudah lebih dulu dipakai (dan diduplikasi) di
 * LeadsReportService::DAY_NAMES dan AdminReportAttendanceExcelExporter::dayName().
 * Kelas ini menjadi rumah untuk kode baru â€” dua salinan lama sengaja dibiarkan
 * agar perubahan ini tidak menyentuh laporan yang sedang berjalan.
 */
final class IndonesianDate
{
    /** Diindeks ISO: 1 = Senin â€¦ 7 = Minggu. */
    private const DAY_NAMES = [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];

    private const MONTH_NAMES = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];

    public static function dayName(CarbonInterface $date): string
    {
        return self::DAY_NAMES[$date->dayOfWeekIso] ?? $date->format('l');
    }

    public static function monthName(CarbonInterface $date): string
    {
        return self::MONTH_NAMES[(int) $date->format('n')] ?? $date->format('F');
    }
}
