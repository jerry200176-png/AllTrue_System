<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TD-078: storeSalaryProfile() let any director write a salary figure that
 * immediately fed payroll totals, with no second approver and no "who
 * changed what" trail — unlike teacher_payroll_deductions, which already
 * has this exact two-stage shape (director_confirmed -> hq_approved).
 *
 * New column defaults to 'pending' so every FUTURE write goes through
 * approval before it can feed payroll. Existing rows are backfilled to
 * 'approved' in the same migration (not left pending) — they already
 * represent figures payroll has been using; the point of this change is
 * to gate what happens next, not to retroactively stop paying teachers
 * their already-established salary until someone re-approves history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fulltime_salary_profiles', function (Blueprint $table) {
            if (!Schema::hasColumn('fulltime_salary_profiles', 'status')) {
                $table->string('status', 16)->default('pending')->after('effective_from');
            }
            if (!Schema::hasColumn('fulltime_salary_profiles', 'approved_by')) {
                $table->unsignedInteger('approved_by')->nullable()->after('created_by');
            }
            if (!Schema::hasColumn('fulltime_salary_profiles', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approved_by');
            }
        });

        // Grandfather every pre-existing row so this migration cannot itself
        // change what payroll pays out on the next run.
        DB::table('fulltime_salary_profiles')
            ->where('status', 'pending')
            ->update(['status' => 'approved', 'approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('fulltime_salary_profiles', function (Blueprint $table) {
            $table->dropColumn(['status', 'approved_by', 'approved_at']);
        });
    }
};
