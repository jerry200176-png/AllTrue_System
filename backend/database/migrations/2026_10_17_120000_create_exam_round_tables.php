<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: school midterm exam rounds (段考) beside Assessment diagnostic ledger.
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('exam_rounds')) {
            Schema::create('exam_rounds', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedInteger('campus_id');
                $table->string('title', 120);
                $table->text('description')->nullable();
                $table->string('status', 24)->default('draft'); // draft|published|closed
                $table->date('exam_date')->nullable();
                $table->date('entry_deadline')->nullable();
                $table->json('grade_ids'); // Student.ClassID allow-list
                $table->unsignedBigInteger('created_by_user_id')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->index(['campus_id', 'status', 'exam_date'], 'exam_rounds_campus_status_date_idx');
                $table->index('created_by_user_id', 'exam_rounds_creator_idx');
            });
        }

        if (!Schema::hasTable('exam_round_subjects')) {
            Schema::create('exam_round_subjects', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('exam_round_id');
                $table->string('name', 40);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->decimal('max_score', 8, 2)->default(100);
                $table->timestamps();

                $table->index(['exam_round_id', 'sort_order'], 'exam_round_subjects_round_sort_idx');
            });
        }

        if (!Schema::hasTable('exam_scores')) {
            Schema::create('exam_scores', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('exam_round_id');
                $table->unsignedBigInteger('exam_round_subject_id');
                $table->unsignedInteger('student_id');
                $table->decimal('score', 8, 2)->nullable();
                $table->boolean('is_absent')->default(false);
                $table->string('notes', 255)->nullable();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();

                $table->unique(
                    ['exam_round_id', 'student_id', 'exam_round_subject_id'],
                    'exam_scores_round_student_subject_uq'
                );
                $table->index(['exam_round_id', 'student_id'], 'exam_scores_round_student_idx');
                $table->index('student_id', 'exam_scores_student_idx');
            });
        }

        if (!Schema::hasTable('exam_score_audit_logs')) {
            Schema::create('exam_score_audit_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('exam_round_id');
                $table->unsignedBigInteger('exam_score_id')->nullable();
                $table->unsignedInteger('campus_id');
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->string('action', 40);
                $table->string('reason', 255)->nullable();
                $table->json('before')->nullable();
                $table->json('after')->nullable();
                $table->timestamps();

                $table->index(['exam_round_id', 'created_at'], 'exam_score_audit_round_created_idx');
                $table->index(['campus_id', 'created_at'], 'exam_score_audit_campus_created_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_score_audit_logs');
        Schema::dropIfExists('exam_scores');
        Schema::dropIfExists('exam_round_subjects');
        Schema::dropIfExists('exam_rounds');
    }
};
