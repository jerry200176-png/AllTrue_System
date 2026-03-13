<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('LearningRecord', 'CreatedByUserID')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->unsignedBigInteger('CreatedByUserID')->nullable()->after('TeacherID');
            });
        }

        if (Schema::hasTable('User') && !Schema::hasColumn('User', 'TeachingSessionCount')) {
            Schema::table('User', function (Blueprint $table) {
                $table->unsignedInteger('TeachingSessionCount')->default(0)->after('phone');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('LearningRecord', 'CreatedByUserID')) {
            Schema::table('LearningRecord', function (Blueprint $table) {
                $table->dropColumn('CreatedByUserID');
            });
        }
        if (Schema::hasTable('User') && Schema::hasColumn('User', 'TeachingSessionCount')) {
            Schema::table('User', function (Blueprint $table) {
                $table->dropColumn('TeachingSessionCount');
            });
        }
    }
};
