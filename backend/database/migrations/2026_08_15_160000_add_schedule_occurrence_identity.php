<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TD-076 Phase 2: nullable identity columns + append-only log.
 * No unique index (backfill / collisions come later). Readers still use the chain.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('schedules')) {
            Schema::table('schedules', function (Blueprint $table) {
                if (!Schema::hasColumn('schedules', 'original_schedule_date')) {
                    $table->date('original_schedule_date')->nullable()->after('original_schedule_id');
                }
                if (!Schema::hasColumn('schedules', 'original_start_time')) {
                    $table->string('original_start_time', 8)->nullable()->after('original_schedule_date');
                }
            });
        }

        if (!Schema::hasTable('schedule_change_log')) {
            Schema::create('schedule_change_log', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('schedule_id')->index();
                $table->unsignedInteger('student_course_id')->index();
                $table->date('original_schedule_date')->nullable();
                $table->string('original_start_time', 8)->nullable();
                $table->date('from_date');
                $table->string('from_time', 8);
                $table->date('to_date');
                $table->string('to_time', 8);
                $table->unsignedInteger('actor_id')->nullable()->index();
                $table->string('reason', 64)->default('reschedule');
                $table->timestamp('created_at')->useCurrent()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_change_log');
        if (Schema::hasTable('schedules')) {
            Schema::table('schedules', function (Blueprint $table) {
                if (Schema::hasColumn('schedules', 'original_start_time')) {
                    $table->dropColumn('original_start_time');
                }
                if (Schema::hasColumn('schedules', 'original_schedule_date')) {
                    $table->dropColumn('original_schedule_date');
                }
            });
        }
    }
};
