<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MasterDataController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\WilayahController;
use App\Http\Controllers\Api\DebugController;
use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1/*
|--------------------------------------------------------------------------
| Sanctum SPA cookie-based auth. Next.js must call:
|   1. GET  /sanctum/csrf-cookie   (sets XSRF-TOKEN cookie)
|   2. POST /api/v1/auth/login     (authenticates, sets session cookie)
*/

Route::prefix('v1')->group(function () {

    // ── Public (guest only) ──────────────────────────────────────
    Route::post('/auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:10,1')
        ->name('api.auth.login');

    // ── Authenticated ────────────────────────────────────────────
    Route::middleware(['web', 'auth'])->group(function () {

        // Auth
        Route::get('/auth/me', [AuthController::class, 'me'])->name('api.auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('api.auth.logout');

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('api.dashboard');

        // Analytics
        Route::get('/analytics', [App\Http\Controllers\Api\AnalyticsController::class, 'index'])
            ->name('api.analytics');

        // Settings
        Route::post('/settings/profile', [App\Http\Controllers\Api\SettingsController::class, 'updateProfile'])
            ->name('api.settings.profile');
        Route::post('/settings/theme', [App\Http\Controllers\Api\SettingsController::class, 'updateTheme'])
            ->name('api.settings.theme');

        // Audit Logs
        Route::get('/audit-logs', [App\Http\Controllers\Api\AuditLogController::class, 'index'])
            ->name('api.audit-logs.index');
        Route::get('/audit-logs/{auditLog}', [App\Http\Controllers\Api\AuditLogController::class, 'show'])
            ->name('api.audit-logs.show');

        // Online Users (realtime presence via last_seen_at polling)
        Route::get('/online-users', [App\Http\Controllers\Api\AuditLogController::class, 'onlineUsers'])
            ->name('api.online-users');

        // Accounts CRUD (Super Admin Only)
        Route::apiResource('accounts', App\Http\Controllers\Api\AccountController::class)
            ->names('api.accounts')
            ->middleware('role:super_admin');

        // Report Attendances
        Route::get('/report-attendances', [App\Http\Controllers\Api\ReportAttendanceController::class, 'index'])
            ->name('api.report-attendances.index');
        Route::get('/report-attendances/export', [App\Http\Controllers\Api\ReportAttendanceController::class, 'export'])
            ->name('api.report-attendances.export');
        Route::post('/report-attendances', [App\Http\Controllers\Api\ReportAttendanceController::class, 'store'])
            ->name('api.report-attendances.store');
        Route::post('/report-attendances/upsert-by-super-admin', [App\Http\Controllers\Api\ReportAttendanceController::class, 'upsertBySuperAdmin'])
            ->name('api.report-attendances.upsert-by-super-admin');

        // Consultations CRUD
        Route::get('/consultations/id-preview', [ConsultationController::class, 'previewId'])
            ->name('api.consultations.preview-id');
        Route::get('/consultations/import/template', [ConsultationController::class, 'downloadTemplate'])
            ->name('api.consultations.template');
        Route::post('/consultations/import', [ConsultationController::class, 'import'])
            ->name('api.consultations.import');
        Route::patch('/consultations/{consultation}/status', [ConsultationController::class, 'updateStatus'])
            ->name('api.consultations.update-status');
        Route::apiResource('consultations', ConsultationController::class)
            ->names('api.consultations');

        // Export
        Route::get('/export/csv', [ExportController::class, 'exportCsv'])->name('api.export.csv');
        Route::get('/export/leads/excel', [ExportController::class, 'exportLeadsExcel'])->name('api.export.leads.excel');
        Route::get('/export/leads/pdf', [ExportController::class, 'exportLeadsPdf'])->name('api.export.leads.pdf');
        Route::get('/export/analytics/excel', [ExportController::class, 'exportAnalyticsExcel'])->name('api.export.analytics.excel');
        Route::get('/export/analytics/pdf', [ExportController::class, 'exportAnalyticsPdf'])->name('api.export.analytics.pdf');

        // Nested Consultations Notes & Reminders
        Route::post('/consultations/{consultation}/notes', [App\Http\Controllers\Api\ConsultationNoteController::class, 'store'])
            ->name('api.consultations.notes.store');
        Route::delete('/consultations/{consultation}/notes/{note}', [App\Http\Controllers\Api\ConsultationNoteController::class, 'destroy'])
            ->name('api.consultations.notes.destroy');
        Route::post('/consultations/{consultation}/reminders', [App\Http\Controllers\Api\ReminderController::class, 'store'])
            ->name('api.consultations.reminders.store');
        Route::delete('/consultations/{consultation}/reminders/{reminder}', [App\Http\Controllers\Api\ReminderController::class, 'destroy'])
            ->name('api.consultations.reminders.destroy');

        // Debug & Testing
        Route::prefix('debug')->group(function () {
            Route::get('/stats', [DebugController::class, 'stats'])->name('api.debug.stats');
            Route::post('/generate-dummy', [DebugController::class, 'generateDummy'])->name('api.debug.generate-dummy');
            Route::post('/clear-dummy', [DebugController::class, 'clearDummy'])->name('api.debug.clear-dummy');
            Route::post('/clear-logs', [DebugController::class, 'clearLogs'])->name('api.debug.clear-logs');
        });

        // Master Data
        Route::get('/master-data/needs-categories', [MasterDataController::class, 'needsCategories'])
            ->name('api.master-data.needs-categories');
        Route::get('/master-data/status-categories', [MasterDataController::class, 'statusCategories'])
            ->name('api.master-data.status-categories');
        Route::get('/master-data/accounts', [MasterDataController::class, 'accounts'])
            ->name('api.master-data.accounts');

        // Master Data CRUD (Super Admin Only)
        Route::prefix('master-data')->name('api.master-data.')->group(function () {
            Route::get('/categories/list', [MasterDataController::class, 'listCategories'])->name('categories.list');
            Route::post('/categories', [MasterDataController::class, 'storeCategory'])->name('categories.store');
            Route::put('/categories/{category}', [MasterDataController::class, 'updateCategory'])->name('categories.update');
            Route::delete('/categories/{category}', [MasterDataController::class, 'destroyCategory'])->name('categories.destroy');

            Route::get('/statuses/list', [MasterDataController::class, 'listStatuses'])->name('statuses.list');
            Route::post('/statuses', [MasterDataController::class, 'storeStatus'])->name('statuses.store');
            Route::put('/statuses/{status}', [MasterDataController::class, 'updateStatus'])->name('statuses.update');
            Route::delete('/statuses/{status}', [MasterDataController::class, 'destroyStatus'])->name('statuses.destroy');

            Route::get('/users', [MasterDataController::class, 'listUsers'])->name('users.index');
            Route::post('/users', [MasterDataController::class, 'storeUser'])->name('users.store');
            Route::put('/users/{user}', [MasterDataController::class, 'updateUser'])->name('users.update');
            Route::delete('/users/{user}', [MasterDataController::class, 'destroyUser'])->name('users.destroy');
            Route::post('/users/{user}/reset-password', [MasterDataController::class, 'resetUserPassword'])->name('users.reset-password');
        });

        // Wilayah (geographic hierarchy)
        Route::prefix('wilayah')->name('api.wilayah.')->group(function () {
            Route::get('/provinces', [WilayahController::class, 'provinces'])->name('provinces');
            Route::get('/cities', [WilayahController::class, 'cities'])->name('cities');
            Route::get('/districts', [WilayahController::class, 'districts'])->name('districts');
        });

        // Notifications
        Route::prefix('notifications')->name('api.notifications.')->group(function () {
            Route::get('/', [NotificationController::class, 'unreadCount'])->name('count');
            Route::get('/summary', [NotificationController::class, 'summary'])->name('summary');
            Route::patch('/notes/{note}/read', [NotificationController::class, 'markNoteRead'])->name('notes.read');
            Route::patch('/reminders/{reminder}/read', [NotificationController::class, 'markReminderRead'])->name('reminders.read');
        });
    });
});
