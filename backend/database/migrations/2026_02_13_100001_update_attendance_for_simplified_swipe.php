<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if (Schema::hasTable('StudentSingIn')) {
            if (!Schema::hasColumn('StudentSingIn', 'PersonType')) {
                if ($driver === 'mysql') {
                    DB::statement("ALTER TABLE `StudentSingIn` ADD COLUMN `PersonType` VARCHAR(16) NOT NULL DEFAULT 'student'");
                } else {
                    DB::statement("ALTER TABLE \"StudentSingIn\" ADD COLUMN \"PersonType\" VARCHAR(16) NOT NULL DEFAULT 'student'");
                }
            }
            if (!Schema::hasColumn('StudentSingIn', 'CampusID')) {
                if ($driver === 'mysql') {
                    DB::statement('ALTER TABLE `StudentSingIn` ADD COLUMN `CampusID` INT NULL');
                } else {
                    DB::statement('ALTER TABLE "StudentSingIn" ADD COLUMN "CampusID" INT NULL');
                }
            }
            if ($driver === 'pgsql') {
                DB::statement('ALTER TABLE "StudentSingIn" ALTER COLUMN "StudentID" DROP NOT NULL');
            }
        }

        if ($driver === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS student_rfid_unique ON "Student" ("RFID") WHERE "RFID" IS NOT NULL');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS teacher_rfid_unique ON "Teacher" ("RFID") WHERE "RFID" IS NOT NULL');
        } elseif ($driver === 'mysql') {
            try {
                if (Schema::hasTable('Student')) {
                    DB::statement('CREATE UNIQUE INDEX student_rfid_unique ON `Student` (`RFID`)');
                }
            } catch (\Throwable $e) { /* index may exist */ }
            try {
                if (Schema::hasTable('Teacher')) {
                    DB::statement('CREATE UNIQUE INDEX teacher_rfid_unique ON `Teacher` (`RFID`)');
                }
            } catch (\Throwable $e) { /* index may exist */ }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS student_rfid_unique');
        DB::statement('DROP INDEX IF EXISTS teacher_rfid_unique');

        DB::statement('ALTER TABLE "StudentSingIn" ALTER COLUMN "StudentID" SET NOT NULL');
        DB::statement('ALTER TABLE "StudentSingIn" DROP COLUMN IF EXISTS "PersonType"');
        DB::statement('ALTER TABLE "StudentSingIn" DROP COLUMN IF EXISTS "CampusID"');
    }
};
