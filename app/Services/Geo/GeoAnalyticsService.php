<?php

namespace App\Services\Geo;

use App\Models\Account;
use App\Models\Consultation;
use App\Models\StatusCategory;
use App\Models\User;
use App\Services\Reports\ReportPeriodResolver;
use App\Support\ConsultationStatusGroups;
use App\Support\PendingConfirmation;
use App\Support\RegionNameMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Geo Analytics Fase 1 — agregasi persebaran konsumen per wilayah untuk peta
 * choropleth. Tidak menyentuh omset/harga/nilai proyek: murni operasional.
 *
 * Memakai ulang, bukan menulis ulang: ReportPeriodResolver (periode termasuk
 * Kustom), Consultation::forUser (akses per role), pola filter account /
 * account_group, RegionNameMatcher (pencocokan wilayah), ConsultationStatusGroups
 * (definisi status). Agregasi memakai GROUP BY di SQL, bukan loop per wilayah.
 */
class GeoAnalyticsService
{
    public function __construct(
        private readonly ReportPeriodResolver $periodResolver,
    ) {
    }

    public function build(User $user, array $filters): array
    {
        $period = $this->periodResolver->resolve($filters);
        $base = $this->scopedQuery($user, $filters, $period);

        $surveyIds = ConsultationStatusGroups::surveyIds();
        $dealIds = ConsultationStatusGroups::dealIds();

        $provinces = $this->provinceAggregation((clone $base), $surveyIds, $dealIds);

        return [
            'period' => [
                'type' => $period['type'],
                'start' => $period['start']->toDateString(),
                'end' => $period['end']->toDateString(),
                'label' => $period['label'],
            ],
            'kpi' => $this->kpi((clone $base), $provinces, $surveyIds, $dealIds),
            'statusBreakdown' => $this->statusBreakdown((clone $base)),
            'provinces' => $provinces['rows'],
            'unlocatedProvince' => $provinces['unlocated'],
            'unmatchedRegions' => $provinces['unmatched'],
            'cities' => $this->cityRanking((clone $base), $filters, $surveyIds, $dealIds),
            'accountRanking' => $this->accountRanking((clone $base), $surveyIds, $dealIds),
            'productByRegion' => $this->productByRegion((clone $base)),
        ];
    }

