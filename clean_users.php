<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;

// Delete all users except superadmin
$deleted = User::where('role', '!=', 'super_admin')->delete();

echo "Deleted {$deleted} non-superadmin users" . PHP_EOL;
echo "Remaining users:" . PHP_EOL;

$users = User::all();
foreach ($users as $u) {
    echo "- {$u->email} ({$u->role->value})" . PHP_EOL;
}
