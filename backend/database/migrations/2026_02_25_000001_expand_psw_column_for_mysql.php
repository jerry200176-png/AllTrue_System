<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expand PSW column for bcrypt hashes (60 chars).
     * add_security_improvements uses PostgreSQL syntax; this fixes MySQL.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `User` MODIFY COLUMN `PSW` VARCHAR(255)');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "User" ALTER COLUMN "PSW" TYPE VARCHAR(255)');
        }
    }

    public function down(): void
    {
        // Cannot safely shrink - would truncate hashes
    }
};
