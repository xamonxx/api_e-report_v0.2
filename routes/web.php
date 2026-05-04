<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ConsultationController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\ConsultationNoteController;
use App\Http\Controllers\ReminderController;
use App\Http\Controllers\ReportAttendanceController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\WilayahController;

// ─── Guest Routes ────────────────────────────────────────
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->name('login.authenticate');
});

// ─── Authenticated Routes ────────────────────────────────
Route::middleware('auth')->group(function () {
    // Fallback for accidental GET requests so the app does not throw a 405 on logout.
    Route::get('/logout', [AuthController::class, 'logout']);
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // API Notifications (Polling) — throttled to prevent abuse
    Route::middleware('throttle:30,1')->group(function () {
        Route::get('/api/notifications', [NotificationController::class, 'unreadCount'])->name('api.notifications');
        Route::get('/api/notifications/summary', [NotificationController::class, 'summary'])->name('api.notifications.summary');
        Route::patch('/api/notifications/notes/{note}/read', [NotificationController::class, 'markNoteRead'])->name('api.notifications.notes.read');
        Route::patch('/api/notifications/reminders/{reminder}/read', [NotificationController::class, 'markReminderRead'])->name('api.notifications.reminders.read');
        Route::get('/api/consultation-id-preview', [ConsultationController::class, 'previewId'])->name('api.consultation-id-preview');
    });

    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/report-attendance', [ReportAttendanceController::class, 'store'])->name('report-attendance.store');

    // API: City-to-Province mapping (controller instead of closure for route caching)
    Route::get('/api/wilayah/kota', [WilayahController::class, 'cities'])->name('api.wilayah.kota');
    Route::get('/api/wilayah/kecamatan', [WilayahController::class, 'districts'])->name('api.wilayah.kecamatan');

    // Consultations (Leads)
    Route::post('/consultations/import', [ConsultationController::class, 'importCsv'])->name('consultations.import');
    Route::get('/consultations/import/template', [ConsultationController::class, 'downloadTemplate'])->name('consultations.template');
    Route::resource('consultations', ConsultationController::class);

    // Audit Logs
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
    Route::get('/audit-logs/{auditLog}', [AuditLogController::class, 'show'])->name('audit-logs.show');
    Route::get('/consultations/{consultation}/audit', [AuditLogController::class, 'consultationHistory'])->name('consultations.audit');

    // Notes
    Route::post('/consultations/{consultation}/notes', [ConsultationNoteController::class, 'store'])->name('consultations.notes.store');
    Route::delete('/consultations/{consultation}/notes/{note}', [ConsultationNoteController::class, 'destroy'])->name('consultations.notes.destroy');

    // Reminders
    Route::post('/consultations/{consultation}/reminders', [ReminderController::class, 'store'])->name('consultations.reminders.store');
    Route::post('/reminders/{reminder}/read', [ReminderController::class, 'markAsRead'])->name('reminders.read');
    Route::delete('/consultations/{consultation}/reminders/{reminder}', [ReminderController::class, 'destroy'])->name('consultations.reminders.destroy');

    // Analytics
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics');

    // Settings
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::put('/settings/account', [SettingsController::class, 'updateAccount'])->name('settings.account');
    Route::put('/settings/theme', [SettingsController::class, 'updateTheme'])->name('settings.theme');

    // Export
    Route::get('/export/csv', [ExportController::class, 'exportCsv'])->name('export.csv');
    Route::get('/export/analytics/excel', [ExportController::class, 'exportAnalyticsExcel'])->name('export.analytics.excel');
    Route::get('/export/analytics/pdf', [ExportController::class, 'exportAnalyticsPdf'])->name('export.analytics.pdf');

    // ─── Super Admin Only ────────────────────────────────
    Route::middleware('role:super_admin')->group(function () {
        Route::resource('accounts', AccountController::class);

        // Report Attendance
        Route::get('/report-attendances', [ReportAttendanceController::class, 'index'])->name('report-attendances.index');
        Route::post('/report-attendances/upsert', [ReportAttendanceController::class, 'upsertBySuperAdmin'])->name('report-attendances.upsert');

        // Master Data
        Route::get('/master-data', [MasterDataController::class, 'index'])->name('master-data.index');

        Route::post('/master-data/categories', [MasterDataController::class, 'storeCategory'])->name('master-data.categories.store');
        Route::put('/master-data/categories/{category}', [MasterDataController::class, 'updateCategory'])->name('master-data.categories.update');
        Route::delete('/master-data/categories/{category}', [MasterDataController::class, 'destroyCategory'])->name('master-data.categories.destroy');

        Route::post('/master-data/statuses', [MasterDataController::class, 'storeStatus'])->name('master-data.statuses.store');
        Route::put('/master-data/statuses/{status}', [MasterDataController::class, 'updateStatus'])->name('master-data.statuses.update');
        Route::delete('/master-data/statuses/{status}', [MasterDataController::class, 'destroyStatus'])->name('master-data.statuses.destroy');

        Route::post('/master-data/users', [MasterDataController::class, 'storeUser'])->name('master-data.users.store');
        Route::put('/master-data/users/{user}', [MasterDataController::class, 'updateUser'])->name('master-data.users.update');
        Route::put('/master-data/users/{user}/reset-password', [MasterDataController::class, 'resetUserPassword'])->name('master-data.users.reset-password');
        Route::delete('/master-data/users/{user}', [MasterDataController::class, 'destroyUser'])->name('master-data.users.destroy');
    });
});
