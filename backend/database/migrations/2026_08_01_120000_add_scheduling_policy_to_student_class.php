<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('StudentClass', 'scheduling_policy')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->string('scheduling_policy', 32)
                    ->default('auto_recurrence')
                    ->after('ScheduleMode')
                    ->index('idx_student_class_scheduling_policy');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('StudentClass', 'scheduling_policy')) {
            Schema::table('StudentClass', function (Blueprint $table) {
                $table->dropIndex('idx_student_class_scheduling_policy');
                $table->dropColumn('scheduling_policy');
            });
        }
    }
};
