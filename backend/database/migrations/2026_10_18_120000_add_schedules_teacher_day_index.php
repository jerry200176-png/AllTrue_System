<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Retryable additive lookups; retain indexes on rollback because pre-existing ownership is unknown. */
return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            ['schedules', 'idx_schedules_teacher_day', ['branch_id', 'teacher_id', 'schedule_date']],
            ['StudentClass', 'idx_sc_student_teacher', ['StudentID', 'TeacherID']],
            ['LearningRecord', 'idx_lr_class_session_teacher', ['ClassSessionID', 'TeacherID']],
        ] as [$table, $index, $columns]) {
            if (DB::select("SHOW INDEX FROM `$table` WHERE Key_name = ?", [$index]) !== []) {
                continue;
            }
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $index));
        }
    }

    public function down(): void
    {
        // Guarded adoption cannot distinguish indexes from a prior partial run or another owner.
        // Roll back callers/code first; any index removal needs a separately reviewed migration.
    }
};
