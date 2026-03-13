<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('LearningRecord', 'Subject')) {
            return;
        }
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->string('Subject', 32)->nullable();
            $table->date('SessionDate')->nullable();
            $table->time('StartTime')->nullable();
            $table->time('EndTime')->nullable();
            $table->string('HomeworkStatus', 16)->nullable();
            $table->string('QuizScore', 32)->nullable();
            $table->string('Progress', 128)->nullable();
            $table->string('NextHomework', 128)->nullable();
            $table->string('Performance', 16)->nullable();
            $table->text('Comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->dropColumn([
                'Subject',
                'SessionDate',
                'StartTime',
                'EndTime',
                'HomeworkStatus',
                'QuizScore',
                'Progress',
                'NextHomework',
                'Performance',
                'Comment',
            ]);
        });
    }
};
