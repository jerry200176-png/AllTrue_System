<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('learning_record_teacher_comments')) {
            return;
        }

        Schema::create('learning_record_teacher_comments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('learning_record_id');
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('student_class_id');
            $table->unsignedBigInteger('class_session_id')->nullable();
            $table->unsignedBigInteger('teacher_id');
            $table->unsignedBigInteger('campus_id');
            $table->unsignedBigInteger('author_user_id');
            $table->text('content');
            $table->dateTime('last_read_by_teacher_at')->nullable();
            $table->timestamps();

            $table->unique('learning_record_id', 'lrtc_learning_record_unique');
            $table->index(['teacher_id', 'last_read_by_teacher_at', 'updated_at'], 'lrtc_teacher_unread_idx');
            $table->index(['campus_id', 'updated_at'], 'lrtc_campus_updated_idx');
            $table->index(['author_user_id', 'updated_at'], 'lrtc_author_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_record_teacher_comments');
    }
};
