<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * #137 / #575：主任「補登空白評量」會把 ExcludeFromSubjectCount 設為 1，
     * 使該評量不計入老師科目數（FinanceController::subjectUnits）。此欄位原本只有
     * 程式碼引用（以 Schema::hasColumn 防呆）卻無 migration，導致功能在多數環境
     * 形同未啟用。新增欄位，預設 0（既有評量維持原行為、不回溯改動科目數/薪資）。
     */
    public function up(): void
    {
        if (!Schema::hasTable('LearningRecord')) {
            return;
        }
        if (Schema::hasColumn('LearningRecord', 'ExcludeFromSubjectCount')) {
            return;
        }

        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->boolean('ExcludeFromSubjectCount')->default(0);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('LearningRecord') && Schema::hasColumn('LearningRecord', 'ExcludeFromSubjectCount')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->dropColumn('ExcludeFromSubjectCount');
            });
        }
    }
};
