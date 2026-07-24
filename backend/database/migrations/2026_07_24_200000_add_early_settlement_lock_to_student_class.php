<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }

        Schema::table('StudentClass', function (Blueprint $table) {
            if (!Schema::hasColumn('StudentClass', 'settlement_locked_at')) {
                $table->timestamp('settlement_locked_at')->nullable();
            }
            if (!Schema::hasColumn('StudentClass', 'settlement_snapshot')) {
                $table->text('settlement_snapshot')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('StudentClass')) {
            return;
        }

        Schema::table('StudentClass', function (Blueprint $table) {
            if (Schema::hasColumn('StudentClass', 'settlement_snapshot')) {
                $table->dropColumn('settlement_snapshot');
            }
            if (Schema::hasColumn('StudentClass', 'settlement_locked_at')) {
                $table->dropColumn('settlement_locked_at');
            }
        });
    }
};
