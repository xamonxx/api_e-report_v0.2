<?php

namespace App\Services\Reports;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Models\StatusCategory;
use App\Models\User;
use App\Support\PendingConfirmation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class AnalyticsReportService
{
    public function __construct(
        private readonly ReportPeriodResolver $periodResolver,
    ) {
    }

    public function buildForUser(User $user, array $filters, array $options = []): array
    {
        $period = $this->periodResolver->resolve($filters);
        $selectedAccount = $user->isSuperAdmin() ? ($filters['account'] ?? null) : $user->account_id;
        $includeRawRows = (bool) ($options['includeRawRows'] ?? false);

        $query = $this->baseQuery($user, $selectedAccount, $period['start'], $period['end']);

        $totalLeads = (clone $query)->count();
        $statusDistribution = $this->buildStatusDistribution($query);
        $needsDistribution = $this->buildNeedsDistribution($query);
        $provinceDistribution = $user->isSuperAdmin() ? $this->buildLocationDistribution($query, 'province') : collect();
        $cityDistribution = $user->isSuperAdmin() ? $this->buildLocationDistribution($query, 'city') : collect();
        $westJavaSegmentDistribution = $user->isSuperAdmin() ? $this->buildWestJavaSegmentDistribution($query) : collect();
        $accountRanking = $user->isSuperAdmin() ? $this->buildAccountRanking($period, $selectedAccount) : collect();
        $adminRanking = $user->isSuperAdmin() ? $this->buildAdminRanking($period, $selectedAccount) : collect();

        $totalSurveys = $this->countByStatusName($statusDistribution, $this->surveyStatusName());
        $totalDeals = $this->countByStatusName($statusDistribution, $this->dealStatusName());
        $conversionRate = $totalLeads > 0 ? round(($totalSurveys / $totalLeads) * 100, 1) : 0;
        $dealRate = $totalLeads > 0 ? round(($totalDeals / $totalLeads) * 100, 1) : 0;
        $growthPercent = $this->buildGrowthPercent($user, $selectedAccount, $period);
        $rawRows = $includeRawRows ? $this->buildRawRows($query) : collect();
        $trendSeries = $this->buildTrendSeries($query, $period);
        $dataQuality = $this->buildDataQuality($query, $period, $totalLeads);
        $pendingConfirmationStats = $this->buildPendingConfirmationStats($query, $totalLeads);
        $funnel = $this->buildFunnel($totalLeads, $totalSurveys, $totalDeals);
        $topPerformers = $this->buildTopPerformers(
            $statusDistribution,
            $needsDistribution,
            $provinceDistribution,
            $accountRanking,
            $adminRanking
        );
        $summaryStats = $this->buildSummaryStats($period, $totalLeads, $dataQuality, $trendSeries);

        return [
            'period' => $period,
            'periodLabel' => $period['label'],
            'comparisonLabel' => $period['comparison_label'],
            'selectedAccount' => $user->isSuperAdmin() ? $selectedAccount : null,
            'selectedPeriodType' => $period['type'],
            'selectedWeekDate' => $period['anchor_date'],
            'selectedMonth' => $period['month'] ?? now()->month,
            'selectedYear' => $period['year'],
            'selectedAccountName' => $this->resolveAccountName($user, $selectedAccount),
            'totalLeads' => $totalLeads,
            'totalSurveys' => $totalSurveys,
            'totalDeals' => $totalDeals,
            'conversionRate' => $conversionRate,
            'dealRate' => $dealRate,
            'growthPercent' => $growthPercent,
            'statusDistribution' => $statusDistribution,
            'needsDistribution' => $needsDistribution,
            'provinceDistribution' => $provinceDistribution,
            'cityDistribution' => $cityDistribution,
            'westJavaSegmentDistribution' => $westJavaSegmentDistribution,
            'accountRanking' => $accountRanking,
            'adminRanking' => $adminRanking,
            'trendSeries' => $trendSeries,
            'dataQuality' => $dataQuality,
            'pendingConfirmationStats' => $pendingConfirmationStats,
            'funnel' => $funnel,
            'topPerformers' => $topPerformers,
            'summaryStats' => $summaryStats,
            'insights' => $this->buildInsights(
                $statusDistribution,
                $needsDistribution,
                $provinceDistribution,
                $accountRanking,
                $adminRanking,
                $growthPercent,
                $dataQuality,
                $trendSeries,
                $funnel
            ),
            'rawRows' => $rawRows,
        ];
    }

    private function baseQuery(User $user, ?int $selectedAccount, Carbon $start, Carbon $end): Builder
    {
        $query = Consultation::query()->forUser($user);

        if ($user->isSuperAdmin() && $selectedAccount) {
            $query->where('account_id', $selectedAccount);
        }

        return $query->whereBetween('consultation_date', [$start->toDateString(), $end->toDateString()]);
    }

    private function buildStatusDistribution(Builder $query): Collection
    {
        $counts = (clone $query)
            ->selectRaw('status_category_id, count(*) as count')
            ->groupBy('status_category_id')
            ->pluck('count', 'status_category_id');

        return StatusCategory::orderBy('sort_order')->get()->map(fn ($status) => [
            'name' => $status->name,
            'color' => $status->color,
            'count' => $counts[$status->id] ?? 0,
        ]);
    }

    private function buildNeedsDistribution(Builder $query): Collection
    {
        if (Consultation::hasNeedsCategoryPivot()) {
            $counts = (clone $query)
                ->join('consultation_needs_category as cnc', 'consultations.id', '=', 'cnc.consultation_id')
                ->selectRaw('cnc.needs_category_id, count(*) as count')
                ->groupBy('cnc.needs_category_id')
                ->pluck('count', 'cnc.needs_category_id');
        } else {
            $counts = (clone $query)
                ->whereNotNull('needs_category_id')
                ->selectRaw('needs_category_id, count(*) as count')
                ->groupBy('needs_category_id')
                ->pluck('count', 'needs_category_id');
        }

        return NeedsCategory::all()
            ->map(fn ($need) => [
                'name' => $need->name,
                'count' => $counts[$need->id] ?? 0,
            ])
            ->filter(fn ($item) => $item['count'] > 0)
            ->sortByDesc('count')
            ->values();
    }

    private function buildLocationDistribution(Builder $query, string $column, int $limit = 10): Collection
    {
        $items = (clone $query)
            ->whereNotNull($column)
            ->pluck($column);

        $distribution = $items->reduce(function (array $carry, $value) {
            $label = $this->cleanLocationLabel($value);

            if ($label === null || $this->isPendingConfirmationValue($label)) {
                return $carry;
            }

            $key = $this->normalizeLocation($label);

            if (! isset($carry[$key])) {
                $carry[$key] = [
                    'name' => $label,
                    'count' => 0,
                ];
            }

            $carry[$key]['count']++;

            return $carry;
        }, []);

        $total = array_sum(array_column($distribution, 'count')) ?: 1;

        return collect($distribution)
            ->sortByDesc('count')
            ->take($limit)
            ->values()
            ->map(fn (array $item) => [
                'name' => $item['name'],
                'count' => $item['count'],
                'percentage' => round(($item['count'] / $total) * 100, 1),
            ]);
    }

    private function buildWestJavaSegmentDistribution(Builder $query): Collection
    {
        $segments = collect($this->westJavaSegments())->map(
            fn (array $config, string $name) => [
                'name' => $name,
                'count' => 0,
                'color' => $config['color'],
            ]
        );

        $rows = (clone $query)->get(['province', 'city']);

        foreach ($rows as $row) {
            $province = $this->normalizeLocation($row->province);
            $city = $this->normalizeLocation($row->city);

            if (! $this->isWestJavaLead($province, $city)) {
                continue;
            }

            $segmentName = $this->resolveWestJavaSegment($city) ?? 'Lainnya Jawa Barat';
            $segment = $segments->get($segmentName);
            $segment['count']++;
            $segments->put($segmentName, $segment);
        }

        return $segments->values();
    }

    private function buildAccountRanking(array $period, ?int $selectedAccount = null): Collection
    {
        $surveyStatusId = $this->resolveStatusId($this->surveyStatusAliases());
        $dealStatusId = $this->resolveStatusId($this->dealStatusAliases());

        $query = Account::query();

        if ($selectedAccount) {
            $query->whereKey($selectedAccount);
        }

        $query->withCount([
            'consultations as total_leads' => function ($builder) use ($period) {
                $builder->whereBetween('consultation_date', [
                    $period['start']->toDateString(),
                    $period['end']->toDateString(),
                ]);
            },
        ]);

        if ($surveyStatusId) {
            $query->withCount([
                'consultations as surveys_count' => function ($builder) use ($period, $surveyStatusId) {
                    $builder->whereBetween('consultation_date', [
                        $period['start']->toDateString(),
                        $period['end']->toDateString(),
                    ])->where('status_category_id', $surveyStatusId);
                },
            ]);
        }

        if ($dealStatusId) {
            $query->withCount([
                'consultations as deals_count' => function ($builder) use ($period, $dealStatusId) {
                    $builder->whereBetween('consultation_date', [
                        $period['start']->toDateString(),
                        $period['end']->toDateString(),
                    ])->where('status_category_id', $dealStatusId);
                },
            ]);
        }

        return $query->get()->map(function ($account) {
            $total = $account->total_leads ?? 0;
            $surveys = $account->surveys_count ?? 0;
            $deals = $account->deals_count ?? 0;
            $rate = $total > 0 ? round(($surveys / $total) * 100, 1) : 0;
            $dealRate = $total > 0 ? round(($deals / $total) * 100, 1) : 0;
            $score = round(($rate * 0.7) + ($dealRate * 0.3), 1);

            return [
                'name' => $account->name,
                'total' => $total,
                'surveys' => $surveys,
                'deals' => $deals,
                'rate' => $rate,
                'deal_rate' => $dealRate,
                'score' => $score,
            ];
        })->sortByDesc('score')->values();
    }

    private function buildAdminRanking(array $period, ?int $selectedAccount = null): Collection
    {
        $query = User::where('role', UserRole::Admin)
            ->with('account')
            ->withCount([
                'consultations' => function ($builder) use ($period) {
                    $builder->whereBetween('consultation_date', [
                        $period['start']->toDateString(),
                        $period['end']->toDateString(),
                    ]);
                },
            ]);

        if ($selectedAccount) {
            $query->where('account_id', $selectedAccount);
        }

        return $query->get()
            ->map(fn ($admin) => [
                'name' => $admin->name,
                'account' => $admin->account?->name ?? '-',
                'total' => $admin->consultations_count,
            ])
            ->sortByDesc('total')
            ->values();
    }

    private function buildRawRows(Builder $query): Collection
    {
        return (clone $query)
            ->with(array_merge(['account', 'statusCategory', 'creator'], Consultation::productRelations()))
            ->orderBy('consultation_date', 'desc')
            ->get()
            ->map(function ($consultation) {
                $consultationDate = $consultation->consultation_date ? Carbon::parse($consultation->consultation_date) : null;
                $updatedAt = $consultation->updated_at ? Carbon::parse($consultation->updated_at) : null;

                return [
                    'consultation_id' => $consultation->consultation_id,
                    'client_name' => $consultation->client_name,
                    'phone' => $consultation->phone,
                    'province' => $consultation->province,
                    'city' => $consultation->city,
                    'account' => $consultation->account?->name,
                    'need' => $consultation->product_names_label,
                    'product_details' => $consultation->product_details,
                    'status' => $consultation->statusCategory?->name,
                    'notes' => $consultation->notes,
                    'consultation_date' => $consultationDate?->format('d/m/Y'),
                    'consultation_date_excel' => $consultationDate?->format('Y-m-d') . 'T00:00:00',
                    'consultation_date_key' => $consultationDate?->toDateString(),
                    'creator' => $consultation->creator?->name,
                    'updated_at' => $updatedAt?->format('d/m/Y H:i'),
                    'updated_at_excel' => $updatedAt?->format('Y-m-d\TH:i:s'),
                ];
            });
    }

    private function buildTrendSeries(Builder $query, array $period): Collection
    {
        $surveyStatusId = $this->resolveStatusId($this->surveyStatusAliases());
        $dealStatusId = $this->resolveStatusId($this->dealStatusAliases());
        $isYearly = $period['type'] === 'yearly';

        $buckets = collect();
        $cursor = $period['start']->copy();

        while ($cursor <= $period['end']) {
            $key = $isYearly ? $cursor->format('Y-m') : $cursor->toDateString();
            $buckets->put($key, [
                'key' => $key,
                'label' => $isYearly ? $cursor->translatedFormat('M Y') : $cursor->translatedFormat('d M'),
                'full_label' => $isYearly ? $cursor->translatedFormat('F Y') : $cursor->translatedFormat('d F Y'),
                'total' => 0,
                'surveys' => 0,
                'deals' => 0,
            ]);

            $cursor = $isYearly ? $cursor->addMonth() : $cursor->addDay();
        }

        $bucketExpression = $isYearly
            ? "DATE_FORMAT(consultation_date, '%Y-%m')"
            : 'DATE(consultation_date)';

        $aggregateQuery = (clone $query)
            ->whereNotNull('consultation_date')
            ->selectRaw("{$bucketExpression} as bucket_key")
            ->selectRaw('COUNT(*) as total');

        if ($surveyStatusId) {
            $aggregateQuery->selectRaw(
                'SUM(CASE WHEN status_category_id = ? THEN 1 ELSE 0 END) as surveys',
                [(int) $surveyStatusId]
            );
        } else {
            $aggregateQuery->selectRaw('0 as surveys');
        }

        if ($dealStatusId) {
            $aggregateQuery->selectRaw(
                'SUM(CASE WHEN status_category_id = ? THEN 1 ELSE 0 END) as deals',
                [(int) $dealStatusId]
            );
        } else {
            $aggregateQuery->selectRaw('0 as deals');
        }

        $rows = $aggregateQuery
            ->groupBy('bucket_key')
            ->get();

        foreach ($rows as $row) {
            if (! $row->bucket_key) {
                continue;
            }

            $key = (string) $row->bucket_key;
            $bucket = $buckets->get($key);

            if (! $bucket) {
                continue;
            }

            $bucket['total'] = (int) $row->total;
            $bucket['surveys'] = (int) $row->surveys;
            $bucket['deals'] = (int) $row->deals;

            $buckets->put($key, $bucket);
        }

        return $buckets->values()->map(function (array $bucket) {
            $bucket['survey_rate'] = $bucket['total'] > 0 ? round(($bucket['surveys'] / $bucket['total']) * 100, 1) : 0;
            $bucket['deal_rate'] = $bucket['total'] > 0 ? round(($bucket['deals'] / $bucket['total']) * 100, 1) : 0;

            return $bucket;
        });
    }

    private function buildDataQuality(Builder $query, array $period, int $totalLeads): array
    {
        $rows = (clone $query)->get(['province', 'city', 'notes', 'phone', 'created_by', 'consultation_date', 'updated_at']);

        $provinceFilled = $rows->filter(fn ($row) => $this->isConfirmedLocationValue($row->province))->count();
        $cityFilled = $rows->filter(fn ($row) => $this->isConfirmedLocationValue($row->city))->count();
        $notesFilled = $rows->filter(fn ($row) => filled(trim((string) $row->notes)))->count();
        $locationComplete = $rows->filter(
            fn ($row) => $this->isConfirmedLocationValue($row->province) && $this->isConfirmedLocationValue($row->city)
        )->count();
        $uniqueProvinces = $rows->pluck('province')
            ->map(fn ($value) => $this->cleanLocationLabel($value))
            ->filter(fn ($value) => $value !== null && ! $this->isPendingConfirmationValue($value))
            ->unique(fn ($value) => $this->normalizeLocation($value))
            ->count();
        $uniqueCities = $rows->pluck('city')
            ->map(fn ($value) => $this->cleanLocationLabel($value))
            ->filter(fn ($value) => $value !== null && ! $this->isPendingConfirmationValue($value))
            ->unique(fn ($value) => $this->normalizeLocation($value))
            ->count();
        $activeAdmins = $rows->pluck('created_by')->filter()->unique()->count();
        $activeDays = $rows->pluck('consultation_date')->filter()->map(fn ($value) => Carbon::parse($value)->toDateString())->unique()->count();
        $duplicatePhones = $rows->pluck('phone')
            ->filter()
            ->map(fn ($value) => preg_replace('/\D+/', '', (string) $value))
            ->filter()
            ->countBy()
            ->filter(fn ($count) => $count > 1)
            ->sum();

        $latestUpdate = $rows->pluck('updated_at')->filter()->max();

        return [
            'province_completion_rate' => $this->toRate($provinceFilled, $totalLeads),
            'city_completion_rate' => $this->toRate($cityFilled, $totalLeads),
            'notes_completion_rate' => $this->toRate($notesFilled, $totalLeads),
            'location_completion_rate' => $this->toRate($locationComplete, $totalLeads),
            'province_filled' => $provinceFilled,
            'city_filled' => $cityFilled,
            'notes_filled' => $notesFilled,
            'location_complete' => $locationComplete,
            'unique_provinces' => $uniqueProvinces,
            'unique_cities' => $uniqueCities,
            'active_admins' => $activeAdmins,
            'active_days' => $activeDays,
            'duplicate_phone_rows' => $duplicatePhones,
            'latest_update' => $latestUpdate ? Carbon::parse($latestUpdate)->format('d/m/Y H:i') : '-',
            'period_days' => $period['start']->diffInDays($period['end']) + 1,
        ];
    }

    private function buildPendingConfirmationStats(Builder $query, int $totalLeads): array
    {
        $pendingConfirmationCategoryId = NeedsCategory::query()
            ->where('name', PendingConfirmation::LABEL)
            ->value('id');

        $provinceCount = (clone $query)
            ->where(fn (Builder $builder) => $this->applyPendingConfirmationConstraint($builder, 'province'))
            ->count();

        $cityCount = (clone $query)
            ->where(fn (Builder $builder) => $this->applyPendingConfirmationConstraint($builder, 'city'))
            ->count();

        $districtCount = (clone $query)
            ->where(fn (Builder $builder) => $this->applyPendingConfirmationConstraint($builder, 'district'))
            ->count();

        $productCount = 0;

        if ($pendingConfirmationCategoryId) {
            if (Consultation::hasNeedsCategoryPivot()) {
                $productCount = (clone $query)
                    ->where(function (Builder $builder) use ($pendingConfirmationCategoryId) {
                        $builder->where('needs_category_id', $pendingConfirmationCategoryId)
                            ->orWhereHas(
                                'needsCategories',
                                fn (Builder $relationQuery) => $relationQuery->where('needs_categories.id', $pendingConfirmationCategoryId)
                            );
                    })
                    ->distinct()
                    ->count('consultations.id');
            } else {
                $productCount = (clone $query)
                    ->where('needs_category_id', $pendingConfirmationCategoryId)
                    ->count();
            }
        }

        return [
            'province' => [
                'count' => $provinceCount,
                'percentage' => $this->toRate($provinceCount, $totalLeads),
                'label' => PendingConfirmation::LABEL,
            ],
            'city' => [
                'count' => $cityCount,
                'percentage' => $this->toRate($cityCount, $totalLeads),
                'label' => PendingConfirmation::LABEL,
            ],
            'district' => [
                'count' => $districtCount,
                'percentage' => $this->toRate($districtCount, $totalLeads),
                'label' => PendingConfirmation::LABEL,
            ],
            'product' => [
                'count' => $productCount,
                'percentage' => $this->toRate($productCount, $totalLeads),
                'label' => PendingConfirmation::LABEL,
            ],
        ];
    }

    private function buildSummaryStats(array $period, int $totalLeads, array $dataQuality, Collection $trendSeries): array
    {
        $activeDays = max($dataQuality['active_days'] ?? 0, 1);
        $calendarDays = max($dataQuality['period_days'] ?? 0, 1);
        $peakBucket = $trendSeries->sortByDesc('total')->first();

        return [
            'avg_per_active_day' => round($totalLeads / $activeDays, 1),
            'avg_per_calendar_day' => round($totalLeads / $calendarDays, 1),
            'peak_period_label' => $peakBucket['full_label'] ?? '-',
            'peak_period_total' => $peakBucket['total'] ?? 0,
        ];
    }

    private function buildFunnel(int $totalLeads, int $totalSurveys, int $totalDeals): array
    {
        return [
            'leads' => $totalLeads,
            'surveys' => $totalSurveys,
            'deals' => $totalDeals,
            'survey_rate' => $this->toRate($totalSurveys, $totalLeads),
            'deal_rate' => $this->toRate($totalDeals, $totalLeads),
            'deal_from_survey_rate' => $this->toRate($totalDeals, $totalSurveys),
        ];
    }

    private function buildTopPerformers(
        Collection $statusDistribution,
        Collection $needsDistribution,
        Collection $provinceDistribution,
        Collection $accountRanking,
        Collection $adminRanking
    ): array {
        return [
            'status' => $statusDistribution->sortByDesc('count')->first(),
            'need' => $needsDistribution->first(),
            'province' => $provinceDistribution->first(),
            'account' => $accountRanking->first(),
            'admin' => $adminRanking->first(),
        ];
    }

    private function buildGrowthPercent(User $user, ?int $selectedAccount, array $period): float
    {
        $current = $this->baseQuery($user, $selectedAccount, $period['start'], $period['end'])->count();
        $previous = $this->baseQuery(
            $user,
            $selectedAccount,
            $period['comparison_start'],
            $period['comparison_end']
        )->count();

        if ($previous > 0) {
            return round((($current - $previous) / $previous) * 100, 1);
        }

        return $current > 0 ? 100.0 : 0.0;
    }

    private function buildInsights(
        Collection $statusDistribution,
        Collection $needsDistribution,
        Collection $provinceDistribution,
        Collection $accountRanking,
        Collection $adminRanking,
        float $growthPercent,
        array $dataQuality,
        Collection $trendSeries,
        array $funnel
    ): Collection {
        $insights = collect();

        if ($topStatus = $statusDistribution->sortByDesc('count')->first()) {
            if ($topStatus['count'] > 0) {
                $insights->push([
                    'icon' => 'analytics',
                    'html' => sprintf(
                        'Status terbesar adalah <mark>%s</mark> dengan <mark>%s</mark> konsultasi.',
                        $topStatus['name'],
                        number_format($topStatus['count'])
                    ),
                ]);
            }
        }

        if ($topNeed = $needsDistribution->first()) {
            if ($topNeed['count'] > 0) {
                $insights->push([
                    'icon' => 'assignment',
                    'html' => sprintf(
                        'Kebutuhan teratas adalah <mark>%s</mark> dengan <mark>%s</mark> lead.',
                        $topNeed['name'],
                        number_format($topNeed['count'])
                    ),
                ]);
            }
        }

        if ($topProvince = $provinceDistribution->first()) {
            if ($topProvince['count'] > 0) {
                $insights->push([
                    'icon' => 'flag',
                    'html' => sprintf(
                        'Wilayah dominan datang dari <mark>%s</mark> dengan kontribusi <mark>%s%%</mark>.',
                        $topProvince['name'],
                        $topProvince['percentage']
                    ),
                ]);
            }
        }

        if ($topAccount = $accountRanking->first()) {
            if ($topAccount['score'] > 0) {
                $insights->push([
                    'icon' => 'groups',
                    'html' => sprintf(
                        'Akun terbaik saat ini adalah <mark>%s</mark> dengan skor performa <mark>%s</mark>.',
                        $topAccount['name'],
                        $topAccount['score']
                    ),
                ]);
            }
        }

        if ($topAdmin = $adminRanking->first()) {
            if ($topAdmin['total'] > 0) {
                $insights->push([
                    'icon' => 'person',
                    'html' => sprintf(
                        'Admin paling produktif adalah <mark>%s</mark> dengan <mark>%s</mark> lead.',
                        $topAdmin['name'],
                        number_format($topAdmin['total'])
                    ),
                ]);
            }
        }

        if ($peak = $trendSeries->sortByDesc('total')->first()) {
            if ($peak['total'] > 0) {
                $insights->push([
                    'icon' => 'trending_up',
                    'html' => sprintf(
                        'Puncak volume terjadi pada <mark>%s</mark> dengan <mark>%s</mark> konsultasi.',
                        $peak['full_label'],
                        number_format($peak['total'])
                    ),
                ]);
            }
        }

        $direction = $growthPercent >= 0 ? 'naik' : 'turun';
        if (abs($growthPercent) > 0) {
            $insights->push([
                'icon' => 'trending_up',
                'html' => sprintf(
                    'Jumlah konsultasi %s <mark>%s%%</mark> dibanding periode pembanding.',
                    $direction,
                    abs($growthPercent)
                ),
            ]);
        }

        if ($funnel['survey_rate'] > 0 || $funnel['deal_from_survey_rate'] > 0) {
            $insights->push([
                'icon' => 'filter_alt',
                'html' => sprintf(
                    'Konversi funnel dari lead ke survey berada di <mark>%s%%</mark>, sedangkan deal dari survey berada di <mark>%s%%</mark>.',
                    $funnel['survey_rate'],
                    $funnel['deal_from_survey_rate']
                ),
            ]);
        }

        $insights->push([
            'icon' => 'task_alt',
            'html' => sprintf(
                'Kelengkapan data lokasi mencapai <mark>%s%%</mark> dan kelengkapan catatan mencapai <mark>%s%%</mark>.',
                $dataQuality['location_completion_rate'],
                $dataQuality['notes_completion_rate']
            ),
        ]);

        if (($dataQuality['duplicate_phone_rows'] ?? 0) > 0) {
            $insights->push([
                'icon' => 'warning',
                'html' => sprintf(
                    'Terdapat <mark>%s</mark> baris dengan nomor telepon duplikat yang perlu direview.',
                    number_format($dataQuality['duplicate_phone_rows'])
                ),
            ]);
        }

        return $insights->values();
    }

    private function resolveAccountName(User $user, ?int $selectedAccount): string
    {
        if ($user->isSuperAdmin()) {
            if (! $selectedAccount) {
                return 'Semua Akun';
            }

            return Account::whereKey($selectedAccount)->value('name') ?? 'Akun Tidak Ditemukan';
        }

        return $user->account?->name ?? 'Akun Admin';
    }

    private function countByStatusName(Collection $statusDistribution, string $statusName): int
    {
        return (int) ($statusDistribution->firstWhere('name', $statusName)['count'] ?? 0);
    }

    private function resolveStatusId(array $aliases): ?int
    {
        return StatusCategory::whereIn('name', array_values(array_filter($aliases)))->value('id');
    }

    private function surveyStatusName(): string
    {
        return config('statuses.survey', 'Request Survey');
    }

    private function dealStatusName(): string
    {
        return config('statuses.deal', 'Selesai/Deal');
    }

    private function surveyStatusAliases(): array
    {
        return [$this->surveyStatusName(), 'Request Survey', 'Masuk Survey'];
    }

    private function dealStatusAliases(): array
    {
        return [$this->dealStatusName(), 'Selesai/Deal', 'Selesai Deal'];
    }

    private function toRate(int $count, int $total): float
    {
        return $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
    }

    private function cleanLocationLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $label = trim(preg_replace('/\s+/', ' ', $value));

        return $label !== '' ? $label : null;
    }

    private function isConfirmedLocationValue(?string $value): bool
    {
        $label = $this->cleanLocationLabel($value);

        return $label !== null && ! $this->isPendingConfirmationValue($label);
    }

    private function isPendingConfirmationValue(?string $value): bool
    {
        $label = $this->cleanLocationLabel($value);

        if ($label === null) {
            return false;
        }

        return $this->normalizeLocation($label) === $this->normalizeLocation(PendingConfirmation::LABEL);
    }

    private function applyPendingConfirmationConstraint(Builder $query, string $column): void
    {
        $query->whereRaw("LOWER(TRIM(COALESCE({$column}, ''))) = ?", [mb_strtolower(PendingConfirmation::LABEL)]);
    }

    private function normalizeLocation(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish();
    }

    private function isWestJavaLead(string $province, string $city): bool
    {
        if (str_contains($province, 'jawa barat') || str_contains($province, 'jabar')) {
            return true;
        }

        return $this->resolveWestJavaSegment($city) !== null;
    }

    private function resolveWestJavaSegment(string $normalizedCity): ?string
    {
        if ($normalizedCity === '') {
            return null;
        }

        foreach ($this->westJavaSegments() as $segmentName => $config) {
            foreach ($config['aliases'] as $alias) {
                if (str_contains($normalizedCity, $alias)) {
                    return $segmentName;
                }
            }
        }

        return null;
    }

    private function westJavaSegments(): array
    {
        return [
            'Bandung Raya' => [
                'aliases' => ['bandung barat', 'kbb', 'cimahi', 'kab bandung', 'kabupaten bandung', 'bandung'],
                'color' => '#2563eb',
            ],
            'Pantura' => [
                'aliases' => ['pangandaran', 'ciamis', 'banjar', 'tasikmalaya', 'tasik', 'garut'],
                'color' => '#16a34a',
            ],
            'Jabar Pantura' => [
                'aliases' => ['indramayu', 'cirebon', 'kuningan', 'sumedang', 'majalengka', 'subang', 'purwakarta'],
                'color' => '#f59e0b',
            ],
            'Jabar Kulon' => [
                'aliases' => ['sukabumi', 'cianjur'],
                'color' => '#7c3aed',
            ],
            'Jabodetabek' => [
                'aliases' => ['bogor', 'depok', 'bekasi', 'karawang'],
                'color' => '#ef4444',
            ],
            'Lainnya Jawa Barat' => [
                'aliases' => [],
                'color' => '#64748b',
            ],
        ];
    }
}
