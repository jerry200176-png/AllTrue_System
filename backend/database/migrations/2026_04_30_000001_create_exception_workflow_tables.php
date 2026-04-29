<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('exception_workflows')) {
            Schema::create('exception_workflows', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('source_key', 191);
                $table->unsignedInteger('campus_id');
                $table->unsignedInteger('student_id')->nullable();
                $table->unsignedBigInteger('student_class_id')->nullable();
                $table->unsignedBigInteger('class_session_id')->nullable();
                $table->string('type', 32);
                $table->string('status', 32)->default('open');
                $table->string('severity', 16)->default('medium');
                $table->string('source_type', 64)->nullable();
                $table->string('source_id', 64)->nullable();
                $table->unsignedInteger('owner_user_id')->nullable();
                $table->dateTime('due_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->string('closed_reason', 64)->nullable();
                $table->json('payload')->nullable();
                $table->unsignedInteger('created_by_user_id')->nullable();
                $table->unsignedBigInteger('parent_session_id')->nullable();
                $table->timestamps();

                $table->unique('source_key', 'ew_source_key_unique');
                $table->index(['campus_id', 'status', 'due_at'], 'ew_campus_status_due_idx');
                $table->index(['campus_id', 'type', 'status'], 'ew_campus_type_status_idx');
                $table->index(['student_id', 'status'], 'ew_student_status_idx');
                $table->index(['student_class_id', 'class_session_id'], 'ew_course_session_idx');
                $table->index(['owner_user_id', 'due_at'], 'ew_owner_due_idx');
                $table->index('closed_at', 'ew_closed_at_idx');
                $table->index('created_at', 'ew_created_at_idx');
            });
        }

        if (!Schema::hasTable('exception_workflow_candidates')) {
            Schema::create('exception_workflow_candidates', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workflow_id');
                $table->unsignedSmallInteger('rank');
                $table->date('candidate_date');
                $table->time('start_time');
                $table->time('end_time');
                $table->unsignedInteger('teacher_id')->nullable();
                $table->unsignedBigInteger('room_id')->nullable();
                $table->string('status', 16)->default('available');
                $table->integer('score')->default(0);
                $table->json('reasons')->nullable();
                $table->dateTime('expires_at');
                $table->timestamps();

                $table->index(['workflow_id', 'rank'], 'ewc_workflow_rank_idx');
                $table->index(['workflow_id', 'status'], 'ewc_workflow_status_idx');
                $table->index(['teacher_id', 'candidate_date', 'start_time'], 'ewc_teacher_date_time_idx');
                $table->index(['room_id', 'candidate_date', 'start_time'], 'ewc_room_date_time_idx');
                $table->index('expires_at', 'ewc_expires_at_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exception_workflow_candidates');
        Schema::dropIfExists('exception_workflows');
    }
};
