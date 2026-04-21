<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->index(
                ['student_course_id', 'schedule_date', 'start_time', 'status', 'original_schedule_id'],
                'idx_sched_course_date_time_status'
            );
        });

        DB::statement('ANALYZE TABLE schedules');
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('idx_sched_course_date_time_status');
        });
    }
};
