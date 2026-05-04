<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\Consultation;
use App\Models\ConsultationNote;
use App\Models\Reminder;
use App\Models\ReportAttendance;
use App\Observers\AuditObserver;
use App\Policies\ConsultationNotePolicy;
use App\Policies\ConsultationPolicy;
use App\Policies\ReminderPolicy;
use App\Services\NotificationSummaryService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
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
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::policy(Consultation::class, ConsultationPolicy::class);
        Gate::policy(ConsultationNote::class, ConsultationNotePolicy::class);
        Gate::policy(Reminder::class, ReminderPolicy::class);

        Consultation::observe(AuditObserver::class);

        $clearDashboardCache = function ($model = null) {
            Cache::forget('dashboard:super_admin');

            $accountId = null;
            if ($model instanceof Consultation) {
                $accountId = $model->account_id;
            } elseif ($model instanceof ReportAttendance) {
                $accountId = $model->account_id;
            }

            if ($accountId) {
                Cache::forget("dashboard:admin:{$accountId}");
            }
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
            $users = \App\Models\User::query()
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
            $forgetNotificationCaches($note->consultation, $note->user_id);
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
