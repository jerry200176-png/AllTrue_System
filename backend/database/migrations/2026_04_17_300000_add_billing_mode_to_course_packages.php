<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 多科共用方案月結制：在 course_packages 新增計費模式相關欄位。
 *
 * - billing_mode: 'count'（堂數制，預設／現行行為）或 'monthly'（月結制）
 * - monthly_fee: 月結方案的整包月費（僅在 billing_mode='monthly' 時有意義）
 * - settlement_day: 每月結算日（1–31；fallback 為當月最後一天）
 *
 * 新欄位均為 nullable 以保持與既有資料相容。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            if (!Schema::hasColumn('course_packages', 'billing_mode')) {
                $table->string('billing_mode', 16)->default('count')->after('class_type');
            }
            if (!Schema::hasColumn('course_packages', 'monthly_fee')) {
                $table->unsignedInteger('monthly_fee')->nullable()->after('billing_mode');
            }
            if (!Schema::hasColumn('course_packages', 'settlement_day')) {
                $table->unsignedTinyInteger('settlement_day')->nullable()->after('monthly_fee');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            if (Schema::hasColumn('course_packages', 'settlement_day')) {
                $table->dropColumn('settlement_day');
            }
            if (Schema::hasColumn('course_packages', 'monthly_fee')) {
                $table->dropColumn('monthly_fee');
            }
            if (Schema::hasColumn('course_packages', 'billing_mode')) {
                $table->dropColumn('billing_mode');
            }
        });
    }
};
