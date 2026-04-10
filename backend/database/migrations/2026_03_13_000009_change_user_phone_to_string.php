<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('User') || !Schema::hasColumn('User', 'phone')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `User` MODIFY COLUMN `phone` VARCHAR(32) NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE "User" ALTER COLUMN "phone" TYPE VARCHAR(32)');
            DB::statement('ALTER TABLE "User" ALTER COLUMN "phone" DROP NOT NULL');
        } else {
            // Keep as-is on unsupported drivers; tests and production use MySQL.
            return;
        }

        if (Schema::hasTable('Teacher') && Schema::hasColumn('Teacher', 'Phone')) {
            if ($driver === 'mysql') {
                DB::statement("
                    UPDATE `User` u
                    INNER JOIN `Teacher` t ON t.id = u.id
                    SET u.phone = t.Phone
                    WHERE u.type = 'T' AND t.Phone IS NOT NULL AND t.Phone <> ''
                ");
            } elseif ($driver === 'pgsql') {
                DB::statement('
                    UPDATE "User" u
                    SET "phone" = t."Phone"
                    FROM "Teacher" t
                    WHERE t."id" = u."id"
                      AND u."type" = \'T\'
                      AND t."Phone" IS NOT NULL
                      AND t."Phone" <> \'\'
                ');
            }
        }

        if ($driver === 'mysql') {
            DB::statement("UPDATE `User` SET `phone` = NULL WHERE `phone` = '' OR `phone` = '0'");
        } elseif ($driver === 'pgsql') {
            DB::statement('UPDATE "User" SET "phone" = NULL WHERE "phone" = \'\' OR "phone" = \'0\'');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('User') || !Schema::hasColumn('User', 'phone')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("UPDATE `User` SET `phone` = '0' WHERE `phone` IS NULL OR `phone` = ''");
            DB::statement('ALTER TABLE `User` MODIFY COLUMN `phone` INT NOT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('UPDATE "User" SET "phone" = \'0\' WHERE "phone" IS NULL OR "phone" = \'\'');
            DB::statement('ALTER TABLE "User" ALTER COLUMN "phone" TYPE INTEGER USING NULLIF("phone", \'\')::INTEGER');
            DB::statement('ALTER TABLE "User" ALTER COLUMN "phone" SET NOT NULL');
        }
    }
};
