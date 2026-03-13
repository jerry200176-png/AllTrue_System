<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('TeacherSingIn') || Schema::hasColumn('TeacherSingIn', 'MDT')) {
            return;
        }

        $driver = DB::connection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE `TeacherSingIn` ADD COLUMN `MDT` DATETIME NULL');
        } else {
            DB::statement('ALTER TABLE "TeacherSingIn" ADD COLUMN "MDT" TIMESTAMP NULL');
        }
    }

    public function down(): void
    {
        //
    }
};
