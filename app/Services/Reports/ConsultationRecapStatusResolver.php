<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Support\AccountGroup;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Satu sumber kebenaran buat status "lapor konsul harian" per akun per
 * tanggal — dipakai bareng oleh exporter matrix kalender
 * (AdminReportAttendanceExcelExporter) dan exporter log harian
 * (ConsultationDailyLogExcelExporter) supaya warna/status di dua format
 * export itu tidak pernah beda untuk kombinasi akun+tanggal yang sama.
 *
 * Diekstrak dari AdminReportAttendanceExcelExporter — logika penentuan
 * warnanya TIDAK diubah, cuma dipindah lokasi & resolveCell() diperkaya
 * jadi resolveStatus() yang juga mengembalikan label & keterangan teks.
 */
class ConsultationRecapStatusResolver
{
    public const CELL_GREEN = 'statusAdaWa';
    public const CELL_YELLOW = 'statusNolWa';
    public const CELL_BLUE = 'statusLibur';
    public const CELL_RED = 'statusTidakLaporan';

    /** Kategori absensi yang berarti admin bekerja hari itu. */
    private const WORKING_CATEGORIES = ['ada_wa', 'nol_wa'];

    private const CATEGORY_DAY_OFF = 'libur_susulan';

    /**
     * Satu baris = satu akun, lengkap dengan konsultasi & kategori absensi
     * per tanggal dalam rentang — siap dipakai kedua exporter.
     *
     * Akun tanpa admin tetap ikut supaya daftar akun utuh — barisnya wajar
     * terbaca merah karena memang tak ada aktivitas.
     *
     * @return Collection<int, array{account_id:int, account_name:string, account_group:string, consultation_counts:Collection, attendance_categories:Collection}>
     */
    public function accountRows(Carbon $rangeStart, Carbon $rangeEnd, ?string $accountGroup = null, ?array $accountIds = null): Collection
    {
        $start = $rangeStart->toDateString();
        $end = $rangeEnd->toDateString();

        $accounts = Account::query()
            ->when($accountGroup, fn ($query) => $query->where('account_group', $accountGroup))
            ->when($accountIds, fn ($query) => $query->whereIn('id', $accountIds))
            ->get(['id', 'name', 'description', 'account_group'])
            ->map(fn (Account $account) => [
                'account_id' => (int) $account->id,
                'account_name' => $account->name,
                'account_group' => AccountGroup::normalize($account->account_group) ?? AccountGroup::PC,
            ])
            ->sortBy([
                fn (array $left, array $right) => $this->accountGroupSort($left['account_group']) <=> $this->accountGroupSort($right['account_group']),
                fn (array $left, array $right) => strcmp($left['account_name'], $right['account_name']),
            ])
            ->values();

        $ids = $accounts->pluck('account_id')->filter()->unique()->values();
        $consultationCounts = $this->consultationCountsByDate($ids, $start, $end);
        $attendanceCategories = $this->attendanceCategoriesByDate($ids, $start, $end);

        return $accounts
            ->map(function (array $row) use ($consultationCounts, $attendanceCategories) {
                $row['consultation_counts'] = $consultationCounts->get($row['account_id'], collect());
                $row['attendance_categories'] = $attendanceCategories->get($row['account_id'], collect());

                return $row;
            })
            ->values();
    }

