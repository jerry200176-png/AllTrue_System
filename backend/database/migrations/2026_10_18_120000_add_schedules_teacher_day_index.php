<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive teacher/day access path; atomic current reads must not lock a table scan. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->index(['branch_id', 'teacher_id', 'schedule_date'], 'idx_schedules_teacher_day');
        });
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->index(['StudentID', 'TeacherID'], 'idx_sc_student_teacher');
        });
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->index(['ClassSessionID', 'TeacherID'], 'idx_lr_class_session_teacher');
        });
    }

    public function down(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->dropIndex('idx_sc_student_teacher');
        });
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->dropIndex('idx_lr_class_session_teacher');
        });
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropIndex('idx_schedules_teacher_day');
        });
    }
};
