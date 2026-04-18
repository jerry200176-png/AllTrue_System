<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('schedule_discrepancies')) {
            return;
        }
        if (Schema::hasColumn('schedule_discrepancies', 'corrected_time_range')) {
            return;
        }

        Schema::table('schedule_discrepancies', function (Blueprint $table) {
            // PRD 2026-04-18 FR-003: teachers reporting "wrong_time" can now
            // indicate what the correct time should be (e.g. "14:00-16:00").
            // Optional; format validated by controller regex.
            $table->string('corrected_time_range', 32)->nullable()->after('time_range');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('schedule_discrepancies')) {
            return;
        }
        if (!Schema::hasColumn('schedule_discrepancies', 'corrected_time_range')) {
            return;
        }

        Schema::table('schedule_discrepancies', function (Blueprint $table) {
            $table->dropColumn('corrected_time_range');
        });
    }
};
