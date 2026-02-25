<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);

$kernel->bootstrap();

// Check users in database
$users = \App\Models\User::where('role', 'super_admin')->get();

echo "Super Admin Users:\n";
foreach ($users as $user) {
    echo "ID: {$user->id}, Name: {$user->name}, Email: {$user->email}, Role: {$user->role}, Status: {$user->account_status}\n";
}

echo "\nAll Admin Users:\n";
$allAdmins = \App\Models\User::whereIn('role', ['admin', 'super_admin'])->get();
foreach ($allAdmins as $user) {
    echo "ID: {$user->id}, Name: {$user->name}, Email: {$user->email}, Role: {$user->role}, Status: {$user->account_status}\n";
}
