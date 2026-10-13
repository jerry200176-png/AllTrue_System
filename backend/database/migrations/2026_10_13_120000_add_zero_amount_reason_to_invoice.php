<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #3991 (Founder 2026-10-13): a NT$0 bill is allowed only when it is explicitly marked free/gift (免費／贈送).
 * Additive nullable column, no backfill: existing bills stay NULL (= not marked). Nothing outside #3991 reads it.
 * Rollback: down() drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Invoice') || Schema::hasColumn('Invoice', 'zero_amount_reason')) {
            return;
        }
        Schema::table('Invoice', function (Blueprint $table) {
            $table->string('zero_amount_reason', 32)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('Invoice') || !Schema::hasColumn('Invoice', 'zero_amount_reason')) {
            return;
        }
        Schema::table('Invoice', function (Blueprint $table) {
            $table->dropColumn('zero_amount_reason');
        });
    }
};
