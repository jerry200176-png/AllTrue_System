<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: 本週讀書計畫 (#5269 step 1). New table only; no unique/foreign keys
 * (student / unit ownership is checked in the controller).
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_study_tasks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('campus_id');
            $table->unsignedBigInteger('student_id');
            // Optional link to student_plan_units.id; informational, the task survives plan edits.
            $table->unsignedBigInteger('plan_unit_id')->nullable();
            $table->string('title', 120);
            // preview | homework | review | correction | other
            $table->string('kind', 12)->default('other');
            $table->date('due_date');
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('done_at')->nullable();
            $table->unsignedBigInteger('done_by')->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'student_id', 'due_date'], 'student_study_tasks_student_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_study_tasks');
    }
};
