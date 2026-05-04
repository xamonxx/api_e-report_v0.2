<?php

namespace App\Http\Controllers;

use App\Http\Requests\AnalyticsReportRequest;
use App\Models\Account;
use App\Services\Reports\AnalyticsReportService;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function index(AnalyticsReportRequest $request, AnalyticsReportService $reportService)
    {
        $user = $request->user();
        $report = $reportService->buildForUser($user, $request->validated());

        $accounts = $user->isSuperAdmin()
            ? Account::query()->orderBy('name')->get(['id', 'name'])
            : collect();

        $months = collect(range(1, 12))->map(fn ($month) => [
            'value' => $month,
            'label' => Carbon::create()->month($month)->translatedFormat('F'),
        ]);

        $years = collect(range(now()->year - 4, now()->year + 1));
        $periodTypes = collect([
            ['value' => 'weekly', 'label' => 'Mingguan'],
            ['value' => 'monthly', 'label' => 'Bulanan'],
            ['value' => 'yearly', 'label' => 'Tahunan'],
        ]);

        return view('analytics.index', [
            ...$report,
            'accounts' => $accounts,
            'months' => $months,
            'years' => $years,
            'periodTypes' => $periodTypes,
            'exportQuery' => $request->validated(),
        ]);
    }
}
