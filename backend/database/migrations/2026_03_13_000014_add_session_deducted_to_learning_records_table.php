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

        Schema::table('LearningRecord', function (Blueprint $table) {
            if (!Schema::hasColumn('LearningRecord', 'SessionDeducted')) {
                $table->boolean('SessionDeducted')->default(false)->after('ApprovedAt');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('LearningRecord')) {
            return;
        }

        Schema::table('LearningRecord', function (Blueprint $table) {
            if (Schema::hasColumn('LearningRecord', 'SessionDeducted')) {
                $table->dropColumn('SessionDeducted');
            }
        });
    }
};
