<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Survey;
use App\Models\SurveyStatus;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SurveyorAssignmentSuggestionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function suggest(Survey $survey, Carbon $targetAt, int $limit = 5): array
    {
        $survey->loadMissing('consultation:id,province,city');

        $targetProvince = trim((string) ($survey->consultation?->province ?? ''));
        $targetCity = trim((string) ($survey->consultation?->city ?? ''));
        $targetTime = $targetAt->format('H:i');
        $date = $targetAt->toDateString();

        $surveyors = User::query()
            ->where('role', UserRole::Surveyor->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $busy = Survey::query()
            ->whereKeyNot($survey->id)
            ->whereDate('scheduled_at', $date)
            ->whereIn('state', [Survey::STATE_SCHEDULED, Survey::STATE_IN_PROGRESS])
            ->get(['surveyor_id', 'scheduled_at']);

        $locationStats = $this->locationStats($survey->id, $targetProvince, $targetCity);
        $performanceStats = $this->performanceStats($survey->id);

        return $surveyors
            ->map(function (User $surveyor) use ($busy, $locationStats, $performanceStats, $targetProvince, $targetCity, $targetTime) {
                $surveyorBusy = $busy->where('surveyor_id', $surveyor->id)->values();
                $busyTimes = $surveyorBusy
                    ->map(fn (Survey $row) => Carbon::parse($row->scheduled_at)->format('H:i'))
                    ->unique()
                    ->sort()
                    ->values();
                $hasConflict = $busyTimes->contains($targetTime);
                $dayLoad = $surveyorBusy->count();
                $location = $locationStats->get($surveyor->id, [
                    'province_count' => 0,
                    'city_count' => 0,
                ]);
                $performance = $performanceStats->get($surveyor->id, [
                    'completed_count' => 0,
                    'deal_count' => 0,
                    'deal_rate' => 0.0,
                ]);

                $score = $this->score(
                    hasConflict: $hasConflict,
                    dayLoad: $dayLoad,
                    provinceCount: (int) $location['province_count'],
                    cityCount: (int) $location['city_count'],
                    dealRate: (float) $performance['deal_rate']
                );

                return [
                    'surveyor_id' => (int) $surveyor->id,
                    'surveyor_name' => $surveyor->name,
                    'email' => $surveyor->email,
                    'score' => $score,
                    'is_available' => ! $hasConflict,
                    'has_conflict' => $hasConflict,
                    'day_load' => $dayLoad,
                    'busy_times' => $busyTimes->all(),
                    'province_count' => (int) $location['province_count'],
                    'city_count' => (int) $location['city_count'],
                    'completed_count' => (int) $performance['completed_count'],
                    'deal_count' => (int) $performance['deal_count'],
                    'deal_rate' => (float) $performance['deal_rate'],
                    'reasons' => $this->reasons(
                        hasConflict: $hasConflict,
                        dayLoad: $dayLoad,
                        targetTime: $targetTime,
                        province: $targetProvince,
                        city: $targetCity,
                        provinceCount: (int) $location['province_count'],
                        cityCount: (int) $location['city_count'],
                        completedCount: (int) $performance['completed_count'],
                        dealRate: (float) $performance['deal_rate']
                    ),
                ];
            })
            ->sortBy([
                ['is_available', 'desc'],
                ['score', 'desc'],
                ['day_load', 'asc'],
                ['surveyor_name', 'asc'],
            ])
            ->values()
            ->map(function (array $item, int $index) {
                $item['rank'] = $index + 1;

                return $item;
            })
            ->take($limit)
            ->all();
    }

    private function score(bool $hasConflict, int $dayLoad, int $provinceCount, int $cityCount, float $dealRate): float
    {
        $score = $hasConflict ? 0 : 60;
        $score -= min($dayLoad, 8) * 6;
        $score += min($cityCount, 12) * 2.5;
        $score += min($provinceCount, 20) * 1.2;
        $score += min($dealRate, 80) * 0.25;

        return round(max(0, min(100, $score)), 1);
    }

    /**
     * @return Collection<int, array{province_count: int, city_count: int}>
     */
    private function locationStats(int $currentSurveyId, string $province, string $city): Collection
    {
        if ($province === '' && $city === '') {
            return collect();
        }

        return Survey::query()
            ->join('consultations', 'consultations.id', '=', 'surveys.consultation_id')
            ->whereKeyNot($currentSurveyId)
            ->whereNotNull('surveys.surveyor_id')
            ->whereIn('surveys.state', [Survey::STATE_SCHEDULED, Survey::STATE_IN_PROGRESS, Survey::STATE_COMPLETED])
            ->groupBy('surveys.surveyor_id')
            ->selectRaw('surveys.surveyor_id')
            ->selectRaw(
                'SUM(CASE WHEN LOWER(consultations.province) = ? THEN 1 ELSE 0 END) as province_count',
                [mb_strtolower($province)]
            )
            ->selectRaw(
                'SUM(CASE WHEN LOWER(consultations.city) = ? THEN 1 ELSE 0 END) as city_count',
                [mb_strtolower($city)]
            )
            ->get()
            ->keyBy('surveyor_id')
            ->map(fn ($row) => [
                'province_count' => (int) $row->province_count,
                'city_count' => (int) $row->city_count,
            ]);
    }

    /**
     * @return Collection<int, array{completed_count: int, deal_count: int, deal_rate: float}>
     */
    private function performanceStats(int $currentSurveyId): Collection
    {
        $dealStatusIds = SurveyStatus::query()
            ->get(['id', 'name'])
            ->filter(fn (SurveyStatus $status) => in_array(
                mb_strtolower(trim($status->name)),
                ['deal', 'selesai/deal', 'closing', 'closing deal'],
                true
            ))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $dealList = $dealStatusIds ? implode(',', $dealStatusIds) : '0';

        return collect(DB::table('surveys')
            ->where('id', '<>', $currentSurveyId)
            ->whereNotNull('surveyor_id')
            ->where('state', Survey::STATE_COMPLETED)
            ->groupBy('surveyor_id')
            ->selectRaw('surveyor_id')
            ->selectRaw('COUNT(*) as completed_count')
            ->selectRaw("SUM(CASE WHEN result_status_id IN ({$dealList}) THEN 1 ELSE 0 END) as deal_count")
            ->get())
            ->keyBy('surveyor_id')
            ->map(function ($row) {
                $completed = (int) $row->completed_count;
                $deals = (int) $row->deal_count;

                return [
                    'completed_count' => $completed,
                    'deal_count' => $deals,
                    'deal_rate' => $completed > 0 ? round(($deals / $completed) * 100, 1) : 0.0,
                ];
            });
    }

    /**
     * @return list<string>
     */
    private function reasons(
        bool $hasConflict,
        int $dayLoad,
        string $targetTime,
        string $province,
        string $city,
        int $provinceCount,
        int $cityCount,
        int $completedCount,
        float $dealRate
    ): array {
        $reasons = [];

        if ($hasConflict) {
            $reasons[] = "Bentrok {$targetTime}";
        } elseif ($dayLoad === 0) {
            $reasons[] = 'Kosong tanggal ini';
        } elseif ($dayLoad <= 1) {
            $reasons[] = 'Beban ringan';
        } else {
            $reasons[] = "{$dayLoad} jadwal tanggal ini";
        }

        if ($city !== '' && $cityCount > 0) {
            $reasons[] = "Pernah survey di {$city} {$cityCount}x";
        } elseif ($province !== '' && $provinceCount > 0) {
            $reasons[] = "Pernah survey di {$province} {$provinceCount}x";
        }

        if ($completedCount > 0) {
            $reasons[] = 'Deal rate ' . number_format($dealRate, 1) . '%';
        }

        return array_slice($reasons, 0, 3);
    }
}
