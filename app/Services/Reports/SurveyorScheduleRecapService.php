<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\Survey;
use App\Models\User;
use App\Support\AccountGroup;
use App\Support\IndonesianDate;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;

/**
 * Rekap jadwal surveyor: beban survey tiap surveyor dalam satu minggu
 * Seninâ€“Minggu.
 *
 * Satu sumber data untuk dua konsumen â€” halaman rekap (JSON) dan
 * SurveyorScheduleRecapExcelExporter. Semua yang bisa berselisih antar keduanya
 * (subtitle, jumlah baris, urutan nama) dihitung di sini, sekali.
 *
 * Sengaja TANPA cache. Stempel `analytics:last_updated` hanya digerakkan event
 * model Consultation & ReportAttendance (AppServiceProvider), bukan Survey â€”
 * ikut memakainya berarti rekap basi sampai 3 menit setiap kali surveyor
 * ditugaskan, tepat pada halaman yang gunanya menampilkan jadwal terkini.
 * Datanya cuma 7 hari dan sudah ter-index; bila kelak melambat, tambahkan
 * stempel `surveys:last_updated` yang digerakkan event model Survey.
 */
class SurveyorScheduleRecapService
{
    /**
     * Hanya survey yang punya jadwal dan belum dibatalkan.
     *
     * Daftar-izin, bukan `!= cancelled`: baris cancelled tetap menyimpan
     * scheduled_at-nya, dan daftar-izin membuat kelalaian itu mustahil sekaligus
     * tetap benar bila kelak ada state baru.
     */
    private const COUNTED_STATES = [
        Survey::STATE_SCHEDULED,
        Survey::STATE_IN_PROGRESS,
        Survey::STATE_COMPLETED,
    ];

    /** Tinggi grid minimum, mengikuti lembar manual yang ditiru. */
    private const MIN_ROWS = 10;

    public function __construct(private readonly ReportPeriodResolver $periodResolver)
    {
    }

    /** Grid Seninâ€“Minggu = 7 kolom. Kontrak ini dipegang exporter juga. */
    public const DAYS_IN_WEEK = 7;

