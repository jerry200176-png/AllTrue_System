<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('LearningRecord', 'ReviewNote')) {
            return;
        }
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->string('ReviewNote', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->dropColumn('ReviewNote');
        });
    }
};
