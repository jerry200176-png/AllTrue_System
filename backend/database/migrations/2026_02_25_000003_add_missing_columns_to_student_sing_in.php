<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 既有 StudentSingIn 表可能缺少 ClassSessionID、CampusID、PersonType
     */
    public function up(): void
    {
        if (!Schema::hasTable('StudentSingIn')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (!Schema::hasColumn('StudentSingIn', 'ClassSessionID')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `StudentSingIn` ADD COLUMN `ClassSessionID` BIGINT UNSIGNED NULL');
            } else {
                DB::statement('ALTER TABLE "StudentSingIn" ADD COLUMN "ClassSessionID" BIGINT NULL');
            }
        }

        if (!Schema::hasColumn('StudentSingIn', 'Status')) {
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `StudentSingIn` ADD COLUMN `Status` VARCHAR(16) NOT NULL DEFAULT 'present'");
            } else {
                DB::statement("ALTER TABLE \"StudentSingIn\" ADD COLUMN \"Status\" VARCHAR(16) NOT NULL DEFAULT 'present'");
            }
        }

        if (!Schema::hasColumn('StudentSingIn', 'CampusID')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `StudentSingIn` ADD COLUMN `CampusID` INT NULL');
            } else {
                DB::statement('ALTER TABLE "StudentSingIn" ADD COLUMN "CampusID" INT NULL');
            }
        }

        if (!Schema::hasColumn('StudentSingIn', 'PersonType')) {
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE `StudentSingIn` ADD COLUMN `PersonType` VARCHAR(16) NOT NULL DEFAULT 'student'");
            } else {
                DB::statement("ALTER TABLE \"StudentSingIn\" ADD COLUMN \"PersonType\" VARCHAR(16) NOT NULL DEFAULT 'student'");
            }
        }
    }

    public function down(): void
    {
        // 不反向移除，避免資料遺失
    }
};
