<?php

namespace Database\Seeders;

use App\Models\ApiClient;
use App\Models\Campus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (ApiClient::count() > 0) {
            return;
        }

        $campus = Campus::where('Current', 1)->first() ?? Campus::first();
        if (!$campus) {
            return;
        }

        $rawKey = 'sk_live_' . Str::random(32);
        $hash = hash('sha256', $rawKey);

        ApiClient::create([
            'Name' => 'Default Device',
            'ApiKeyHash' => $hash,
            'CampusID' => $campus->id,
            'Active' => 1,
        ]);

        if ($this->command) {
            $this->command->info('Default API Key: ' . $rawKey);
        }
    }
}
