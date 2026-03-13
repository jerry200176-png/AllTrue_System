<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('StudentClass', 'ClassType')) {
            return;
        }
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->string('ClassType', 32)->default('regular');
        });
    }

    public function down(): void
    {
        Schema::table('StudentClass', function (Blueprint $table) {
            $table->dropColumn('ClassType');
        });
    }
};
