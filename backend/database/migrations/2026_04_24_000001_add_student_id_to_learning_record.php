<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('LearningRecord')) {
            return;
        }
        if (Schema::hasColumn('LearningRecord', 'StudentID')) {
            return;
        }
        Schema::table('LearningRecord', function (Blueprint $table) {
            $table->bigInteger('StudentID')->default(0)->after('id');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('LearningRecord') && Schema::hasColumn('LearningRecord', 'StudentID')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->dropColumn('StudentID');
            });
        }
    }
};
