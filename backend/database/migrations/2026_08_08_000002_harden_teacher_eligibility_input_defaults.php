<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('teacher_payroll_events')) {
            return;
        }

        if (Schema::hasTable('teacher_payroll_achievements') && !Schema::hasColumn('teacher_payroll_achievements', 'award_year')) {
            Schema::table('teacher_payroll_achievements', function ($table) {
                $table->unsignedSmallInteger('award_year')->nullable()->after('subject');
            });
        }

        // The first version of the table defaulted manually entered events to
        // approved. That could turn an unreviewed holiday/leave fact into
        // payroll. New input is always pending until a director approves it.
        DB::statement("ALTER TABLE teacher_payroll_events MODIFY status VARCHAR(24) NOT NULL DEFAULT 'pending'");
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE teacher_payroll_events MODIFY makeup_completed TINYINT(1) NULL DEFAULT NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE teacher_payroll_events ALTER COLUMN makeup_completed DROP NOT NULL');
            DB::statement('ALTER TABLE teacher_payroll_events ALTER COLUMN makeup_completed DROP DEFAULT');
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('teacher_payroll_events')) {
            return;
        }

        if (Schema::hasTable('teacher_payroll_achievements') && Schema::hasColumn('teacher_payroll_achievements', 'award_year')) {
            Schema::table('teacher_payroll_achievements', function ($table) {
                $table->dropColumn('award_year');
            });
        }

        DB::statement("ALTER TABLE teacher_payroll_events MODIFY status VARCHAR(24) NOT NULL DEFAULT 'approved'");
        $driver = Schema::getConnection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE teacher_payroll_events MODIFY makeup_completed TINYINT(1) NOT NULL DEFAULT 0');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE teacher_payroll_events ALTER COLUMN makeup_completed SET DEFAULT false');
            DB::statement('ALTER TABLE teacher_payroll_events ALTER COLUMN makeup_completed SET NOT NULL');
        }
    }
};
