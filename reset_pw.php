<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Hash;

$email = 'superadmin@npp.com';
$newPassword = 'password123';

$user = App\Models\User::where('email', $email)->first();

if (!$user) {
    echo "User {$email} not found" . PHP_EOL;
    exit(1);
}

$user->password = Hash::make($newPassword);
$user->save();

echo "Password for {$email} has been reset to: {$newPassword}" . PHP_EOL;
