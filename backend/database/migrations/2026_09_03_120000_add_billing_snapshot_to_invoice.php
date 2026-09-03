<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('Invoice') && !Schema::hasColumn('Invoice', 'billing_snapshot')) {
            Schema::table('Invoice', function (Blueprint $table) {
                $table->json('billing_snapshot')->nullable()->after('billing_period');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('Invoice') && Schema::hasColumn('Invoice', 'billing_snapshot')) {
            Schema::table('Invoice', function (Blueprint $table) {
                $table->dropColumn('billing_snapshot');
            });
        }
    }
};
