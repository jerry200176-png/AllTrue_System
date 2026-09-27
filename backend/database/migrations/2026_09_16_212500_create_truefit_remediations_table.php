<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrueFit Slice 4 — Remediation structured storage.
 * Phase: Simple Add. Does not touch LearningRecord/attendance/billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('truefit_remediations')) {
            return;
        }

        Schema::create('truefit_remediations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->index();
            $table->unsignedBigInteger('teacher_user_id')->index();
            $table->unsignedBigInteger('class_session_id')->default(0)->index();
            $table->unsignedBigInteger('student_class_id')->index();
            $table->date('session_date');
            $table->string('start_time', 8);
            $table->unsignedBigInteger('source_diagnosis_id')->nullable()->index();
            $table->string('status', 24)->default('saved');
            $table->string('remediation_schema_version', 64)->default('truefit.remediation.v1');
            $table->string('follow_up_window', 32)->default('next_session');
            $table->string('teacher_decision', 16)->default('pending');
            $table->json('remediation_json');
            $table->timestamp('planned_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['teacher_user_id', 'class_session_id', 'student_class_id', 'session_date', 'start_time'],
                'truefit_remediations_session_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truefit_remediations');
    }
};
