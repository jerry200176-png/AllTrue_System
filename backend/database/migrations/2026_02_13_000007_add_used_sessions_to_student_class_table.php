<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('StudentClass', 'UsedSessions')) {
            return;
        }
        Schema::table('StudentClass', function (Blueprint $table) {
            // Track monthly usage for 'month' mode students
            $table->integer('UsedSessions')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->dropColumn('UsedSessions');
        });
    }
};
