<?php

namespace App\Services\Reports;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Consultation;
use App\Models\ConsultationStatusHistory;
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
        $totalLeadsAllPeriods = $this->baseScopeQuery($user, $selectedAccount)->count();
        $statusDistribution = $this->buildStatusDistribution($query);
        $needsDistribution = $this->buildNeedsDistribution($query);
        $provinceDistribution = $user->isSuperAdmin() ? $this->buildLocationDistribution($query, 'province') : collect();
        $cityDistribution = $user->isSuperAdmin() ? $this->buildLocationDistribution($query, 'city') : collect();
        $westJavaSegmentDistribution = $user->isSuperAdmin() ? $this->buildWestJavaSegmentDistribution($query) : collect();
        $accountRanking = $user->isSuperAdmin() ? $this->buildAccountRanking($period, $selectedAccount) : collect();
        $adminRanking = $user->isSuperAdmin() ? $this->buildAdminRanking($period, $selectedAccount) : collect();

        $totalSurveys = $this->countByStatusAliases($statusDistribution, $this->surveyStatusAliases());
        $totalDeals = $this->countByStatusAliases($statusDistribution, $this->dealStatusAliases());
        $requestSurveyRate = $totalLeads > 0 ? round(($totalSurveys / $totalLeads) * 100, 1) : 0;
        $conversionRate = $totalLeads > 0 ? round(($totalDeals / $totalLeads) * 100, 1) : 0;
        $dealRate = $conversionRate;
        $growthPercent = $this->buildGrowthPercent($user, $selectedAccount, $period);
        $previousTotalLeads = $this->baseQuery(
            $user,
            $selectedAccount,
            $period['comparison_start'],
            $period['comparison_end']
        )->count();
        $rawRows = $includeRawRows ? $this->buildRawRows($query) : collect();
        $trendSeries = $this->buildTrendSeries($query, $period);
        $dataQuality = $this->buildDataQuality($query, $period, $totalLeads);
        $pendingConfirmationStats = $this->buildPendingConfirmationStats($query, $totalLeads);
        $funnel = $this->buildFunnel($totalLeads, $totalSurveys, $totalDeals);
        $diagnosticSnapshot = $this->buildDiagnosticSnapshot($user, $selectedAccount, $period);
        $comparisonSnapshot = $this->buildDiagnosticSnapshot($user, $selectedAccount, [
            'type' => $period['type'],
            'start' => $period['comparison_start'],
            'end' => $period['comparison_end'],
            'label' => $period['comparison_label'],
            'comparison_label' => $period['label'],
        ]);
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
            'totalLeadsAllPeriods' => $totalLeadsAllPeriods,
            'totalSurveys' => $totalSurveys,
            'totalDeals' => $totalDeals,
            'requestSurveyRate' => $requestSurveyRate,
            'conversionRate' => $conversionRate,
            'dealRate' => $dealRate,
            'growthPercent' => $growthPercent,
            'previousTotalLeads' => $previousTotalLeads,
            'growthDelta' => $totalLeads - $previousTotalLeads,
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
            'cohortConversion' => $this->buildCohortConversion($user, $selectedAccount, $period),
            'stageVelocity' => $this->buildStageVelocity($user, $selectedAccount, $period),
            'topPerformers' => $topPerformers,
            'summaryStats' => $summaryStats,
            'comparisonMatrix' => $this->buildComparisonMatrix($user, $selectedAccount, $period),
            'performanceAnalysis' => $this->buildPerformanceAnalysis(
                $diagnosticSnapshot,
                $comparisonSnapshot,
                $period,
                $dataQuality,
                $pendingConfirmationStats
            ),
            'onlyInquiryAnalysis' => $this->buildOnlyInquiryAnalysis($user, $selectedAccount, $period),
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
        $query = $this->baseScopeQuery($user, $selectedAccount);

        return $query->whereBetween(
            $this->consultationColumn('consultation_date'),
            [$start->toDateString(), $end->toDateString()]
        );
    }

    private function baseScopeQuery(User $user, ?int $selectedAccount): Builder
    {
        $query = Consultation::query()->forUser($user);

        if ($user->isSuperAdmin() && $selectedAccount) {
            $query->where($this->consultationColumn('account_id'), $selectedAccount);
        }

        return $query;
    }

    private function buildStatusDistribution(Builder $query): Collection
    {
        $counts = (clone $query)
            ->selectRaw($this->consultationColumn('status_category_id') . ' as status_category_id, count(*) as count')
            ->groupBy($this->consultationColumn('status_category_id'))
            ->pluck('count', 'status_category_id');

        return StatusCategory::orderBy('sort_order')->get()->map(fn ($status) => [
            'name' => $status->name,
            'color' => $status->color,
            'count' => $counts[$status->id] ?? 0,
        ]);
    }

    private function buildNeedsDistribution(Builder $query): Collection
    {
        return $this->buildNeedsDistributionFromQuery($query);
    }

    private function buildNeedsDistributionFromQuery(Builder $query): Collection
    {
        if (Consultation::hasNeedsCategoryPivot()) {
            $counts = (clone $query)
                ->join('consultation_needs_category as cnc', 'consultations.id', '=', 'cnc.consultation_id')
                ->selectRaw('cnc.needs_category_id, count(*) as count')
                ->groupBy('cnc.needs_category_id')
                ->pluck('count', 'cnc.needs_category_id');
        } else {
            $counts = (clone $query)
                ->whereNotNull($this->consultationColumn('needs_category_id'))
                ->selectRaw($this->consultationColumn('needs_category_id') . ' as needs_category_id, count(*) as count')
                ->groupBy($this->consultationColumn('needs_category_id'))
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
        $qualifiedColumn = $this->consultationColumn($column);
        $pendingLabel = mb_strtolower(PendingConfirmation::LABEL);

        $rows = (clone $query)
            ->whereNotNull($qualifiedColumn)
            ->whereRaw("LOWER(TRIM({$qualifiedColumn})) != ?", [$pendingLabel])
            ->selectRaw("{$qualifiedColumn} as location_value, COUNT(*) as count")
            ->groupBy($qualifiedColumn)
            ->orderByDesc('count')
            ->limit($limit * 3)
            ->get();

        $distribution = $rows->reduce(function (array $carry, $row) {
            $label = $this->cleanLocationLabel($row->location_value);

            if ($label === null || $this->isPendingConfirmationValue($label)) {
                return $carry;
            }

            $key = $this->normalizeLocation($label);

            if (! isset($carry[$key])) {
                $carry[$key] = ['name' => $label, 'count' => 0];
            }

            $carry[$key]['count'] += (int) $row->count;

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

        $pc = $this->consultationColumn('province');
        $cc = $this->consultationColumn('city');

        $rows = (clone $query)
            ->selectRaw("{$pc} as province, {$cc} as city, COUNT(*) as count")
            ->groupBy($pc, $cc)
            ->get();

        foreach ($rows as $row) {
            $province = $this->normalizeLocation($row->province);
            $city = $this->normalizeLocation($row->city);

            if (! $this->isWestJavaLead($province, $city)) {
                continue;
            }

            $segmentName = $this->resolveWestJavaSegment($city) ?? 'Lainnya Jawa Barat';
            $segment = $segments->get($segmentName);
            $segment['count'] += (int) $row->count;
            $segments->put($segmentName, $segment);
        }

        return $segments->values();
    }

    private function buildAccountRanking(array $period, ?int $selectedAccount = null): Collection
    {
        $surveyStatusIds = $this->resolveStatusIds($this->surveyStatusAliases());
        $dealStatusIds = $this->resolveStatusIds($this->dealStatusAliases());

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

        if ($surveyStatusIds->isNotEmpty()) {
            $query->withCount([
                'consultations as surveys_count' => function ($builder) use ($period, $surveyStatusIds) {
                    $builder->whereBetween('consultation_date', [
                        $period['start']->toDateString(),
                        $period['end']->toDateString(),
                    ])->whereIn('status_category_id', $surveyStatusIds->all());
                },
            ]);
        }

        if ($dealStatusIds->isNotEmpty()) {
            $query->withCount([
                'consultations as deals_count' => function ($builder) use ($period, $dealStatusIds) {
                    $builder->whereBetween('consultation_date', [
                        $period['start']->toDateString(),
                        $period['end']->toDateString(),
                    ])->whereIn('status_category_id', $dealStatusIds->all());
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

    private function buildRawRows(Builder $query, int $limit = 5000): Collection
    {
        return (clone $query)
            ->with(array_merge(['account', 'statusCategory', 'creator'], Consultation::productRelations()))
            ->orderBy('consultation_date', 'desc')
            ->limit($limit)
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
        $surveyStatusIds = $this->resolveStatusIds($this->surveyStatusAliases());
        $dealStatusIds = $this->resolveStatusIds($this->dealStatusAliases());
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
            ? "DATE_FORMAT({$this->consultationColumn('consultation_date')}, '%Y-%m')"
            : 'DATE(' . $this->consultationColumn('consultation_date') . ')';

        $aggregateQuery = (clone $query)
            ->whereNotNull($this->consultationColumn('consultation_date'))
            ->selectRaw("{$bucketExpression} as bucket_key")
            ->selectRaw('COUNT(*) as total');

        if ($surveyStatusIds->isNotEmpty()) {
            $aggregateQuery->selectRaw(
                'SUM(CASE WHEN ' . $this->consultationColumn('status_category_id') . ' IN (' . $surveyStatusIds->implode(',') . ') THEN 1 ELSE 0 END) as surveys'
            );
        } else {
            $aggregateQuery->selectRaw('0 as surveys');
        }

        if ($dealStatusIds->isNotEmpty()) {
            $aggregateQuery->selectRaw(
                'SUM(CASE WHEN ' . $this->consultationColumn('status_category_id') . ' IN (' . $dealStatusIds->implode(',') . ') THEN 1 ELSE 0 END) as deals'
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
        $pendingLabel = mb_strtolower(PendingConfirmation::LABEL);
        $pc = $this->consultationColumn('province');
        $cc = $this->consultationColumn('city');
        $nc = $this->consultationColumn('notes');
        $cbc = $this->consultationColumn('created_by');
        $cdc = $this->consultationColumn('consultation_date');
        $uac = $this->consultationColumn('updated_at');
        $phonec = $this->consultationColumn('phone');

        $stats = (clone $query)->selectRaw("
            SUM(CASE WHEN {$pc} IS NOT NULL AND LOWER(TRIM({$pc})) != ? THEN 1 ELSE 0 END) as province_filled,
            SUM(CASE WHEN {$cc} IS NOT NULL AND LOWER(TRIM({$cc})) != ? THEN 1 ELSE 0 END) as city_filled,
            SUM(CASE WHEN {$nc} IS NOT NULL AND TRIM({$nc}) != '' THEN 1 ELSE 0 END) as notes_filled,
            SUM(CASE WHEN {$pc} IS NOT NULL AND LOWER(TRIM({$pc})) != ? AND {$cc} IS NOT NULL AND LOWER(TRIM({$cc})) != ? THEN 1 ELSE 0 END) as location_complete,
            COUNT(DISTINCT CASE WHEN {$pc} IS NOT NULL AND LOWER(TRIM({$pc})) != ? THEN LOWER(TRIM({$pc})) END) as unique_provinces,
            COUNT(DISTINCT CASE WHEN {$cc} IS NOT NULL AND LOWER(TRIM({$cc})) != ? THEN LOWER(TRIM({$cc})) END) as unique_cities,
            COUNT(DISTINCT {$cbc}) as active_admins,
            COUNT(DISTINCT DATE({$cdc})) as active_days,
            MAX({$uac}) as latest_update
        ", [$pendingLabel, $pendingLabel, $pendingLabel, $pendingLabel, $pendingLabel, $pendingLabel])->first();

        $duplicatePhones = (int) (clone $query)
            ->whereNotNull($phonec)
            ->selectRaw("{$phonec} as phone_val, COUNT(*) as phone_count")
            ->groupBy($phonec)
            ->havingRaw('phone_count > 1')
            ->get()
            ->sum('phone_count');

        return [
            'province_completion_rate' => $this->toRate((int) ($stats->province_filled ?? 0), $totalLeads),
            'city_completion_rate' => $this->toRate((int) ($stats->city_filled ?? 0), $totalLeads),
            'notes_completion_rate' => $this->toRate((int) ($stats->notes_filled ?? 0), $totalLeads),
            'location_completion_rate' => $this->toRate((int) ($stats->location_complete ?? 0), $totalLeads),
            'province_filled' => (int) ($stats->province_filled ?? 0),
            'city_filled' => (int) ($stats->city_filled ?? 0),
            'notes_filled' => (int) ($stats->notes_filled ?? 0),
            'location_complete' => (int) ($stats->location_complete ?? 0),
            'unique_provinces' => (int) ($stats->unique_provinces ?? 0),
            'unique_cities' => (int) ($stats->unique_cities ?? 0),
            'active_admins' => (int) ($stats->active_admins ?? 0),
            'active_days' => (int) ($stats->active_days ?? 0),
            'duplicate_phone_rows' => $duplicatePhones,
            'latest_update' => $stats->latest_update ? Carbon::parse($stats->latest_update)->format('d/m/Y H:i') : '-',
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
                        $builder->where($this->consultationColumn('needs_category_id'), $pendingConfirmationCategoryId)
                            ->orWhereHas(
                                'needsCategories',
                                fn (Builder $relationQuery) => $relationQuery->where('needs_categories.id', $pendingConfirmationCategoryId)
                            );
                    })
                    ->distinct()
                    ->count('consultations.id');
            } else {
                $productCount = (clone $query)
                    ->where($this->consultationColumn('needs_category_id'), $pendingConfirmationCategoryId)
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

    private function buildComparisonMatrix(User $user, ?int $selectedAccount, array $period): Collection
    {
        $weeklyPeriod = $this->periodResolver->resolve([
            'period_type' => 'weekly',
            'week_date' => $period['end']->toDateString(),
        ]);

        $monthlyPeriod = $this->periodResolver->resolve([
            'period_type' => 'monthly',
            'month' => $period['end']->month,
            'year' => $period['end']->year,
        ]);

        $yearlyPeriod = $this->periodResolver->resolve([
            'period_type' => 'yearly',
            'year' => $period['end']->year,
        ]);

        return collect([
            ['key' => 'wow', 'short_label' => 'WoW', 'title' => 'Minggu ke Minggu', 'period' => $weeklyPeriod, 'icon' => 'history'],
            ['key' => 'mom', 'short_label' => 'MoM', 'title' => 'Bulan ke Bulan', 'period' => $monthlyPeriod, 'icon' => 'calendar_month'],
            ['key' => 'yoy', 'short_label' => 'YoY', 'title' => 'Tahun ke Tahun', 'period' => $yearlyPeriod, 'icon' => 'leaderboard'],
        ])->map(function (array $item) use ($user, $selectedAccount) {
            $periodWindow = $item['period'];
            $current = $this->buildMetricSnapshot($user, $selectedAccount, $periodWindow['start'], $periodWindow['end']);
            $previous = $this->buildMetricSnapshot($user, $selectedAccount, $periodWindow['comparison_start'], $periodWindow['comparison_end']);

            return [
                'key' => $item['key'],
                'short_label' => $item['short_label'],
                'title' => $item['title'],
                'icon' => $item['icon'],
                'current_label' => $periodWindow['label'],
                'previous_label' => $periodWindow['comparison_label'],
                'leads' => $this->buildDeltaMetric($current['total_leads'], $previous['total_leads']),
                'surveys' => $this->buildDeltaMetric($current['surveys'], $previous['surveys']),
                'deals' => $this->buildDeltaMetric($current['deals'], $previous['deals']),
                'deal_rate' => $this->buildDeltaMetric($current['deal_rate'], $previous['deal_rate'], false),
                'survey_rate' => $this->buildDeltaMetric($current['survey_rate'], $previous['survey_rate'], false),
            ];
        })->values();
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

    /**
     * Cohort Conversion — kelompokkan lead per bulan masuk (consultation_date)
     * dalam tahun terpilih, lalu hitung konversi survey & deal per cohort.
     */
    private function buildCohortConversion(User $user, ?int $selectedAccount, array $period): Collection
    {
        $year = (int) ($period['year'] ?? now()->year);
        $column = $this->consultationColumn('consultation_date');
        $statusColumn = $this->consultationColumn('status_category_id');

        $surveyIds = $this->resolveStatusIds($this->surveyStatusAliases());
        $dealIds = $this->resolveStatusIds($this->dealStatusAliases());
        $surveyList = $surveyIds->isNotEmpty() ? $surveyIds->implode(',') : '0';
        $dealList = $dealIds->isNotEmpty() ? $dealIds->implode(',') : '0';

        $rows = $this->baseScopeQuery($user, $selectedAccount)
            ->whereNotNull($column)
            ->whereYear($column, $year)
            ->selectRaw('MONTH(' . $column . ') as month_no')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN ' . $statusColumn . ' IN (' . $surveyList . ') THEN 1 ELSE 0 END) as surveys')
            ->selectRaw('SUM(CASE WHEN ' . $statusColumn . ' IN (' . $dealList . ') THEN 1 ELSE 0 END) as deals')
            ->groupBy('month_no')
            ->get()
            ->keyBy('month_no');

        $monthLabels = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        // Rentang cohort mengikuti filter periode: bulan terpilih (bulanan),
        // bulan dari tanggal acuan (mingguan), atau penuh setahun (tahunan).
        $now = now();
        $type = $period['type'] ?? 'monthly';

        if ($type === 'yearly') {
            $endMonth = 12;
        } elseif ($type === 'weekly') {
            $anchor = ! empty($period['anchor_date']) ? Carbon::parse($period['anchor_date']) : $now;
            $endMonth = (int) $anchor->month;
        } else {
            $endMonth = (int) ($period['month'] ?? $now->month);
        }

        // Jangan tampilkan bulan masa depan pada tahun berjalan.
        if ($year === (int) $now->year) {
            $endMonth = min($endMonth, (int) $now->month);
        }

        $maxMonth = max(1, min(12, $endMonth));

        return collect(range(1, $maxMonth))->map(function (int $m) use ($rows, $year, $monthLabels) {
            $row = $rows->get($m);
            $total = (int) ($row->total ?? 0);
            $surveys = (int) ($row->surveys ?? 0);
            $deals = (int) ($row->deals ?? 0);

            return [
                'month' => sprintf('%04d-%02d', $year, $m),
                'label' => $monthLabels[$m],
                'total' => $total,
                'surveys' => $surveys,
                'deals' => $deals,
                'survey_rate' => $this->toRate($surveys, $total),
                'conversion_rate' => $this->toRate($deals, $total),
            ];
        })->values();
    }

    /**
     * Sales Cycle & Time-in-Stage — dihitung dari consultation_status_histories.
     * Data terkumpul sejak fitur pelacakan transisi aktif; saat kosong
     * mengembalikan collecting=true.
     */
    private function buildStageVelocity(User $user, ?int $selectedAccount, array $period): array
    {
        $periodStart = $period['start']->copy()->startOfDay();
        $periodEnd = $period['end']->copy()->endOfDay();

        $query = ConsultationStatusHistory::query()
            ->orderBy('consultation_id')
            ->orderBy('created_at')
            ->orderBy('id');

        if ($user->isAdmin()) {
            $query->where('account_id', $user->account_id);
        } elseif ($selectedAccount) {
            $query->where('account_id', $selectedAccount);
        }

        $histories = $query->get(['id', 'consultation_id', 'from_status_id', 'to_status_id', 'created_at']);

        $dealIds = $this->resolveStatusIds($this->dealStatusAliases())->all();

        $cycleDays = [];
        $stageAgg = [];
        $transitionsInPeriod = 0;

        foreach ($histories->groupBy('consultation_id') as $entries) {
            $entries = $entries->values();
            $cycleStart = $entries->first()?->created_at;
            $count = $entries->count();

            for ($i = 0; $i < $count; $i++) {
                $entry = $entries[$i];
                $next = $entries[$i + 1] ?? null;
                $inPeriod = $entry->created_at->gte($periodStart) && $entry->created_at->lte($periodEnd);

                if ($inPeriod) {
                    $transitionsInPeriod++;
                }

                if ($next && $entry->to_status_id) {
                    $exitAt = $next->created_at;
                    if ($exitAt->gte($periodStart) && $exitAt->lte($periodEnd)) {
                        $sid = (int) $entry->to_status_id;
                        $stageAgg[$sid] ??= ['sum' => 0.0, 'count' => 0];
                        $stageAgg[$sid]['sum'] += $entry->created_at->floatDiffInDays($exitAt);
                        $stageAgg[$sid]['count']++;
                    }
                }

                if ($cycleStart && $entry->to_status_id && in_array((int) $entry->to_status_id, $dealIds, true) && $inPeriod) {
                    $cycleDays[] = $cycleStart->floatDiffInDays($entry->created_at);
                }
            }
        }

        $stages = StatusCategory::orderBy('sort_order')->get()
            ->map(function (StatusCategory $status) use ($stageAgg) {
                $agg = $stageAgg[$status->id] ?? null;

                return [
                    'status_id' => $status->id,
                    'name' => $status->name,
                    'color' => $status->color,
                    'avg_days' => $agg ? round($agg['sum'] / max($agg['count'], 1), 1) : null,
                    'sample' => $agg['count'] ?? 0,
                ];
            })
            ->filter(fn (array $row) => $row['sample'] > 0)
            ->values();

        sort($cycleDays);
        $sampleSize = count($cycleDays);

        return [
            'collecting' => $transitionsInPeriod === 0,
            'transitions' => $transitionsInPeriod,
            'sales_cycle' => [
                'sample' => $sampleSize,
                'avg_days' => $sampleSize > 0 ? round(array_sum($cycleDays) / $sampleSize, 1) : null,
                'median_days' => $sampleSize > 0 ? round($this->medianOfSorted($cycleDays), 1) : null,
                'fastest_days' => $sampleSize > 0 ? round($cycleDays[0], 1) : null,
                'slowest_days' => $sampleSize > 0 ? round($cycleDays[$sampleSize - 1], 1) : null,
            ],
            'stages' => $stages,
        ];
    }

    private function medianOfSorted(array $sorted): float
    {
        $n = count($sorted);

        if ($n === 0) {
            return 0.0;
        }

        $mid = intdiv($n, 2);

        return $n % 2 === 0
            ? ($sorted[$mid - 1] + $sorted[$mid]) / 2
            : $sorted[$mid];
    }

    private function buildMetricSnapshot(User $user, ?int $selectedAccount, Carbon $start, Carbon $end): array
    {
        $query = $this->baseQuery($user, $selectedAccount, $start, $end);
        $surveyStatusIds = $this->resolveStatusIds($this->surveyStatusAliases());
        $dealStatusIds = $this->resolveStatusIds($this->dealStatusAliases());
        $totalLeads = (clone $query)->count();
        $surveys = $surveyStatusIds->isNotEmpty()
            ? (clone $query)->whereIn($this->consultationColumn('status_category_id'), $surveyStatusIds->all())->count()
            : 0;
        $deals = $dealStatusIds->isNotEmpty()
            ? (clone $query)->whereIn($this->consultationColumn('status_category_id'), $dealStatusIds->all())->count()
            : 0;

        return [
            'total_leads' => $totalLeads,
            'surveys' => $surveys,
            'deals' => $deals,
            'survey_rate' => $this->toRate($surveys, $totalLeads),
            'deal_rate' => $this->toRate($deals, $totalLeads),
            'active_days' => (clone $query)
                ->whereNotNull($this->consultationColumn('consultation_date'))
                ->selectRaw('DATE(' . $this->consultationColumn('consultation_date') . ') as day_key')
                ->groupBy('day_key')
                ->get()
                ->count(),
        ];
    }

    private function buildDiagnosticSnapshot(User $user, ?int $selectedAccount, array $period): array
    {
        $query = $this->baseQuery($user, $selectedAccount, $period['start'], $period['end']);
        $metrics = $this->buildMetricSnapshot($user, $selectedAccount, $period['start'], $period['end']);
        $dealStatusIds = $this->resolveStatusIds($this->dealStatusAliases());
        $statusDistribution = $this->buildStatusDistribution($query);
        $needsDistribution = $this->buildNeedsDistributionFromQuery($query);
        $trendSeries = $this->buildTrendSeries($query, [
            'type' => $period['type'] ?? 'monthly',
            'start' => $period['start'],
            'end' => $period['end'],
        ]);
        $topPeak = $trendSeries->sortByDesc('total')->first();

        $dealNeeds = $dealStatusIds->isNotEmpty()
            ? $this->buildNeedsDistributionFromQuery(
                (clone $query)->whereIn($this->consultationColumn('status_category_id'), $dealStatusIds->all())
            )
            : collect();

        $nonDealNeeds = $dealStatusIds->isNotEmpty()
            ? $this->buildNeedsDistributionFromQuery(
                (clone $query)->whereNotIn($this->consultationColumn('status_category_id'), $dealStatusIds->all())
            )
            : $needsDistribution;

        $contributorDimension = $user->isSuperAdmin() && ! $selectedAccount ? 'account' : 'admin';
        $contributors = $this->buildContributorDistribution($query, $contributorDimension);
        $dealContributors = $dealStatusIds->isNotEmpty()
            ? $this->buildContributorDistribution(
                (clone $query)->whereIn($this->consultationColumn('status_category_id'), $dealStatusIds->all()),
                $contributorDimension
            )
            : collect();

        return [
            'label' => $period['label'] ?? '',
            'total_leads' => $metrics['total_leads'],
            'surveys' => $metrics['surveys'],
            'deals' => $metrics['deals'],
            'non_deals' => max($metrics['total_leads'] - $metrics['deals'], 0),
            'survey_rate' => $metrics['survey_rate'],
            'deal_rate' => $metrics['deal_rate'],
            'active_days' => $metrics['active_days'],
            'top_need' => $needsDistribution->first(),
            'top_deal_need' => $dealNeeds->first(),
            'top_non_deal_need' => $nonDealNeeds->first(),
            'top_contributor' => $contributors->first(),
            'top_deal_contributor' => $dealContributors->first(),
            'top_non_deal_statuses' => $statusDistribution
                ->filter(fn (array $status) => $status['count'] > 0 && ! $this->statusNameMatches($status['name'], $this->dealStatusAliases()))
                ->sortByDesc('count')
                ->take(3)
                ->values(),
            'need_map' => $needsDistribution->pluck('count', 'name')->all(),
            'contributor_map' => $contributors->pluck('count', 'name')->all(),
            'peak_bucket' => $topPeak,
            'contributor_dimension' => $contributorDimension,
        ];
    }

    private function buildContributorDistribution(Builder $query, string $dimension = 'admin'): Collection
    {
        if ($dimension === 'account') {
            $counts = (clone $query)
                ->join('accounts', 'accounts.id', '=', 'consultations.account_id')
                ->selectRaw('accounts.name as contributor_name, COUNT(*) as aggregate_count')
                ->groupBy('accounts.name')
                ->orderByDesc('aggregate_count')
                ->get();
        } else {
            $counts = (clone $query)
                ->leftJoin('users', 'users.id', '=', 'consultations.created_by')
                ->selectRaw("COALESCE(users.name, 'Tanpa Admin') as contributor_name, COUNT(*) as aggregate_count")
                ->groupBy('contributor_name')
                ->orderByDesc('aggregate_count')
                ->get();
        }

        return collect($counts)->map(fn ($row) => [
            'name' => (string) $row->contributor_name,
            'count' => (int) $row->aggregate_count,
        ])->values();
    }

    private function buildPerformanceAnalysis(
        array $current,
        array $previous,
        array $period,
        array $dataQuality,
        array $pendingConfirmationStats
    ): Collection {
        $consultationUp = collect();
        $consultationDown = collect();
        $dealUp = collect();
        $dealDown = collect();

        if ($current['total_leads'] > 0) {
            $consultationUp->push(sprintf(
                'Total konsultasi periode ini <strong>%s</strong> lead. Kebutuhan paling dominan adalah <strong>%s</strong> dengan <strong>%s</strong> lead.',
                number_format($current['total_leads']),
                $current['top_need']['name'] ?? '-',
                number_format((int) ($current['top_need']['count'] ?? 0))
            ));

            if (! empty($current['top_contributor']['name'])) {
                $consultationUp->push(sprintf(
                    'Kontributor terbesar datang dari <strong>%s %s</strong> dengan <strong>%s</strong> lead.',
                    $current['contributor_dimension'] === 'account' ? 'akun' : 'admin',
                    $current['top_contributor']['name'],
                    number_format((int) ($current['top_contributor']['count'] ?? 0))
                ));
            }

            if (($current['peak_bucket']['total'] ?? 0) > 0) {
                $consultationUp->push(sprintf(
                    'Puncak volume terjadi pada <strong>%s</strong> dengan <strong>%s</strong> konsultasi.',
                    $current['peak_bucket']['full_label'] ?? ($current['peak_bucket']['label'] ?? '-'),
                    number_format((int) ($current['peak_bucket']['total'] ?? 0))
                ));
            }
        } else {
            $consultationUp->push('Belum ada konsultasi pada periode ini, jadi belum ada pendorong volume yang bisa dianalisa.');
        }

        $leadDelta = $current['total_leads'] - $previous['total_leads'];
        $leadDeltaPct = $this->deltaPercent($current['total_leads'], $previous['total_leads']);
        $consultationDown->push(sprintf(
            'Perbandingan vs <strong>%s</strong>: konsultasi %s <strong>%s</strong> lead (%s<strong>%s%%</strong>).',
            $period['comparison_label'],
            $leadDelta >= 0 ? 'naik' : 'turun',
            number_format(abs($leadDelta)),
            $leadDelta >= 0 ? '+' : '-',
            number_format(abs($leadDeltaPct), 1)
        ));

        if ($topDropNeed = $this->findLargestDeltaItem($current['need_map'], $previous['need_map'], 'decrease')) {
            $consultationDown->push(sprintf(
                'Penurunan paling terasa ada pada kebutuhan <strong>%s</strong> yang turun <strong>%s</strong> lead dari periode pembanding.',
                $topDropNeed['name'],
                number_format(abs($topDropNeed['delta']))
            ));
        }

        if ($topDropContributor = $this->findLargestDeltaItem($current['contributor_map'], $previous['contributor_map'], 'decrease')) {
            $consultationDown->push(sprintf(
                'Kontribusi <strong>%s %s</strong> juga turun <strong>%s</strong> lead.',
                $current['contributor_dimension'] === 'account' ? 'akun' : 'admin',
                $topDropContributor['name'],
                number_format(abs($topDropContributor['delta']))
            ));
        }

        if ($current['active_days'] < $previous['active_days']) {
            $consultationDown->push(sprintf(
                'Hari aktif input berkurang dari <strong>%s</strong> hari menjadi <strong>%s</strong> hari.',
                number_format($previous['active_days']),
                number_format($current['active_days'])
            ));
        }

        if ($current['deals'] > 0) {
            $dealUp->push(sprintf(
                'Periode ini menghasilkan <strong>%s</strong> deal dengan deal rate <strong>%s%%</strong>.',
                number_format($current['deals']),
                number_format($current['deal_rate'], 1)
            ));

            if (! empty($current['top_deal_need']['name'])) {
                $dealUp->push(sprintf(
                    'Kebutuhan paling sering berujung deal adalah <strong>%s</strong> dengan <strong>%s</strong> deal.',
                    $current['top_deal_need']['name'],
                    number_format((int) ($current['top_deal_need']['count'] ?? 0))
                ));
            }

            if (! empty($current['top_deal_contributor']['name'])) {
                $dealUp->push(sprintf(
                    'Kontributor deal terbesar berasal dari <strong>%s %s</strong> dengan <strong>%s</strong> deal.',
                    $current['contributor_dimension'] === 'account' ? 'akun' : 'admin',
                    $current['top_deal_contributor']['name'],
                    number_format((int) ($current['top_deal_contributor']['count'] ?? 0))
                ));
            }
        } else {
            $dealUp->push('Belum ada deal pada periode ini, jadi belum ada pola kemenangan yang bisa dibaca.');
        }

        $dealDelta = $current['deals'] - $previous['deals'];
        $dealDeltaPct = $this->deltaPercent($current['deals'], $previous['deals']);
        $dealDown->push(sprintf(
            'Deal vs <strong>%s</strong> %s <strong>%s</strong> (%s<strong>%s%%</strong>).',
            $period['comparison_label'],
            $dealDelta >= 0 ? 'naik' : 'turun',
            number_format(abs($dealDelta)),
            $dealDelta >= 0 ? '+' : '-',
            number_format(abs($dealDeltaPct), 1)
        ));

        $topNonDealStatuses = $current['top_non_deal_statuses'] ?? collect();
        if ($topNonDealStatuses instanceof Collection && $topNonDealStatuses->isNotEmpty()) {
            $labels = $topNonDealStatuses->take(2)->map(
                fn (array $status) => sprintf('%s (%s)', $status['name'], number_format($status['count']))
            )->implode(', ');

            $dealDown->push(sprintf(
                'Lead yang belum deal paling banyak tertahan di status <strong>%s</strong>.',
                $labels
            ));
        }

        if (! empty($current['top_non_deal_need']['name'])) {
            $dealDown->push(sprintf(
                'Kebutuhan yang paling banyak belum deal adalah <strong>%s</strong> dengan <strong>%s</strong> lead.',
                $current['top_non_deal_need']['name'],
                number_format((int) ($current['top_non_deal_need']['count'] ?? 0))
            ));
        }

        if (($pendingConfirmationStats['product']['count'] ?? 0) > 0 || ($pendingConfirmationStats['city']['count'] ?? 0) > 0) {
            $dealDown->push(sprintf(
                'Masih ada hambatan kualitas data: produk belum konfirmasi <strong>%s</strong> lead dan kota belum konfirmasi <strong>%s</strong> lead.',
                number_format((int) ($pendingConfirmationStats['product']['count'] ?? 0)),
                number_format((int) ($pendingConfirmationStats['city']['count'] ?? 0))
            ));
        }

        if (($dataQuality['notes_completion_rate'] ?? 0) < 60) {
            $dealDown->push(sprintf(
                'Kelengkapan catatan baru <strong>%s%%</strong>, ini bisa membuat penyebab gagal closing kurang terbaca dengan tajam.',
                number_format((float) ($dataQuality['notes_completion_rate'] ?? 0), 1)
            ));
        }

        return collect([
            [
                'eyebrow' => 'Volume Driver',
                'title' => 'Pendorong Konsultasi',
                'subtitle' => 'Faktor utama yang paling mendorong masuknya konsultasi pada periode aktif.',
                'icon' => 'trending_up',
                'tone' => 'positive',
                'metric' => number_format($current['total_leads']),
                'metric_label' => 'Total konsultasi',
                'badge' => $current['top_need']['name'] ?? 'Belum ada kebutuhan dominan',
                'items' => $consultationUp->filter()->values()->all(),
            ],
            [
                'eyebrow' => 'Volume Change',
                'title' => $leadDelta < 0 ? 'Penyebab Konsultasi Turun' : 'Arah Perubahan Konsultasi',
                'subtitle' => 'Membandingkan perubahan volume dengan periode pembanding untuk melihat area yang menekan atau menahan pertumbuhan.',
                'icon' => 'history_toggle_off',
                'tone' => 'warning',
                'metric' => ($leadDelta > 0 ? '+' : ($leadDelta < 0 ? '-' : '')) . number_format(abs($leadDelta)),
                'metric_label' => 'Delta vs ' . $period['comparison_label'],
                'badge' => $leadDelta >= 0 ? 'Volume masih bertumbuh' : 'Butuh pemulihan volume',
                'items' => $consultationDown->filter()->values()->all(),
            ],
            [
                'eyebrow' => 'Closing Driver',
                'title' => 'Pendorong Deal',
                'subtitle' => 'Sinyal yang paling banyak berkontribusi terhadap deal dan efektivitas closing.',
                'icon' => 'verified',
                'tone' => 'success',
                'metric' => number_format($current['deals']),
                'metric_label' => 'Total deal',
                'badge' => number_format($current['deal_rate'], 1) . '% deal rate',
                'items' => $dealUp->filter()->values()->all(),
            ],
            [
                'eyebrow' => 'Closing Barrier',
                'title' => 'Hambatan Closing',
                'subtitle' => 'Pola yang paling sering membuat lead belum bergerak sampai deal.',
                'icon' => 'warning',
                'tone' => 'danger',
                'metric' => number_format($current['non_deals']),
                'metric_label' => 'Lead belum deal',
                'badge' => ($current['top_non_deal_statuses']->first()['name'] ?? 'Belum ada hambatan dominan'),
                'items' => $dealDown->filter()->values()->all(),
            ],
        ]);
    }

    private function buildOnlyInquiryAnalysis(User $user, ?int $selectedAccount, array $period): array
    {
        $topicMinOccurrences = 4;
        $keywordLimit = 24;
        $sampleLimit = 12;
        $keywordMinOccurrences = 6;
        $statusId = $this->resolveStatusId($this->onlyInquiryStatusAliases());

        if (! $statusId) {
            return $this->emptyOnlyInquiryAnalysis();
        }

        $baseStatusQuery = $this->baseQuery($user, $selectedAccount, $period['start'], $period['end'])
            ->where($this->consultationColumn('status_category_id'), $statusId);

        $totalOnlyInquiry = (clone $baseStatusQuery)->count();

        if ($totalOnlyInquiry === 0) {
            return $this->emptyOnlyInquiryAnalysis();
        }

        $rows = (clone $baseStatusQuery)
            ->orderByDesc($this->consultationColumn('updated_at'))
            ->limit(2000)
            ->get(['consultation_id', 'client_name', 'notes', 'consultation_date', 'updated_at']);

        $filledNotes = $rows->filter(fn ($row) => filled(trim((string) $row->notes)))->values();
        $notesFilledCount = $filledNotes->count();

        $topicDefinitions = $this->onlyInquiryTopicDefinitions();
        $topicStats = collect($topicDefinitions)->mapWithKeys(function (array $topic) {
            return [$topic['key'] => array_merge($topic, [
                'note_count' => 0,
                'keyword_hits' => 0,
                'matched_keywords' => [],
            ])];
        });

        $rawKeywordCounter = [];
        $samples = collect();
        $uncategorizedCount = 0;

        foreach ($filledNotes as $row) {
            $rawNote = trim((string) $row->notes);
            $normalizedNote = $this->normalizeAnalysisText($rawNote);
            $tokens = $this->tokenizeAnalysisWords($normalizedNote);
            $matchedTopics = collect();
            $matchedKeywordPreview = collect();

            foreach ($tokens as $token) {
                $rawKeywordCounter[$token] = ($rawKeywordCounter[$token] ?? 0) + 1;
            }

            foreach ($topicDefinitions as $topic) {
                $matchedKeywords = collect($topic['keywords'])
                    ->map(fn (string $keyword) => $this->normalizeAnalysisText($keyword))
                    ->filter(fn (string $keyword) => $keyword !== '' && $this->analysisTextContains($normalizedNote, $keyword))
                    ->unique()
                    ->values();

                if ($matchedKeywords->isEmpty()) {
                    continue;
                }

                $matchedTopics->push($topic['label']);
                $matchedKeywordPreview = $matchedKeywordPreview->merge($matchedKeywords);
                $currentTopic = $topicStats->get($topic['key']);
                $currentTopic['note_count']++;
                $currentTopic['keyword_hits'] += $matchedKeywords->count();

                foreach ($matchedKeywords as $keyword) {
                    $currentTopic['matched_keywords'][$keyword] = ($currentTopic['matched_keywords'][$keyword] ?? 0) + 1;
                }

                $topicStats->put($topic['key'], $currentTopic);
            }

            if ($matchedTopics->isEmpty()) {
                $uncategorizedCount++;
            }

            $samples->push([
                'consultation_id' => $row->consultation_id,
                'client_name' => $row->client_name,
                'note' => $rawNote,
                'topics' => $matchedTopics->values()->all(),
                'keywords' => $matchedKeywordPreview->unique()->take(5)->values()->all(),
                'updated_at' => $row->updated_at ? Carbon::parse($row->updated_at)->format('d M Y H:i') : '-',
            ]);
        }

        $allTopicCards = $topicStats->values()
            ->filter(fn (array $topic) => $topic['note_count'] > 0)
            ->map(function (array $topic) use ($notesFilledCount) {
                arsort($topic['matched_keywords']);
                $topic['coverage_rate'] = $notesFilledCount > 0 ? round(($topic['note_count'] / $notesFilledCount) * 100, 1) : 0;
                $topic['top_keywords'] = collect($topic['matched_keywords'])->take(4)->keys()->values()->all();

                return $topic;
            })
            ->sortByDesc('note_count')
            ->values();

        $topicCards = $allTopicCards
            ->filter(fn (array $topic) => (int) $topic['note_count'] >= $topicMinOccurrences)
            ->values();

        arsort($rawKeywordCounter);
        $topKeywords = collect($rawKeywordCounter)
            ->filter(fn (int $count) => $count >= $keywordMinOccurrences)
            ->take($keywordLimit)
            ->map(fn ($count, $keyword) => [
                'keyword' => $keyword,
                'count' => $count,
            ])
            ->values();

        $dominantTopic = $topicCards->first();
        $sampleNotes = $samples->take($sampleLimit)->values();

        return [
            'status_label' => 'Hanya Tanya Tanya',
            'total_only_inquiry' => $totalOnlyInquiry,
            'notes_filled_count' => $notesFilledCount,
            'notes_coverage_rate' => $totalOnlyInquiry > 0 ? round(($notesFilledCount / $totalOnlyInquiry) * 100, 1) : 0,
            'uncategorized_count' => $uncategorizedCount,
            'categorized_count' => max($notesFilledCount - $uncategorizedCount, 0),
            'dominant_topic' => $dominantTopic,
            'topic_cards' => $topicCards,
            'topic_cards_all_total' => $allTopicCards->count(),
            'topic_cards_total' => $topicCards->count(),
            'topic_cards_min_occurrences' => $topicMinOccurrences,
            'top_keywords' => $topKeywords,
            'top_keywords_total' => count($rawKeywordCounter),
            'top_keywords_filtered_total' => $topKeywords->count(),
            'top_keywords_limit' => $keywordLimit,
            'top_keywords_min_occurrences' => $keywordMinOccurrences,
            'sample_notes' => $sampleNotes,
            'sample_notes_total' => $notesFilledCount,
            'sample_notes_limit' => $sampleLimit,
            'has_data' => $notesFilledCount > 0,
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
            return round($this->deltaPercent($current, $previous), 1);
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

    private function buildDeltaMetric(int|float $current, int|float $previous, bool $roundPercent = true): array
    {
        $delta = $current - $previous;
        $deltaPercent = $this->deltaPercent($current, $previous);

        return [
            'current' => $current,
            'previous' => $previous,
            'delta' => $delta,
            'delta_percent' => $roundPercent ? round($deltaPercent, 1) : $deltaPercent,
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
        ];
    }

    private function deltaPercent(int|float $current, int|float $previous): float
    {
        if ((float) $previous === 0.0) {
            return (float) $current > 0 ? 100.0 : 0.0;
        }

        return max(-100.0, min((($current - $previous) / $previous) * 100, 100.0));
    }

    private function findLargestDeltaItem(array $currentMap, array $previousMap, string $direction = 'increase'): ?array
    {
        $keys = collect(array_keys($currentMap))
            ->merge(array_keys($previousMap))
            ->unique()
            ->values();

        $candidates = $keys->map(function ($key) use ($currentMap, $previousMap) {
            $current = (int) ($currentMap[$key] ?? 0);
            $previous = (int) ($previousMap[$key] ?? 0);

            return [
                'name' => (string) $key,
                'current' => $current,
                'previous' => $previous,
                'delta' => $current - $previous,
            ];
        });

        $filtered = $direction === 'decrease'
            ? $candidates->filter(fn (array $item) => $item['delta'] < 0)->sortBy('delta')
            : $candidates->filter(fn (array $item) => $item['delta'] > 0)->sortByDesc('delta');

        return $filtered->first();
    }

    private function onlyInquiryStatusAliases(): array
    {
        return ['Hanya Tanya Tanya'];
    }

    private function onlyInquiryTopicDefinitions(): array
    {
        return [
            [
                'key' => 'harga_budget',
                'label' => 'Harga & Budget',
                'icon' => 'sell',
                'accent' => 'rose',
                'keywords' => ['harga', 'budget', 'biaya', 'murah', 'mahal', 'promo', 'diskon', 'estimasi', 'quotation', 'penawaran', 'per meter', 'permeter', 'meteran'],
            ],
            [
                'key' => 'material_bahan',
                'label' => 'Material & Bahan',
                'icon' => 'category',
                'accent' => 'sky',
                'keywords' => ['bahan', 'material', 'multiplek', 'plywood', 'pvc', 'hpl', 'aluminium', 'almunium', 'kaca', 'duco', 'mfc', 'mdf', 'kayu', 'besi', 'marmer', 'granit'],
            ],
            [
                'key' => 'desain_model',
                'label' => 'Desain & Model',
                'icon' => 'architecture',
                'accent' => 'violet',
                'keywords' => ['desain', 'design', 'model', 'minimalis', 'modern', 'klasik', 'layout', 'konsep', 'custom', 'gambar', 'warna', 'motif'],
            ],
            [
                'key' => 'ukuran_spesifikasi',
                'label' => 'Ukuran & Spesifikasi',
                'icon' => 'tag',
                'accent' => 'amber',
                'keywords' => ['ukuran', 'dimensi', 'lebar', 'tinggi', 'panjang', 'detail ukuran', 'spesifikasi', 'size', 'cm', 'meter'],
            ],
            [
                'key' => 'survey_lokasi',
                'label' => 'Survey & Lokasi',
                'icon' => 'location_on',
                'accent' => 'emerald',
                'keywords' => ['survey', 'survei', 'lokasi', 'alamat', 'kunjungan', 'visit', 'cek lokasi', 'ukur lokasi'],
            ],
            [
                'key' => 'jadwal_waktu',
                'label' => 'Jadwal & Waktu',
                'icon' => 'schedule',
                'accent' => 'cyan',
                'keywords' => ['jadwal', 'kapan', 'deadline', 'estimasi waktu', 'lama', 'cepat', 'proses', 'pengerjaan', 'hari', 'minggu', 'bulan'],
            ],
            [
                'key' => 'pembayaran',
                'label' => 'Pembayaran',
                'icon' => 'save',
                'accent' => 'indigo',
                'keywords' => ['dp', 'termin', 'pelunasan', 'pembayaran', 'cash', 'transfer', 'cicilan', 'invoice'],
            ],
        ];
    }

    private function emptyOnlyInquiryAnalysis(): array
    {
        return [
            'status_label' => 'Hanya Tanya Tanya',
            'total_only_inquiry' => 0,
            'notes_filled_count' => 0,
            'notes_coverage_rate' => 0,
            'uncategorized_count' => 0,
            'categorized_count' => 0,
            'dominant_topic' => null,
            'topic_cards' => collect(),
            'topic_cards_all_total' => 0,
            'topic_cards_total' => 0,
            'topic_cards_min_occurrences' => 4,
            'top_keywords' => collect(),
            'top_keywords_total' => 0,
            'top_keywords_filtered_total' => 0,
            'top_keywords_limit' => 24,
            'top_keywords_min_occurrences' => 6,
            'sample_notes' => collect(),
            'sample_notes_total' => 0,
            'sample_notes_limit' => 12,
            'has_data' => false,
        ];
    }

    private function normalizeAnalysisText(?string $value): string
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

    private function tokenizeAnalysisWords(string $normalizedText): array
    {
        if ($normalizedText === '') {
            return [];
        }

        $stopwords = [
            'dan', 'atau', 'yang', 'untuk', 'dengan', 'dari', 'pada', 'agar', 'jadi', 'sudah',
            'belum', 'masih', 'bisa', 'apakah', 'kah', 'nya', 'aja', 'saja', 'minta', 'ingin',
            'mau', 'buat', 'perlu', 'lebih', 'kurang', 'sih', 'nih', 'dong', 'ya', 'yg', 'utk',
            'di', 'ke', 'itu', 'ini', 'karena', 'tentang', 'soal', 'via', 'wa', 'chat', 'konsul',
            'konsultasi', 'tanya', 'tanyatanya', 'only', 'lead', 'admin',
        ];

        return collect(explode(' ', $normalizedText))
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => $token !== '' && strlen($token) >= 3 && ! in_array($token, $stopwords, true))
            ->values()
            ->all();
    }

    private function analysisTextContains(string $normalizedText, string $normalizedKeyword): bool
    {
        if ($normalizedText === '' || $normalizedKeyword === '') {
            return false;
        }

        return str_contains(' ' . $normalizedText . ' ', ' ' . $normalizedKeyword . ' ');
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

    private function countByStatusAliases(Collection $statusDistribution, array $aliases): int
    {
        return (int) $statusDistribution
            ->filter(fn (array $status) => $this->statusNameMatches($status['name'] ?? '', $aliases))
            ->sum('count');
    }

    private function resolveStatusId(array $aliases): ?int
    {
        return $this->resolveStatusIds($aliases)->first();
    }

    private function resolveStatusIds(array $aliases): Collection
    {
        static $allStatuses = null;

        if ($allStatuses === null) {
            $allStatuses = StatusCategory::query()->get(['id', 'name']);
        }

        $normalizedAliases = collect($aliases)
            ->filter()
            ->map(fn (string $alias) => $this->normalizeStatusName($alias))
            ->unique()
            ->values();

        if ($normalizedAliases->isEmpty()) {
            return collect();
        }

        return $allStatuses
            ->filter(fn (StatusCategory $status) => $normalizedAliases->contains($this->normalizeStatusName($status->name)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    private function statusNameMatches(?string $statusName, array $aliases): bool
    {
        $normalizedStatus = $this->normalizeStatusName($statusName);

        return collect($aliases)
            ->filter()
            ->map(fn (string $alias) => $this->normalizeStatusName($alias))
            ->contains($normalizedStatus);
    }

    private function normalizeStatusName(?string $value): string
    {
        return (string) Str::of((string) $value)
            ->lower()
            ->ascii()
            ->replace(['/', '-', '_'], ' ')
            ->squish();
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
        $qualifiedColumn = str_contains($column, '.') ? $column : $this->consultationColumn($column);

        $query->whereRaw("LOWER(TRIM(COALESCE({$qualifiedColumn}, ''))) = ?", [mb_strtolower(PendingConfirmation::LABEL)]);
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

    private function consultationColumn(string $column): string
    {
        return 'consultations.' . $column;
    }
}
