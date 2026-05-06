<?php

namespace App\Services\Reports;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class LeadsReportService
{
    private const SURVEYOR_METRIC_KEYS = [
        'survey',
        'hold',
        'cancel',
        'deal_survey_current',
        'deal_survey_previous',
        'deal_omset_current',
        'deal_omset_next',
    ];

    private const DAY_NAMES = [
        'Sunday' => 'Minggu',
        'Monday' => 'Senin',
        'Tuesday' => 'Selasa',
        'Wednesday' => 'Rabu',
        'Thursday' => 'Kamis',
        'Friday' => 'Jumat',
        'Saturday' => 'Sabtu',
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

    public function buildForUser(User $user, array $filters): array
    {
        $period = $this->resolvePeriod($filters);
        $accounts = $this->accountsForUser($user, $filters);
        $detailRows = $this->buildDetailRows($user, $filters);
        $klasemenRows = $this->buildKlasemenRows($user, $filters, $period, $accounts);
        $surveyorRows = $this->buildSurveyorRows($user, $filters, $period);

        return [
            'period' => $period,
            'periodLabel' => $this->periodLabel($period['start'], $period['end']),
            'periodTitle' => $this->periodTitle($period['start'], $period['end']),
            'generatedAt' => now(),
            'generatedDateLabel' => $this->fullDate(now()),
            'selectedAccountName' => $this->selectedAccountName($user, $filters),
            'allowedAccounts' => $accounts,
            'klasemenRows' => $klasemenRows,
            'klasemenTotals' => $this->buildTotals($klasemenRows),
            'surveyorRows' => $surveyorRows,
            'surveyorTotals' => $this->buildSurveyorTotals($surveyorRows),
            'detailRows' => $detailRows,
            'analysisRows' => $this->buildAnalysisRows($detailRows),
            'analysisTotal' => $detailRows->count(),
        ];
    }

    private function accountsForUser(User $user, array $filters): Collection
    {
        $query = Account::query();

        if ($user->isAdmin()) {
            $query->whereKey($user->account_id);
        } elseif (! empty($filters['account'])) {
            $query->whereKey((int) $filters['account']);
        }

        return $query->orderBy('id')->get(['id', 'name']);
    }

    private function selectedAccountName(User $user, array $filters): string
    {
        if ($user->isAdmin()) {
            return $user->account?->name ?? 'Akun Saya';
        }

        if (! empty($filters['account'])) {
            return Account::query()->whereKey((int) $filters['account'])->value('name') ?? 'Akun Dipilih';
        }

        return 'Semua Akun';
    }

    private function buildDetailRows(User $user, array $filters): Collection
    {
        return $this->baseLeadQuery($user, $filters, includeDateFilter: true)
            ->withProductRelations()
            ->orderByDesc('consultation_date')
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Consultation $lead) => [
                'consultation_id' => $lead->consultation_id,
                'client_name' => $lead->client_name,
                'phone' => $lead->phone,
                'domicile' => filled($lead->city) ? ($this->isInsideCityLead($lead) ? 'Dalam Kota' : 'Luar Kota') : '',
                'province' => $lead->province,
                'city' => $lead->city,
                'account' => $lead->account?->name ?? '-',
                'need' => $lead->product_names_label ?: '-',
                'product_details' => $lead->product_details,
                'status' => $lead->statusCategory?->name ?? '-',
                'notes' => $lead->notes,
                'consultation_date' => $lead->consultation_date?->format('d/m/Y') ?? '',
                'consultation_date_excel' => $lead->consultation_date?->format('Y-m-d\T00:00:00.000') ?? null,
                'creator' => $lead->creator?->name ?? '-',
                'updated_at' => $lead->updated_at?->format('d/m/Y H:i') ?? '',
                'updated_at_excel' => $lead->updated_at?->format('Y-m-d\TH:i:s.000') ?? null,
            ]);
    }

    private function buildKlasemenRows(User $user, array $filters, array $period, Collection $accounts): Collection
    {
        $periodLeads = $this->baseLeadQuery($user, $filters, includeDateFilter: false)
            ->with(['account:id,name', 'statusCategory:id,name'])
            ->whereDate('consultation_date', '>=', $period['start']->toDateString())
            ->whereDate('consultation_date', '<=', $period['end']->toDateString())
            ->get();

        $previousDeals = $this->baseLeadQuery($user, $filters, includeDateFilter: false)
            ->with(['statusCategory:id,name'])
            ->whereDate('consultation_date', '<', $period['start']->toDateString())
            ->get()
            ->filter(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name) === 'deal')
            ->groupBy('account_id')
            ->map->count();

        $nextDeals = $this->baseLeadQuery($user, $filters, includeDateFilter: false)
            ->with(['statusCategory:id,name'])
            ->whereDate('consultation_date', '>', $period['end']->toDateString())
            ->get()
            ->filter(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name) === 'deal')
            ->groupBy('account_id')
            ->map->count();

        $periodByAccount = $periodLeads->groupBy('account_id');

        $rows = $accounts->map(function (Account $account) use ($periodByAccount, $previousDeals, $nextDeals) {
            $items = $periodByAccount->get($account->id, collect());
            $statusCounts = $items
                ->groupBy(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name))
                ->map->count();
            $dealCurrent = (int) ($statusCounts->get('deal', 0));

            return [
                'account_id' => $account->id,
                'account_name' => $account->name,
                'interaction' => $items->count(),
                'remaining_consultation' => (int) ($statusCounts->get('remaining', 0)),
                'survey' => (int) ($statusCounts->get('survey', 0)),
                'hold' => (int) ($statusCounts->get('hold', 0)),
                'cancel' => (int) ($statusCounts->get('cancel', 0)),
                'deal_survey_current' => $dealCurrent,
                'deal_survey_previous' => (int) ($previousDeals->get($account->id, 0)),
                'project_deal_current' => $dealCurrent,
                'project_deal_next' => (int) ($nextDeals->get($account->id, 0)),
            ];
        });

        $minimumRows = max(35, $rows->count());

        while ($rows->count() < $minimumRows) {
            $rows->push([
                'account_id' => null,
                'account_name' => '',
                'interaction' => 0,
                'remaining_consultation' => 0,
                'survey' => 0,
                'hold' => 0,
                'cancel' => 0,
                'deal_survey_current' => 0,
                'deal_survey_previous' => 0,
                'project_deal_current' => 0,
                'project_deal_next' => 0,
            ]);
        }

        return $rows->values();
    }

    private function buildSurveyorRows(User $user, array $filters, array $period): Collection
    {
        $periodLeads = $this->baseLeadQuery($user, $filters, includeDateFilter: false)
            ->with(['creator:id,name,account_id,role', 'statusCategory:id,name'])
            ->whereDate('consultation_date', '>=', $period['start']->toDateString())
            ->whereDate('consultation_date', '<=', $period['end']->toDateString())
            ->get();

        $previousDeals = $this->baseLeadQuery($user, $filters, includeDateFilter: false)
            ->with(['creator:id,name,account_id,role', 'statusCategory:id,name'])
            ->whereDate('consultation_date', '<', $period['start']->toDateString())
            ->get()
            ->filter(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name) === 'deal');

        $nextDeals = $this->baseLeadQuery($user, $filters, includeDateFilter: false)
            ->with(['creator:id,name,account_id,role', 'statusCategory:id,name'])
            ->whereDate('consultation_date', '>', $period['end']->toDateString())
            ->get()
            ->filter(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name) === 'deal');

        $periodByCreator = $periodLeads->groupBy('created_by');
        $previousDealsByCreator = $previousDeals->groupBy('created_by');
        $nextDealsByCreator = $nextDeals->groupBy('created_by');
        $surveyors = $this->surveyorsForUser($user, $filters, collect([
            ...$periodByCreator->keys(),
            ...$previousDealsByCreator->keys(),
            ...$nextDealsByCreator->keys(),
        ])->filter()->map(fn ($id) => (int) $id));

        $rows = $surveyors->map(function (User $surveyor) use ($periodByCreator, $previousDealsByCreator, $nextDealsByCreator) {
            $items = $periodByCreator->get($surveyor->id, collect());
            $statusGroups = $items->groupBy(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name));
            $currentDeals = $statusGroups->get('deal', collect());

            return [
                'surveyor_id' => $surveyor->id,
                'surveyor_name' => $surveyor->name,
                'survey' => $this->locationTotals($statusGroups->get('survey', collect())),
                'hold' => $this->locationTotals($statusGroups->get('hold', collect())),
                'cancel' => $this->locationTotals($statusGroups->get('cancel', collect())),
                'deal_survey_current' => $this->locationTotals($currentDeals),
                'deal_survey_previous' => $this->locationTotals($previousDealsByCreator->get($surveyor->id, collect())),
                'deal_omset_current' => $this->locationTotals($currentDeals),
                'deal_omset_next' => $this->locationTotals($nextDealsByCreator->get($surveyor->id, collect())),
            ];
        });

        if ($periodByCreator->has('')) {
            $rows->push(array_merge($this->emptySurveyorRow('TANPA SURVEYOR'), [
                'survey' => $this->locationTotals($periodByCreator->get('', collect())->filter(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name) === 'survey')),
                'hold' => $this->locationTotals($periodByCreator->get('', collect())->filter(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name) === 'hold')),
                'cancel' => $this->locationTotals($periodByCreator->get('', collect())->filter(fn (Consultation $lead) => $this->statusBucket($lead->statusCategory?->name) === 'cancel')),
            ]));
        }

        $rows = $rows->sortBy('surveyor_name')->values();
        $minimumRows = max(150, $rows->count());

        while ($rows->count() < $minimumRows) {
            $rows->push($this->emptySurveyorRow(''));
        }

        return $rows->values();
    }

    private function surveyorsForUser(User $user, array $filters, Collection $creatorIds): Collection
    {
        $query = User::query()->select(['id', 'name', 'account_id', 'role']);

        if ($user->isAdmin()) {
            $query->whereKey($user->id);
        } else {
            $query->where('role', UserRole::Admin->value);

            if (! empty($filters['account'])) {
                $query->where('account_id', (int) $filters['account']);
            }
        }

        if ($creatorIds->isNotEmpty()) {
            $query->orWhereIn('id', $creatorIds->unique()->values()->all());
        }

        return $query->orderBy('name')->get();
    }

    private function emptySurveyorRow(string $name): array
    {
        return [
            'surveyor_id' => null,
            'surveyor_name' => $name,
            'survey' => $this->emptyLocationTotals(),
            'hold' => $this->emptyLocationTotals(),
            'cancel' => $this->emptyLocationTotals(),
            'deal_survey_current' => $this->emptyLocationTotals(),
            'deal_survey_previous' => $this->emptyLocationTotals(),
            'deal_omset_current' => $this->emptyLocationTotals(),
            'deal_omset_next' => $this->emptyLocationTotals(),
        ];
    }

    private function locationTotals(Collection $items): array
    {
        $dk = $items->filter(fn (Consultation $lead) => $this->isInsideCityLead($lead))->count();
        $total = $items->count();

        return [
            'total' => $total,
            'dk' => $dk,
            'lk' => max($total - $dk, 0),
        ];
    }

    private function emptyLocationTotals(): array
    {
        return ['total' => 0, 'dk' => 0, 'lk' => 0];
    }

    private function buildSurveyorTotals(Collection $rows): array
    {
        return collect(self::SURVEYOR_METRIC_KEYS)
            ->mapWithKeys(function (string $metricKey) use ($rows) {
                return [
                    $metricKey => [
                        'total' => (int) $rows->sum(fn (array $row) => (int) ($row[$metricKey]['total'] ?? 0)),
                        'dk' => (int) $rows->sum(fn (array $row) => (int) ($row[$metricKey]['dk'] ?? 0)),
                        'lk' => (int) $rows->sum(fn (array $row) => (int) ($row[$metricKey]['lk'] ?? 0)),
                    ],
                ];
            })
            ->all();
    }

    private function buildTotals(Collection $rows): array
    {
        $columns = [
            'interaction',
            'remaining_consultation',
            'survey',
            'hold',
            'cancel',
            'deal_survey_current',
            'deal_survey_previous',
            'project_deal_current',
            'project_deal_next',
        ];

        return collect($columns)
            ->mapWithKeys(fn (string $column) => [$column => (int) $rows->sum($column)])
            ->all();
    }

    private function buildAnalysisRows(Collection $detailRows): Collection
    {
        $definitions = collect([
            [
                'category' => 'Hanya Tanya Tanya',
                'detail' => 'Menanyakan harga per meter atau estimasi total biaya',
                'aliases' => ['Hanya Tanya Tanya'],
            ],
            [
                'category' => 'Penjadwalan',
                'detail' => 'Menunggu jadwal survey atau janji temu lapangan',
                'aliases' => ['Penjadwalan'],
            ],
            [
                'category' => 'Perbandingan Harga',
                'detail' => 'Sedang membandingkan harga dengan vendor lain',
                'aliases' => ['Perbandingan Harga'],
            ],
            [
                'category' => 'Kendala Anggaran',
                'detail' => 'Anggaran belum sesuai (Terlalu mahal/Over Budget)',
                'aliases' => ['Kendala Anggaran'],
            ],
            [
                'category' => 'Menunggu Bangunan',
                'detail' => 'Menunggu progres renovasi atau serah terima kunci',
                'aliases' => ['Menunggu Bangunan'],
            ],
            [
                'category' => 'Tidak Ada Respon',
                'detail' => 'Tidak membalas pesan atau menunda komunikasi',
                'aliases' => ['Tidak Ada Respon'],
            ],
            [
                'category' => 'Masuk Survey',
                'detail' => 'Report Survey',
                'aliases' => ['Masuk Survey', 'Request Survey'],
            ],
        ]);

        $statusCounts = $detailRows
            ->groupBy(fn (array $row) => $this->normalizeAnalysisCategory($row['status'] ?? ''))
            ->map->count();
        $total = max($detailRows->count(), 0);

        return $definitions->map(function (array $definition) use ($statusCounts, $total) {
            $count = collect($definition['aliases'])
                ->sum(fn (string $alias) => (int) ($statusCounts[$this->normalizeAnalysisCategory($alias)] ?? 0));

            return [
                'category' => $definition['category'],
                'detail' => $definition['detail'],
                'count' => $count,
                'percentage' => $total > 0 ? $count / $total : 0,
            ];
        });
    }

    private function normalizeAnalysisCategory(?string $value): string
    {
        return str((string) $value)
            ->lower()
            ->replace(['/', '-', '_'], ' ')
            ->squish()
            ->toString();
    }

    private function baseLeadQuery(User $user, array $filters, bool $includeDateFilter): Builder
    {
        $query = Consultation::query()->forUser($user);

        if ($user->isSuperAdmin() && ! empty($filters['account'])) {
            $query->where('account_id', (int) $filters['account']);
        }

        if (! empty($filters['status'])) {
            $query->where('status_category_id', (int) $filters['status']);
        }

        if ($includeDateFilter) {
            $hasDateRange = ! empty($filters['start_date']) || ! empty($filters['end_date']);

            if (! empty($filters['start_date'])) {
                $query->whereDate('consultation_date', '>=', $filters['start_date']);
            }

            if (! empty($filters['end_date'])) {
                $query->whereDate('consultation_date', '<=', $filters['end_date']);
            }

            if (! $hasDateRange && ! empty($filters['month'])) {
                $query->whereMonth('consultation_date', (int) $filters['month']);
                $query->whereYear('consultation_date', (int) ($filters['year'] ?? now()->year));
            }

            if (! $hasDateRange && empty($filters['month']) && ! empty($filters['year'])) {
                $query->whereYear('consultation_date', (int) $filters['year']);
            }
        }

        if (! empty($filters['search'])) {
            $this->applySearch($query, trim((string) $filters['search']));
        }

        return $query;
    }

    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $phoneSearchSql = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), ' ', ''), '-', ''), '+', ''), '(', ''), ')', '')";
        $phoneSearchTokens = collect([
            Consultation::normalizeLeadPhone($search),
            ltrim(preg_replace('/^(?:62|0)/', '', Consultation::normalizeLeadPhone($search)) ?? '', '0'),
        ])->filter()->unique()->values();

        $query->where(function (Builder $innerQuery) use ($search, $phoneSearchSql, $phoneSearchTokens) {
            $innerQuery->where('client_name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('consultation_id', 'like', "%{$search}%");

            foreach ($phoneSearchTokens as $token) {
                $innerQuery->orWhereRaw("{$phoneSearchSql} like ?", ["%{$token}%"]);
            }
        });
    }

    private function resolvePeriod(array $filters): array
    {
        if (! empty($filters['start_date']) || ! empty($filters['end_date'])) {
            $start = ! empty($filters['start_date'])
                ? Carbon::parse($filters['start_date'])->startOfDay()
                : Carbon::parse($filters['end_date'])->startOfMonth();
            $end = ! empty($filters['end_date'])
                ? Carbon::parse($filters['end_date'])->endOfDay()
                : $start->copy()->endOfMonth();

            return compact('start', 'end');
        }

        $month = (int) ($filters['month'] ?? now()->month);
        $year = (int) ($filters['year'] ?? now()->year);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        return compact('start', 'end');
    }

    private function statusBucket(?string $statusName): string
    {
        $normalized = str((string) $statusName)->lower()->replace(['/', '-', '_'], ' ')->squish()->toString();

        if (str_contains($normalized, 'deal') || str_contains($normalized, 'selesai')) {
            return 'deal';
        }

        if (str_contains($normalized, 'survey')) {
            return 'survey';
        }

        if (str_contains($normalized, 'kendala') || str_contains($normalized, 'hold')) {
            return 'hold';
        }

        if (str_contains($normalized, 'tidak ada respon') || str_contains($normalized, 'cancel') || str_contains($normalized, 'batal')) {
            return 'cancel';
        }

        return 'remaining';
    }

    private function isInsideCityLead(Consultation $lead): bool
    {
        $city = $this->normalizeLocation($lead->city);

        foreach (['bandung barat', 'kbb', 'cimahi', 'kab bandung', 'kabupaten bandung', 'bandung'] as $alias) {
            if (str_contains($city, $alias)) {
                return true;
            }
        }

        return false;
    }

    private function normalizeLocation(?string $value): string
    {
        return str((string) $value)
            ->lower()
            ->replace(['kota ', 'kab. ', 'kabupaten '], ['', 'kabupaten ', 'kabupaten '])
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->toString();
    }

    private function periodLabel(Carbon $start, Carbon $end): string
    {
        if ($start->isSameDay($end)) {
            return $this->fullDate($start);
        }

        if ($start->isSameMonth($end)) {
            if ($start->day === 1 && $end->isSameDay($end->copy()->endOfMonth())) {
                return sprintf('%s %s', $this->monthName($start), $start->year);
            }

            return sprintf('%s %s-%s %s', $this->monthName($start), $start->format('d'), $end->format('d'), $start->year);
        }

        return $this->fullDate($start) . ' - ' . $this->fullDate($end);
    }

    private function periodTitle(Carbon $start, Carbon $end): string
    {
        if ($start->isSameMonth($end)) {
            return strtoupper(sprintf('%s %s', $this->monthName($start), $start->year));
        }

        return strtoupper($this->periodLabel($start, $end));
    }

    public function fullDate(Carbon $date): string
    {
        return sprintf(
            '%s, %s %s %s',
            self::DAY_NAMES[$date->format('l')] ?? $date->format('l'),
            $date->format('d'),
            $this->monthName($date),
            $date->format('Y')
        );
    }

    public function monthName(Carbon $date): string
    {
        return self::MONTH_NAMES[(int) $date->format('n')] ?? $date->format('F');
    }
}