    public function buildForUser(User $user, array $filters, array $options = []): array
    {
        // array_merge, bukan operator +: mingguan harus MENANG atas apapun yang
        // dikirim pemanggil. Dengan `+`, period_type=monthly milik pemanggil
        // tetap dipakai dan menghasilkan 31 slot hari â€” grid dan exporter
        // sama-sama mengasumsikan tepat 7.
        $period = $this->periodResolver->resolve(array_merge($filters, ['period_type' => 'weekly']));
        $accountGroup = AccountGroup::normalize($filters['account_group'] ?? null);

        $surveys = $this->fetchSurveys($period, $accountGroup, $filters);
        $days = $this->buildDays($period, $surveys);
        $summary = $this->buildSummary($surveys);

        return [
            'period' => [
                'type' => $period['type'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
                'label' => $period['label'],
                'anchorDate' => $period['anchor_date'],
            ],
            'subtitle' => $this->buildSubtitle($period, $accountGroup),
            'accountGroup' => $accountGroup,
            'rowCount' => max(self::MIN_ROWS, (int) $days->max('count')),
            'days' => $days->values()->all(),
            'summary' => $summary->values()->all(),
            'total' => $surveys->count(),
            'generatedAt' => now()->toIso8601String(),
        ];
    }

    private function fetchSurveys(array $period, ?string $accountGroup, array $filters): Collection
    {
        $accountId = $filters['account'] ?? null;
        $surveyorId = $filters['surveyor'] ?? null;

        return Survey::query()
            ->select(['surveys.id', 'surveys.surveyor_id', 'surveys.scheduled_at', 'surveys.account_id'])
            // join, bukan with(): satu baris per survey dengan nama sudah rata.
            // Inner join sekaligus menjamin surveyor_id tidak null.
            ->join('users as surveyors', 'surveyors.id', '=', 'surveys.surveyor_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'surveys.account_id')
            ->leftJoin('consultations', 'consultations.id', '=', 'surveys.consultation_id')
            ->addSelect([
                'surveyors.name as surveyor_name',
                'accounts.account_group as account_group',
                'consultations.consultation_id as consumer_id',
                'consultations.client_name as client_name',
                'consultations.city as city',
            ])
            ->whereIn('surveys.state', self::COUNTED_STATES)
            ->whereNotNull('surveys.scheduled_at')
            ->whereBetween('surveys.scheduled_at', [$period['start'], $period['end']])
            ->when($accountGroup, fn ($query) => $query->whereIn(
                'surveys.account_id',
                Account::query()->where('account_group', $accountGroup)->select('id')
            ))
            ->when($accountId, fn ($query) => $query->where('surveys.account_id', (int) $accountId))
            ->when($surveyorId, fn ($query) => $query->where('surveys.surveyor_id', (int) $surveyorId))
            // surveys.id terakhir: urutan deterministik, jadi grid dan Excel
            // tidak pernah menampilkan susunan berbeda untuk data yang sama.
            ->orderBy('surveys.scheduled_at')
            ->orderBy('surveyors.name')
            ->orderBy('surveys.id')
            ->get();
    }

    /**
     * Tepat 7 slot hari, walau kosong â€” gridnya selalu Seninâ€“Minggu.
     */
    private function buildDays(array $period, Collection $surveys): Collection
    {
        $byDate = $surveys->groupBy(fn (Survey $survey) => $survey->scheduled_at->format('Y-m-d'));

        return collect(CarbonPeriod::create($period['start']->copy()->startOfDay(), $period['end']))
            ->map(function ($date, int $index) use ($byDate) {
                $items = $byDate->get($date->format('Y-m-d'), collect())
                    ->map(fn (Survey $survey) => $this->scheduleItem($survey))
                    ->values()
                    ->all();
                $names = collect($items)
                    ->pluck('displayLabel')
                    ->all();

                return [
                    'date' => $date->toDateString(),
                    'dayName' => IndonesianDate::dayName($date),
                    'dateLabel' => $date->format('d/m/Y'),
                    // Boolean posisi, bukan perbandingan nama hari: pewarnaan
                    // kuning/oranye di Excel ikut posisi dalam minggu dan tetap
                    // benar tanpa bergantung locale.
                    'isFirstDay' => $index === 0,
                    'isLastDay' => $index === self::DAYS_IN_WEEK - 1,
                    'scheduleItems' => $items,
                    'surveyorNames' => $names,
                    'count' => count($names),
                ];
            });
    }

    private function buildSummary(Collection $surveys): Collection
    {
        return $surveys
            ->groupBy('surveyor_id')
            ->map(fn (Collection $rows) => [
                'surveyorId' => (int) $rows->first()->surveyor_id,
                'surveyorName' => $rows->first()->surveyor_name,
                'count' => $rows->count(),
            ])
            ->sortBy([
                fn (array $left, array $right) => $right['count'] <=> $left['count'],
                fn (array $left, array $right) => strcasecmp($left['surveyorName'], $right['surveyorName']),
            ]);
    }

    private function scheduleItem(Survey $survey): array
    {
        $surveyorName = $this->displayPart($survey->surveyor_name ?? null, 'SURVEYOR');
        $groupLabel = $this->displayPart(AccountGroup::label($survey->account_group ?? null) ?? $survey->account_group ?? null, 'GRUP');
        $consumerId = $this->displayPart($survey->consumer_id ?? null, '-');
        $clientName = $this->displayPart($survey->client_name ?? null, 'KONSUMEN');
        $city = $this->displayPart($survey->city ?? null, 'KOTA/KAB');
        $timeLabel = $survey->scheduled_at?->format('H:i') ?? '-';

        return [
            'surveyorName' => $surveyorName,
            'groupLabel' => $groupLabel,
            'consumerId' => $consumerId,
            'clientName' => $clientName,
            'city' => $city,
            'timeLabel' => $timeLabel,
            'displayLabel' => $this->scheduleLabel($surveyorName, $groupLabel, $consumerId, $clientName, $city, $timeLabel),
        ];
    }

    private function scheduleLabel(
        string $surveyorName,
        string $groupLabel,
        string $consumerId,
        string $clientName,
        string $city,
        string $timeLabel
    ): string
    {
        return sprintf(
            'Surveyor: %s | Grup: %s | ID Konsumen: %s | Konsumen: %s | Kota/Kab: %s | Jam: %s',
            $surveyorName,
            $groupLabel,
            $consumerId,
            $clientName,
            $city,
            $timeLabel
        );
    }

    private function displayPart(?string $value, string $fallback): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $clean !== '' ? $clean : $fallback;
    }

    /**
     * Contoh: "PUTRA CORPORATION - PERIODE JULI 2026".
     *
     * Disusun di sini, bukan di exporter dan frontend masing-masing, supaya
     * judul di layar dan di Excel tidak bisa melenceng satu sama lain.
     */
    private function buildSubtitle(array $period, ?string $accountGroup): string
    {
        return sprintf(
            '%s - PERIODE %s',
            AccountGroup::subtitleLabel($accountGroup),
            $this->periodMonthLabel($period)
        );
    }

    /**
     * Minggu Seninâ€“Minggu bisa melintasi batas bulan (mis. 29 Jun â€“ 5 Jul).
     * Menyebut satu bulan saja akan menyesatkan, jadi keduanya ditulis saat
     * berbeda: "JUNI - JULI 2026", atau "DESEMBER 2026 - JANUARI 2027".
     */
    private function periodMonthLabel(array $period): string
    {
        $start = $period['start'];
        $end = $period['end'];

        $startMonth = mb_strtoupper(IndonesianDate::monthName($start));
        $endMonth = mb_strtoupper(IndonesianDate::monthName($end));

        if ($startMonth === $endMonth && $start->year === $end->year) {
            return sprintf('%s %d', $startMonth, $start->year);
        }

        if ($start->year !== $end->year) {
            return sprintf('%s %d - %s %d', $startMonth, $start->year, $endMonth, $end->year);
        }

        return sprintf('%s - %s %d', $startMonth, $endMonth, $start->year);
    }
}
