<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$email = 'superadmin@npp.com';
$deleted = App\Models\LoginAttempt::where('email', $email)
    ->where('successful', false)
    ->delete();

echo "Deleted {$deleted} failed login attempts for {$email}" . PHP_EOL;