    /**
     * Query dasar: konsultasi milik user, dalam periode, sesuai filter akun /
     * grup akun / status / kategori kebutuhan / provinsi.
     */
    private function scopedQuery(User $user, array $filters, array $period): Builder
    {
        // Kolom di-qualify ke `consultations.` karena beberapa agregasi
        // meng-join accounts/needs_categories yang punya kolom senama.
        $query = Consultation::query()
            ->forUser($user)
            ->whereBetween('consultations.consultation_date', [
                $period['start']->toDateString(),
                $period['end']->toDateString(),
            ]);

        if ($user->isSuperAdmin() && ! empty($filters['account'])) {
            $query->where('consultations.account_id', (int) $filters['account']);
        }

        if ($user->isSuperAdmin() && ! empty($filters['account_group'])) {
            $query->whereIn(
                'consultations.account_id',
                Account::query()->where('account_group', $filters['account_group'])->select('id')
            );
        }

        if (! empty($filters['status'])) {
            $query->where('consultations.status_category_id', (int) $filters['status']);
        }

        if (! empty($filters['needs_category'])) {
            $query->where('consultations.needs_category_id', (int) $filters['needs_category']);
        }

        // Filter provinsi: terima region_id (hasil normalisasi) maupun nama
        // mentah. Dicocokkan di PHP karena beberapa ejaan bisa memetakan ke
        // region yang sama.
        if (! empty($filters['province'])) {
            $wanted = RegionNameMatcher::normalize((string) $filters['province']);
            $names = $this->provinceNamesForRegion($wanted);

            if ($names->isNotEmpty()) {
                $query->whereIn('consultations.province', $names->all());
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query;
    }

    /**
     * Nama-nama `province` mentah di DB yang memetakan ke satu region_id.
     */
    private function provinceNamesForRegion(string $regionId): Collection
    {
        return Consultation::query()
            ->select('province')
            ->distinct()
            ->pluck('province')
            ->filter(function (?string $name) use ($regionId) {
                $match = RegionNameMatcher::matchProvince($name);

                return $match !== null && $match['region_id'] === $regionId;
            })
            ->values();
    }

    private function kpi(Builder $base, array $provinces, array $surveyIds, array $dealIds): array
    {
        $total = (clone $base)->count();
        $surveys = $surveyIds ? (clone $base)->whereIn('status_category_id', $surveyIds)->count() : 0;
        $deals = $dealIds ? (clone $base)->whereIn('status_category_id', $dealIds)->count() : 0;
        $customers = (clone $base)->whereNotNull('phone')->distinct()->count('phone');

        return [
            'total_consultations' => $total,
            'total_customers' => $customers,
            'total_surveys' => $surveys,
            'total_deals' => $deals,
            'closing_rate' => $total > 0 ? round(($deals / $total) * 100, 1) : 0.0,
            'survey_rate' => $total > 0 ? round(($surveys / $total) * 100, 1) : 0.0,
            'active_regions' => count($provinces['rows']),
            'unlocated' => $provinces['unlocated'],
        ];
    }

    /**
     * Jumlah per status_categories, nama + warna dari master data (bukan
     * hardcode), diurutkan `sort_order`.
     */
    private function statusBreakdown(Builder $base): Collection
    {
        $counts = (clone $base)
            ->selectRaw('status_category_id, COUNT(*) as total')
            ->groupBy('status_category_id')
            ->pluck('total', 'status_category_id');

        return StatusCategory::query()
            ->orderBy('sort_order')
            ->get(['id', 'name', 'color'])
            ->map(fn (StatusCategory $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'count' => (int) ($counts[$s->id] ?? 0),
            ])
            ->filter(fn (array $row) => $row['count'] > 0)
            ->values();
    }

    /**
     * Agregasi per provinsi. Baris DB digabung ke region_id kanonik; placeholder
     * dihitung terpisah (`unlocated`), nama tak dikenali dikumpulkan
     * (`unmatched`) — tidak hilang diam-diam.
     *
     * @return array{rows: list<array>, unlocated: int, unmatched: list<array>}
     */
    private function provinceAggregation(Builder $base, array $surveyIds, array $dealIds): array
    {
        $rows = (clone $base)
            ->selectRaw('province, COUNT(*) as total')
            ->selectRaw($this->sumInIds($surveyIds) . ' as surveys')
            ->selectRaw($this->sumInIds($dealIds) . ' as deals')
            ->groupBy('province')
            ->get();

        $regions = [];
        $unlocated = 0;
        $unmatched = [];

        foreach ($rows as $row) {
            if (PendingConfirmation::matches($row->province)) {
                $unlocated += (int) $row->total;
                continue;
            }

            $match = RegionNameMatcher::matchProvince($row->province);

            if ($match === null) {
                $unmatched[] = ['name' => (string) $row->province, 'total' => (int) $row->total];
                continue;
            }

            $id = $match['region_id'];
            $regions[$id] ??= [
                'region_id' => $id,
                'name' => $match['name'],
                'total' => 0,
                'surveys' => 0,
                'deals' => 0,
            ];
            $regions[$id]['total'] += (int) $row->total;
            $regions[$id]['surveys'] += (int) $row->surveys;
            $regions[$id]['deals'] += (int) $row->deals;
        }

        $grandTotal = array_sum(array_column($regions, 'total')) ?: 1;

        $result = collect($regions)
            ->map(function (array $r) use ($grandTotal) {
                $r['closing_rate'] = $r['total'] > 0 ? round(($r['deals'] / $r['total']) * 100, 1) : 0.0;
                $r['share'] = round(($r['total'] / $grandTotal) * 100, 1);

                return $r;
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        usort($unmatched, fn ($a, $b) => $b['total'] <=> $a['total']);

        return ['rows' => $result, 'unlocated' => $unlocated, 'unmatched' => $unmatched];
    }

    /**
     * Peringkat kota. Ikut terfilter bila satu provinsi dipilih. Placeholder
     * dilewati; kota tak dikenali tetap ditampilkan apa adanya.
     */
    /**
     * Semua kota berdata (bukan hanya top-N) dengan `region_id` untuk join ke
     * peta kabupaten. Nama tak dikenali tetap ditampilkan; frontend memotong
     * top-N untuk tabel peringkat. Baris DB digabung per region_id (beberapa
     * ejaan bisa memetakan ke kota yang sama).
     */
    private function cityRanking(Builder $base, array $filters, array $surveyIds, array $dealIds): Collection
    {
        $rows = (clone $base)
            ->selectRaw('city, COUNT(*) as total')
            ->selectRaw($this->sumInIds($surveyIds) . ' as surveys')
            ->selectRaw($this->sumInIds($dealIds) . ' as deals')
            ->groupBy('city')
            ->get()
            ->reject(fn ($row) => PendingConfirmation::matches($row->city));

        $merged = [];
        foreach ($rows as $row) {
            $match = RegionNameMatcher::matchCity($row->city);
            $id = $match['region_id'] ?? RegionNameMatcher::normalize($row->city);

            $merged[$id] ??= [
                'region_id' => $id,
                'name' => $match['name'] ?? (string) $row->city,
                'province' => $match['province'] ?? null,
                'total' => 0,
                'surveys' => 0,
                'deals' => 0,
            ];
            $merged[$id]['total'] += (int) $row->total;
            $merged[$id]['surveys'] += (int) $row->surveys;
            $merged[$id]['deals'] += (int) $row->deals;
        }

        return collect($merged)
            ->map(function (array $c) {
                $c['closing_rate'] = $c['total'] > 0 ? round(($c['deals'] / $c['total']) * 100, 1) : 0.0;

                return $c;
            })
            ->sortByDesc('total')
            ->values();
    }

    /**
     * Top 10 akun ("marketing" = akun/cabang; created_by tidak andal untuk
     * ranking per admin).
     */
    private function accountRanking(Builder $base, array $surveyIds, array $dealIds, int $limit = 10): Collection
    {
        return (clone $base)
            ->join('accounts', 'accounts.id', '=', 'consultations.account_id')
            ->selectRaw('accounts.id, accounts.name, COUNT(*) as total')
            ->selectRaw($this->sumInIds($surveyIds, 'consultations.status_category_id') . ' as surveys')
            ->selectRaw($this->sumInIds($dealIds, 'consultations.status_category_id') . ' as deals')
            ->groupBy('accounts.id', 'accounts.name')
            ->get()
            ->map(function ($row) {
                $total = (int) $row->total;
                $deals = (int) $row->deals;

                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'total' => $total,
                    'surveys' => (int) $row->surveys,
                    'deals' => $deals,
                    'closing_rate' => $total > 0 ? round(($deals / $total) * 100, 1) : 0.0,
                ];
            })
            ->sortByDesc('total')
            ->take($limit)
            ->values();
    }

    /**
     * Kebutuhan (produk) terpopuler per provinsi — menjawab "produk apa paling
     * diminati di setiap wilayah". Hanya kategori teratas per region.
     */
    private function productByRegion(Builder $base): Collection
    {
        $rows = (clone $base)
            ->join('needs_categories', 'needs_categories.id', '=', 'consultations.needs_category_id')
            ->selectRaw('consultations.province, needs_categories.name as product, COUNT(*) as total')
            ->groupBy('consultations.province', 'needs_categories.name')
            ->get();

        $byRegion = [];
        foreach ($rows as $row) {
            $match = RegionNameMatcher::matchProvince($row->province);
            if ($match === null) {
                continue;
            }

            $id = $match['region_id'];
            $total = (int) $row->total;
            if (! isset($byRegion[$id]) || $total > $byRegion[$id]['total']) {
                $byRegion[$id] = [
                    'region_id' => $id,
                    'name' => $match['name'],
                    'top_product' => (string) $row->product,
                    'total' => $total,
                ];
            }
        }

        return collect($byRegion)->sortByDesc('total')->values();
    }

    /**
     * Ekspresi SUM(CASE WHEN status IN (...) THEN 1 ELSE 0 END). Id di-cast ke
     * int di sini, aman dari injeksi. List kosong menghasilkan 0.
     */
    private function sumInIds(array $ids, string $column = 'status_category_id'): string
    {
        if (empty($ids)) {
            return '0';
        }

        $list = implode(',', array_map('intval', $ids));

        return "SUM(CASE WHEN {$column} IN ({$list}) THEN 1 ELSE 0 END)";
    }
}
