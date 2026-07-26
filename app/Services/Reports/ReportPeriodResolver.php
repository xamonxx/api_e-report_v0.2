<?php

namespace App\Services\Reports;

use Carbon\Carbon;

class ReportPeriodResolver
{
    public function resolve(array $filters): array
    {
        $type = $filters['period_type'] ?? 'monthly';

        return match ($type) {
            'weekly' => $this->resolveWeekly($filters),
            'yearly' => $this->resolveYearly($filters),
            'custom' => $this->resolveCustom($filters),
            default => $this->resolveMonthly($filters),
        };
    }

    private function resolveWeekly(array $filters): array
    {
        $anchor = isset($filters['week_date'])
            ? Carbon::parse($filters['week_date'])
            : now();

        $start = $anchor->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $anchor->copy()->endOfWeek(Carbon::SUNDAY)->endOfDay();
        $previousStart = $start->copy()->subWeek();
        $previousEnd = $end->copy()->subWeek();

        return [
            'type' => 'weekly',
            'start' => $start,
            'end' => $end,
            'anchor_date' => $anchor->toDateString(),
            'month' => $start->month,
            'year' => $start->year,
            'label' => sprintf(
                'Minggu %s - %s',
                $start->translatedFormat('d M Y'),
                $end->translatedFormat('d M Y')
            ),
            'short_label' => $start->translatedFormat('d M') . ' - ' . $end->translatedFormat('d M Y'),
            'comparison_start' => $previousStart,
            'comparison_end' => $previousEnd,
            'comparison_label' => sprintf(
                'Minggu %s - %s',
                $previousStart->translatedFormat('d M Y'),
                $previousEnd->translatedFormat('d M Y')
            ),
        ];
    }

    private function resolveMonthly(array $filters): array
    {
        $month = (int) ($filters['month'] ?? now()->month);
        $year = (int) ($filters['year'] ?? now()->year);

        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $previousStart = $start->copy()->subMonth()->startOfMonth();
        $previousEnd = $start->copy()->subMonth()->endOfMonth();

        return [
            'type' => 'monthly',
            'start' => $start,
            'end' => $end,
            'anchor_date' => $start->toDateString(),
            'month' => $month,
            'year' => $year,
            'label' => $start->translatedFormat('F Y'),
            'short_label' => $start->translatedFormat('M Y'),
            'comparison_start' => $previousStart,
            'comparison_end' => $previousEnd,
            'comparison_label' => $previousStart->translatedFormat('F Y'),
        ];
    }

    private function resolveYearly(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);

        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end = $start->copy()->endOfYear();
        $previousStart = $start->copy()->subYear()->startOfYear();
        $previousEnd = $start->copy()->subYear()->endOfYear();

        return [
            'type' => 'yearly',
            'start' => $start,
            'end' => $end,
            'anchor_date' => $start->toDateString(),
            'month' => null,
            'year' => $year,
            'label' => 'Tahun ' . $year,
            'short_label' => (string) $year,
            'comparison_start' => $previousStart,
            'comparison_end' => $previousEnd,
            'comparison_label' => 'Tahun ' . $previousStart->year,
        ];
    }

    private function resolveCustom(array $filters): array
    {
        $start = Carbon::parse($filters['start_date'])->startOfDay();
        $end = Carbon::parse($filters['end_date'])->endOfDay();

        $days = (int) $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $previousEnd = $start->copy()->subDay()->endOfDay();
        $previousStart = $previousEnd->copy()->subDays($days - 1)->startOfDay();

        return [
            'type' => 'custom',
            'start' => $start,
            'end' => $end,
            'anchor_date' => $start->toDateString(),
            'month' => null,
            'year' => $start->year,
            'label' => sprintf(
                '%s - %s',
                $start->translatedFormat('d M Y'),
                $end->translatedFormat('d M Y')
            ),
            'short_label' => $start->translatedFormat('d M') . ' - ' . $end->translatedFormat('d M Y'),
            'comparison_start' => $previousStart,
            'comparison_end' => $previousEnd,
            'comparison_label' => sprintf(
                '%s - %s',
                $previousStart->translatedFormat('d M Y'),
                $previousEnd->translatedFormat('d M Y')
            ),
        ];
    }
}
