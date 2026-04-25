<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_record_feedbacks')) {
            return;
        }

        Schema::create('learning_record_feedbacks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('learning_record_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('student_class_id');
            $table->unsignedBigInteger('class_session_id')->nullable();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('campus_id');
            $table->text('content');
            $table->unsignedBigInteger('parent_session_id')->nullable();
            $table->dateTime('last_read_by_teacher_at')->nullable();
            $table->dateTime('last_read_by_director_at')->nullable();
            $table->timestamps();

            $table->unique('learning_record_id', 'lrf_learning_record_unique');
            $table->index(['student_id', 'updated_at'], 'lrf_student_updated_idx');
            $table->index(['teacher_id', 'last_read_by_teacher_at', 'updated_at'], 'lrf_teacher_unread_idx');
            $table->index(['campus_id', 'last_read_by_director_at', 'updated_at'], 'lrf_campus_unread_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_record_feedbacks');
    }
};
