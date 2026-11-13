<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: 學生學習計畫 (#4970 step 4a). New tables only; no unique/foreign keys
 * ("one non-archived plan per course" is enforced in the controller under a row lock).
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_learning_plans', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('campus_id');
            $table->unsignedBigInteger('student_id');
            // StudentClass.ID: the course/contract this plan belongs to.
            $table->unsignedBigInteger('student_class_id');
            // Informational only; unit content is copied, template unit ids change on every template save.
            $table->unsignedBigInteger('source_template_id')->nullable();
            $table->string('title', 120);
            $table->string('status', 12)->default('draft');
            $table->date('plan_start');
            $table->date('plan_end');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['campus_id', 'student_id', 'status'], 'student_learning_plans_student_idx');
            $table->index('student_class_id', 'student_learning_plans_course_idx');
        });
        Schema::create('student_plan_units', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('plan_id');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('title', 120);
            $table->string('note', 255)->nullable();
            $table->unsignedTinyInteger('exam_sequence')->nullable();
            $table->date('planned_start');
            $table->date('planned_end');
            $table->boolean('dates_manual')->default(false);
            $table->dateTime('completed_at')->nullable();
            $table->unsignedBigInteger('completed_by')->nullable();
            $table->timestamps();

            $table->index(['plan_id', 'sort_order'], 'student_plan_units_order_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_plan_units');
        Schema::dropIfExists('student_learning_plans');
    }
};
