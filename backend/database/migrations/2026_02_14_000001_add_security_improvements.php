<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Uses raw SQL to avoid doctrine/dbal.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // 1. Add expires_at to auth_tokens for token expiration
        if (Schema::hasTable('auth_tokens') && !Schema::hasColumn('auth_tokens', 'expires_at')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE auth_tokens ADD COLUMN expires_at TIMESTAMP NULL');
            } else {
                DB::statement('ALTER TABLE auth_tokens ADD COLUMN IF NOT EXISTS expires_at TIMESTAMP NULL');
            }
        }

        // 2. Expand PSW column to accommodate bcrypt hashes (60 chars)
        if (Schema::hasTable('User')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `User` MODIFY COLUMN `PSW` VARCHAR(255)');
            } else {
                DB::statement('ALTER TABLE "User" ALTER COLUMN "PSW" TYPE VARCHAR(255)');
            }
        }

        // 3. Hash all existing plaintext passwords
        $users = DB::table('User')->get();
        foreach ($users as $user) {
            if (!str_starts_with($user->PSW ?? '', '$2y$')) {
                DB::table('User')
                    ->where('id', $user->id)
                    ->update(['PSW' => Hash::make($user->PSW)]);
            }
        }

        // 4. Set expiration for existing tokens (30 days from now)
        if (Schema::hasTable('auth_tokens') && Schema::hasColumn('auth_tokens', 'expires_at')) {
            DB::table('auth_tokens')
                ->whereNull('expires_at')
                ->update(['expires_at' => now()->addDays(30)]);
        }
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE auth_tokens DROP COLUMN IF EXISTS expires_at');
        // Note: Cannot reverse PSW column type or password hashing
    }
};
