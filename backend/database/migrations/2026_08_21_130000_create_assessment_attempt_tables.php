<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('assessment_question_snapshots')) {
            Schema::create('assessment_question_snapshots', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assessment_id');
                $table->unsignedBigInteger('question_bank_item_id');
                $table->uuid('question_key');
                $table->unsignedInteger('version_no');
                $table->string('question_type', 24);
                $table->text('prompt');
                $table->json('choices')->nullable();
                // Correct answers stay server-side; normal attempt payloads never expose this column.
                $table->json('answer')->nullable();
                $table->text('explanation')->nullable();
                $table->string('knowledge_tag', 120);
                $table->unsignedTinyInteger('difficulty');
                $table->decimal('points', 8, 2)->default(1);
                $table->unsignedInteger('position');
                $table->timestamps();

                $table->unique(['assessment_id', 'position'], 'assessment_snapshot_position_unique');
                $table->unique(['assessment_id', 'question_key'], 'assessment_snapshot_question_unique');
                $table->index(['assessment_id', 'question_type'], 'assessment_snapshot_type_idx');
            });
        }

        if (!Schema::hasTable('assessment_attempts')) {
            Schema::create('assessment_attempts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assessment_id');
                $table->unsignedBigInteger('student_id');
                $table->unsignedBigInteger('student_class_id')->nullable();
                $table->unsignedInteger('attempt_no');
                $table->string('status', 24)->default('in_progress');
                $table->decimal('auto_score', 8, 2)->nullable();
                $table->decimal('manual_score', 8, 2)->nullable();
                $table->decimal('score', 8, 2)->nullable();
                $table->decimal('max_score_snapshot', 8, 2);
                $table->decimal('percent', 5, 2)->nullable();
                $table->text('notes')->nullable();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(['assessment_id', 'student_id', 'attempt_no'], 'assessment_attempt_identity_unique');
                $table->index(['assessment_id', 'status'], 'assessment_attempt_assessment_status_idx');
                $table->index(['student_id', 'created_at'], 'assessment_attempt_student_created_idx');
            });
        }

        if (!Schema::hasTable('assessment_answers')) {
            Schema::create('assessment_answers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('assessment_attempt_id');
                $table->unsignedBigInteger('assessment_question_snapshot_id');
                $table->json('answer')->nullable();
                $table->decimal('score', 8, 2)->nullable();
                $table->decimal('max_score', 8, 2)->default(1);
                $table->string('status', 24)->default('pending');
                $table->text('review_note')->nullable();
                $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->timestamps();

                $table->unique(['assessment_attempt_id', 'assessment_question_snapshot_id'], 'assessment_answer_identity_unique');
                $table->index(['assessment_attempt_id', 'status'], 'assessment_answer_attempt_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_answers');
        Schema::dropIfExists('assessment_attempts');
        Schema::dropIfExists('assessment_question_snapshots');
    }
};
