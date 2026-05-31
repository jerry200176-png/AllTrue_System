<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #613 A1（minutes-based 餘額）PR1：地基。
 * 每筆扣堂/還原事件帶 minutes（最小整數單位）。
 * legacy 既有列維持 NULL，引擎讀取時 fallback 為「該課每堂分鐘」＝等同整堂，
 * 因此本 migration 不改變任何既有 ledger 的淨值語意。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('session_deduction_ledger')) {
            return;
        }
        Schema::table('session_deduction_ledger', function (Blueprint $table) {
            if (!Schema::hasColumn('session_deduction_ledger', 'minutes')) {
                $table->integer('minutes')->nullable()->after('source');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('session_deduction_ledger')) {
            return;
        }
        Schema::table('session_deduction_ledger', function (Blueprint $table) {
            if (Schema::hasColumn('session_deduction_ledger', 'minutes')) {
                $table->dropColumn('minutes');
            }
        });
    }
};
