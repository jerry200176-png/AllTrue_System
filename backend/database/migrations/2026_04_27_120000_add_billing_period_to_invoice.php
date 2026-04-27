<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('Invoice', function (Blueprint $table) {
            $table->string('billing_period', 7)->nullable()->after('Note')->comment('YYYY-MM 格式，月結制逐期帳單標識');
        });
    }

    public function down(): void
    {
        Schema::table('Invoice', function (Blueprint $table) {
            $table->dropColumn('billing_period');
        });
    }
};
