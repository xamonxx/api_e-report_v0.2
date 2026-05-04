<?php

namespace App\Services;

use App\Models\ConsultationNote;
use App\Models\Reminder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

class NotificationSummaryService
{
    public function getForUser(User $user): array
    {
        return Cache::remember(
            $this->detailCacheKey($user->id),
            now()->addMinutes(2),
            fn () => $this->buildSummary($user)
        );
    }

    public function getCountsForUser(User $user): array
    {
        return Cache::remember(
            $this->countsCacheKey($user->id),
            now()->addMinutes(2),
            fn () => $this->buildCounts($user)
        );
    }

    public function forgetForUser(int $userId): void
    {
        Cache::forget($this->detailCacheKey($userId));
        Cache::forget($this->countsCacheKey($userId));
    }

    private function buildSummary(User $user): array
    {
        $counts = $this->buildCounts($user);

        $unreadNotes = ConsultationNote::query()
            ->with(['user:id,name', 'consultation:id,client_name'])
            ->where('is_read', false)
            ->where('user_id', '!=', $user->id)
            ->whereHas('consultation', fn ($query) => $query->forUser($user))
            ->latest()
            ->take(5)
            ->get();

        $activeReminders = Reminder::query()
            ->forUser($user)
            ->where('is_read', false)
            ->with(['consultation:id,client_name', 'user:id,name'])
            ->orderBy('remind_at')
            ->take(5)
            ->get();

        return [
            ...$counts,
            'activeReminders' => $activeReminders,
            'unreadNotes' => $unreadNotes,
        ];
    }

    private function buildCounts(User $user): array
    {
        $unreadNotesCount = ConsultationNote::query()
            ->where('is_read', false)
            ->where('user_id', '!=', $user->id)
            ->whereHas('consultation', fn ($query) => $query->forUser($user))
            ->count();

        $upcomingRemindersCount = Reminder::query()
            ->forUser($user)
            ->where('is_read', false)
            ->where('remind_at', '<=', Carbon::now()->addMinutes(30))
            ->count();

        return [
            'unreadNotesCount' => $unreadNotesCount,
            'upcomingRemindersCount' => $upcomingRemindersCount,
            'initialTotalAlerts' => $unreadNotesCount + $upcomingRemindersCount,
        ];
    }

    private function detailCacheKey(int $userId): string
    {
        return "api_notif:detail:{$userId}";
    }

    private function countsCacheKey(int $userId): string
    {
        return "api_notif:counts:{$userId}";
    }
}
