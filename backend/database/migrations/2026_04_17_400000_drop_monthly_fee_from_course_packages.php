<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 月結制修正：移除 monthly_fee 欄位。
 * 月結金額改為 sessions × rate（本月實際出席堂數 × 每堂費率）動態計算，
 * 不再儲存固定月費。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            if (Schema::hasColumn('course_packages', 'monthly_fee')) {
                $table->dropColumn('monthly_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            if (!Schema::hasColumn('course_packages', 'monthly_fee')) {
                $table->unsignedInteger('monthly_fee')->nullable()->after('billing_mode');
            }
        });
    }
};
