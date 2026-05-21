<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = \App\Models\User::first();
if ($user) {
    $user->password = \Illuminate\Support\Facades\Hash::make('password');
    $user->save();
    echo "Reset password for: " . $user->email . "\n";
} else {
    echo "No user found\n";
}
