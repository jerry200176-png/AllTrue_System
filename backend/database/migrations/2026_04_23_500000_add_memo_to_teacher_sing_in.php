<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 補上 TeacherSingIn.Memo 欄位（production DB 已存在，補齊 migration 記錄）
 * 用途：teacher-signin:close-orphans command 寫入「系統自動補登簽退」備註
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('TeacherSingIn', function (Blueprint $table) {
            if (!Schema::hasColumn('TeacherSingIn', 'Memo')) {
                $table->string('Memo', 512)->nullable()->after('Hours');
            }
        });
    }

    public function down(): void
    {
        Schema::table('TeacherSingIn', function (Blueprint $table) {
            if (Schema::hasColumn('TeacherSingIn', 'Memo')) {
                $table->dropColumn('Memo');
            }
        });
    }
};
