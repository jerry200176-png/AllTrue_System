<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrueFit Slice 3 — Misconception Diagnosis structured storage.
 * Phase: Simple Add (additive CREATE TABLE). Reversible via dropIfExists.
 * Does not touch LearningRecord, attendance, enrollment, or billing tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('truefit_diagnoses')) {
            return;
        }

        Schema::create('truefit_diagnoses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->index();
            $table->unsignedBigInteger('teacher_user_id')->index();
            $table->unsignedBigInteger('class_session_id')->default(0)->index();
            $table->unsignedBigInteger('student_class_id')->index();
            $table->date('session_date');
            $table->string('start_time', 8);
            $table->unsignedBigInteger('source_observation_id')->nullable()->index();
            $table->string('status', 24)->default('saved');
            $table->string('diagnosis_schema_version', 64)->default('truefit.diagnosis.v1');
            $table->string('confidence', 16)->default('medium');
            $table->string('teacher_decision', 16)->default('pending');
            $table->json('diagnosis_json');
            $table->timestamp('proposed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['teacher_user_id', 'class_session_id', 'student_class_id', 'session_date', 'start_time'],
                'truefit_diagnoses_session_unique'
            );
            $table->index(['campus_id', 'teacher_user_id', 'session_date'], 'truefit_diagnoses_teacher_day_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truefit_diagnoses');
    }
};
