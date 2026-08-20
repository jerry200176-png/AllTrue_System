<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('assessments')) {
            Schema::create('assessments', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('campus_id');
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->unsignedBigInteger('student_class_id')->nullable();
                $table->string('title', 120);
                $table->text('description')->nullable();
                $table->string('assessment_type', 24)->default('checkpoint');
                $table->string('status', 24)->default('draft');
                $table->date('scheduled_for')->nullable();
                $table->decimal('max_score', 8, 2)->default(100);
                $table->decimal('passing_score', 8, 2)->nullable();
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['campus_id', 'status', 'scheduled_for'], 'assessments_campus_status_date_idx');
                $table->index(['student_class_id', 'status'], 'assessments_class_status_idx');
                $table->index('created_by_user_id', 'assessments_creator_idx');
            });
        }

        if (!Schema::hasTable('assessment_results')) {
            Schema::create('assessment_results', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assessment_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('student_class_id')->nullable();
                $table->unsignedInteger('attempt_no')->default(1);
                $table->decimal('score', 8, 2);
                $table->decimal('max_score_snapshot', 8, 2);
                $table->decimal('percent', 5, 2);
                $table->string('status', 24)->default('submitted');
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(['assessment_id', 'student_id', 'attempt_no'], 'assessment_result_attempt_unique');
                $table->index(['assessment_id', 'status'], 'assessment_results_assessment_status_idx');
                $table->index(['student_id', 'created_at'], 'assessment_results_student_created_idx');
                $table->index('student_class_id', 'assessment_results_class_idx');
            });
        }

        if (!Schema::hasTable('assessment_audit_logs')) {
            Schema::create('assessment_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assessment_id');
                $table->unsignedBigInteger('assessment_result_id')->nullable();
                $table->unsignedInteger('campus_id');
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('action', 32);
                $table->text('reason')->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamps();

                $table->index(['assessment_id', 'created_at'], 'assessment_audit_assessment_created_idx');
                $table->index(['assessment_result_id', 'created_at'], 'assessment_audit_result_created_idx');
                $table->index(['campus_id', 'created_at'], 'assessment_audit_campus_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_audit_logs');
        Schema::dropIfExists('assessment_results');
        Schema::dropIfExists('assessments');
    }
};