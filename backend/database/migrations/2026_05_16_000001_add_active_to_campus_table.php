<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('Campus')) return;
        if (Schema::hasColumn('Campus', 'active')) return;

        Schema::table('Campus', function (Blueprint $table) {
            $table->boolean('active')->default(true)->after('SwipeWindowMinutes');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('Campus') || !Schema::hasColumn('Campus', 'active')) return;
        Schema::table('Campus', function (Blueprint $table) {
            $table->dropColumn('active');
        });
    }
};
