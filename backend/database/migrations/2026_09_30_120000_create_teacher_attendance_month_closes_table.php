<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 老師出勤月底確認：主任確認某分校某月後就不能補卡，要先重新開啟（需原因）。
 * 每次確認一列；重新開啟時填 reopened_*，不刪列，留完整紀錄。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_attendance_month_closes', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('campus_id');
            $table->char('year_month', 7);
            $table->unsignedInteger('closed_by_user_id');
            $table->dateTime('closed_at');
            $table->unsignedInteger('reopened_by_user_id')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->text('reopen_reason')->nullable();

            $table->index(['campus_id', 'year_month'], 'idx_tamc_campus_month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_attendance_month_closes');
    }
};
