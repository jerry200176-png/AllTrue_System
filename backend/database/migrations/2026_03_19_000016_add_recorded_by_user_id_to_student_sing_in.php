<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentSingIn') || Schema::hasColumn('StudentSingIn', 'RecordedByUserID')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE `StudentSingIn` ADD COLUMN `RecordedByUserID` BIGINT UNSIGNED NULL AFTER `TeacherID`');
        } catch (\Throwable $e) {
            DB::statement('ALTER TABLE "StudentSingIn" ADD COLUMN "RecordedByUserID" BIGINT NULL');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('StudentSingIn') || !Schema::hasColumn('StudentSingIn', 'RecordedByUserID')) {
            return;
        }

        try {
            DB::statement('ALTER TABLE `StudentSingIn` DROP COLUMN `RecordedByUserID`');
        } catch (\Throwable $e) {
            DB::statement('ALTER TABLE "StudentSingIn" DROP COLUMN "RecordedByUserID"');
        }
    }
};
