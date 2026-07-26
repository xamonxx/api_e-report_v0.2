<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Consultation;
use App\Models\NeedsCategory;
use App\Models\ReportAttendance;
use App\Models\StatusCategory;
use App\Models\User;
use App\Support\ConsultationStatusGroups;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    public function getForUser(User $user): array
    {
        if ($user->isSuperAdmin()) {
            return $this->superAdminDashboard();
        }

        return $this->adminDashboard($user);
    }

    public function superAdminDashboard(): array
    {
        $cacheKey = 'dashboard:super_admin:' . auth()->id();

        return Cache::remember($cacheKey, 15 * 60, function () {
            return [
                'stats' => $this->buildOverviewStats(),
                'recent_consultations' => $this->getRecentConsultations(),
                'upcoming' => $this->getUpcoming(),
                'accounts' => $this->buildAccountRanking(),
                'status_distribution' => StatusCategory::withCount('consultations')
                    ->orderBy('sort_order')->get(),
                'needs_distribution' => NeedsCategory::withCount('consultations')
                    ->having('consultations_count', '>', 0)
                    ->orderByDesc('consultations_count')->get(),
                'admin_attendances' => $this->buildAdminAttendances(),
                'top_admin' => $this->findTopAdmin(),
            ];
        });
    }

    public function adminDashboard(User $user): array
    {
        $accountId = $user->account_id;

        if (!$accountId) {
            abort(403, 'Akun belum di-assign ke user Anda. Hubungi Super Admin.');
        }

        $cacheKey = "dashboard:admin:{$accountId}";

        return Cache::remember($cacheKey, 5 * 60, function () use ($accountId, $user) {
            $dealIds = $this->statusIds('deal');
            $pendingIds = $this->statusIds('pending');
            $cancelledIds = $this->statusIds('cancelled');

            $totalLeads = Consultation::where('account_id', $accountId)->count();
            $totalDeals = $dealIds
                ? Consultation::where('account_id', $accountId)->whereIn('status_category_id', $dealIds)->count()
                : 0;
            $conversionRate = $totalLeads > 0 ? round(($totalDeals / $totalLeads) * 100, 1) : 0;

            return [
                'stats' => [
                    'total_leads' => $totalLeads,
                    'pending_leads' => $pendingIds
                        ? Consultation::where('account_id', $accountId)->whereIn('status_category_id', $pendingIds)->count()
                        : 0,
                    'completed_this_month' => Consultation::where('account_id', $accountId)
                        ->whereBetween('consultation_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])
                        ->count(),
                    'cancelled_leads' => $cancelledIds
                        ? Consultation::where('account_id', $accountId)->whereIn('status_category_id', $cancelledIds)->count()
                        : 0,
                    'conversion_rate' => $conversionRate,
                    'pending_surveys' => $this->countRequestSurveys($accountId),
                ],
                'recent_consultations' => $this->getRecentConsultations($accountId),
                'upcoming' => $this->getUpcoming($accountId),
                'status_distribution' => StatusCategory::withCount(['consultations' => fn($q) => $q->where('account_id', $accountId)])
                    ->orderBy('sort_order')->get(),
                'needs_distribution' => NeedsCategory::withCount(['consultations' => fn($q) => $q->where('account_id', $accountId)])
                    ->having('consultations_count', '>', 0)
                    ->orderByDesc('consultations_count')->get(),
                'account' => $user->account,
            ];
        });
    }

    private function buildOverviewStats(): array
    {
        $totalLeads = Consultation::count();
        $dealIds = $this->statusIds('deal');
        $totalDeals = $dealIds ? Consultation::whereIn('status_category_id', $dealIds)->count() : 0;
        $avgConversion = $totalLeads > 0 ? round(($totalDeals / $totalLeads) * 100, 1) : 0;

        $now = Carbon::now();
        $thisMonth = Consultation::whereBetween('consultation_date', [
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ])->count();

        $prev = $now->copy()->subMonth();
        $lastMonth = Consultation::whereBetween('consultation_date', [
            $prev->copy()->startOfMonth()->toDateString(),
            $prev->copy()->endOfMonth()->toDateString(),
        ])->count();

        $growthPercent = $lastMonth > 0
            ? max(-100, min(round((($thisMonth - $lastMonth) / $lastMonth) * 100, 1), 100))
            : ($thisMonth > 0 ? 100 : 0);

        return [
            'total_leads' => $totalLeads,
            'pending_leads' => ($ids = $this->statusIds('pending')) ? Consultation::whereIn('status_category_id', $ids)->count() : 0,
            'completed_this_month' => $thisMonth,
            'cancelled_leads' => ($ids = $this->statusIds('cancelled')) ? Consultation::whereIn('status_category_id', $ids)->count() : 0,
            'total_accounts' => Account::count(),
            'active_accounts' => Account::has('admins')->count(),
            'avg_conversion' => $avgConversion,
            'growth_percent' => $growthPercent,
            'total_request_surveys' => ($surveyIds = $this->statusIds('survey')) ? Consultation::whereIn('status_category_id', $surveyIds)->count() : 0,
        ];
    }

    public function getRecentConsultations(?int $accountId = null, int $limit = 5): array
    {
        $query = Consultation::with(['account:id,name', 'needsCategory:id,name', 'statusCategory:id,name,css_class', 'creator:id,name'])
            ->latest();

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        return $query->take($limit)->get()->map(fn ($c) => [
            'id' => $c->id,
            'consultation_id' => $c->consultation_id,
            'client_name' => $c->client_name,
            'phone' => $c->phone,
            'type' => $c->needsCategory?->name,
            'status' => $c->statusCategory?->name,
            'status_css_class' => $c->statusCategory?->css_class,
            'scheduled_at' => $c->consultation_date?->toIsoString(),
            'created_at' => $c->created_at?->toIsoString(),
            'account_name' => $c->account?->name,
        ])->toArray();
    }

    public function getUpcoming(?int $accountId = null, int $limit = 5): array
    {
        $query = Consultation::with(['account:id,name'])
            ->whereNotNull('consultation_date')
            ->whereDate('consultation_date', '>=', now()->startOfDay())
            ->when($this->statusIds('cancelled'), fn ($q, $ids) => $q->whereNotIn('status_category_id', $ids))
            ->orderBy('consultation_date', 'asc');

        if ($accountId) {
            $query->where('account_id', $accountId);
        }

        return $query->take($limit)->get()->map(fn ($c) => [
            'id' => $c->id,
            'consultation_id' => $c->consultation_id,
            'client_name' => $c->client_name,
            'phone' => $c->phone,
            'scheduled_at' => $c->consultation_date?->toIsoString(),
            'account_name' => $c->account?->name,
            'duration_minutes' => 60,
        ])->toArray();
    }

    private function findTopAdmin(): ?array
    {
        $dealIds = $this->statusIds('deal');
        if (!$dealIds) return null;

        $topAdmin = User::where('role', 'admin')
            ->withCount(['consultations as deal_count' => fn($q) => $q->whereIn('status_category_id', $dealIds)])
            ->orderByDesc('deal_count')->first();

        return ($topAdmin && $topAdmin->deal_count > 0)
            ? ['id' => $topAdmin->id, 'name' => $topAdmin->name, 'deal_count' => $topAdmin->deal_count]
            : null;
    }

    private function buildAccountRanking(): array
    {
        $dealIds = $this->statusIds('deal');
        $query = Account::withCount(['consultations']);

        if ($dealIds) {
            $query->withCount(['consultations as deals_count' => fn($q) => $q->whereIn('status_category_id', $dealIds)]);
        }

        return $query->with(['admins:id,name,account_id'])
            ->get()
            ->sortByDesc(fn ($a) => $a->conversion_rate)
            ->values()
            ->take(10)
            ->map(fn ($a) => [
                'id' => $a->id,
                'name' => $a->name,
                'total_leads' => $a->consultations_count,
                'deals' => $a->deals_count ?? 0,
                'conversion_rate' => $a->conversion_rate,
                'admins' => $a->admins->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->toArray(),
            ])
            ->toArray();
    }

    private function buildAdminAttendances(): array
    {
        $todayStr = Carbon::today()->format('Y-m-d');

        return User::where('role', 'admin')
            ->with(['account:id,name', 'reportAttendances' => fn($q) => $q->where('report_date', $todayStr)])
            ->get()
            ->map(fn ($admin) => [
                'id' => $admin->id,
                'name' => $admin->name,
                'account_name' => $admin->account?->name,
                'has_reported' => $admin->reportAttendances->isNotEmpty(),
                'reported_at' => $admin->reportAttendances->first()?->created_at?->toIsoString(),
                'report_category' => $admin->reportAttendances->first()?->report_category,
            ])
            ->toArray();
    }

    private function countRequestSurveys(int $accountId): int
    {
        $surveyIds = $this->statusIds('survey');

        if (! $surveyIds) return 0;

        return Consultation::query()
            ->where('account_id', $accountId)
            ->whereIn('status_category_id', $surveyIds)
            ->count();
    }

    /**
     * ID status untuk sebuah grup, didelegasikan ke satu sumber kebenaran
     * bersama AnalyticsReportService & Geo Analytics.
     *
     * @return list<int>
     */
    private function statusIds(string $group): array
    {
        return ConsultationStatusGroups::ids($group);
    }

    public function invalidateCache(User $user): void
    {
        Cache::forget('dashboard:super_admin:' . $user->id);
        if ($user->account_id) {
            Cache::forget("dashboard:admin:{$user->account_id}");
        }
    }
}