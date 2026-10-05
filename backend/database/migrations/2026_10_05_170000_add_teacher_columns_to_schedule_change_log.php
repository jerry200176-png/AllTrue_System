<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TD-076 Track B PR-A: additive nullable teacher columns on the append-only log.
 * Existing rows keep reason=reschedule and null teachers. No writer uses them yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('schedule_change_log')) {
            return;
        }
        Schema::table('schedule_change_log', function (Blueprint $table) {
            if (!Schema::hasColumn('schedule_change_log', 'from_teacher_id')) {
                $table->unsignedInteger('from_teacher_id')->nullable()->after('to_time');
            }
            if (!Schema::hasColumn('schedule_change_log', 'to_teacher_id')) {
                $table->unsignedInteger('to_teacher_id')->nullable()->after('from_teacher_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('schedule_change_log')) {
            return;
        }
        Schema::table('schedule_change_log', function (Blueprint $table) {
            if (Schema::hasColumn('schedule_change_log', 'to_teacher_id')) {
                $table->dropColumn('to_teacher_id');
            }
            if (Schema::hasColumn('schedule_change_log', 'from_teacher_id')) {
                $table->dropColumn('from_teacher_id');
            }
        });
    }
};
