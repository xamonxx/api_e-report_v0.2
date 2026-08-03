<?php

namespace App\Services;

use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\AttendanceNotification;
use App\Models\Reminder;
use App\Models\SurveyNotification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
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

        $unreadNotes = $this->unreadNotesQuery($user)
            ->with(['user:id,name', 'consultation:id,client_name'])
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
        $unreadNotesCount = $this->unreadNotesQuery($user)->count();

        $upcomingRemindersCount = Reminder::query()
            ->forUser($user)
            ->where('is_read', false)
            ->where('remind_at', '<=', Carbon::now()->addMinutes(30))
            ->count();

        // Ikut di-cache di sini, bukan dihitung ulang tiap request di
        // NotificationController. Polling berjalan tiap 30 detik, jadi query
        // mentah per request langsung menambah beban koneksi DB.
        $unreadSurveysCount = SurveyNotification::query()
            ->where('user_id', $user->id)
            ->whereNull('read_at')
            ->count();

        $unreadAttendancesCount = $user->isSuperAdmin()
            ? AttendanceNotification::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count()
            : 0;

        // Lead yang sudah masuk tahap survey tapi belum pernah diajukan.
        // Hanya relevan untuk admin/super admin - surveyor tidak mengajukan.
        $pendingSurveyRequests = ($user->isAdmin() || $user->isSuperAdmin())
            ? Consultation::query()->forUser($user)->needsSurveyRequest()->count()
            : 0;

        return [
            'unreadNotesCount' => $unreadNotesCount,
            'upcomingRemindersCount' => $upcomingRemindersCount,
            'unreadSurveysCount' => $unreadSurveysCount,
            'unreadAttendancesCount' => $unreadAttendancesCount,
            'pendingSurveyRequests' => $pendingSurveyRequests,
            'initialTotalAlerts' => $unreadNotesCount + $upcomingRemindersCount + $unreadSurveysCount + $unreadAttendancesCount,
        ];
    }

    private function unreadNotesQuery(User $user): Builder
    {
        $query = ConsultationNote::query()
            ->where('is_read', false)
            ->where('user_id', '!=', $user->id);

        if ($user->isSuperAdmin()) {
            return $query;
        }

        if ($user->isAdmin()) {
            return $query->whereHas(
                'consultation',
                fn (Builder $consultationQuery) => $consultationQuery
                    ->where('account_id', $user->account_id)
            );
        }

        // Role lain tidak memiliki percakapan konsultasi dan tidak boleh
        // mengetahui jumlah, pengirim, maupun isi catatan.
        return $query->whereRaw('1 = 0');
    }

    private function detailCacheKey(int $userId): string
    {
        return "api_notif:v2:detail:{$userId}";
    }

    private function countsCacheKey(int $userId): string
    {
        return "api_notif:v2:counts:{$userId}";
    }
}
