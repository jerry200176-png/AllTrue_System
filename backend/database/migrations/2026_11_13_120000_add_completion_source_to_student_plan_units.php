<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive: where a plan unit was ticked done (#4970 step 5). Two nullable columns, no default, no index,
 * no backfill (existing units were not ticked from a lesson).
 * Additive-Migration: yes
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_plan_units', function (Blueprint $table) {
            $table->unsignedBigInteger('completed_session_id')->nullable();
            $table->unsignedBigInteger('completed_record_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_plan_units', function (Blueprint $table) {
            $table->dropColumn(['completed_session_id', 'completed_record_id']);
        });
    }
};
