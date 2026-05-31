<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #613 A1（minutes-based 餘額）PR1：地基。
 * 新增「分鐘制」權威餘額欄位（additive、nullable，無行為變更）。
 * - PurchasedMinutes：購買總分鐘（= SessionCount × 每堂分鐘），權威購買量。
 * - RemainingMinutes：剩餘分鐘，權威餘額；RemainingSessions 後續改為其衍生顯示值。
 * 引擎尚未讀取這兩欄，本 migration 不改變任何扣堂行為。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }
        Schema::table('StudentClass', function (Blueprint $table) {
            if (!Schema::hasColumn('StudentClass', 'PurchasedMinutes')) {
                $table->integer('PurchasedMinutes')->nullable()->after('SessionDuration');
            }
            if (!Schema::hasColumn('StudentClass', 'RemainingMinutes')) {
                $table->integer('RemainingMinutes')->nullable()->after('PurchasedMinutes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }
        Schema::table('StudentClass', function (Blueprint $table) {
            foreach (['RemainingMinutes', 'PurchasedMinutes'] as $col) {
                if (Schema::hasColumn('StudentClass', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
