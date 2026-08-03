<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\Reminder;
use App\Models\ReportAttendance;
use App\Models\Survey;
use App\Models\SurveyReschedule;
use App\Models\SurveyStatus;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Policies\ConsultationNotePolicy;
use App\Policies\ConsultationPolicy;
use App\Policies\ReminderPolicy;
use App\Services\NotificationSummaryService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        if ($this->app->isProduction() && config('app.debug')) {
            throw new \LogicException('APP_DEBUG must be false in production.');
        }

        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('bug-reports', function (Request $request) {
            $key = 'bug-report:'.$request->ip();

            return [
                Limit::perMinute(5)->by($key),
                Limit::perDay(20)->by($key),
            ];
        });

        Gate::policy(Consultation::class, ConsultationPolicy::class);
        Gate::policy(ConsultationNote::class, ConsultationNotePolicy::class);
        Gate::policy(Reminder::class, ReminderPolicy::class);

        Consultation::observe(AuditObserver::class);
        Survey::observe(AuditObserver::class);
        SurveyStatus::observe(AuditObserver::class);
        SurveyReschedule::observe(AuditObserver::class);

        User::saved(fn () => Cache::forget(User::SUPER_ADMIN_CACHE_KEY));
        User::deleted(fn () => Cache::forget(User::SUPER_ADMIN_CACHE_KEY));

        $clearDashboardCache = function ($model = null) {
            // Clear all super admin dashboard caches (as key includes user ID)
            try {
                foreach (User::cachedSuperAdminIds() as $adminId) {
                    Cache::forget("dashboard:super_admin:{$adminId}");
                }
            } catch (\Throwable $e) {
                Cache::forget('dashboard:super_admin');
            }

            $accountId = null;
            if ($model instanceof Consultation) {
                $accountId = $model->account_id;
            } elseif ($model instanceof ReportAttendance) {
                $accountId = $model->account_id;
            }

            if ($accountId) {
                Cache::forget("dashboard:admin:{$accountId}");
            }

            // Invalidate analytics report cache
            Cache::forever('analytics:last_updated', now()->timestamp);
        };

        Consultation::created($clearDashboardCache);
        Consultation::updated($clearDashboardCache);
        Consultation::deleted($clearDashboardCache);
        ReportAttendance::created($clearDashboardCache);
        ReportAttendance::updated($clearDashboardCache);
        ReportAttendance::deleted($clearDashboardCache);

        $forgetNotificationCaches = function (?Consultation $consultation, ?int $ownerUserId = null) {
            if (! $consultation) {
                return;
            }

            $accountId = $consultation->account_id;
            $users = User::query()
                ->where(function ($query) use ($accountId, $ownerUserId) {
                    $query->where('account_id', $accountId)
                        ->orWhere('role', UserRole::SuperAdmin);

                    if ($ownerUserId) {
                        $query->orWhere('id', $ownerUserId);
                    }
                })
                ->pluck('id')
                ->unique();

            $notificationSummaryService = app(NotificationSummaryService::class);

            foreach ($users as $userId) {
                $notificationSummaryService->forgetForUser($userId);
            }
        };

        ConsultationNote::created(function (ConsultationNote $note) use ($forgetNotificationCaches) {
            $forgetNotificationCaches(
                Consultation::query()->find($note->consultation_id),
                $note->user_id
            );
        });

        ConsultationNote::updated(function (ConsultationNote $note) use ($forgetNotificationCaches) {
            $forgetNotificationCaches(
                Consultation::query()->find($note->consultation_id),
                $note->user_id
            );
        });

        ConsultationNote::deleted(function (ConsultationNote $note) use ($forgetNotificationCaches) {
            $forgetNotificationCaches(
                Consultation::query()->find($note->consultation_id),
                $note->user_id
            );
        });

        Reminder::created(function (Reminder $reminder) use ($forgetNotificationCaches) {
            $forgetNotificationCaches($reminder->consultation, $reminder->user_id);
        });

        Reminder::updated(function (Reminder $reminder) use ($forgetNotificationCaches) {
            $forgetNotificationCaches($reminder->consultation, $reminder->user_id);
        });

        Reminder::deleted(function (Reminder $reminder) use ($forgetNotificationCaches) {
            $forgetNotificationCaches($reminder->consultation, $reminder->user_id);
        });

        View::composer('layouts.app', function ($view) {
            if (auth()->check()) {
                $summary = app(NotificationSummaryService::class)->getCountsForUser(auth()->user());
                $view->with($summary);

                return;
            }

            $view->with([
                'unreadNotesCount' => 0,
                'upcomingRemindersCount' => 0,
                'initialTotalAlerts' => 0,
            ]);
        });
    }
}
