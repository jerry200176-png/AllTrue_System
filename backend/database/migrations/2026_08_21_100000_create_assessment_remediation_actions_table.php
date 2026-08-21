<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('assessment_remediation_actions')) {
            return;
        }

        Schema::create('assessment_remediation_actions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('assessment_id');
            $table->unsignedBigInteger('assessment_result_id');
            $table->unsignedInteger('campus_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('student_class_id')->nullable();
            $table->string('knowledge_tag', 120);
            $table->string('action_type', 32)->default('practice');
            $table->string('status', 24)->default('open');
            $table->text('plan')->nullable();
            $table->date('due_date')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'status', 'due_date'], 'assessment_remediation_campus_status_due_idx');
            $table->index(['assessment_result_id', 'status'], 'assessment_remediation_result_status_idx');
            $table->index(['student_id', 'status'], 'assessment_remediation_student_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assessment_remediation_actions');
    }
};
