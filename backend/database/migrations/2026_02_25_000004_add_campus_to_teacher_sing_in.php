<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 既有 TeacherSingIn 表可能缺少 CampusID、MDT
     */
    public function up(): void
    {
        if (!Schema::hasTable('TeacherSingIn')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (!Schema::hasColumn('TeacherSingIn', 'CampusID')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `TeacherSingIn` ADD COLUMN `CampusID` INT NULL');
            } else {
                DB::statement('ALTER TABLE "TeacherSingIn" ADD COLUMN "CampusID" INT NULL');
            }
        }

        if (!Schema::hasColumn('TeacherSingIn', 'MDT')) {
            if ($driver === 'mysql') {
                DB::statement('ALTER TABLE `TeacherSingIn` ADD COLUMN `MDT` DATETIME NULL');
            } else {
                DB::statement('ALTER TABLE "TeacherSingIn" ADD COLUMN "MDT" TIMESTAMP NULL');
            }
        }
    }

    public function down(): void
    {
        //
    }
};
