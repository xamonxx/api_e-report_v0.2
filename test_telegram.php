<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$service = app(\App\Services\TelegramService::class);
$result = $service->sendFailedLoginAlert('superadmin@npp.com', '103.28.12.45', 5, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
echo $result ? 'SUCCESS' : 'FAILED';
echo PHP_EOL;
