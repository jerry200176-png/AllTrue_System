<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('payment_reports') || Schema::hasColumn('payment_reports', 'note')) {
            return;
        }

        Schema::table('payment_reports', function (Blueprint $table) {
            $table->text('note')->nullable()->after('account_last5');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('payment_reports', 'note')) {
            Schema::table('payment_reports', function (Blueprint $table) {
                $table->dropColumn('note');
            });
        }
    }
};
