<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('StudentClass')) {
            return;
        }
        Schema::create('StudentClass', function (Blueprint $table) {
            $table->bigIncrements('ID');
            $table->integer('StudentID');
            $table->integer('GradeID');
            $table->integer('SubjectID');
            $table->integer('TeacherID');
            $table->integer('by1');
            $table->integer('Period')->default(4);
            $table->dateTime('StartDate');
            $table->dateTime('EndDate')->nullable();
            $table->integer('week')->nullable();
            $table->time('time')->nullable();
            $table->integer('week1')->nullable();
            $table->time('time1')->nullable();
            $table->integer('week2')->nullable();
            $table->time('time2')->nullable();
            $table->integer('week3')->nullable();
            $table->time('time3')->nullable();
            $table->integer('week4')->nullable();
            $table->time('time4')->nullable();
            $table->integer('week5')->nullable();
            $table->time('time5')->nullable();
            $table->integer('week6')->nullable();
            $table->time('time6')->nullable();
            $table->integer('TotalHours');
            $table->string('Memo', 512)->nullable();
            $table->integer('Charge')->nullable();
            $table->integer('Pay')->nullable();
            $table->date('PayDate')->nullable();
            $table->integer('Paid')->default(0);
            $table->integer('Disconunt')->nullable();
            $table->float('Rate')->nullable();
            $table->integer('LearnTimeID')->nullable();
            $table->string('RoomID', 32);
            $table->dateTime('MDate')->useCurrent();
            $table->boolean('Stop')->default(false);
            $table->string('ScheduleMode', 16)->default('date');
            $table->integer('SessionCount')->nullable();
            $table->integer('SessionDuration')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('StudentClass');
    }
};