    /**
     * Konsul per akun per tanggal, dipecah jadi laporan tepat waktu dan
     * susulan. Pembandingnya `DATE(created_at)` vs `consultation_date`:
     * dibuat di hari yang sama = tepat waktu, dibuat belakangan = susulan.
     *
     * @return Collection<int, Collection<string, array{normal:int, susulan:int}>>
     */
    public function consultationCountsByDate(Collection $accountIds, string $start, string $end): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return DB::table('consultations')
            ->select([
                'account_id',
                DB::raw('DATE(consultation_date) as consultation_day'),
                DB::raw('SUM(CASE WHEN DATE(created_at) = DATE(consultation_date) THEN 1 ELSE 0 END) as normal_total'),
                DB::raw('SUM(CASE WHEN DATE(created_at) > DATE(consultation_date) THEN 1 ELSE 0 END) as susulan_total'),
            ])
            ->whereNull('deleted_at')
            ->whereIn('account_id', $accountIds->all())
            ->whereDate('consultation_date', '>=', $start)
            ->whereDate('consultation_date', '<=', $end)
            ->groupBy('account_id', DB::raw('DATE(consultation_date)'))
            ->get()
            ->groupBy(fn ($row) => (int) $row->account_id)
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [
                (string) $row->consultation_day => [
                    'normal' => (int) $row->normal_total,
                    'susulan' => (int) $row->susulan_total,
                ],
            ]));
    }

    /**
     * Kategori absensi per akun per tanggal. Satu akun bisa punya lebih dari
     * satu admin, jadi nilainya daftar — bukan satu kategori.
     *
     * @return Collection<int, Collection<string, list<string>>>
     */
    public function attendanceCategoriesByDate(Collection $accountIds, string $start, string $end): Collection
    {
        if ($accountIds->isEmpty()) {
            return collect();
        }

        return DB::table('report_attendances')
            ->select(['account_id', 'report_date', 'report_category'])
            ->whereIn('account_id', $accountIds->all())
            ->whereDate('report_date', '>=', $start)
            ->whereDate('report_date', '<=', $end)
            ->get()
            ->groupBy(fn ($row) => (int) $row->account_id)
            ->map(fn (Collection $rows) => $rows
                ->groupBy(fn ($row) => Carbon::parse($row->report_date)->format('Y-m-d'))
                ->map(fn (Collection $dayRows) => $dayRows
                    ->pluck('report_category')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all()));
    }

    /**
     * @param  array{normal:int, susulan:int}  $split
     */
    public function splitForDate(array $row, string $dateKey): array
    {
        $counts = $row['consultation_counts']->get($dateKey);

        return [
            'normal' => (int) ($counts['normal'] ?? 0),
            'susulan' => (int) ($counts['susulan'] ?? 0),
        ];
    }

    /**
     * Menentukan style warna, angka, label, dan keterangan satu sel
     * akun+tanggal. Urutan prioritas, berhenti di kecocokan pertama:
     *   1. ada laporan tepat waktu  -> hijau, angka = tepat waktu + susulan
     *   2. hanya laporan susulan    -> biru,  angka = susulan
     *   3. absen & bekerja          -> kuning, angka 0
     *   4. absen & libur/susulan    -> biru,   angka 0
     *   5. tidak ada apa-apa        -> merah,  angka 0
     *
     * Aturan 1 yang menjaga tanggal yang sudah hijau tidak berubah jadi biru
     * ketika kemudian ditambah data susulan — susulan hanya menambah angka.
     * Aturan 3 didahulukan atas 4 supaya akun dengan dua admin, yang satu
     * melapor kerja dan satunya libur, tidak terbaca sebagai hari libur.
     *
     * @param  list<string>  $categories
     */
    public function resolveStatus(int $normal, int $susulan, array $categories): ConsultationRecapStatus
    {
        if ($normal > 0) {
            return new ConsultationRecapStatus(
                self::CELL_GREEN,
                $normal + $susulan,
                'ADA WA',
                $susulan > 0
                    ? sprintf('%d konsultasi tepat waktu, %d susulan', $normal, $susulan)
                    : sprintf('%d konsultasi tepat waktu', $normal)
            );
        }

        if ($susulan > 0) {
            return new ConsultationRecapStatus(
                self::CELL_BLUE,
                $susulan,
                'SUSULAN / LIBUR',
                sprintf('%d konsultasi susulan', $susulan)
            );
        }

        if (array_intersect(self::WORKING_CATEGORIES, $categories) !== []) {
            return new ConsultationRecapStatus(self::CELL_YELLOW, 0, '0 DATA', 'Admin lapor kerja, 0 konsultasi');
        }

        if (in_array(self::CATEGORY_DAY_OFF, $categories, true)) {
            return new ConsultationRecapStatus(self::CELL_BLUE, 0, 'SUSULAN / LIBUR', 'Diklaim libur/susulan admin');
        }

        return new ConsultationRecapStatus(self::CELL_RED, 0, 'TIDAK LAPORAN', 'Tidak ada laporan/aktivitas');
    }

    /**
     * @param  array{consultation_counts: Collection, attendance_categories: Collection}  $row
     */
    public function resolveStatusForDate(array $row, string $dateKey): ConsultationRecapStatus
    {
        $split = $this->splitForDate($row, $dateKey);

        return $this->resolveStatus(
            $split['normal'],
            $split['susulan'],
            $row['attendance_categories']->get($dateKey, [])
        );
    }

    /** Urutan grup mengikuti urutan deklarasi di AccountGroup. */
    public function accountGroupSort(string $group): int
    {
        $order = array_search($group, AccountGroup::values(), true);

        return $order === false ? PHP_INT_MAX : $order;
    }
}
