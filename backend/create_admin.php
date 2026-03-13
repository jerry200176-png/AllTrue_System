<?php
// A simple standalone script to insert a super admin user.
// Execute this via browser or CLI if available.

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

try {
    $email = 'admin@admin.com';
    $password = 'admin123';
    $name = 'Super Admin';

    $user = User::firstOrCreate(
        ['LoginName' => $email],
        [
            'Name' => $name,
            'PSW' => Hash::make($password),
            'type' => 'S',
            'phone' => 0
        ]
    );

    // Ensure it's 'S' if it already existed
    if ($user->type !== 'S') {
        $user->type = 'S';
        $user->PSW = Hash::make($password); // reset password just in case
        $user->save();
        echo "Updated existing user {$email} to Super Admin (type 'S')\n";
    } else {
        echo "Super Admin user {$email} created or already exists.\n";
    }

    echo "Login: {$email}\n";
    echo "Password: {$password}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
