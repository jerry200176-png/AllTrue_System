<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('Campus', 'SwipeWindowMinutes')) {
            return;
        }
        Schema::table('Campus', function (Blueprint $table) {
            $table->integer('SwipeWindowMinutes')->default(30);
        });
    }

    public function down(): void
    {
        Schema::table('Campus', function (Blueprint $table) {
            $table->dropColumn('SwipeWindowMinutes');
        });
    }
};
