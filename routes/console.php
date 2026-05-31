<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Security: Bersihkan login_attempts yang lebih dari 30 hari ───
Schedule::call(fn () => \App\Models\LoginAttempt::purgeOlderThan(30))->daily();

// ── Web Push: kirim notifikasi untuk reminder yang jatuh tempo ───
// Butuh cron server menjalankan `php artisan schedule:run` tiap menit.
Schedule::command('reminders:push-due')->everyMinute()->withoutOverlapping();
