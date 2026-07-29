<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Course Continuity (#1382) — group view without physical StudentClass merge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_contract_groups', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('student_id');
            $table->unsignedInteger('campus_id');
            $table->unsignedInteger('subject_id')->nullable();
            $table->string('label', 128)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'campus_id'], 'idx_ccg_student_campus');
            $table->index(['campus_id', 'subject_id'], 'idx_ccg_campus_subject');
        });

        Schema::create('course_contract_group_members', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('group_id');
            $table->unsignedBigInteger('student_class_id');
            $table->enum('relation_type', [
                'original',
                'renewal',
                'replacement',
                'trial',
                'parallel',
            ]);
            $table->date('effective_from')->nullable();
            $table->integer('sequence')->default(0);
            $table->string('decision_reason', 255)->nullable();
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamp('unlinked_at')->nullable();
            $table->timestamps();

            $table->unique('student_class_id', 'uq_ccgm_student_class');
            $table->index(['group_id', 'sequence'], 'idx_ccgm_group_seq');
            $table->foreign('group_id', 'fk_ccgm_group')
                ->references('id')
                ->on('course_contract_groups')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_contract_group_members');
        Schema::dropIfExists('course_contract_groups');
    }
};
