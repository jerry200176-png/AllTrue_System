<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrueFit Slice 1 — LessonPrep + structured TeacherBrief storage.
 * Phase: Simple Add (additive CREATE TABLE). Reversible via dropIfExists.
 * Does not touch LearningRecord, attendance, enrollment, or billing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('truefit_lesson_preps')) {
            return;
        }

        Schema::create('truefit_lesson_preps', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->index();
            $table->unsignedBigInteger('teacher_user_id')->index();
            $table->unsignedBigInteger('class_session_id')->default(0)->index();
            $table->unsignedBigInteger('student_class_id')->index();
            $table->date('session_date');
            $table->string('start_time', 8);
            $table->string('material_unit_key', 64);
            $table->string('status', 24)->default('ready');
            $table->string('brief_provider', 32)->default('fixture');
            $table->unsignedSmallInteger('brief_schema_version')->default(1);
            $table->json('brief_json');
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['teacher_user_id', 'class_session_id', 'student_class_id', 'session_date', 'start_time'],
                'truefit_lesson_preps_session_unique'
            );
            $table->index(['campus_id', 'teacher_user_id', 'session_date'], 'truefit_lesson_preps_teacher_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truefit_lesson_preps');
    }
};
