<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TrueFit Slice 5 — Mastery / delayed-retrieval structured storage.
 * Phase: Simple Add. Does not touch LearningRecord/attendance/billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('truefit_mastery_evidence')) {
            return;
        }

        Schema::create('truefit_mastery_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campus_id')->index();
            $table->unsignedBigInteger('teacher_user_id')->index();
            $table->unsignedBigInteger('class_session_id')->default(0)->index();
            $table->unsignedBigInteger('student_class_id')->index();
            $table->date('session_date');
            $table->string('start_time', 8);
            $table->unsignedBigInteger('source_remediation_id')->nullable()->index();
            $table->string('status', 24)->default('saved');
            $table->string('mastery_schema_version', 64)->default('truefit.mastery.v1');
            $table->string('outcome', 24)->default('not_checked');
            $table->string('next_review_window', 32)->default('none');
            $table->string('teacher_decision', 16)->default('pending');
            $table->json('mastery_json');
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['teacher_user_id', 'class_session_id', 'student_class_id', 'session_date', 'start_time'],
                'truefit_mastery_evidence_session_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('truefit_mastery_evidence');
    }
};
