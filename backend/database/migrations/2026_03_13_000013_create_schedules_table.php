<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schedules')) {
            return;
        }

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->integer('student_id');
            $table->integer('teacher_id')->nullable();
            $table->string('subject')->nullable();
            $table->integer('day_of_week');
            $table->string('start_time');
            $table->string('end_time');
            $table->decimal('duration_hours', 5, 2)->nullable();
            $table->string('class_type')->nullable();
            $table->string('status')->default('scheduled');
            $table->string('type')->default('normal');
            $table->integer('deduction')->default(1);
            $table->integer('branch_id');
            $table->date('schedule_date')->nullable();
            $table->integer('student_course_id')->nullable();
            $table->integer('original_schedule_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
