<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('StudentClass', 'RemainingSessions')) {
            return;
        }
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->integer('RemainingSessions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->dropColumn('RemainingSessions');
        });
    }
};
